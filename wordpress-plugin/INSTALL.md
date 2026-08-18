# Installing the plugin (no shell access needed)

This is the WordPress-plugin version of the nightly maintenance job, for
hosting without SSH. Same rules as the shell version: 4:00 AM site-local
schedule, 40 GB ceiling, auction-date retention, dry-run by default.

## Install

1. Zip the single file:
   `afimex-nightly-maintenance.php` → `afimex-nightly-maintenance.zip`
2. In wp-admin: **Plugins → Add New → Upload Plugin** → upload the zip → Activate.
3. Open **Tools → Nightly Maintenance**.

## Configure (the settings page does the survey for you)

The page lists every post type on the site with its post count. Click the one
that holds your vehicles — the page then lists its date-like meta keys with row
counts and sample values so you can identify the auction-date field.

1. Set **Vehicle post type** (e.g. `vehicle`).
2. Set **Auction date meta key** (e.g. `auction_date`). Accepted value formats:
   `YYYY-MM-DD`, `YYYY-MM-DD HH:MM[:SS]`, unix timestamp. Vehicles whose date
   doesn't parse are preserved, never guessed at.
3. Leave **Execute mode OFF**. Click **Run now (dry run)**.
4. Read the report: storage totals, tier, how many vehicles are eligible and
   exactly which ones it *would* delete.
5. Repeat for a few nights. Only when the numbers look right, tick
   **Enable real deletions** and save.

## What it will never do

- Delete anything while Execute mode is off.
- Delete a vehicle whose auction date is within the retention window (7 days),
  unparseable, or missing.
- Purge at all if fewer than half the vehicles carry the configured date key
  (the key is probably wrong).
- Delete the front page, anything in a nav menu, or anything carrying a
  `_maintenance_keep` meta flag.
- Delete an image still referenced as a featured image or in any live post.

## Notes

- Scheduling uses WP-Cron, which fires on page visits. On a low-traffic site,
  ask your host to add a real cron hitting `wp-cron.php` every 15 minutes, or
  the 4:00 AM run may drift to the first morning visitor.
- Runs are capped at ~10 minutes to stay inside shared-host limits; anything
  unfinished continues the next night.
- Logs: the on-page report, plus `wp-content/uploads/anm-maintenance-logs/maintenance.log`
  (blocked from public access).
