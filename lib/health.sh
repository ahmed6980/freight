#!/usr/bin/env bash
# Health and performance verification.
#
# Requirement 12: the run may only be reported HEALTHY if these checks actually
# pass. HEALTH_FAILURES is the evidence the final report is built from.

HEALTH_FAILURES=0
HEALTH_NOTES=""
PERF_NOTES=""

health_note() { HEALTH_NOTES="${HEALTH_NOTES}\n  - $*"; }
perf_note()   { PERF_NOTES="${PERF_NOTES}\n  - $*"; }
health_fail() { HEALTH_FAILURES=$((HEALTH_FAILURES + 1)); log_error "HEALTH: $*"; health_note "FAIL: $*"; }

site_url() {
    [[ -n "$SITE_URL" ]] && { printf '%s' "$SITE_URL"; return 0; }
    wp_try option get home 2>/dev/null | tr -d '[:space:]'
}

# Fetch a URL; echo "status_code total_time size_bytes redirects".
probe_url() {
    curl -sS -o /dev/null -L --max-redirs 10 --max-time 30 \
         -w '%{http_code} %{time_total} %{size_download} %{num_redirects}' \
         "$1" 2>/dev/null || printf '000 0 0 0'
}

check_http_health() {
    log_info "--- HTTP health checks ---"
    local base; base="$(site_url)"
    if [[ -z "$base" ]]; then
        health_fail "Could not determine the site URL; HTTP checks impossible."
        return 0
    fi

    local urls="$base"
    [[ -n "$HEALTH_URLS" ]] && urls="${urls} ${HEALTH_URLS}"

    # Include a live vehicle listing and a live vehicle detail page.
    if [[ -n "$VEHICLE_POST_TYPE" ]]; then
        local one
        one="$(wp_try post list --post_type="$VEHICLE_POST_TYPE" --post_status=publish --posts_per_page=1 --field=url 2>/dev/null | head -1 | tr -d '[:space:]')"
        [[ -n "$one" ]] && urls="${urls} ${one}"
        local archive
        archive="$(wp_try eval "echo get_post_type_archive_link('${VEHICLE_POST_TYPE}');" 2>/dev/null | tr -d '[:space:]')"
        [[ -n "$archive" && "$archive" != "0" ]] && urls="${urls} ${archive}"
    fi

    local u
    for u in $urls; do
        [[ "$u" =~ ^https?:// ]] || continue
        read -r code time size redirects <<<"$(probe_url "$u")"
        log_info "  ${code}  ${time}s  $(human_bytes "${size:-0}")  redirects=${redirects}  ${u}"

        case "$code" in
            200|301|302) : ;;
            000) health_fail "No response from ${u} (connection failed or timed out)." ;;
            *)   health_fail "${u} returned HTTP ${code}." ;;
        esac

        awk -v t="${time:-0}" 'BEGIN { exit !(t > 3.0) }' && perf_note "Slow response ${time}s: ${u}"
        awk -v s="${size:-0}" 'BEGIN { exit !(s > 3145728) }' && perf_note "Large page $(human_bytes "$size"): ${u}"
        (( ${redirects:-0} > 2 )) && perf_note "Redirect chain of ${redirects} hops: ${u}"
    done

    # HTTPS/SSL
    if [[ "$base" == https://* ]]; then
        local host; host="${base#https://}"; host="${host%%/*}"
        if command -v openssl >/dev/null 2>&1; then
            local exp
            exp="$(echo | openssl s_client -servername "$host" -connect "${host}:443" 2>/dev/null \
                   | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
            if [[ -n "$exp" ]]; then
                local days; days=$(( ( $(date -d "$exp" +%s 2>/dev/null || echo 0) - $(date +%s) ) / 86400 ))
                log_info "  SSL certificate expires in ${days} day(s) (${exp})"
                (( days < 14 )) && health_fail "SSL certificate expires in ${days} days."
            fi
        fi
    else
        health_note "Site is not served over HTTPS."
    fi
    return 0
}

check_wordpress_health() {
    log_info "--- WordPress health ---"

    wp_cli db check >/dev/null 2>&1 \
        && log_info "  Database connectivity and table check: OK" \
        || health_fail "Database check failed."

    # Core file integrity.
    if wp_cli core verify-checksums >/dev/null 2>&1; then
        log_info "  Core checksums: OK"
    else
        health_note "Core checksum verification reported differences (review manually)."
        log_warn "  Core checksums differ from upstream."
    fi

    # PHP fatals in the debug log since yesterday.
    local dbg="${WP_PATH}/wp-content/debug.log"
    if [[ -f "$dbg" ]]; then
        local fatals
        fatals="$(grep -c 'PHP Fatal error' "$dbg" 2>/dev/null || echo 0)"
        local recent
        recent="$(tail -2000 "$dbg" 2>/dev/null | grep -c 'PHP Fatal error' || echo 0)"
        log_info "  debug.log: ${fatals} fatal error(s) total, ${recent} in the last 2000 lines"
        (( recent > 0 )) && health_fail "${recent} recent PHP fatal error(s) in debug.log."
    fi

    # Cron: overdue events mean scheduled imports are not running.
    local overdue
    overdue="$(wp_try cron event list --fields=hook,next_run_relative --format=csv 2>/dev/null | grep -c 'now\|ago' || echo 0)"
    if [[ "$overdue" =~ ^[0-9]+$ ]] && (( overdue > 5 )); then
        health_fail "${overdue} overdue WP-Cron events — scheduled tasks may not be running."
    else
        log_info "  WP-Cron: ${overdue} overdue event(s)"
    fi

    if wp_cli cron test >/dev/null 2>&1; then
        log_info "  WP-Cron spawn test: OK"
    else
        health_note "WP-Cron spawn test failed (check DISABLE_WP_CRON / server cron)."
    fi

    # Plugin/theme state.
    local inactive
    inactive="$(wp_try plugin list --status=inactive --format=count 2>/dev/null | tr -d '[:space:]')"
    [[ "$inactive" =~ ^[0-9]+$ ]] && (( inactive > 0 )) && perf_note "${inactive} inactive plugin(s) still installed."
    return 0
}

check_performance() {
    log_info "--- Performance audit ---"

    # Object cache present?
    if [[ -f "${WP_PATH}/wp-content/object-cache.php" ]]; then
        log_info "  Persistent object cache: present"
    else
        perf_note "No persistent object cache drop-in (object-cache.php)."
    fi

    # Autoloaded options are the classic silent performance killer.
    local prefix; prefix="$(table_prefix)"
    if [[ -n "$prefix" ]]; then
        local auto
        auto="$(wp_cli db query "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM ${prefix}options WHERE autoload='yes';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
        if [[ "$auto" =~ ^[0-9]+$ ]]; then
            log_info "  Autoloaded options: $(human_bytes "$auto")"
            (( auto > 1048576 )) && perf_note "Autoloaded options total $(human_bytes "$auto") (>1 MiB loads on every request)."
        fi

        # Largest tables, as a bloat signal.
        log_info "  Largest database tables:"
        local name; name="$(wp_try config get DB_NAME 2>/dev/null || true)"
        if [[ -n "$name" ]]; then
            wp_cli db query "SELECT table_name, data_length+index_length AS sz FROM information_schema.TABLES WHERE table_schema='${name}' ORDER BY sz DESC LIMIT 8;" --skip-column-names 2>/dev/null \
                | while IFS=$'\t' read -r tn sz; do log_info "    ${tn}: $(human_bytes "${sz:-0}")"; done
        fi
    fi

    # Unoptimized images: large originals with no WebP sibling.
    local ud; ud="$(uploads_dir)"
    if [[ -d "$ud" ]]; then
        local unopt
        unopt="$(find "$ud" -xdev -type f \( -iname '*.jpg' -o -iname '*.png' \) -size +1M 2>/dev/null | wc -l)"
        (( unopt > 0 )) && perf_note "${unopt} image(s) over 1 MiB — candidates for compression/WebP."
    fi

    if [[ -z "$PERF_NOTES" ]]; then
        PERF_STATUS="HEALTHY"
        log_info "  No performance problems detected."
    else
        PERF_STATUS="NEEDS ATTENTION"
        log_info "  Performance findings:"
        printf '%b\n' "$PERF_NOTES" | while read -r l; do [[ -n "$l" ]] && log_info "  $l"; done
    fi
    return 0
}

# Requirement 12: run AFTER cleanup to prove the site still works.
final_verification() {
    log_info "--- Final verification ---"
    local before="$HEALTH_FAILURES"

    check_http_health
    check_wordpress_health

    # Confirm the cleanup did not create new orphans.
    local prefix; prefix="$(table_prefix)"
    if [[ -n "$prefix" ]]; then
        local broken
        broken="$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}postmeta pm LEFT JOIN ${prefix}posts p ON p.ID=pm.post_id WHERE p.ID IS NULL;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
        if [[ "$broken" =~ ^[0-9]+$ ]] && (( broken > 0 )); then
            health_fail "Cleanup left ${broken} orphaned postmeta rows."
        else
            log_info "  No orphaned metadata introduced."
        fi
    fi

    local new=$((HEALTH_FAILURES - before))
    (( new == 0 )) && log_info "  Post-cleanup verification passed." \
                   || log_error "  Post-cleanup verification found ${new} failure(s)."
    return 0
}
