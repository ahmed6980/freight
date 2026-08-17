#!/usr/bin/env bash
# Vehicle/auction retention.
#
# Authoritative rule (requirement 5): retention is driven by the vehicle's
# AUCTION DATE, never by post creation date. A vehicle whose auction date is
# more than VEHICLE_RETENTION_DAYS old is eligible for permanent deletion,
# oldest first.
#
# This module refuses to delete anything until it knows, with evidence, which
# post type holds vehicles and which meta key holds the auction date. A wrong
# guess here deletes live inventory, so ambiguity is a hard stop, not a warning.

: "${VEHICLE_BATCH_SIZE:=50}"
# retention = always enforce the 7-day policy (requirement 5)
# storage   = only purge once storage crosses THRESHOLD_REMOVE_OLD_GIB (requirement 3)
: "${VEHICLE_PURGE_MODE:=retention}"

# SPEC CONFLICT — read before changing.
#   Requirement 5 says: "If it is still within 7 days, preserve it."
#   Requirement 2's example says to keep deleting past 7 days, down to 2 days.
# These cannot both hold. The default honours requirement 5: nothing younger
# than VEHICLE_RETENTION_DAYS is ever deleted. Lowering EMERGENCY_MIN_AGE_DAYS
# below VEHICLE_RETENTION_DAYS opts in to requirement 2's reading, and then only
# while storage is above the hard ceiling and only after every older vehicle has
# already been removed.
: "${EMERGENCY_MIN_AGE_DAYS:=${VEHICLE_RETENTION_DAYS}}"

# Candidate names used only for *detection*, never for deletion on their own.
VEHICLE_TYPE_PATTERNS='vehicle|auction|listing|inventory|lot|car|truck|equipment'
AUCTION_META_PATTERNS='auction_date|auction_dt|sale_date|auction_end|end_date|auction_day|date_of_auction|auction_datetime'

VEHICLES_ELIGIBLE=0
VEHICLE_MODEL_READY=false
DELETED_VEHICLE_IDS=""

table_prefix() { wp_try db prefix 2>/dev/null | tr -d '[:space:]'; }

# --- detection -------------------------------------------------------------
detect_vehicle_model() {
    log_info "--- Vehicle model detection ---"

    if [[ -n "$VEHICLE_POST_TYPE" && -n "$AUCTION_DATE_META" ]]; then
        log_info "Using configured model: post_type=${VEHICLE_POST_TYPE} auction_meta=${AUCTION_DATE_META}"
        verify_vehicle_model && VEHICLE_MODEL_READY=true
        return 0
    fi

    if [[ -z "$VEHICLE_POST_TYPE" ]]; then
        local types matches count
        types="$(wp_try post-type list --field=name 2>/dev/null || true)"
        [[ -z "$types" ]] && { log_error "Could not list post types; vehicle purge disabled."; return 0; }
        matches="$(printf '%s\n' "$types" | grep -Ei "$VEHICLE_TYPE_PATTERNS" | grep -Ev '^(post|page|attachment|revision|nav_menu_item)$' || true)"
        count="$(printf '%s' "$matches" | grep -c . || true)"

        if (( count == 1 )); then
            VEHICLE_POST_TYPE="$(printf '%s' "$matches" | tr -d '[:space:]')"
            log_info "Auto-detected vehicle post type: ${VEHICLE_POST_TYPE}"
        else
            log_warn "Vehicle post type is ambiguous (${count} candidates): $(printf '%s' "$matches" | tr '\n' ' ')"
            log_warn "Set VEHICLE_POST_TYPE in maintenance.conf. Vehicle purge DISABLED this run."
            return 0
        fi
    fi

    if [[ -z "$AUCTION_DATE_META" ]]; then
        local prefix sql keys count
        prefix="$(table_prefix)"
        [[ -z "$prefix" ]] && { log_error "Could not read table prefix; vehicle purge disabled."; return 0; }
        sql="SELECT DISTINCT pm.meta_key FROM ${prefix}postmeta pm
             JOIN ${prefix}posts p ON p.ID = pm.post_id
             WHERE p.post_type = '${VEHICLE_POST_TYPE}' LIMIT 500;"
        keys="$(wp_cli db query "$sql" --skip-column-names 2>/dev/null | grep -Ei "$AUCTION_META_PATTERNS" || true)"
        count="$(printf '%s' "$keys" | grep -c . || true)"

        if (( count == 1 )); then
            AUCTION_DATE_META="$(printf '%s' "$keys" | tr -d '[:space:]')"
            log_info "Auto-detected auction date meta key: ${AUCTION_DATE_META}"
        else
            log_warn "Auction date meta key is ambiguous (${count} candidates): $(printf '%s' "$keys" | tr '\n' ' ')"
            log_warn "Set AUCTION_DATE_META in maintenance.conf. Vehicle purge DISABLED this run."
            return 0
        fi
    fi

    verify_vehicle_model && VEHICLE_MODEL_READY=true
    return 0
}

