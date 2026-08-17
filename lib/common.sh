#!/usr/bin/env bash
# Shared helpers: config, logging, locking, run-once guard, WP-CLI wrapper.
# Sourced by bin/nightly-maintenance.sh — not meant to be executed directly.

# ---------------------------------------------------------------------------
# Defaults. Everything here is overridable from config/maintenance.conf.
# ---------------------------------------------------------------------------
: "${WP_PATH:=/var/www/html}"
: "${WP_CLI:=wp}"
: "${WP_CLI_EXTRA_ARGS:=}"

# Storage thresholds, in GiB. STORAGE_LIMIT_GIB is the hard ceiling.
: "${STORAGE_LIMIT_GIB:=40}"
: "${THRESHOLD_AGGRESSIVE_GIB:=35}"   # 35-38 -> aggressive optimization
: "${THRESHOLD_REMOVE_OLD_GIB:=38}"   # 38-39 -> begin removing eligible old content
: "${THRESHOLD_HARD_CLEAN_GIB:=39}"   # 39-40 -> aggressive removal of eligible content

# Which filesystem paths make up "the website".
: "${SITE_PATHS:=${WP_PATH}}"

# Vehicle/auction model. Left EMPTY on purpose: the suite refuses to delete
# vehicle pages until these are either configured or confidently auto-detected.
: "${VEHICLE_POST_TYPE:=}"
: "${AUCTION_DATE_META:=}"
: "${VEHICLE_RETENTION_DAYS:=7}"

# Orphan handling. Reporting is always safe; deletion is opt-in and additionally
# requires human review, because "no inbound links" alone is not evidence.
: "${ORPHAN_AUTODELETE:=false}"

# Database backup command. Must exit non-zero on failure.
: "${DB_BACKUP_ENABLED:=true}"
: "${BACKUP_DIR:=/var/backups/wp-maintenance}"
: "${BACKUP_RETENTION_DAYS:=14}"

# Health checks
: "${SITE_URL:=}"
: "${HEALTH_URLS:=}"

: "${LOG_DIR:=/var/log/wp-maintenance}"
: "${STATE_DIR:=/var/lib/wp-maintenance}"

# ---------------------------------------------------------------------------
# Runtime state
# ---------------------------------------------------------------------------
DRY_RUN="${DRY_RUN:-true}"
FORCE_RUN="${FORCE_RUN:-false}"
RUN_ID=""
RUN_STARTED_EPOCH=""
LOG_FILE=""
EVENT_LOG=""
MANIFEST=""

# Counters surfaced in the final report.
COUNT_FILES_DELETED=0
COUNT_PAGES_DELETED=0
COUNT_VEHICLES_DELETED=0
COUNT_IMAGES_DELETED=0
COUNT_ERRORS=0
COUNT_ORPHANS_FOUND=0
BYTES_RECLAIMED=0
DB_CLEANED="none"
BACKUP_STATUS="SKIPPED"
PERF_STATUS="UNKNOWN"

GIB=$((1024 * 1024 * 1024))

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log() {
    local level="$1"; shift
    local ts; ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    local line="[${ts}] [${level}] $*"
    printf '%s\n' "$line"
    [[ -n "$LOG_FILE" ]] && printf '%s\n' "$line" >>"$LOG_FILE"
    return 0
}

log_info()  { log INFO  "$@"; }
log_warn()  { log WARN  "$@"; }
log_error() { log ERROR "$@"; COUNT_ERRORS=$((COUNT_ERRORS + 1)); }

# Structured event for the machine-readable log.
event() {
    local kind="$1"; shift
    local detail="$*"
    [[ -z "$EVENT_LOG" ]] && return 0
    printf '{"ts":"%s","run_id":"%s","kind":"%s","dry_run":%s,"detail":%s}\n' \
        "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$RUN_ID" "$kind" "$DRY_RUN" \
        "$(json_string "$detail")" >>"$EVENT_LOG"
}

# Minimal JSON string escaping (quotes, backslashes, control chars).
json_string() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\r'/\\r}"
    s="${s//$'\t'/\\t}"
    printf '"%s"' "$s"
}

