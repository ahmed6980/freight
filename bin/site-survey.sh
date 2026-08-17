#!/usr/bin/env bash
#
# READ-ONLY site survey. Makes NO changes of any kind: no deletes, no cache
# flushes, no writes to WordPress. Every database call is a SELECT.
#
# Purpose: collect everything needed to configure nightly-maintenance.sh
# correctly — the vehicle post type, the auction-date meta key, and the real
# storage breakdown.
#
# Run on the webserver:
#   ./bin/site-survey.sh --path /var/www/html > survey.txt 2>&1
#
# Then send survey.txt back. It contains no passwords: DB credentials are never
# printed, only the database NAME and aggregate sizes.
#
set -uo pipefail

WP_PATH=""
WP_CLI="wp"
WP_EXTRA=""

while (( $# > 0 )); do
    case "$1" in
        --path)  WP_PATH="$2"; shift ;;
        --wp)    WP_CLI="$2"; shift ;;
        --allow-root) WP_EXTRA="--allow-root" ;;
        -h|--help) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

# Locate WordPress if not given.
if [[ -z "$WP_PATH" ]]; then
    for c in /var/www/html /var/www /home/*/public_html /usr/share/nginx/html /srv/www; do
        [[ -f "${c}/wp-config.php" ]] && { WP_PATH="$c"; break; }
    done
fi
[[ -z "$WP_PATH" ]] && WP_PATH="$(dirname "$(find / -maxdepth 6 -name wp-config.php -not -path '*/vendor/*' 2>/dev/null | head -1)" 2>/dev/null)"

if [[ -z "$WP_PATH" || ! -f "${WP_PATH}/wp-config.php" ]]; then
    printf 'ERROR: could not find wp-config.php. Re-run with --path /path/to/wordpress\n'
    exit 1
fi

wpc() { "$WP_CLI" --path="$WP_PATH" $WP_EXTRA "$@" 2>/dev/null; }
hdr() { printf '\n============================================================\n%s\n============================================================\n' "$1"; }
have_wp=true
command -v "$WP_CLI" >/dev/null 2>&1 || have_wp=false
wpc core is-installed >/dev/null 2>&1 || have_wp=false

hdr "SURVEY METADATA"
printf 'Generated      : %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')"
printf 'Server time    : %s (timezone %s)\n' "$(date)" "$(date '+%Z %z')"
printf 'WordPress path : %s\n' "$WP_PATH"
printf 'Hostname       : %s\n' "$(hostname 2>/dev/null)"
printf 'OS             : %s\n' "$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME" || uname -s)"
printf 'awk            : %s\n' "$(awk --version 2>/dev/null | head -1 || awk -W version 2>&1 | head -1)"
printf 'WP-CLI usable  : %s\n' "$have_wp"

if ! $have_wp; then
    printf '\nWP-CLI is not available, so only filesystem data follows.\n'
    printf 'Install it: https://wp-cli.org/#installing\n'
fi

hdr "STORAGE — TOTALS"
printf 'Document root : %s\n' "$(du -sh "$WP_PATH" 2>/dev/null | cut -f1)"
printf 'Filesystem    :\n'; df -h "$WP_PATH" 2>/dev/null

hdr "STORAGE — TOP-LEVEL BREAKDOWN"
du -sh "$WP_PATH"/* 2>/dev/null | sort -rh | head -20
printf '\nwp-content:\n'
du -sh "$WP_PATH"/wp-content/* 2>/dev/null | sort -rh | head -20

hdr "STORAGE — UPLOADS BY YEAR"
UP="${WP_PATH}/wp-content/uploads"
[[ -d "$UP" ]] && du -sh "$UP"/* 2>/dev/null | sort -rh | head -25 || echo "no uploads dir at $UP"

hdr "STORAGE — 30 LARGEST FILES"
find "$WP_PATH" -xdev -type f -printf '%s\t%p\n' 2>/dev/null | sort -rn | head -30 \
    | awk -F'\t' '{ s=$1; u="B"; if(s>=1073741824){s=s/1073741824;u="GB"} else if(s>=1048576){s=s/1048576;u="MB"} else if(s>=1024){s=s/1024;u="KB"}; printf "%8.1f %-3s %s\n", s, u, $2 }'

hdr "STORAGE — 25 LARGEST DIRECTORIES"
du -b --max-depth=4 "$WP_PATH" 2>/dev/null | sort -rn | head -25 \
    | awk '{ s=$1; u="B"; if(s>=1073741824){s=s/1073741824;u="GB"} else if(s>=1048576){s=s/1048576;u="MB"} else if(s>=1024){s=s/1024;u="KB"}; $1=""; printf "%8.1f %-3s%s\n", s, u, $0 }'

hdr "CACHE / LOG / TEMP FOOTPRINT"
for d in cache et-cache uploads/cache wphb-cache litespeed w3tc-cache backup backups ai1wm-backups updraft; do
    p="${WP_PATH}/wp-content/${d}"
    [[ -d "$p" ]] && printf '%-28s %s\n' "$d" "$(du -sh "$p" 2>/dev/null | cut -f1)"
done
printf '\nLog files over 10 MB:\n'
find "$WP_PATH" -xdev -type f \( -name '*.log' -o -name 'debug.log*' \) -size +10M -printf '%s\t%p\n' 2>/dev/null \
    | sort -rn | head -15 | awk -F'\t' '{printf "%8.1f MB  %s\n", $1/1048576, $2}'
printf '\nTemp/backup-ish files over 50 MB:\n'
find "$WP_PATH" -xdev -type f \( -name '*.sql' -o -name '*.zip' -o -name '*.gz' -o -name '*.tar' -o -name '*.tmp' -o -name '*.bak' \) -size +50M -printf '%s\t%p\n' 2>/dev/null \
    | sort -rn | head -15 | awk -F'\t' '{printf "%8.1f MB  %s\n", $1/1048576, $2}'

$have_wp || exit 0

PREFIX="$(wpc db prefix | tr -d '[:space:]')"
DBNAME="$(wpc config get DB_NAME | tr -d '[:space:]')"

hdr "WORDPRESS"
printf 'WP version   : %s\n' "$(wpc core version)"
printf 'PHP version  : %s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
printf 'Site URL     : %s\n' "$(wpc option get siteurl)"
printf 'Home URL     : %s\n' "$(wpc option get home)"
printf 'Table prefix : %s\n' "$PREFIX"
printf 'Database     : %s\n' "$DBNAME"
printf 'Multisite    : %s\n' "$(wpc config get MULTISITE 2>/dev/null || echo no)"
printf 'Permalinks   : %s\n' "$(wpc option get permalink_structure)"
printf 'Timezone     : %s\n' "$(wpc option get timezone_string)"

hdr "DATABASE SIZE"
printf 'Total: '; wpc db size --human-readable 2>/dev/null | tail -1
printf '\n20 largest tables:\n'
wpc db query "SELECT table_name, ROUND((data_length+index_length)/1048576,1) AS mb, table_rows
              FROM information_schema.TABLES WHERE table_schema='${DBNAME}'
              ORDER BY (data_length+index_length) DESC LIMIT 20;" --skip-column-names 2>/dev/null \
    | awk -F'\t' '{printf "%10s MB  %12s rows  %s\n", $2, $3, $1}'

hdr "POST TYPES AND COUNTS  <-- IDENTIFIES THE VEHICLE TYPE"
wpc db query "SELECT post_type, post_status, COUNT(*)
              FROM ${PREFIX}posts GROUP BY post_type, post_status
              ORDER BY COUNT(*) DESC;" --skip-column-names 2>/dev/null \
    | awk -F'\t' '{printf "%-28s %-14s %8s\n", $1, $2, $3}'

printf '\nRegistered (non-builtin) post types:\n'
wpc post-type list --public=1 --fields=name,label,public 2>/dev/null

hdr "CANDIDATE VEHICLE POST TYPES — META KEYS AND SAMPLE DATES"
# Any custom post type that looks like inventory gets its date-ish meta keys
# dumped with sample values, so the auction-date key can be identified.
CANDIDATES="$(wpc db query "SELECT DISTINCT post_type FROM ${PREFIX}posts
    WHERE post_type NOT IN ('post','page','attachment','revision','nav_menu_item',
    'custom_css','customize_changeset','oembed_cache','user_request','wp_block',
    'wp_template','wp_template_part','wp_global_styles','wp_navigation','auto-draft');" \
    --skip-column-names 2>/dev/null)"

if [[ -z "$CANDIDATES" ]]; then
    echo "No custom post types found. Vehicles may be regular posts, or in a plugin table."
else
    for pt in $CANDIDATES; do
        cnt="$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='${pt}';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
        printf '\n--- post_type: %s  (%s posts) ---\n' "$pt" "$cnt"

        printf 'Date-like meta keys (key | rows with a value | 3 sample values):\n'
        wpc db query "SELECT pm.meta_key, COUNT(*) FROM ${PREFIX}postmeta pm
                      JOIN ${PREFIX}posts p ON p.ID=pm.post_id
                      WHERE p.post_type='${pt}' AND pm.meta_value <> ''
                        AND (pm.meta_key LIKE '%date%' OR pm.meta_key LIKE '%auction%'
                             OR pm.meta_key LIKE '%sale%' OR pm.meta_key LIKE '%sold%'
                             OR pm.meta_key LIKE '%time%' OR pm.meta_key LIKE '%expire%'
                             OR pm.meta_key LIKE '%end%' OR pm.meta_key LIKE '%status%')
                      GROUP BY pm.meta_key ORDER BY COUNT(*) DESC LIMIT 25;" \
            --skip-column-names 2>/dev/null \
        | while IFS=$'\t' read -r k n; do
            [[ -z "$k" ]] && continue
            samples="$(wpc db query "SELECT pm.meta_value FROM ${PREFIX}postmeta pm
                        JOIN ${PREFIX}posts p ON p.ID=pm.post_id
                        WHERE p.post_type='${pt}' AND pm.meta_key='${k}' AND pm.meta_value <> ''
                        LIMIT 3;" --skip-column-names 2>/dev/null | tr '\n' ' | ' | cut -c1-90)"
            printf '  %-34s %7s   %s\n' "$k" "$n" "$samples"
          done

        printf 'All meta keys on this type (top 30 by usage):\n'
        wpc db query "SELECT pm.meta_key, COUNT(*) FROM ${PREFIX}postmeta pm
                      JOIN ${PREFIX}posts p ON p.ID=pm.post_id
                      WHERE p.post_type='${pt}'
                      GROUP BY pm.meta_key ORDER BY COUNT(*) DESC LIMIT 30;" \
            --skip-column-names 2>/dev/null | awk -F'\t' '{printf "  %-40s %s\n", $1, $2}'

        printf 'Sample permalinks:\n'
        wpc post list --post_type="$pt" --posts_per_page=3 --field=url 2>/dev/null | sed 's/^/  /'
    done
fi

hdr "AGE DISTRIBUTION BY POST DATE (rough retention preview)"
for pt in $CANDIDATES; do
    printf '\n%s:\n' "$pt"
    wpc db query "SELECT
        SUM(post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_7d,
        SUM(post_date <  DATE_SUB(NOW(), INTERVAL 7 DAY) AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d7_30,
        SUM(post_date <  DATE_SUB(NOW(), INTERVAL 30 DAY) AND post_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS d30_90,
        SUM(post_date <  DATE_SUB(NOW(), INTERVAL 90 DAY)) AS older
        FROM ${PREFIX}posts WHERE post_type='${pt}';" --skip-column-names 2>/dev/null \
        | awk -F'\t' '{printf "  <7d: %s | 7-30d: %s | 30-90d: %s | >90d: %s\n", $1, $2, $3, $4}'
done
printf '\nNOTE: this uses post_date. Retention uses the AUCTION DATE meta field,\n'
printf 'which is why the meta keys above matter.\n'

hdr "MEDIA"
printf 'Attachment count: %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='attachment';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Files in uploads: %s\n' "$(find "$UP" -type f 2>/dev/null | wc -l)"
printf 'Images over 5 MB: %s\n' "$(find "$UP" -type f \( -iname '*.jpg' -o -iname '*.png' -o -iname '*.webp' \) -size +5M 2>/dev/null | wc -l)"
printf 'Generated thumbnails: %s\n' "$(find "$UP" -type f -regextype posix-extended -regex '.*-[0-9]+x[0-9]+\.(jpg|jpeg|png|gif|webp)$' 2>/dev/null | wc -l)"

hdr "DATABASE BLOAT INDICATORS"
printf 'Revisions        : %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='revision';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Auto-drafts      : %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_status='auto-draft';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Trashed posts    : %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_status='trash';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Transients       : %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}options WHERE option_name LIKE '%_transient_%';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Orphaned postmeta: %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}postmeta pm LEFT JOIN ${PREFIX}posts p ON p.ID=pm.post_id WHERE p.ID IS NULL;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Spam comments    : %s\n' "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}comments WHERE comment_approved='spam';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
printf 'Autoloaded opts  : %s KB\n' "$(wpc db query "SELECT ROUND(SUM(LENGTH(option_value))/1024) FROM ${PREFIX}options WHERE autoload='yes';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"

hdr "PLUGINS AND THEMES"
wpc plugin list --fields=name,status,version 2>/dev/null
printf '\nThemes:\n'; wpc theme list --fields=name,status 2>/dev/null

hdr "CRON / SCHEDULED TASKS"
printf 'DISABLE_WP_CRON: %s\n' "$(wpc config get DISABLE_WP_CRON 2>/dev/null || echo 'not set')"
printf '\nScheduled events (may reveal the vehicle import job):\n'
wpc cron event list --fields=hook,next_run_relative,recurrence 2>/dev/null | head -40

hdr "SYSTEM CRON ENTRIES MENTIONING WP"
crontab -l 2>/dev/null | grep -iE 'wp|php|cron' | head -20
ls -1 /etc/cron.d/ 2>/dev/null | head -20

hdr "RECENT PHP ERRORS (last 30 fatal lines)"
DBG="${WP_PATH}/wp-content/debug.log"
if [[ -f "$DBG" ]]; then
    printf 'debug.log size: %s\n\n' "$(du -h "$DBG" | cut -f1)"
    grep -i 'fatal' "$DBG" 2>/dev/null | tail -30
else
    echo "no wp-content/debug.log"
fi

hdr "SURVEY COMPLETE"
printf 'Nothing was modified. Send this output back to configure the\n'
printf 'nightly maintenance job.\n'
