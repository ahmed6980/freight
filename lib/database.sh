#!/usr/bin/env bash
# Database backup and cleanup.
#
# Hard rule (requirement 7): a verified backup exists before any destructive
# database operation. If the backup fails, destructive DB work is skipped.

: "${KEEP_REVISIONS:=3}"
: "${TRASH_MAX_AGE_DAYS:=30}"
: "${DB_OPTIMIZE:=true}"
BACKUP_FILE=""

backup_database() {
    if [[ "$DB_BACKUP_ENABLED" != "true" ]]; then
        log_warn "DB_BACKUP_ENABLED is false — skipping backup."
        BACKUP_STATUS="DISABLED"
        return 1
    fi

    mkdir -p "$BACKUP_DIR" 2>/dev/null || { log_error "Cannot create ${BACKUP_DIR}"; BACKUP_STATUS="FAILED"; return 1; }

    BACKUP_FILE="${BACKUP_DIR}/db-${RUN_ID}.sql"
    log_info "Backing up database to ${BACKUP_FILE}.gz"

    if [[ "$DRY_RUN" == "true" ]]; then
        # Still prove a backup *could* be taken, without writing a full dump.
        if wp_cli db check >/dev/null 2>&1 || wp_cli db size >/dev/null 2>&1; then
            log_info "DRY-RUN: database is reachable and exportable; backup would succeed."
            BACKUP_STATUS="DRY_RUN_OK"
            return 0
        fi
        log_error "DRY-RUN: database is not reachable — a real run could not back up."
        BACKUP_STATUS="FAILED"
        return 1
    fi

    if ! wp_cli db export "$BACKUP_FILE" --add-drop-table >/dev/null 2>&1; then
        log_error "Database export failed. Destructive DB cleanup will be skipped."
        BACKUP_STATUS="FAILED"
        return 1
    fi

    # Verify the dump is plausible before trusting it.
    local sz; sz="$(stat -c %s "$BACKUP_FILE" 2>/dev/null || echo 0)"
    if (( sz < 1024 )); then
        log_error "Backup file is only ${sz} bytes — treating as failed."
        BACKUP_STATUS="FAILED"
        return 1
    fi
    if ! tail -c 4096 "$BACKUP_FILE" | grep -qi 'dump completed\|^-- \|;'; then
        log_warn "Backup tail does not look like a complete SQL dump."
    fi

    gzip -f "$BACKUP_FILE" 2>/dev/null && BACKUP_FILE="${BACKUP_FILE}.gz"
    log_info "Backup verified: $(human_bytes "$(stat -c %s "$BACKUP_FILE" 2>/dev/null || echo 0)")"
    BACKUP_STATUS="VERIFIED"
    return 0
}

cleanup_database() {
    log_info "--- Database cleanup ---"

    if [[ "$BACKUP_STATUS" != "VERIFIED" && "$BACKUP_STATUS" != "DRY_RUN_OK" ]]; then
        log_error "No verified backup (status=${BACKUP_STATUS}). Skipping destructive DB cleanup."
        DB_CLEANED="skipped-no-backup"
        return 0
    fi

    local prefix; prefix="$(table_prefix)"
    [[ -z "$prefix" ]] && { log_error "Cannot read table prefix; skipping DB cleanup."; return 0; }

    db_cleanup_revisions "$prefix"
    db_cleanup_autodrafts_and_trash "$prefix"
    db_cleanup_transients
    db_cleanup_orphaned_meta "$prefix"
    db_cleanup_spam_comments
    report_unknown_tables "$prefix"
    [[ "$DB_OPTIMIZE" == "true" ]] && db_optimize

    DB_CLEANED="revisions,drafts,transients,orphan-meta,spam$([[ "$DB_OPTIMIZE" == "true" ]] && echo ',optimize')"
    return 0
}

# Keep the most recent KEEP_REVISIONS per post; delete the rest.
db_cleanup_revisions() {
    local prefix="$1"
    local total
    total="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}posts WHERE post_type='revision';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    [[ "$total" =~ ^[0-9]+$ ]] || return 0
    (( total == 0 )) && { log_info "No revisions to prune."; return 0; }

    log_info "Revisions in database: ${total} (keeping newest ${KEEP_REVISIONS} per post)"

    local ids
    ids="$(wp_cli db query "
        SELECT r.ID FROM ${prefix}posts r
        WHERE r.post_type='revision'
          AND (
            SELECT COUNT(*) FROM (SELECT ID, post_parent, post_date FROM ${prefix}posts WHERE post_type='revision') n
            WHERE n.post_parent = r.post_parent AND n.post_date > r.post_date
          ) >= ${KEEP_REVISIONS};" --skip-column-names 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' || true)"

    local n; n="$(printf '%s' "$ids" | grep -c . || true)"
    (( n == 0 )) && { log_info "No revisions exceed the keep threshold."; return 0; }

    log_info "Deleting ${n} excess revision(s)."
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY-RUN would: delete ${n} revisions"
        return 0
    fi
    printf '%s\n' "$ids" | xargs -r -n 100 wp_cli_post_delete_batch
    return 0
}

# Helper so xargs can call the WP-CLI wrapper with a batch of IDs.
wp_cli_post_delete_batch() {
    wp_cli post delete "$@" --force >/dev/null 2>&1 \
        && log_info "Deleted batch of $# post(s)." \
        || log_warn "Batch delete failed for: $*"
}
export -f wp_cli_post_delete_batch 2>/dev/null || true