human_bytes() {
    local b="${1:-0}"
    awk -v b="$b" 'BEGIN {
        split("B KiB MiB GiB TiB", u, " ")
        i = 1
        while (b >= 1024 && i < 5) { b /= 1024; i++ }
        printf (i == 1 ? "%.0f %s" : "%.2f %s"), b, u[i]
    }'
}

# Bytes -> GiB with 2 decimals, for reports.
to_gib() { awk -v b="${1:-0}" -v g="$GIB" 'BEGIN { printf "%.2f", b / g }'; }

# Integer-safe comparison of a byte count against a GiB threshold.
bytes_ge_gib() {
    local bytes="$1" gib="$2"
    awk -v b="$bytes" -v t="$gib" -v g="$GIB" 'BEGIN { exit !(b >= t * g) }'
}

# ---------------------------------------------------------------------------
# Destructive-action guard. Every delete in this suite goes through here.
# ---------------------------------------------------------------------------
mutate() {
    local description="$1"; shift
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY-RUN would: ${description}"
        event "dry_run_skip" "$description"
        return 0
    fi
    log_info "EXEC: ${description}"
    if "$@"; then
        event "mutated" "$description"
        record_manifest "$description"
        return 0
    fi
    log_error "FAILED: ${description}"
    event "mutate_failed" "$description"
    return 1
}

record_manifest() {
    [[ -n "$MANIFEST" ]] && printf '%s\t%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1" >>"$MANIFEST"
    return 0
}

# ---------------------------------------------------------------------------
# WP-CLI wrapper
# ---------------------------------------------------------------------------
wp_cli() {
    # shellcheck disable=SC2086
    "$WP_CLI" --path="$WP_PATH" $WP_CLI_EXTRA_ARGS "$@"
}

# Read-only WP-CLI call that must not abort the run on failure.
wp_try() {
    local out
    if out="$(wp_cli "$@" 2>&1)"; then
        printf '%s' "$out"
        return 0
    fi
    log_warn "wp ${*} failed: ${out}"
    return 1
}

# The auction-date parser depends on awk's mktime() and on regex behaviour that
# differs between gawk and mawk. If awk cannot do what the parser needs, every
# vehicle would be silently treated as unparseable and never deleted — storage
# would grow forever with no error. Fail loudly instead.
require_awk_capabilities() {
    local ok=true

    if ! awk 'BEGIN { t = mktime("2026 07 18 00 00 00"); exit !(t > 0) }' 2>/dev/null; then
        log_error "awk lacks a working mktime(). Install gawk: apt-get install gawk"
        ok=false
    fi

    # Round-trip a known date through the same logic the purge uses.
    local got
    got="$(printf '2026-07-18\n' | awk '
        function to_epoch(v,   d, t) {
            gsub(/^[ \t]+|[ \t]+$/, "", v)
            if (v ~ /^[0-9]+$/ && length(v) >= 9 && length(v) <= 11) return v + 0
            if (v ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/) {
                d = substr(v, 1, 10); gsub(/-/, " ", d)
                return mktime(d " 00 00 00")
            }
            return -1
        }
        { print to_epoch($0) }' 2>/dev/null)"
    local want; want="$(date -d '2026-07-18 00:00:00' +%s 2>/dev/null)"
    if [[ "$got" != "$want" ]]; then
        log_error "awk date parsing is wrong (got '${got}', expected '${want}'). Refusing to run the vehicle purge."
        ok=false
    fi

    # Timestamp branch must also work, or timestamp-format dates get skipped.
    got="$(printf '1784332800\n' | awk '{ print ($0 ~ /^[0-9]+$/ && length($0) >= 9 && length($0) <= 11) ? "yes" : "no" }' 2>/dev/null)"
    if [[ "$got" != "yes" ]]; then
        log_error "awk cannot classify unix-timestamp dates. Refusing to run the vehicle purge."
        ok=false
    fi

    if $ok; then
        log_info "awk capability check passed ($(awk --version 2>/dev/null | head -1 || echo 'unknown awk'))."
        return 0
    fi
    return 1
}

