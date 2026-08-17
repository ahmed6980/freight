# Nightly WordPress Maintenance (vehicle/auction site)

Automated 4:00 AM maintenance that keeps a WordPress vehicle/auction site under a
hard **40 GB** storage ceiling while refusing to break the live site to get there.

> **This suite has never been run against a real site.** It was written without
> access to the target server, so every site-specific value is configuration, and
> every destructive path is disabled until you confirm it. Do not skip the
> dry-run period described below.

## What it does, in order

```
Monitor -> Detect -> Optimize -> Remove temp data -> Remove orphaned data
  -> Remove oldest eligible auction pages -> Recalculate -> Verify -> Log
```

1. **Verify time and single-run** — flock plus a per-cycle state file, so a
   double-fired cron cannot run the job twice in one night.
2. **Storage audit** — total, available, largest dirs, largest files, database,
   uploads, caches, logs, temp files. Classifies into a tier.
3. **Backup** — verified `wp db export` before any destructive DB work.
4. **Priority 1** — expired caches, temp files, stale logs, build artifacts.
5. **Vehicle model detection** — determines the vehicle post type and auction
   date meta key, and **refuses to purge** if either is ambiguous.
6. **Orphan scan** — classifies every page; reports by default.
7. **Priority 2** — deletes vehicles whose *auction date* is older than the
   retention window, oldest first, recalculating storage every batch.
8. **Media** — removes images orphaned by those deletions, after confirming
   nothing else references them.
9. **Database** — revisions, auto-drafts, transients, orphaned meta, spam.
10. **Verification** — HTTP checks, WordPress health, no new orphans.
11. **Log and report**.

## Storage tiers

| Usage | Tier | Behaviour |
|---|---|---|
| < 35 GB | `NORMAL` | routine optimization |
| 35–38 GB | `AGGRESSIVE` | aggressive optimization |
| 38–39 GB | `REMOVE_OLD` | begin removing eligible old content |
| 39–40 GB | `HARD_CLEAN` | aggressive removal of eligible content |
| ≥ 40 GB | `EMERGENCY` | emergency cleanup, repeated until under the ceiling |

## Install

Run **on the webserver**, not from a workstation:

```bash
git clone <this-repo> && cd freight
sudo ./install/install.sh --user www-data --prefix /opt/wp-maintenance
sudo -e /opt/wp-maintenance/config/maintenance.conf   # set the vehicle fields
```

The installer writes `/etc/cron.d/wp-maintenance` for **04:00 daily, server
local time**, and it deliberately installs in `--dry-run` mode.

## Rolling it out safely

```bash
# 1. Audit only — no writes at all.
sudo -u www-data /opt/wp-maintenance/bin/nightly-maintenance.sh --report-only

# 2. Dry run — full logic, every deletion logged as "DRY-RUN would:".
sudo -u www-data /opt/wp-maintenance/bin/nightly-maintenance.sh --dry-run

# 3. Read the logs for several nights.
less /var/log/wp-maintenance/maintenance.log
column -t -s$'\t' /var/log/wp-maintenance/orphan-report-*.tsv

# 4. Only when the dry-run output is correct, edit /etc/cron.d/wp-maintenance
#    and replace --dry-run with --execute.
```

## Step 0: run the survey

Before configuring anything, run the read-only survey on the webserver. It
changes nothing (every database call is a `SELECT`) and reports the storage
breakdown, post types, and candidate auction-date meta keys:

```bash
./bin/site-survey.sh --path /var/www/html > survey.txt 2>&1
```

Its output tells you exactly what to put in the two settings below.

## The two settings that matter

Everything else has a safe default. These two do not:

```bash
VEHICLE_POST_TYPE=""    # e.g. "vehicle"
AUCTION_DATE_META=""    # e.g. "auction_date" — the AUCTION date, not post_date
```

Find them on the server:

```bash
wp post-type list --field=name
wp db query "SELECT DISTINCT meta_key FROM wp_postmeta pm \
  JOIN wp_posts p ON p.ID = pm.post_id WHERE p.post_type='YOUR_TYPE';"
```

If you leave them blank, the script tries to detect them and **disables the
vehicle purge** when the answer is ambiguous or when fewer than half of the
vehicles carry the date key. That is intentional: a wrong guess deletes live
inventory.

## Known specification conflict

The spec is internally inconsistent about the retention floor:

- **§5**: "If it is still within 7 days, preserve it."
- **§2** (revised example): delete 8 days old, then 7, then 6, "continue toward
  2 days old".

The default implementation follows **§5** — nothing younger than
`VEHICLE_RETENTION_DAYS` (7) is ever deleted. To adopt §2's reading, set
`EMERGENCY_MIN_AGE_DAYS=2`; the deeper purge then applies **only** while storage
is above the hard ceiling and **only** after every older vehicle is already gone.

## Safety properties

- Dry run by default; `--execute` is required for any deletion.
- Every deletion routes through one `mutate()` guard and is recorded in a
  per-run manifest (`deletions-<run-id>.tsv`).
- Database cleanup is skipped entirely unless a backup verified successfully.
- Vehicles are re-verified individually immediately before deletion (post type,
  age, keep-flag, front/menu membership).
- Media is deleted only after confirming no surviving post references it.
- Orphan deletion is off by default and re-classifies each page at delete time.
- The job refuses to delete active content to get under the ceiling; it reports
  and exits `CRITICAL` instead.
- Plugin tables are reported, never dropped.

## Logs

| Path | Contents |
|---|---|
| `/var/log/wp-maintenance/maintenance.log` | full run log |
| `/var/log/wp-maintenance/maintenance-history.log` | persistent per-run ledger |
| `/var/log/wp-maintenance/events.jsonl` | machine-readable events |
| `/var/log/wp-maintenance/deletions-<run>.tsv` | exactly what was deleted |
| `/var/log/wp-maintenance/orphan-report-<run>.tsv` | page classifications |
| `/var/lib/wp-maintenance/storage-history.tsv` | growth trend |

## Requirements

- WordPress with WP-CLI on `PATH`
- bash 4+, GNU coreutils (`du -b`, `find -printf`), `flock`, `curl`
- A user that can read the WordPress install and reach the database
