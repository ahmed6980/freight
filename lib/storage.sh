#!/usr/bin/env bash
# Storage measurement and threshold classification.

TOTAL_BYTES=0
AVAILABLE_BYTES=0
DB_BYTES=0
UPLOADS_BYTES=0
STORAGE_TIER="UNKNOWN"

# du that never dies on a permission error and always yields a number.
dir_bytes() {
    local path="$1"
    [[ -d "$path" ]] || { printf '0'; return 0; }
    du -sb --one-file-system "$path" 2>/dev/null | awk '{print $1+0; exit}' || printf '0'
}

uploads_dir() {
    local d
    d="$(wp_try eval 'echo wp_get_upload_dir()["basedir"];' 2>/dev/null || true)"
    [[ -n "$d" && -d "$d" ]] && { printf '%s' "$d"; return 0; }
    printf '%s' "${WP_PATH}/wp-content/uploads"
}

# Database size in bytes, from information_schema.
db_size_bytes() {
    local name sql out
    name="$(wp_try config get DB_NAME 2>/dev/null || true)"
    [[ -z "$name" ]] && { printf '0'; return 0; }
    sql="SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema='${name}';"
    out="$(wp_cli db query "$sql" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)"
    [[ "$out" =~ ^[0-9]+$ ]] && printf '%s' "$out" || printf '0'
}

measure_storage() {
    log_info "--- Storage audit ---"

    TOTAL_BYTES=0
    local p
    for p in $SITE_PATHS; do
        local b; b="$(dir_bytes "$p")"
        TOTAL_BYTES=$((TOTAL_BYTES + b))
        log_info "  path ${p}: $(human_bytes "$b")"
    done

    DB_BYTES="$(db_size_bytes)"
    # The DB lives outside the document root, so it counts toward the total.
    TOTAL_BYTES=$((TOTAL_BYTES + DB_BYTES))

    local ud; ud="$(uploads_dir)"
    UPLOADS_BYTES="$(dir_bytes "$ud")"

    AVAILABLE_BYTES="$(df -B1 --output=avail "$WP_PATH" 2>/dev/null | awk 'NR==2{print $1+0}')"
    [[ -z "$AVAILABLE_BYTES" ]] && AVAILABLE_BYTES=0

    log_info "  database: $(human_bytes "$DB_BYTES")"
    log_info "  uploads (${ud}): $(human_bytes "$UPLOADS_BYTES")"
    log_info "  TOTAL: $(human_bytes "$TOTAL_BYTES") of ${STORAGE_LIMIT_GIB} GiB ceiling"
    log_info "  filesystem available: $(human_bytes "$AVAILABLE_BYTES")"

    classify_storage
    event "storage_measured" "total=${TOTAL_BYTES} db=${DB_BYTES} uploads=${UPLOADS_BYTES} tier=${STORAGE_TIER}"
}

classify_storage() {
    if   bytes_ge_gib "$TOTAL_BYTES" "$STORAGE_LIMIT_GIB";        then STORAGE_TIER="EMERGENCY"
    elif bytes_ge_gib "$TOTAL_BYTES" "$THRESHOLD_HARD_CLEAN_GIB"; then STORAGE_TIER="HARD_CLEAN"
    elif bytes_ge_gib "$TOTAL_BYTES" "$THRESHOLD_REMOVE_OLD_GIB"; then STORAGE_TIER="REMOVE_OLD"
    elif bytes_ge_gib "$TOTAL_BYTES" "$THRESHOLD_AGGRESSIVE_GIB"; then STORAGE_TIER="AGGRESSIVE"
    else STORAGE_TIER="NORMAL"
    fi
    log_info "  tier: ${STORAGE_TIER}"
}

# Detailed inventory for the report. Read-only.
report_largest() {
    log_info "--- Largest directories (top 15) ---"
    local p
    for p in $SITE_PATHS; do
        [[ -d "$p" ]] || continue
        du -b --one-file-system --max-depth=3 "$p" 2>/dev/null \
            | sort -rn | head -15 \
            | while read -r size path; do
                  log_info "  $(human_bytes "$size")\t${path}"
              done
    done

    log_info "--- Largest files (top 25) ---"
    # shellcheck disable=SC2086
    find $SITE_PATHS -xdev -type f -printf '%s\t%p\n' 2>/dev/null \
        | sort -rn | head -25 \
        | while IFS=$'\t' read -r size path; do
              log_info "  $(human_bytes "$size")\t${path}"
          done
}

# Recalculate after a cleanup stage and log the delta.
recalculate_storage() {
    local stage="$1"
    local before="$TOTAL_BYTES"
    measure_storage
    local delta=$((before - TOTAL_BYTES))
    (( delta > 0 )) && BYTES_RECLAIMED=$((BYTES_RECLAIMED + delta))
    log_info "After ${stage}: $(human_bytes "$TOTAL_BYTES") (reclaimed $(human_bytes "$delta"))"
    event "recalculated" "stage=${stage} total=${TOTAL_BYTES} reclaimed=${delta}"
}

# True while storage still exceeds the ceiling.
over_limit() { bytes_ge_gib "$TOTAL_BYTES" "$STORAGE_LIMIT_GIB"; }

# True once we are safely under the "begin removing old content" threshold.
under_safe_threshold() { ! bytes_ge_gib "$TOTAL_BYTES" "$THRESHOLD_REMOVE_OLD_GIB"; }

# Report what is driving growth rather than deleting blindly (requirement 14).
report_growth_sources() {
    local hist="${STATE_DIR}/storage-history.tsv"
    printf '%s\t%s\t%s\t%s\n' "$(date '+%Y-%m-%d')" "$TOTAL_BYTES" "$DB_BYTES" "$UPLOADS_BYTES" >>"$hist"

    local lines; lines="$(wc -l <"$hist" 2>/dev/null || echo 0)"
    (( lines < 2 )) && { log_info "Growth analysis: not enough history yet (${lines} sample(s))."; return 0; }

    log_info "--- Growth analysis (last 7 samples) ---"
    tail -7 "$hist" | awk -v g="$GIB" '
        NR > 1 {
            printf "  %s -> %s: total %+.2f GiB, db %+.2f GiB, uploads %+.2f GiB\n",
                pd, $1, ($2-pt)/g, ($3-pdb)/g, ($4-pu)/g
        }
        { pd=$1; pt=$2; pdb=$3; pu=$4 }
    ' | while read -r l; do log_info "$l"; done
}