require_wp_cli() {
    if ! command -v "$WP_CLI" >/dev/null 2>&1; then
        log_error "WP-CLI not found (WP_CLI=${WP_CLI}). Cannot continue."
        return 1
    fi
    if ! wp_cli core is-installed >/dev/null 2>&1; then
        log_error "No WordPress installation at WP_PATH=${WP_PATH}. Cannot continue."
        return 1
    fi
    local ver; ver="$(wp_try core version || echo unknown)"
    log_info "WordPress ${ver} detected at ${WP_PATH}"
    return 0
}

# ---------------------------------------------------------------------------
# Single-run enforcement
# ---------------------------------------------------------------------------
# Requirement: "confirm that the maintenance job is actually running only once
# per scheduled cycle" before any destructive change. Two independent guards:
#   1. flock  -> no two runs concurrently
#   2. state file keyed on the cycle date -> no second run in the same cycle
# ---------------------------------------------------------------------------
LOCK_FD=""
acquire_lock() {
    local lockfile="${STATE_DIR}/maintenance.lock"
    exec {LOCK_FD}>"$lockfile" || { log_error "Cannot open lock file ${lockfile}"; return 1; }
    if ! flock -n "$LOCK_FD"; then
        log_error "Another maintenance run holds the lock. Refusing to run concurrently."
        return 1
    fi
    log_info "Acquired run lock (${lockfile})"
    return 0
}

release_lock() {
    [[ -n "$LOCK_FD" ]] && exec {LOCK_FD}>&- 2>/dev/null
    return 0
}

# The "cycle" is the calendar day in the server's local timezone.
current_cycle() { date '+%Y-%m-%d'; }

check_run_once() {
    local marker="${STATE_DIR}/last-successful-cycle"
    local cycle; cycle="$(current_cycle)"
    if [[ -f "$marker" ]]; then
        local last; last="$(cat "$marker" 2>/dev/null || echo '')"
        if [[ "$last" == "$cycle" ]]; then
            if [[ "$FORCE_RUN" == "true" ]]; then
                log_warn "Cycle ${cycle} already completed; continuing anyway because --force was given."
                return 0
            fi
            log_info "Cycle ${cycle} already completed at $(stat -c %y "$marker" 2>/dev/null). Nothing to do."
            return 1
        fi
    fi
    log_info "Cycle ${cycle} has not run yet. Proceeding."
    return 0
}

mark_cycle_complete() {
    [[ "$DRY_RUN" == "true" ]] && { log_info "DRY-RUN: not marking cycle complete."; return 0; }
    printf '%s\n' "$(current_cycle)" >"${STATE_DIR}/last-successful-cycle"
    return 0
}

# ---------------------------------------------------------------------------
# Init
# ---------------------------------------------------------------------------
init_runtime() {
    RUN_STARTED_EPOCH="$(date +%s)"
    RUN_ID="$(date '+%Y%m%d-%H%M%S')-$$"

    mkdir -p "$LOG_DIR" "$STATE_DIR" 2>/dev/null || {
        LOG_DIR="${MAINT_ROOT}/var/log"
        STATE_DIR="${MAINT_ROOT}/var/state"
        mkdir -p "$LOG_DIR" "$STATE_DIR"
        printf 'Falling back to %s for logs/state\n' "$LOG_DIR"
    }

    LOG_FILE="${LOG_DIR}/maintenance.log"
    EVENT_LOG="${LOG_DIR}/events.jsonl"
    MANIFEST="${LOG_DIR}/deletions-${RUN_ID}.tsv"
    : >"$MANIFEST"

    log_info "=========================================================="
    log_info "Nightly maintenance run ${RUN_ID}"
    log_info "Local time: $(date '+%Y-%m-%d %H:%M:%S %Z')  |  UTC: $(date -u '+%Y-%m-%d %H:%M:%S')"
    log_info "Mode: $([[ "$DRY_RUN" == "true" ]] && echo 'DRY RUN (no changes)' || echo 'EXECUTE (destructive)')"
    log_info "Hard storage ceiling: ${STORAGE_LIMIT_GIB} GiB"
    log_info "=========================================================="
}
