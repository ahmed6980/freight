#!/usr/bin/env bash
#
# Nightly WordPress maintenance for a vehicle/auction site.
#
# Strategy (requirement 14):
#   Monitor -> Detect -> Optimize -> Remove temporary data -> Remove orphaned
#   data -> Remove oldest eligible auction pages -> Recalculate -> Verify -> Log
#
# SAFETY: this script is DRY RUN by default. It will not delete anything until
# you pass --execute. Read docs/RUNBOOK.md before you do.
#
# Usage:
#   nightly-maintenance.sh [--execute] [--force] [--config PATH] [--report-only]
#
set -uo pipefail

MAINT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export MAINT_ROOT

CONFIG_FILE="${MAINT_ROOT}/config/maintenance.conf"
REPORT_ONLY=false

while (( $# > 0 )); do
    case "$1" in
        --execute)     DRY_RUN=false ;;
        --dry-run)     DRY_RUN=true ;;
        --force)       FORCE_RUN=true ;;
        --report-only) REPORT_ONLY=true; DRY_RUN=true ;;
        --config)      CONFIG_FILE="$2"; shift ;;
        -h|--help)
            sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

DRY_RUN="${DRY_RUN:-true}"
FORCE_RUN="${FORCE_RUN:-false}"

# Config first, so it can override every default in the libraries.
if [[ -f "$CONFIG_FILE" ]]; then
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
else
    printf 'WARNING: no config at %s — using defaults.\n' "$CONFIG_FILE" >&2
fi

for lib in common storage cleanup_temp vehicles orphans media database health report; do
    # shellcheck source=/dev/null
    source "${MAINT_ROOT}/lib/${lib}.sh"
done

cleanup_on_exit() {
    local rc=$?
    release_lock
    (( rc != 0 )) && log_error "Maintenance run exited with code ${rc}."
    exit "$rc"
}
trap cleanup_on_exit EXIT
trap 'log_error "Interrupted."; exit 130' INT TERM

main() {
    init_runtime

    # --- Requirement 1: verify time and enforce one run per cycle -----------
    acquire_lock       || exit 1
    check_run_once     || exit 0
    require_wp_cli     || exit 1
    # If awk cannot parse auction dates correctly, disable the vehicle purge
    # rather than let it silently find nothing eligible, night after night.
    require_awk_capabilities || VEHICLE_PURGE_BLOCKED=true

    # --- Monitor: full storage audit before touching anything --------------
    measure_storage
    STORAGE_BEFORE_BYTES="$TOTAL_BYTES"
    report_largest
    report_growth_sources

    if [[ "$REPORT_ONLY" == "true" ]]; then
        log_info "--report-only: auditing and reporting, no cleanup."
        detect_vehicle_model
        scan_orphans
        check_performance
        check_http_health
        check_wordpress_health
        determine_status
        write_maintenance_log
        print_summary
        exit 0
    fi

    [[ "$STORAGE_TIER" == "EMERGENCY" ]] && \
        log_warn "STORAGE AT OR ABOVE THE ${STORAGE_LIMIT_GIB} GiB CEILING — EMERGENCY CLEANUP MODE."

    # --- Backup before anything destructive touches the database -----------
    backup_database || log_warn "Proceeding without a verified backup; DB cleanup will be skipped."

    # --- Priority 1: temporary, cache, logs, build artifacts ---------------
    cleanup_temp_and_cache
    recalculate_storage "priority 1 (temp/cache/logs)"

    # --- Establish the vehicle model before any content deletion -----------
    detect_vehicle_model

    # --- Orphan classification (reports; deletes only if opted in) ---------
    scan_orphans
    recalculate_storage "orphan cleanup"

    # --- Priority 2: oldest eligible auction/vehicle pages -----------------
    purge_old_vehicles
    recalculate_storage "vehicle retention purge"

    # --- Media tied to what we just deleted --------------------------------
    cleanup_media
    recalculate_storage "media cleanup"

    # --- Database ----------------------------------------------------------
    cleanup_database
    recalculate_storage "database cleanup"

    # --- Requirement 12: keep going while still over the hard ceiling ------
    local pass=1
    while over_limit && (( pass <= 5 )); do
        log_warn "Still at $(to_gib "$TOTAL_BYTES") GiB after pass ${pass}. Continuing cleanup."
        purge_vehicles_emergency
        recalculate_storage "emergency pass ${pass}"
        if over_limit && (( EMERGENCY_MIN_AGE_DAYS >= VEHICLE_RETENTION_DAYS )); then
            log_error "Over the ceiling with no further content eligible for safe deletion."
            log_error "Refusing to delete active content to make room. Human review required."
            break
        fi
        pass=$((pass + 1))
    done

    # --- Verify the site still works ---------------------------------------
    check_performance
    final_verification

    over_limit && log_error "FINAL: storage is $(to_gib "$TOTAL_BYTES") GiB, still at or above the ${STORAGE_LIMIT_GIB} GiB ceiling."

    determine_status
    mark_cycle_complete
    write_maintenance_log
    print_summary

    [[ "$OVERALL_STATUS" == "CRITICAL" ]] && exit 1
    return 0
}

main "$@"