db_cleanup_autodrafts_and_trash() {
    local prefix="$1"
    local n
    n="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}posts WHERE post_status='auto-draft';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "Auto-drafts: ${n}"
        mutate "delete ${n} auto-drafts" \
            wp_cli db query "DELETE FROM ${prefix}posts WHERE post_status='auto-draft';"
    fi

    n="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}posts WHERE post_status='trash' AND post_modified < DATE_SUB(NOW(), INTERVAL ${TRASH_MAX_AGE_DAYS} DAY);" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "Trashed posts older than ${TRASH_MAX_AGE_DAYS}d: ${n}"
        if [[ "$DRY_RUN" == "false" ]]; then
            local ids
            ids="$(wp_cli db query "SELECT ID FROM ${prefix}posts WHERE post_status='trash' AND post_modified < DATE_SUB(NOW(), INTERVAL ${TRASH_MAX_AGE_DAYS} DAY);" --skip-column-names 2>/dev/null | grep -E '^[0-9]+$' || true)"
            printf '%s\n' "$ids" | xargs -r -n 50 wp_cli_post_delete_batch
            COUNT_PAGES_DELETED=$((COUNT_PAGES_DELETED + n))
        else
            log_info "DRY-RUN would: permanently delete ${n} long-trashed posts"
        fi
    fi
    return 0
}

db_cleanup_transients() {
    log_info "Clearing expired transients."
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY-RUN would: wp transient delete --expired"
        return 0
    fi
    wp_cli transient delete --expired >/dev/null 2>&1 && log_info "Expired transients cleared." \
        || log_warn "Could not clear expired transients."
    wp_cli transient delete --expired --network >/dev/null 2>&1 || true
    return 0
}

# Meta and relationships whose owning row no longer exists.
db_cleanup_orphaned_meta() {
    local prefix="$1" n

    n="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}postmeta pm LEFT JOIN ${prefix}posts p ON p.ID=pm.post_id WHERE p.ID IS NULL;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "Orphaned postmeta rows: ${n}"
        mutate "delete ${n} orphaned postmeta rows" \
            wp_cli db query "DELETE pm FROM ${prefix}postmeta pm LEFT JOIN ${prefix}posts p ON p.ID=pm.post_id WHERE p.ID IS NULL;"
    fi

    n="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}term_relationships tr LEFT JOIN ${prefix}posts p ON p.ID=tr.object_id WHERE p.ID IS NULL;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "Orphaned term relationships: ${n}"
        mutate "delete ${n} orphaned term relationships" \
            wp_cli db query "DELETE tr FROM ${prefix}term_relationships tr LEFT JOIN ${prefix}posts p ON p.ID=tr.object_id WHERE p.ID IS NULL;"
    fi

    n="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}commentmeta cm LEFT JOIN ${prefix}comments c ON c.comment_ID=cm.comment_id WHERE c.comment_ID IS NULL;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "Orphaned commentmeta rows: ${n}"
        mutate "delete ${n} orphaned commentmeta rows" \
            wp_cli db query "DELETE cm FROM ${prefix}commentmeta cm LEFT JOIN ${prefix}comments c ON c.comment_ID=cm.comment_id WHERE c.comment_ID IS NULL;"
    fi

    # Recount terms after relationship changes so archive counts stay correct.
    [[ "$DRY_RUN" == "false" ]] && wp_cli term recount --all >/dev/null 2>&1 || true
    return 0
}

db_cleanup_spam_comments() {
    local n; n="$(wp_try comment list --status=spam --format=count 2>/dev/null | tr -d '[:space:]')"
    [[ "$n" =~ ^[0-9]+$ ]] || return 0
    (( n == 0 )) && return 0
    log_info "Spam comments: ${n}"
    if [[ "$DRY_RUN" == "false" ]]; then
        wp_cli comment delete "$(wp_cli comment list --status=spam --format=ids 2>/dev/null)" --force >/dev/null 2>&1 \
            && log_info "Deleted ${n} spam comment(s)." || log_warn "Spam comment deletion failed."
    else
        log_info "DRY-RUN would: delete ${n} spam comments"
    fi
    return 0
}

# Report — never drop — tables that look like they belong to removed plugins.
report_unknown_tables() {
    local prefix="$1"
    log_info "Tables not owned by WordPress core (review manually, nothing dropped):"
    local core='posts postmeta options users usermeta terms termmeta term_taxonomy term_relationships comments commentmeta links'
    local t base known
    while read -r t; do
        [[ -z "$t" ]] && continue
        base="${t#"$prefix"}"
        known=false
        for c in $core; do [[ "$base" == "$c" || "$base" =~ ^[0-9]+_${c}$ ]] && known=true; done
        $known || log_info "  ${t} ($(table_size_human "$t"))"
    done < <(wp_cli db tables --all-tables-with-prefix --format=csv 2>/dev/null | tr ',' '\n')
    log_info "  (Dropping plugin tables requires confirming the plugin is truly uninstalled — not automated.)"
    return 0
}

table_size_human() {
    local t="$1" name sz
    name="$(wp_try config get DB_NAME 2>/dev/null || true)"
    [[ -z "$name" ]] && { printf 'unknown'; return 0; }
    sz="$(wp_cli db query "SELECT data_length+index_length FROM information_schema.TABLES WHERE table_schema='${name}' AND table_name='${t}';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    [[ "$sz" =~ ^[0-9]+$ ]] && human_bytes "$sz" || printf 'unknown'
}

db_optimize() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY-RUN would: wp db optimize"
        return 0
    fi
    log_info "Optimizing tables to reclaim freed pages."
    wp_cli db optimize >/dev/null 2>&1 && log_info "Database optimized." || log_warn "wp db optimize failed."
    return 0
}
