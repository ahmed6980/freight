#!/usr/bin/env bash
# Persistent maintenance log and the end-of-run summary report.

STORAGE_BEFORE_BYTES=0
OVERALL_STATUS="UNKNOWN"

determine_status() {
    # CRITICAL: over the hard ceiling, or the site is not serving correctly.
    if over_limit || (( HEALTH_FAILURES > 0 )); then
        OVERALL_STATUS="CRITICAL"
        return 0
    fi
    # WARNING: inside the ceiling but in a pressure tier, or errors occurred.
    if [[ "$STORAGE_TIER" != "NORMAL" ]] || (( COUNT_ERRORS > 0 )) || [[ "$PERF_STATUS" == "NEEDS ATTENTION" ]]; then
        OVERALL_STATUS="WARNING"
        return 0
    fi
    OVERALL_STATUS="HEALTHY"
}

write_maintenance_log() {
    local ledger="${LOG_DIR}/maintenance-history.log"
    local duration=$(( $(date +%s) - RUN_STARTED_EPOCH ))

    {
        printf '===============================================================\n'
        printf 'RUN            : %s\n' "$RUN_ID"
        printf 'DATE/TIME      : %s (UTC %s)\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$(date -u '+%Y-%m-%d %H:%M:%S')"
        printf 'MODE           : %s\n' "$([[ "$DRY_RUN" == "true" ]] && echo 'DRY RUN' || echo 'EXECUTE')"
        printf 'DURATION       : %ss\n' "$duration"
        printf 'STORAGE BEFORE : %s GiB\n' "$(to_gib "$STORAGE_BEFORE_BYTES")"
        printf 'STORAGE AFTER  : %s GiB\n' "$(to_gib "$TOTAL_BYTES")"
        printf 'RECLAIMED      : %s\n' "$(human_bytes "$BYTES_RECLAIMED")"
        printf 'REMAINING FREE : %s\n' "$(human_bytes "$AVAILABLE_BYTES")"
        printf 'TIER           : %s\n' "$STORAGE_TIER"
        printf 'FILES DELETED  : %s\n' "$COUNT_FILES_DELETED"
        printf 'PAGES DELETED  : %s\n' "$COUNT_PAGES_DELETED"
        printf 'VEHICLES DEL.  : %s\n' "$COUNT_VEHICLES_DELETED"
        printf 'IMAGES DELETED : %s\n' "$COUNT_IMAGES_DELETED"
        printf 'DB CLEANUP     : %s\n' "$DB_CLEANED"
        printf 'ORPHANS FOUND  : %s\n' "$COUNT_ORPHANS_FOUND"
        printf 'VEHICLES ELIG. : %s\n' "$VEHICLES_ELIGIBLE"
        printf 'ERRORS         : %s\n' "$COUNT_ERRORS"
        printf 'HEALTH FAILURES: %s\n' "$HEALTH_FAILURES"
        printf 'PERFORMANCE    : %s\n' "$PERF_STATUS"
        printf 'BACKUP         : %s\n' "$BACKUP_STATUS"
        printf 'STATUS         : %s\n' "$OVERALL_STATUS"
        [[ -n "$HEALTH_NOTES" ]] && { printf 'HEALTH NOTES   :'; printf '%b\n' "$HEALTH_NOTES"; }
        [[ -n "$PERF_NOTES" ]]   && { printf 'PERF NOTES     :'; printf '%b\n' "$PERF_NOTES"; }
        printf 'DELETION LOG   : %s\n' "$MANIFEST"
        printf 'ORPHAN REPORT  : %s\n' "${ORPHAN_REPORT:-none}"
        printf '===============================================================\n'
    } >>"$ledger"

    log_info "Maintenance ledger updated: ${ledger}"
}

print_summary() {
    local next
    next="$(date -d 'tomorrow 04:00' '+%Y-%m-%d %H:%M %Z' 2>/dev/null || echo '04:00 tomorrow')"

    printf '\n'
    printf '================ NIGHTLY MAINTENANCE REPORT ================\n'
    printf 'STATUS: %s\n' "$OVERALL_STATUS"
    printf 'Storage: %s GB / %s GB\n' "$(to_gib "$TOTAL_BYTES")" "$STORAGE_LIMIT_GIB"
    printf 'Deleted: %s pages, %s files, %s\n' "$COUNT_PAGES_DELETED" "$COUNT_FILES_DELETED" "$(human_bytes "$BYTES_RECLAIMED")"
    printf 'Orphaned pages: %s\n' "$COUNT_ORPHANS_FOUND"
    printf 'Old auction pages removed: %s\n' "$COUNT_VEHICLES_DELETED"
    printf 'Errors: %s\n' "$COUNT_ERRORS"
    printf 'Performance: %s\n' "$PERF_STATUS"
    printf 'Backup: %s\n' "$BACKUP_STATUS"
    printf 'Next scheduled run: %s\n' "$next"
    if [[ "$DRY_RUN" == "true" ]]; then
        printf '\nNOTE: DRY RUN — nothing was deleted. Re-run with --execute to apply.\n'
    fi
    printf '============================================================\n'

    # Mirror the summary into the run log too.
    log_info "STATUS=${OVERALL_STATUS} storage=$(to_gib "$TOTAL_BYTES")/${STORAGE_LIMIT_GIB} GiB errors=${COUNT_ERRORS}"
}
