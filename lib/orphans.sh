#!/usr/bin/env bash
# Orphaned content detection.
#
# Requirement 4 is emphatic that "no inbound links" is NOT sufficient grounds
# for deletion. So this module classifies every candidate and, by default,
# only reports. Deletion requires ORPHAN_AUTODELETE=true *and* a classification
# of TRUE_ORPHAN, which excludes every system, plugin, SEO and legal page.

ORPHAN_REPORT=""
CONTENT_INDEX=""

# Slugs that are never orphans regardless of inbound links.
: "${PROTECTED_SLUGS:=privacy-policy cart checkout my-account shop terms terms-and-conditions contact about home homepage sample-page thank-you 404 search login register sitemap}"
: "${PROTECTED_POST_TYPES:=attachment nav_menu_item revision wp_block wp_template wp_template_part wp_global_styles wp_navigation customize_changeset oembed_cache user_request scheduled-action shop_order}"

# Build a single searchable index of everything that can reference a page:
# post content, menus, widgets, and options. Grepping this once per candidate
# is far cheaper than a LIKE query per post.
build_content_index() {
    local prefix; prefix="$(table_prefix)"
    CONTENT_INDEX="${LOG_DIR}/content-index-${RUN_ID}.txt"

    log_info "Building reference index (post content, menus, widgets, options)..."
    {
        wp_cli db query "SELECT post_content FROM ${prefix}posts WHERE post_status IN ('publish','future','private','draft');" --skip-column-names 2>/dev/null || true
        wp_cli db query "SELECT option_value FROM ${prefix}options WHERE option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%' OR option_name IN ('page_on_front','page_for_posts','wp_page_for_privacy_policy');" --skip-column-names 2>/dev/null || true
        wp_cli db query "SELECT meta_value FROM ${prefix}postmeta WHERE meta_key IN ('_menu_item_object_id','_menu_item_url','_elementor_data','panels_data');" --skip-column-names 2>/dev/null || true
    } >"$CONTENT_INDEX" 2>/dev/null

    local sz; sz="$(stat -c %s "$CONTENT_INDEX" 2>/dev/null || echo 0)"
    log_info "Reference index built: $(human_bytes "$sz")"
}

# Is this post the front page, posts page, privacy page, or in any nav menu?
is_front_or_menu_page() {
    local id="$1"
    local front posts_page privacy
    front="$(wp_try option get page_on_front 2>/dev/null | tr -d '[:space:]')"
    posts_page="$(wp_try option get page_for_posts 2>/dev/null | tr -d '[:space:]')"
    privacy="$(wp_try option get wp_page_for_privacy_policy 2>/dev/null | tr -d '[:space:]')"

    [[ "$id" == "$front" || "$id" == "$posts_page" || "$id" == "$privacy" ]] && return 0

    local prefix; prefix="$(table_prefix)"
    local in_menu
    in_menu="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}postmeta WHERE meta_key='_menu_item_object_id' AND meta_value='${id}';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    [[ "$in_menu" =~ ^[0-9]+$ ]] && (( in_menu > 0 )) && return 0

    return 1
}

# Classify a single candidate. Echoes one of:
#   SYSTEM | PLUGIN | PROTECTED | LINKED | SEO_LANDING | ACTIVE_VEHICLE | TRUE_ORPHAN
classify_page() {
    local id="$1" slug="$2" ptype="$3" status="$4"

    # System / required pages.
    is_front_or_menu_page "$id" && { printf 'SYSTEM'; return 0; }

    local s
    for s in $PROTECTED_SLUGS; do
        [[ "$slug" == "$s" ]] && { printf 'PROTECTED'; return 0; }
    done

    # Plugin-owned post types are the plugin's business, not ours.
    for s in $PROTECTED_POST_TYPES; do
        [[ "$ptype" == "$s" ]] && { printf 'PLUGIN'; return 0; }
    done

    # A vehicle still inside its retention window is active by definition.
    if [[ -n "$VEHICLE_POST_TYPE" && "$ptype" == "$VEHICLE_POST_TYPE" ]]; then
        printf 'ACTIVE_VEHICLE'; return 0
    fi

    # Anything carrying SEO metadata is an intentional landing page.
    local seo
    seo="$(wp_try post meta get "$id" _yoast_wpseo_focuskw 2>/dev/null | tr -d '[:space:]')"
    [[ -z "$seo" ]] && seo="$(wp_try post meta get "$id" rank_math_focus_keyword 2>/dev/null | tr -d '[:space:]')"
    [[ -n "$seo" ]] && { printf 'SEO_LANDING'; return 0; }

    # Inbound references anywhere in the index?
    if [[ -s "$CONTENT_INDEX" ]] && grep -qF -- "/${slug}" "$CONTENT_INDEX" 2>/dev/null; then
        printf 'LINKED'; return 0
    fi
    if [[ -s "$CONTENT_INDEX" ]] && grep -qE "(^|[^0-9])${id}([^0-9]|$)" "$CONTENT_INDEX" 2>/dev/null; then
        printf 'LINKED'; return 0
    fi

    printf 'TRUE_ORPHAN'
}

