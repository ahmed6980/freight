#!/usr/bin/env bash
# Media and uploads cleanup.
#
# Rule: never delete a file that is still referenced by active content. Every
# deletion here is preceded by a reference check against the content index
# built in orphans.sh.

: "${LARGE_IMAGE_BYTES:=5242880}"        # 5 MiB — flagged, never auto-deleted
: "${MEDIA_DELETE_UNREFERENCED:=false}"  # broad orphan-media sweep is opt-in
: "${THUMBNAIL_CLEANUP:=true}"

cleanup_media() {
    log_info "--- Media and uploads audit ---"
    local ud; ud="$(uploads_dir)"
    [[ -d "$ud" ]] || { log_warn "Uploads directory not found at ${ud}"; return 0; }

    log_info "Uploads: $(human_bytes "$(dir_bytes "$ud")") at ${ud}"

    cleanup_deleted_vehicle_media
    report_large_images "$ud"
    report_duplicate_images "$ud"
    cleanup_orphaned_thumbnails "$ud"
    [[ "$MEDIA_DELETE_UNREFERENCED" == "true" ]] && cleanup_unreferenced_attachments
    return 0
}

# Attachments that belonged to vehicles deleted earlier in this run.
cleanup_deleted_vehicle_media() {
    local file="${LOG_DIR}/vehicle-attachments-${RUN_ID}.txt"
    [[ -s "$file" ]] || { log_info "No vehicle attachments recorded this run."; return 0; }

    local total; total="$(sort -u "$file" | grep -c . || true)"
    log_info "Checking ${total} attachment(s) from deleted vehicles..."

    local att
    while read -r att; do
        [[ "$att" =~ ^[0-9]+$ ]] || continue

        # Still attached to a surviving post? Leave it alone.
        local parent; parent="$(wp_try post get "$att" --field=post_parent 2>/dev/null | tr -d '[:space:]')"
        if [[ "$parent" =~ ^[0-9]+$ ]] && (( parent > 0 )); then
            local pexists; pexists="$(wp_try post get "$parent" --field=ID 2>/dev/null | tr -d '[:space:]')"
            [[ "$pexists" == "$parent" ]] && { log_info "Attachment ${att} still owned by post ${parent} — keeping."; continue; }
        fi

        if attachment_is_referenced "$att"; then
            log_info "Attachment ${att} still referenced elsewhere — keeping."
            continue
        fi

        local sz; sz="$(attachment_bytes "$att")"
        if mutate "delete orphaned attachment ${att} ($(human_bytes "$sz")) from deleted vehicle" \
                  wp_cli post delete "$att" --force; then
            COUNT_IMAGES_DELETED=$((COUNT_IMAGES_DELETED + 1))
            COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + 1))
        fi
    done < <(sort -u "$file")
    return 0
}

# Does anything still point at this attachment, by ID or by filename?
attachment_is_referenced() {
    local att="$1"
    local rel; rel="$(wp_try post meta get "$att" _wp_attached_file 2>/dev/null | tr -d '\n')"
    local base; base="$(basename "${rel:-}")"

    [[ -s "$CONTENT_INDEX" ]] || return 1

    [[ -n "$base" ]] && grep -qF -- "$base" "$CONTENT_INDEX" 2>/dev/null && return 0
    grep -qE "wp-image-${att}([^0-9]|$)" "$CONTENT_INDEX" 2>/dev/null && return 0

    local prefix; prefix="$(table_prefix)"
    local used
    used="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}postmeta WHERE meta_key='_thumbnail_id' AND meta_value='${att}';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    [[ "$used" =~ ^[0-9]+$ ]] && (( used > 0 )) && return 0

    return 1
}

attachment_bytes() {
    local att="$1"
    local ud rel
    ud="$(uploads_dir)"
    rel="$(wp_try post meta get "$att" _wp_attached_file 2>/dev/null | tr -d '\n')"
    [[ -z "$rel" ]] && { printf '0'; return 0; }
    stat -c %s "${ud}/${rel}" 2>/dev/null || printf '0'
}