# Sanity-check the model before trusting it with deletions.
verify_vehicle_model() {
    local prefix sql total dated
    prefix="$(table_prefix)"
    [[ -z "$prefix" ]] && return 1

    total="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}posts WHERE post_type='${VEHICLE_POST_TYPE}';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
    dated="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}posts p JOIN ${prefix}postmeta pm ON pm.post_id=p.ID AND pm.meta_key='${AUCTION_DATE_META}' WHERE p.post_type='${VEHICLE_POST_TYPE}' AND pm.meta_value <> '';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"

    [[ "$total" =~ ^[0-9]+$ ]] || return 1
    [[ "$dated" =~ ^[0-9]+$ ]] || return 1

    log_info "Model check: ${total} '${VEHICLE_POST_TYPE}' posts, ${dated} carry '${AUCTION_DATE_META}'."

    if (( total == 0 )); then
        log_warn "No posts of type ${VEHICLE_POST_TYPE}. Vehicle purge disabled."
        return 1
    fi
    # If most vehicles lack the date field, the key is probably wrong.
    if awk -v d="$dated" -v t="$total" 'BEGIN { exit !(d < t * 0.5) }'; then
        log_error "Only ${dated}/${total} vehicles have '${AUCTION_DATE_META}'. Refusing to purge on an unreliable key."
        return 1
    fi
    return 0
}

# --- eligibility -----------------------------------------------------------
# Emits "ID<TAB>auction_epoch<TAB>age_days<TAB>status<TAB>title", oldest first.
list_eligible_vehicles() {
    local prefix sql now
    prefix="$(table_prefix)"
    now="$(date +%s)"

    sql="SELECT p.ID, pm.meta_value, p.post_status, REPLACE(REPLACE(p.post_title, '\t', ' '), '\n', ' ')
         FROM ${prefix}posts p
         JOIN ${prefix}postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '${AUCTION_DATE_META}'
         WHERE p.post_type = '${VEHICLE_POST_TYPE}'
           AND p.post_status NOT IN ('trash','auto-draft')
           AND pm.meta_value <> ''
         ORDER BY pm.meta_value ASC;"

    # The eligibility logic lives in lib/auction-date.awk so that the test
    # suite exercises this exact code rather than a copy of it.
    wp_cli db query "$sql" --skip-column-names 2>/dev/null \
        | awk -F'\t' -v now="$now" -v ret="$VEHICLE_RETENTION_DAYS" \
              -f "${MAINT_ROOT}/lib/auction-date.awk" 2>>"${LOG_FILE:-/dev/null}"
}

# --- purge -----------------------------------------------------------------
purge_old_vehicles() {
    log_info "--- Vehicle retention purge (>${VEHICLE_RETENTION_DAYS} days past auction date) ---"

    if [[ "${VEHICLE_PURGE_BLOCKED:-false}" == "true" ]]; then
        log_error "Vehicle purge blocked: awk cannot parse auction dates reliably on this host."
        log_error "Install gawk (apt-get install gawk) and re-run. No vehicles were deleted."
        return 0
    fi

    if [[ "$VEHICLE_MODEL_READY" != "true" ]]; then
        log_warn "Vehicle model not established. Skipping purge — nothing will be deleted."
        return 0
    fi

    if [[ "$VEHICLE_PURGE_MODE" == "storage" ]] && under_safe_threshold; then
        log_info "Purge mode is 'storage' and usage is below ${THRESHOLD_REMOVE_OLD_GIB} GiB. Skipping."
        return 0
    fi

    local listfile="${LOG_DIR}/eligible-vehicles-${RUN_ID}.tsv"
    list_eligible_vehicles >"$listfile"
    VEHICLES_ELIGIBLE="$(wc -l <"$listfile" | tr -d ' ')"

    if (( VEHICLES_ELIGIBLE == 0 )); then
        log_info "No vehicles past the ${VEHICLE_RETENTION_DAYS}-day retention window."
        return 0
    fi

    log_info "${VEHICLES_ELIGIBLE} vehicle(s) eligible for deletion (oldest first)."
    log_info "Oldest 10:"
    head -10 "$listfile" | while IFS=$'\t' read -r id epoch age status title; do
        log_info "  ID=${id} age=${age}d status=${status} auction=$(date -d "@${epoch}" '+%Y-%m-%d' 2>/dev/null) \"${title}\""
    done

    local processed=0
    while IFS=$'\t' read -r id epoch age status title; do
        [[ -z "$id" ]] && continue

        # Re-verify each vehicle individually right before deleting it.
        if ! vehicle_is_deletable "$id" "$age"; then
            log_warn "Skipping ID=${id}: failed pre-delete verification."
            continue
        fi

        collect_vehicle_attachments "$id"

        if mutate "permanently delete vehicle ID=${id} (auction $(date -d "@${epoch}" '+%Y-%m-%d' 2>/dev/null), ${age}d old, \"${title}\")" \
                  wp_cli post delete "$id" --force; then
            COUNT_VEHICLES_DELETED=$((COUNT_VEHICLES_DELETED + 1))
            COUNT_PAGES_DELETED=$((COUNT_PAGES_DELETED + 1))
            DELETED_VEHICLE_IDS="${DELETED_VEHICLE_IDS} ${id}"
        fi

        processed=$((processed + 1))

        # Recalculate periodically so we stop as soon as we are safely under.
        if (( processed % VEHICLE_BATCH_SIZE == 0 )); then
            recalculate_storage "vehicle purge batch (${processed}/${VEHICLES_ELIGIBLE})"
            if [[ "$VEHICLE_PURGE_MODE" == "storage" ]] && under_safe_threshold; then
                log_info "Storage back under ${THRESHOLD_REMOVE_OLD_GIB} GiB after ${processed} deletions. Stopping purge."
                break
            fi
        fi
    done <"$listfile"

    log_info "Vehicle purge processed ${processed} of ${VEHICLES_ELIGIBLE} eligible."
    return 0
}