scan_orphans() {
    log_info "--- Orphaned content scan ---"
    build_content_index

    ORPHAN_REPORT="${LOG_DIR}/orphan-report-${RUN_ID}.tsv"
    printf 'ID\tTYPE\tSTATUS\tSLUG\tCLASSIFICATION\tTITLE\n' >"$ORPHAN_REPORT"

    local prefix; prefix="$(table_prefix)"
    local rows
    rows="$(wp_cli db query "
        SELECT ID, post_type, post_status, post_name,
               REPLACE(REPLACE(post_title, '\t', ' '), '\n', ' ')
        FROM ${prefix}posts
        WHERE post_status IN ('publish','draft','private','pending')
          AND post_type NOT IN ('revision','nav_menu_item','attachment','auto-draft')
        ORDER BY ID ASC;" --skip-column-names 2>/dev/null || true)"

    [[ -z "$rows" ]] && { log_warn "Could not enumerate posts for orphan scan."; return 0; }

    local scanned=0
    while IFS=$'\t' read -r id ptype status slug title; do
        [[ -z "$id" ]] && continue
        local class; class="$(classify_page "$id" "$slug" "$ptype" "$status")"
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$ptype" "$status" "$slug" "$class" "$title" >>"$ORPHAN_REPORT"
        [[ "$class" == "TRUE_ORPHAN" ]] && COUNT_ORPHANS_FOUND=$((COUNT_ORPHANS_FOUND + 1))
        scanned=$((scanned + 1))
    done <<<"$rows"

    log_info "Scanned ${scanned} posts. TRUE_ORPHAN candidates: ${COUNT_ORPHANS_FOUND}"
    log_info "Full classification: ${ORPHAN_REPORT}"

    awk -F'\t' 'NR>1 {c[$5]++} END {for (k in c) printf "  %s: %d\n", k, c[k]}' "$ORPHAN_REPORT" \
        | while read -r l; do log_info "$l"; done

    if (( COUNT_ORPHANS_FOUND > 0 )); then
        log_info "Orphan candidates (first 20):"
        awk -F'\t' '$5=="TRUE_ORPHAN" {print "  ID=" $1 " type=" $2 " slug=" $4 " \"" $6 "\""}' "$ORPHAN_REPORT" \
            | head -20 | while read -r l; do log_info "$l"; done
    fi

    if [[ "$ORPHAN_AUTODELETE" == "true" ]]; then
        delete_true_orphans
    else
        log_info "ORPHAN_AUTODELETE is false — reporting only. Review ${ORPHAN_REPORT} before enabling."
    fi
    return 0
}

delete_true_orphans() {
    log_warn "ORPHAN_AUTODELETE is enabled. Deleting TRUE_ORPHAN pages."
    local id ptype status slug class title
    while IFS=$'\t' read -r id ptype status slug class title; do
        [[ "$class" == "TRUE_ORPHAN" ]] || continue
        # Re-classify at deletion time; the index may be stale by now.
        local recheck; recheck="$(classify_page "$id" "$slug" "$ptype" "$status")"
        if [[ "$recheck" != "TRUE_ORPHAN" ]]; then
            log_info "ID=${id} reclassified as ${recheck} — preserving."
            continue
        fi
        if mutate "delete orphaned ${ptype} ID=${id} slug='${slug}' \"${title}\"" \
                  wp_cli post delete "$id" --force; then
            COUNT_PAGES_DELETED=$((COUNT_PAGES_DELETED + 1))
        fi
    done < <(tail -n +2 "$ORPHAN_REPORT")
    return 0
}
