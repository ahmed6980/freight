#!/usr/bin/env bash
#
# Installs the nightly maintenance job to run at 4:00 AM server-local time.
# Run this ON THE WEBSERVER, as a user that can run WP-CLI against the site.
#
#   sudo ./install/install.sh [--user www-data] [--prefix /opt/wp-maintenance]
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFIX="/opt/wp-maintenance"
RUN_USER="www-data"

while (( $# > 0 )); do
    case "$1" in
        --user)   RUN_USER="$2"; shift ;;
        --prefix) PREFIX="$2"; shift ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

printf 'Installing to %s (run user: %s)\n' "$PREFIX" "$RUN_USER"

install -d "$PREFIX" "$PREFIX/bin" "$PREFIX/lib" "$PREFIX/config" "$PREFIX/docs"
install -m 0755 "$SRC/bin/nightly-maintenance.sh" "$PREFIX/bin/"
install -m 0644 "$SRC"/lib/*.sh                   "$PREFIX/lib/"
install -m 0644 "$SRC/config/maintenance.conf.example" "$PREFIX/config/"
[[ -f "$SRC/docs/RUNBOOK.md" ]] && install -m 0644 "$SRC/docs/RUNBOOK.md" "$PREFIX/docs/"

if [[ ! -f "$PREFIX/config/maintenance.conf" ]]; then
    cp "$PREFIX/config/maintenance.conf.example" "$PREFIX/config/maintenance.conf"
    printf 'Created %s/config/maintenance.conf — EDIT IT before enabling the cron job.\n' "$PREFIX"
fi

install -d -o "$RUN_USER" -g "$RUN_USER" /var/log/wp-maintenance /var/lib/wp-maintenance /var/backups/wp-maintenance
chown -R "$RUN_USER":"$RUN_USER" "$PREFIX" 2>/dev/null || true

# Log rotation so our own logs cannot become the storage problem.
cat >/etc/logrotate.d/wp-maintenance <<'ROTATE'
/var/log/wp-maintenance/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
ROTATE

# 4:00 AM daily, server-local time. Cron uses the system timezone.
cat >/etc/cron.d/wp-maintenance <<CRON
# Nightly WordPress maintenance — 4:00 AM server local time.
# Runs in dry-run mode until you remove --dry-run and add --execute.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""

0 4 * * * ${RUN_USER} ${PREFIX}/bin/nightly-maintenance.sh --dry-run >> /var/log/wp-maintenance/cron.log 2>&1
CRON
chmod 0644 /etc/cron.d/wp-maintenance

printf '\nInstalled.\n'
printf 'Current server time : %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')"
printf 'Cron entry          : /etc/cron.d/wp-maintenance (04:00 daily, %s)\n' "$(date '+%Z')"
printf '\nNEXT STEPS\n'
printf '  1. Edit %s/config/maintenance.conf (set VEHICLE_POST_TYPE and AUCTION_DATE_META).\n' "$PREFIX"
printf '  2. Audit only:   sudo -u %s %s/bin/nightly-maintenance.sh --report-only\n' "$RUN_USER" "$PREFIX"
printf '  3. Dry run:      sudo -u %s %s/bin/nightly-maintenance.sh --dry-run\n' "$RUN_USER" "$PREFIX"
printf '  4. Review /var/log/wp-maintenance/maintenance.log for several nights.\n'
printf '  5. Only then switch --dry-run to --execute in /etc/cron.d/wp-maintenance.\n'
printf '\nIf the server timezone is not your intended "local time", fix it first:\n'
printf '  timedatectl set-timezone <Area/City>\n'
