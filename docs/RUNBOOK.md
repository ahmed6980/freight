# Runbook

Operational reference for the nightly maintenance job.

## Reading the nightly report

```
STATUS: HEALTHY | WARNING | CRITICAL
```

| Status | Meaning | Action |
|---|---|---|
| `HEALTHY` | Under 35 GB, no errors, all health checks passed | none |
| `WARNING` | In a pressure tier, or non-fatal errors, or performance findings | review within a day |
| `CRITICAL` | At or above the 40 GB ceiling **or** a health check failed | investigate now |

`STATUS` is derived from evidence, not assumed. Any failed HTTP probe, database
check, or recent PHP fatal forces `CRITICAL`.

## Common situations

### "Vehicle purge DISABLED this run"

Detection could not identify the post type or auction meta key unambiguously.
The job did not delete any vehicles. Fix by setting both values explicitly:

```bash
wp post-type list --field=name
wp db query "SELECT DISTINCT meta_key FROM wp_postmeta pm \
  JOIN wp_posts p ON p.ID = pm.post_id WHERE p.post_type='vehicle';"
```

Then set `VEHICLE_POST_TYPE` and `AUCTION_DATE_META` in `maintenance.conf`.

### "Refusing to purge on an unreliable key"

Fewer than half the vehicles carry the configured auction-date meta key, so the
key is probably wrong. Verify with:

```bash
wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_type='vehicle';"
wp db query "SELECT COUNT(*) FROM wp_posts p \
  JOIN wp_postmeta pm ON pm.post_id=p.ID AND pm.meta_key='auction_date' \
  WHERE p.post_type='vehicle' AND pm.meta_value <> '';"
```

### "UNPARSED" lines in the log

An auction date value did not match a supported format. Supported: `YYYY-MM-DD`,
`YYYY-MM-DD HH:MM:SS`, or a 9–11 digit unix timestamp. Unparsed vehicles are
**never** deleted — they are skipped. If your dates use another format, extend
`to_epoch()` in `lib/vehicles.sh`.

### Over the ceiling with nothing left to delete

```
Over the ceiling with no further content eligible for safe deletion.
Refusing to delete active content to make room. Human review required.
```

The job did the right thing. Investigate the source of growth rather than
widening deletion rules:

```bash
tail -20 /var/lib/wp-maintenance/storage-history.tsv
grep 'Largest' -A 20 /var/log/wp-maintenance/maintenance.log | tail -40
```

Ask what changed: a runaway import, an uncapped log, a plugin writing to
uploads, a backup plugin storing archives inside the document root.

### Backup failed

Destructive database cleanup is skipped automatically. Filesystem cleanup still
runs. Fix the backup before the next night:

```bash
sudo -u www-data wp db export /tmp/test.sql --path=/var/www/html
df -h /var/backups
```

## Restoring from a backup

```bash
gunzip -c /var/backups/wp-maintenance/db-<run-id>.sql.gz > /tmp/restore.sql
wp db import /tmp/restore.sql --path=/var/www/html
```

Deleted **files** are not recoverable from the DB backup. The manifest at
`/var/log/wp-maintenance/deletions-<run-id>.tsv` records exactly what was
removed; restore those from your filesystem/offsite backup if needed.

## Verifying the schedule

```bash
cat /etc/cron.d/wp-maintenance
date                                        # confirm the server timezone
timedatectl                                 # confirm "Time zone:" line
cat /var/lib/wp-maintenance/last-successful-cycle
grep 'Nightly maintenance run' /var/log/wp-maintenance/maintenance.log | tail -7
```

Exactly one run per date should appear. The job enforces this itself: a second
invocation in the same calendar day exits immediately unless `--force` is given.

## Turning on the riskier options

Enable these one at a time, with a dry run between each:

| Setting | Effect | Prerequisite |
|---|---|---|
| `ORPHAN_AUTODELETE=true` | deletes pages classified `TRUE_ORPHAN` | several nights of orphan reports reviewed by a human |
| `MEDIA_DELETE_UNREFERENCED=true` | sweeps attachments nothing references | confirm your page builder stores references in post content or postmeta |
| `EMERGENCY_MIN_AGE_DAYS=2` | lets emergency mode delete vehicles 2–7 days past auction | an explicit business decision; contradicts §5 of the spec |

## Manual invocations

```bash
# Audit only, no writes
nightly-maintenance.sh --report-only

# Full logic, nothing deleted
nightly-maintenance.sh --dry-run

# Real run
nightly-maintenance.sh --execute

# Second run in the same day (normally blocked)
nightly-maintenance.sh --execute --force
```