report_large_images() {
    local ud="$1"
    log_info "Images larger than $(human_bytes "$LARGE_IMAGE_BYTES") (reported, not deleted):"
    local n=0
    while IFS=$'\t' read -r size path; do
        log_info "  $(human_bytes "$size")\t${path}"
        n=$((n + 1))
    done < <(find "$ud" -xdev -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.webp' -o -iname '*.gif' \) -size "+${LARGE_IMAGE_BYTES}c" -printf '%s\t%p\n' 2>/dev/null | sort -rn | head -20)
    (( n == 0 )) && log_info "  none"
    return 0
}

report_duplicate_images() {
    local ud="$1"
    log_info "Duplicate media (identical checksums, reported only):"
    local dupes; dupes="${LOG_DIR}/duplicate-media-${RUN_ID}.txt"

    # Only checksum files that share a size — avoids hashing the whole library.
    find "$ud" -xdev -type f -printf '%s\t%p\n' 2>/dev/null \
        | sort -n | awk -F'\t' '{ if ($1 == prev) { print pline; print $0 } prev=$1; pline=$0 }' \
        | cut -f2 | sort -u \
        | while read -r f; do [[ -f "$f" ]] && md5sum "$f" 2>/dev/null; done \
        | sort | awk '{ if ($1 == prev) print; prev=$1 }' >"$dupes" 2>/dev/null || true

    local n; n="$(wc -l <"$dupes" 2>/dev/null | tr -d ' ')"
    if [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )); then
        log_info "  ${n} duplicate file(s) listed in ${dupes}"
        head -10 "$dupes" | while read -r l; do log_info "  ${l}"; done
    else
        log_info "  none"
    fi
    return 0
}

# Generated thumbnails whose original no longer exists.
cleanup_orphaned_thumbnails() {
    local ud="$1"
    [[ "$THUMBNAIL_CLEANUP" == "true" ]] || return 0

    log_info "Scanning for thumbnails whose original is gone..."
    local list="${LOG_DIR}/orphan-thumbs-${RUN_ID}.txt"
    : >"$list"

    # Matches WordPress's name-WIDTHxHEIGHT.ext convention.
    while IFS= read -r thumb; do
        local dir base orig
        dir="$(dirname "$thumb")"
        base="$(basename "$thumb")"
        orig="$(printf '%s' "$base" | sed -E 's/-[0-9]+x[0-9]+(\.[A-Za-z0-9]+)$/\1/')"
        [[ "$orig" == "$base" ]] && continue
        [[ -f "${dir}/${orig}" ]] || printf '%s\n' "$thumb" >>"$list"
    done < <(find "$ud" -xdev -type f -regextype posix-extended -regex '.*-[0-9]+x[0-9]+\.(jpg|jpeg|png|gif|webp)$' 2>/dev/null)

    local n bytes
    n="$(wc -l <"$list" | tr -d ' ')"
    (( n == 0 )) && { log_info "  no orphaned thumbnails"; return 0; }
    bytes="$(while read -r f; do stat -c %s "$f" 2>/dev/null; done <"$list" | awk '{s+=$1} END {print s+0}')"

    log_info "  ${n} orphaned thumbnail(s), $(human_bytes "$bytes")"
    if mutate "delete ${n} orphaned thumbnails ($(human_bytes "$bytes"))" \
              xargs -r -d '\n' rm -f -- <"$list"; then
        COUNT_IMAGES_DELETED=$((COUNT_IMAGES_DELETED + n))
        COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + n))
    fi
    return 0
}

# Opt-in: attachments referenced by nothing at all.
cleanup_unreferenced_attachments() {
    log_warn "MEDIA_DELETE_UNREFERENCED enabled — sweeping unreferenced attachments."
    local att
    while read -r att; do
        [[ "$att" =~ ^[0-9]+$ ]] || continue
        local parent; parent="$(wp_try post get "$att" --field=post_parent 2>/dev/null | tr -d '[:space:]')"
        [[ "$parent" =~ ^[0-9]+$ ]] && (( parent > 0 )) && continue
        attachment_is_referenced "$att" && continue

        local sz; sz="$(attachment_bytes "$att")"
        if mutate "delete unreferenced attachment ${att} ($(human_bytes "$sz"))" \
                  wp_cli post delete "$att" --force; then
            COUNT_IMAGES_DELETED=$((COUNT_IMAGES_DELETED + 1))
            COUNT_FILES_DELETED=$((COUNT_FILES_DELETED + 1))
        fi
    done < <(wp_try post list --post_type=attachment --format=ids 2>/dev/null | tr ' ' '\n')
    return 0
}
