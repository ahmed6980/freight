#!/usr/bin/env bash
# Priority 1 cleanup: caches, temp files, build artifacts, stale logs.
# Nothing here touches published content.

# Directories that are safe to empty because WordPress or the plugin
# regenerates them on demand. Paths are relative to WP_PATH.
: "${CACHE_DIRS:=wp-content/cache wp-content/et-cache wp-content/uploads/cache wp-content/wphb-cache wp-content/litespeed}"
: "${TEMP_GLOBS:=*.tmp *.temp *.swp *~ .DS_Store Thumbs.db}"
: "${LOG_MAX_AGE_DAYS:=14}"
: "${CACHE_MAX_AGE_DAYS:=7}"

cleanup_temp_and_cache() {
    log_info "--- Priority 1: temporary, cache and log cleanup ---"
    cleanup_object_and_page_cache
    cleanup_stale_cache_files
    cleanup_temp_files
    cleanup_old_logs
    cleanup_build_artifacts
    cleanup_stale_backups
}

# Ask WordPress to drop its own caches first — safest, and plugin-aware.
cleanup_object_and_page_cache() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY-RUN would: wp cache flush"
        return 0
    fi
    if wp_cli cache flush >/dev/null 2>&1; then
        log_info "Flushed the WordPress object cache."
    else
        log_warn "wp cache flush unavailable or failed (this is not fatal)."
    fi
    # Page-cache plugins expose their own flush commands; try the common ones.
    local cmd
    for cmd in "w3-total-cache flush all" "super-cache flush" "litespeed-purge all" "rocket clean"; do
        # shellcheck disable=SC2086
        wp_cli $cmd >/dev/null 2>&1 && log_info "Flushed page cache via: wp ${cmd}"
    done
    return 0
}

# Remove cache files older than CACHE_MAX_AGE_DAYS. Regenerated on demand.
cleanup_stale_cache_files() {
    local rel abs count bytes
    for rel in $CACHE_DIRS; do
        abs="${WP_PATH}/${rel}"
        [[ -d "$abs" ]] || continue

        count="$(find "$abs" -type f -mtime "+${CACHE_MAX_AGE_DAYS}" 2>/dev/null | wc -l)"
        (( count == 0 )) && continue
        bytes="$(find "$abs" -type f -mtime "+${CACHE_MAX_AGE_DAYS}" -printf '%s\n' 2>/dev/null | awk '{s+=$1} END {print s+0}')"

        log_info "Cache ${rel}: ${count} file(s) older than ${CACHE_MAX_AGE_DAYS}d, $(human_bytes "$bytes")"
        if mutate "delete ${count} stale cache files under ${rel} ($(human_bytes "$bytes"))" \
                  find "$abs" -type f -mtime "+${CACHE_MAX_AGE_DAYS}" -delete; then
            COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + count))
        fi
        # Clear directories the deletions left empty, but never the cache root.
        [[ "$DRY_RUN" == "false" ]] && find "$abs" -mindepth 1 -type d -empty -delete 2>/dev/null
    done
    return 0
}

cleanup_temp_files() {
    local args=() g first=true
    for g in $TEMP_GLOBS; do
        if $first; then args+=(-name "$g"); first=false
        else args+=(-o -name "$g"); fi
    done
    (( ${#args[@]} == 0 )) && return 0

    local count bytes
    # shellcheck disable=SC2086
    count="$(find $SITE_PATHS -xdev -type f \( "${args[@]}" \) 2>/dev/null | wc -l)"
    (( count == 0 )) && { log_info "No stray temp files found."; return 0; }
    # shellcheck disable=SC2086
    bytes="$(find $SITE_PATHS -xdev -type f \( "${args[@]}" \) -printf '%s\n' 2>/dev/null | awk '{s+=$1} END {print s+0}')"

    log_info "Temp files: ${count}, $(human_bytes "$bytes")"
    # shellcheck disable=SC2086
    if mutate "delete ${count} temp files ($(human_bytes "$bytes"))" \
              find $SITE_PATHS -xdev -type f \( "${args[@]}" \) -delete; then
        COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + count))
    fi
    return 0
}

# Rotate rather than truncate: keep recent logs, drop genuinely old ones.
cleanup_old_logs() {
    local count bytes
    # shellcheck disable=SC2086
    count="$(find $SITE_PATHS -xdev -type f \( -name '*.log' -o -name 'debug.log.*' -o -name '*.log.[0-9]*' -o -name '*.log.gz' \) -mtime "+${LOG_MAX_AGE_DAYS}" 2>/dev/null | wc -l)"
    if (( count > 0 )); then
        # shellcheck disable=SC2086
        bytes="$(find $SITE_PATHS -xdev -type f \( -name '*.log' -o -name 'debug.log.*' -o -name '*.log.[0-9]*' -o -name '*.log.gz' \) -mtime "+${LOG_MAX_AGE_DAYS}" -printf '%s\n' 2>/dev/null | awk '{s+=$1} END {print s+0}')"
        log_info "Old logs: ${count} older than ${LOG_MAX_AGE_DAYS}d, $(human_bytes "$bytes")"
        # shellcheck disable=SC2086
        if mutate "delete ${count} log files older than ${LOG_MAX_AGE_DAYS}d ($(human_bytes "$bytes"))" \
                  find $SITE_PATHS -xdev -type f \( -name '*.log' -o -name 'debug.log.*' -o -name '*.log.[0-9]*' -o -name '*.log.gz' \) -mtime "+${LOG_MAX_AGE_DAYS}" -delete; then
            COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + count))
        fi
    fi

    # The active debug.log is truncated, not deleted, so WordPress keeps writing.
    local dbg="${WP_PATH}/wp-content/debug.log"
    if [[ -f "$dbg" ]]; then
        local sz; sz="$(stat -c %s "$dbg" 2>/dev/null || echo 0)"
        if (( sz > 50 * 1024 * 1024 )); then
            log_warn "debug.log is $(human_bytes "$sz") — truncating (contents archived to the run log first)."
            [[ "$DRY_RUN" == "false" ]] && tail -c 1000000 "$dbg" >"${LOG_DIR}/debug.log.tail-${RUN_ID}" 2>/dev/null
            mutate "truncate oversized debug.log ($(human_bytes "$sz"))" truncate -s 0 "$dbg"
        fi
    fi
    return 0
}

# Build artifacts and dependency trees that belong to a build step, not to runtime.
cleanup_build_artifacts() {
    local d count=0
    while IFS= read -r d; do
        [[ -z "$d" ]] && continue
        local b; b="$(dir_bytes "$d")"
        log_info "Build artifact dir: ${d} ($(human_bytes "$b"))"
        mutate "remove build artifact directory ${d} ($(human_bytes "$b"))" rm -rf -- "$d" && count=$((count + 1))
    done < <(find "${WP_PATH}/wp-content" -xdev -maxdepth 4 -type d \
                  \( -name 'node_modules' -o -name '.sass-cache' -o -name '.parcel-cache' \) 2>/dev/null)
    (( count == 0 )) && log_info "No build artifact directories found."
    return 0
}

# Prune our own old database backups so the backup dir cannot grow unbounded.
cleanup_stale_backups() {
    [[ -d "$BACKUP_DIR" ]] || return 0
    local count
    count="$(find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS}" 2>/dev/null | wc -l)"
    (( count == 0 )) && return 0
    log_info "Pruning ${count} database backup(s) older than ${BACKUP_RETENTION_DAYS}d"
    mutate "delete ${count} expired DB backups" \
        find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS}" -delete \
        && COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + count))
    return 0
}