# Per-vehicle verification immediately before deletion.
vehicle_is_deletable() {
    local id="$1" age="$2"

    # Age must genuinely exceed retention.
    (( age > VEHICLE_RETENTION_DAYS )) || { log_warn "ID=${id} age ${age}d is within retention."; return 1; }

    # Must still be the expected post type (guards against a stale list).
    local ptype; ptype="$(wp_try post get "$id" --field=post_type 2>/dev/null | tr -d '[:space:]')"
    [[ "$ptype" == "$VEHICLE_POST_TYPE" ]] || { log_warn "ID=${id} is post_type='${ptype}', not '${VEHICLE_POST_TYPE}'."; return 1; }

    # An explicit protection flag always wins.
    local keep; keep="$(wp_try post meta get "$id" "${VEHICLE_KEEP_META:-_maintenance_keep}" 2>/dev/null | tr -d '[:space:]')"
    [[ -n "$keep" && "$keep" != "0" ]] && { log_info "ID=${id} carries a keep flag — preserving."; return 1; }

    # Never remove something the front page or menus point at.
    is_front_or_menu_page "$id" && { log_warn "ID=${id} is a front/menu page — preserving."; return 1; }

    return 0
}

# Emergency-only escalation, used when the site is still over the hard ceiling
# after every vehicle older than VEHICLE_RETENTION_DAYS has already been removed.
# Walks the retention window down one day at a time, oldest first, and stops the
# moment storage drops below the ceiling.
purge_vehicles_emergency() {
    if (( EMERGENCY_MIN_AGE_DAYS >= VEHICLE_RETENTION_DAYS )); then
        log_warn "Still over the ceiling, but EMERGENCY_MIN_AGE_DAYS=${EMERGENCY_MIN_AGE_DAYS} forbids deleting inside the ${VEHICLE_RETENTION_DAYS}-day window."
        log_warn "No further vehicle deletions are permitted. Escalate to a human — do not widen retention automatically."
        return 0
    fi

    log_warn "EMERGENCY: walking retention down from ${VEHICLE_RETENTION_DAYS}d toward ${EMERGENCY_MIN_AGE_DAYS}d."
    local original="$VEHICLE_RETENTION_DAYS"
    local day
    for (( day = VEHICLE_RETENTION_DAYS - 1; day >= EMERGENCY_MIN_AGE_DAYS; day-- )); do
        over_limit || { log_info "Back under the ceiling — stopping emergency purge."; break; }
        log_warn "EMERGENCY: now treating vehicles older than ${day} day(s) as eligible."
        VEHICLE_RETENTION_DAYS="$day"
        purge_old_vehicles
        recalculate_storage "emergency purge at >${day}d"
    done
    VEHICLE_RETENTION_DAYS="$original"
    return 0
}

# Record attachment IDs owned by a vehicle before the post disappears.
collect_vehicle_attachments() {
    local id="$1"
    local file="${LOG_DIR}/vehicle-attachments-${RUN_ID}.txt"
    {
        wp_try post list --post_type=attachment --post_parent="$id" --field=ID 2>/dev/null || true
        wp_try post meta get "$id" _thumbnail_id 2>/dev/null || true
    } | tr -d '[:space:]' | grep -E '^[0-9]+$' >>"$file" 2>/dev/null || true
    return 0
}
