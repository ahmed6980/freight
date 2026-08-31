<?php
/**
 * Plugin Name: Afimex Nightly Maintenance
 * Description: Nightly 4:00 AM maintenance: storage audit against a 30 GB ceiling, auction-date-based vehicle retention, database cleanup, a nightly email report (storage, health, SEO), and a persistent maintenance log. DRY RUN by default — deletes nothing until explicitly enabled.
 * Version: 2.0.0
 * Author: Site Maintenance
 * License: GPL-2.0-or-later
 *
 * Install: upload this single file (zipped) via Plugins > Add New > Upload,
 * or copy it into wp-content/plugins/ and activate. No shell access required.
 *
 * Everything destructive is gated three times:
 *   1. dry_run is ON by default — runs report what they WOULD delete.
 *   2. The vehicle purge stays disabled until a post type AND an auction-date
 *      meta key are configured on the settings page (Tools > Nightly Maintenance).
 *   3. Every deletion re-verifies the target immediately before deleting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Afimex_Nightly_Maintenance {

	const VERSION     = '2.0.0';
	const OPT_VERSION = 'anm_version';
	const CRON_HOOK   = 'anm_nightly_maintenance';
	const OPT_SETTINGS = 'anm_settings';
	const OPT_REPORT   = 'anm_last_report';
	const OPT_HISTORY  = 'anm_history';
	const OPT_CYCLE    = 'anm_last_cycle';
	const LOCK_KEY     = 'anm_run_lock';

	private static $instance = null;

	/** Per-run state. */
	private $log            = array();
	private $counts         = array();
	private $dry_run        = true;
	private $bytes_reclaimed = 0;
	private $lock_token     = '';
	private $run_completed  = true;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( self::CRON_HOOK, array( $this, 'run_maintenance' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_anm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_anm_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_anm_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'dry_run'               => 1,   // 1 = report only. The safe default.
			'vehicle_post_type'     => '',  // must be set before any vehicle purge
			'auction_date_meta'     => '',  // must be set before any vehicle purge
			'retention_days'        => 7,
			'storage_limit_gb'      => 30,  // hard ceiling; tiers derive from it
			'keep_revisions'        => 3,
			'trash_max_age_days'    => 30,
			'delete_vehicle_media'  => 1,   // remove images of deleted vehicles when unreferenced
			'clean_ewww_backups'    => 1,   // prune EWWW pre-optimization image backups
			'email_enabled'         => 1,   // nightly report email after every run
			'email_to'              => 'ahmedould@gmail.com', // falls back to admin_email if emptied/invalid
			'purge_batch'           => 50,  // vehicles per batch before recheck
			'max_runtime_seconds'   => 600, // stop cleanly before PHP/host limits kill us
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPT_SETTINGS, array() ), self::defaults() );
	}

	/**
	 * Pure settings migration (no WordPress calls, unit-testable).
	 * v2.0.0: the ceiling drops to 30 GB and the two tier thresholds become
	 * derived from the limit, so their stored values are removed.
	 */
	public static function migrate_settings( array $stored ) {
		if ( isset( $stored['storage_limit_gb'] ) ) {
			$stored['storage_limit_gb'] = min( max( 5, (int) $stored['storage_limit_gb'] ), 30 );
		}
		unset( $stored['threshold_aggressive'], $stored['threshold_remove_old'] );
		return $stored;
	}

	/** One-time upgrade of stored settings, keyed on the stored version. */
	public function maybe_upgrade() {
		$stored_version = (string) get_option( self::OPT_VERSION, '' );
		if ( version_compare( $stored_version, self::VERSION, '>=' ) ) {
			return;
		}
		$raw      = (array) get_option( self::OPT_SETTINGS, array() );
		$migrated = self::migrate_settings( $raw );
		if ( $migrated !== $raw ) {
			update_option( self::OPT_SETTINGS, $migrated, false );
		}
		// Autoload yes on purpose: this option is read on every request.
		update_option( self::OPT_VERSION, self::VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling: daily at 4:00 AM in the SITE timezone                   */
	/* ------------------------------------------------------------------ */

	public function activate() {
		$this->schedule();
	}

	public function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	private function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$tz   = wp_timezone();
		$next = new DateTime( 'today 04:00', $tz );
		$now  = new DateTime( 'now', $tz );
		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Logging                                                             */
	/* ------------------------------------------------------------------ */

	private function log( $level, $msg ) {
		$line = sprintf( '[%s] [%s] %s', wp_date( 'Y-m-d H:i:s' ), $level, $msg );
		$this->log[] = $line;
		if ( 'ERROR' === $level ) {
			$this->counts['errors']++;
		}
		// Mirror to a file under uploads, best effort.
		$dir = $this->log_dir();
		if ( $dir ) {
			@file_put_contents( $dir . '/maintenance.log', $line . "\n", FILE_APPEND | LOCK_EX );
		}
	}

	private function log_dir() {
		$up  = wp_get_upload_dir();
		$dir = $up['basedir'] . '/anm-maintenance-logs';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Keep the logs out of the public eye.
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	/**
	 * Every destructive action funnels through here.
	 * In dry-run mode it logs what WOULD happen and does nothing.
	 */
	private function mutate( $description, callable $fn ) {
		if ( $this->dry_run ) {
			$this->log( 'INFO', 'DRY-RUN would: ' . $description );
			return true;
		}
		$this->log( 'INFO', 'EXEC: ' . $description );
		$ok = (bool) call_user_func( $fn );
		if ( ! $ok ) {
			$this->log( 'ERROR', 'FAILED: ' . $description );
		}
		return $ok;
	}

	/* ------------------------------------------------------------------ */
	/* Storage measurement                                                 */
	/* ------------------------------------------------------------------ */


	private function db_bytes() {
		global $wpdb;
		$size = $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = %s',
			DB_NAME
		) );
		return (int) $size;
	}

	/** Largest directories from the most recent measure_storage() walk. */
	private $breakdown = array();

	/**
	 * Total site footprint: document root + database. The same walk also
	 * aggregates bytes per directory (3 levels deep) so the report can show
	 * WHERE the space is — essential when deciding what to free.
	 */
	private function measure_storage( $deadline = 0 ) {
		$root    = untrailingslashit( ABSPATH );
		$total   = 0;
		$dirs    = array();
		$partial = false;
		$i       = 0;
		$rootlen = strlen( $root ) + 1;
		if ( is_dir( $root ) ) {
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				foreach ( $it as $file ) {
					if ( $deadline && 0 === ( ++$i % 2048 ) && time() > $deadline ) {
						$partial = true;
						break;
					}
					if ( ! $file->isFile() ) {
						continue;
					}
					try {
						$sz = $file->getSize();
					} catch ( RuntimeException $e ) {
						continue; // file vanished mid-walk — skip it, not the whole walk
					}
					$total += $sz;
					$rel    = str_replace( '\\', '/', substr( $file->getPath(), $rootlen ) );
					$parts  = ( '' === $rel ) ? array( '(root)' ) : explode( '/', $rel );
					$key    = implode( '/', array_slice( $parts, 0, 3 ) );
					$dirs[ $key ] = ( isset( $dirs[ $key ] ) ? $dirs[ $key ] : 0 ) + $sz;
				}
			} catch ( Exception $e ) {
				$this->log( 'WARN', 'Size scan issue: ' . $e->getMessage() );
			}
		}
		arsort( $dirs );
		// Keep entries of 50 MB and up; cap the list.
		$this->breakdown = array_slice( array_filter( $dirs, function ( $b ) {
			return $b >= 50 * 1024 * 1024;
		} ), 0, 15, true );
		$db = $this->db_bytes();
		return array(
			'files'   => $total,
			'db'      => $db,
			'total'   => $total + $db,
			'partial' => $partial,
		);
	}

	private static function gb( $bytes ) {
		return round( $bytes / ( 1024 * 1024 * 1024 ), 2 );
	}

	/** Tiers derive from the single ceiling: limit-5 / limit-2 / limit-1. */
	public static function storage_tier( $total_bytes, $s ) {
		$gb    = $total_bytes / ( 1024 * 1024 * 1024 );
		$limit = max( 5, (int) $s['storage_limit_gb'] );
		if ( $gb >= $limit )      return 'EMERGENCY';
		if ( $gb >= $limit - 1 )  return 'HARD_CLEAN';
		if ( $gb >= $limit - 2 )  return 'REMOVE_OLD';
		if ( $gb >= $limit - 5 )  return 'AGGRESSIVE';
		return 'NORMAL';
	}

	/* ------------------------------------------------------------------ */
	/* Run lock: token-owned, atomic acquire, CAS takeover, heartbeat      */
	/* ------------------------------------------------------------------ */

	private function acquire_lock() {
		global $wpdb;
		$this->lock_token = wp_generate_password( 12, false ) . '.' . getmypid();
		$val = $this->lock_token . '|' . time();
		$ok  = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::LOCK_KEY, $val
		) );
		if ( 1 === (int) $ok ) {
			wp_cache_delete( self::LOCK_KEY, 'options' );
			return true;
		}
		$cur   = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_KEY
		) );
		$parts = explode( '|', (string) $cur );
		$ts    = isset( $parts[1] ) ? (int) $parts[1] : 0;
		// A live run refreshes its timestamp via heartbeat_lock(), so a
		// timestamp older than an hour really is a crashed run.
		if ( $ts > time() - HOUR_IN_SECONDS ) {
			return false;
		}
		// Compare-and-swap on the exact observed value, so two takeover
		// attempts cannot both win.
		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$val, self::LOCK_KEY, (string) $cur
		) );
		if ( 1 === (int) $rows ) {
			wp_cache_delete( self::LOCK_KEY, 'options' );
			return true;
		}
		return false;
	}

	/** Refresh the lock timestamp so a long live run is never seen as stale. */
	private function heartbeat_lock() {
		global $wpdb;
		if ( '' === $this->lock_token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
			$this->lock_token . '|' . time(), self::LOCK_KEY, $wpdb->esc_like( $this->lock_token ) . '|%'
		) );
	}

	/** Release only if we still own it — never clobber another run's lock. */
	private function release_lock() {
		global $wpdb;
		if ( '' === $this->lock_token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			self::LOCK_KEY, $wpdb->esc_like( $this->lock_token ) . '|%'
		) );
		wp_cache_delete( self::LOCK_KEY, 'options' );
		$this->lock_token = '';
	}

	/**
	 * Fatal errors and hard PHP timeouts skip `finally`, but shutdown
	 * functions still run. If a run dies mid-flight, release the lock and
	 * record an ABORTED report so the next night is not blocked and the
	 * operator can see what happened.
	 */
	public function on_shutdown() {
		if ( $this->run_completed ) {
			return;
		}
		$this->release_lock();
		$report = wp_parse_args( (array) get_option( self::OPT_REPORT, array() ), array(
			'storage_before' => '?', 'storage_after' => '?', 'storage_limit' => 30,
			'tier' => 'UNKNOWN', 'reclaimed_gb' => 0, 'health_ok' => false, 'duration_s' => 0,
		) );
		$report['status'] = 'ABORTED';
		$report['time']   = wp_date( 'Y-m-d H:i:s T' );
		$report['mode']   = $this->dry_run ? 'DRY RUN' : 'EXECUTE';
		$report['counts'] = $this->counts;
		$this->log[]      = '[shutdown] Run aborted mid-flight (fatal error or PHP time limit). Lock released.';
		$report['log']    = array_slice( $this->log, -400 );
		update_option( self::OPT_REPORT, $report, false );

		// Keep the history honest: an aborted night must show up there too.
		$history   = (array) get_option( self::OPT_HISTORY, array() );
		$history[] = array(
			'time'     => $report['time'], 'status' => 'ABORTED', 'mode' => $report['mode'],
			'before'   => $report['storage_before'], 'after' => $report['storage_after'],
			'vehicles' => isset( $this->counts['vehicles_deleted'] ) ? (int) $this->counts['vehicles_deleted'] : 0,
			'errors'   => isset( $this->counts['errors'] ) ? (int) $this->counts['errors'] : 0,
		);
		update_option( self::OPT_HISTORY, array_slice( $history, -60 ), false );
	}

	/* ------------------------------------------------------------------ */
	/* The nightly run                                                     */
	/* ------------------------------------------------------------------ */

	public function run_maintenance( $force = false ) {
		$s             = self::settings();
		$this->dry_run = (bool) $s['dry_run'];
		$this->log     = array();
		$this->counts  = array(
			'files_deleted' => 0, 'pages_deleted' => 0, 'vehicles_deleted' => 0,
			'images_deleted' => 0, 'errors' => 0, 'vehicles_eligible' => 0,
		);
		$this->bytes_reclaimed = 0;
		$started  = time();
		$deadline = $started + max( 60, (int) $s['max_runtime_seconds'] );
		// Captured before this run overwrites it — used to flag ABORTED nights.
		$prev_report = (array) get_option( self::OPT_REPORT, array() );

		// --- Guard 1: no concurrent runs. ---------------------------------
		if ( ! $this->acquire_lock() ) {
			$this->log( 'ERROR', 'Another maintenance run holds the lock. Aborting.' );
			return;
		}
		$this->run_completed = false;
		static $shutdown_registered = false;
		if ( ! $shutdown_registered ) {
			$shutdown_registered = true;
			register_shutdown_function( array( $this, 'on_shutdown' ) );
		}

		try {
			// --- Guard 2: once per calendar day (site timezone). ----------
			$cycle = wp_date( 'Y-m-d' );
			if ( ! $force && get_option( self::OPT_CYCLE ) === $cycle ) {
				$this->log( 'INFO', "Cycle {$cycle} already completed. Nothing to do." );
				return;
			}

			$this->log( 'INFO', '===== Nightly maintenance run =====' );
			$this->log( 'INFO', 'Site time: ' . wp_date( 'Y-m-d H:i:s T' ) . ' | Mode: ' . ( $this->dry_run ? 'DRY RUN' : 'EXECUTE' ) );

			// --- Monitor -------------------------------------------------
			$before = $this->measure_storage( $deadline );
			$this->heartbeat_lock();
			if ( ! empty( $before['partial'] ) ) {
				$this->log( 'ERROR', 'Storage walk hit the runtime budget — totals are PARTIAL, tier may be understated.' );
			}
			$tier   = $this->storage_tier( $before['total'], $s );
			$this->log( 'INFO', sprintf(
				'Storage: %s GB total (files %s GB, database %s GB) of %d GB ceiling — tier %s',
				self::gb( $before['total'] ), self::gb( $before['files'] ), self::gb( $before['db'] ),
				$s['storage_limit_gb'], $tier
			) );
			if ( 'EMERGENCY' === $tier ) {
				$this->log( 'WARN', 'AT OR ABOVE THE CEILING — emergency cleanup mode.' );
			}
			$this->log( 'INFO', 'Largest directories (relative to the WordPress root):' );
			foreach ( $this->breakdown as $bd_dir => $bd_bytes ) {
				$this->log( 'INFO', sprintf( '  %7.2f GB  %s', $bd_bytes / ( 1024 * 1024 * 1024 ), $bd_dir ) );
			}

			// --- Priority 1: transients, revisions, drafts, trash, spam ---
			$this->cleanup_database( $s );
			$this->heartbeat_lock();
			$this->cleanup_stale_files( $s, $deadline );
			$this->heartbeat_lock();

			// --- Priority 2: vehicles past the auction-date retention -----
			$this->purge_vehicles( $s, $deadline );

			// --- Recalculate ---------------------------------------------
			$after = $this->measure_storage( $deadline + 120 ); // small grace so the final number is complete
			$this->heartbeat_lock();
			if ( ! empty( $after['partial'] ) ) {
				$this->log( 'ERROR', 'Post-cleanup storage walk was PARTIAL — reported totals are a lower bound.' );
			}
			$this->bytes_reclaimed = max( 0, $before['total'] - $after['total'] );
			$tier_after = $this->storage_tier( $after['total'], $s );
			$this->log( 'INFO', sprintf(
				'After cleanup: %s GB (reclaimed %s GB) — tier %s',
				self::gb( $after['total'] ), self::gb( $this->bytes_reclaimed ), $tier_after
			) );
			if ( 'EMERGENCY' === $tier_after ) {
				$this->log( 'ERROR', 'Still at or above the ceiling after cleanup. Oldest eligible content is exhausted or purge is not configured — human review required.' );
			}

			// --- Verify --------------------------------------------------
			$health    = $this->verify_site( $s );
			$health_ok = ! empty( $health['ok'] );

			// --- SEO audit (must never break the run) --------------------
			$seo = array();
			try {
				$seo = $this->seo_checks( $s, $health );
			} catch ( Throwable $e ) {
				$this->log( 'WARN', 'SEO checks failed: ' . $e->getMessage() );
			}

			// --- Report --------------------------------------------------
			$status = 'HEALTHY';
			if ( 'NORMAL' !== $tier_after || $this->counts['errors'] > 0 ) {
				$status = 'WARNING';
			}
			if ( 'EMERGENCY' === $tier_after || ! $health_ok ) {
				$status = 'CRITICAL';
			}

			// The homepage body is only needed in-memory for the SEO checks;
			// never persist up to 256 KB of HTML into the options table.
			$health_persist = $health;
			unset( $health_persist['homepage_body'] );

			$report = array(
				'time'            => wp_date( 'Y-m-d H:i:s T' ),
				'mode'            => $this->dry_run ? 'DRY RUN' : 'EXECUTE',
				'status'          => $status,
				'storage_before'  => self::gb( $before['total'] ),
				'storage_after'   => self::gb( $after['total'] ),
				'storage_limit'   => (int) $s['storage_limit_gb'],
				'tier'            => $tier_after,
				'reclaimed_gb'    => self::gb( $this->bytes_reclaimed ),
				'counts'          => $this->counts,
				'health_ok'       => $health_ok,
				'health'          => $health_persist,
				'seo'             => $seo,
				'partial'         => ! empty( $before['partial'] ) || ! empty( $after['partial'] ),
				'duration_s'      => time() - $started,
				'breakdown'       => array_map( function ( $b ) {
					return round( $b / ( 1024 * 1024 * 1024 ), 2 );
				}, $this->breakdown ),
				'log'             => array_slice( $this->log, -400 ),
			);

			$history   = (array) get_option( self::OPT_HISTORY, array() );
			$history[] = array(
				'time' => $report['time'], 'status' => $status, 'mode' => $report['mode'],
				'before' => $report['storage_before'], 'after' => $report['storage_after'],
				'vehicles' => $this->counts['vehicles_deleted'], 'errors' => $this->counts['errors'],
			);
			$history = array_slice( $history, -60 );
			update_option( self::OPT_HISTORY, $history, false );

			$report['notices'] = $this->important_notices( $s, $report, $history, $prev_report );
			$report['log']     = array_slice( $this->log, -400 );
			update_option( self::OPT_REPORT, $report, false );

			if ( ! $this->dry_run ) {
				update_option( self::OPT_CYCLE, $cycle, false );
			}
			$this->log( 'INFO', "STATUS: {$status}" );

			// Email goes out last: the run's results are already persisted,
			// so a mail failure can never cost a night of cleanup.
			$this->send_report_email( $s, $report );
		} finally {
			$this->run_completed = true;
			$this->release_lock();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Database cleanup                                                    */
	/* ------------------------------------------------------------------ */

	private function cleanup_database( $s ) {
		global $wpdb;
		$this->log( 'INFO', '--- Database cleanup ---' );

		// Expired transients: core-safe helper.
		$this->mutate( 'delete expired transients', function () {
			delete_expired_transients( true );
			return true;
		} );

		// Excess revisions, keeping the newest N per post.
		$keep = max( 0, (int) $s['keep_revisions'] );
		$ids  = $wpdb->get_col( $wpdb->prepare(
			"SELECT r.ID FROM {$wpdb->posts} r
			 WHERE r.post_type = 'revision' AND (
			   SELECT COUNT(*) FROM {$wpdb->posts} n
			   WHERE n.post_type = 'revision' AND n.post_parent = r.post_parent
			     AND n.post_date > r.post_date
			 ) >= %d",
			$keep
		) );
		if ( $ids ) {
			$n = count( $ids );
			$this->mutate( "delete {$n} excess revisions (keeping newest {$keep} per post)", function () use ( $ids ) {
				foreach ( $ids as $id ) {
					wp_delete_post_revision( (int) $id );
				}
				return true;
			} );
		}

		// Auto-drafts.
		$drafts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		if ( $drafts ) {
			$n = count( $drafts );
			$this->mutate( "delete {$n} auto-drafts", function () use ( $drafts ) {
				foreach ( $drafts as $id ) {
					wp_delete_post( (int) $id, true );
				}
				return true;
			} );
		}

		// Long-trashed posts.
		$age   = max( 1, (int) $s['trash_max_age_days'] );
		$trash = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'
			 AND post_modified < DATE_SUB( NOW(), INTERVAL %d DAY )",
			$age
		) );
		if ( $trash ) {
			$n = count( $trash );
			$counts = &$this->counts;
			$this->mutate( "permanently delete {$n} posts trashed more than {$age} days ago", function () use ( $trash, &$counts ) {
				foreach ( $trash as $id ) {
					if ( wp_delete_post( (int) $id, true ) ) {
						$counts['pages_deleted']++;
					}
				}
				return true;
			} );
		}

		// Spam comments.
		$spam = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
		if ( $spam ) {
			$n = count( $spam );
			$this->mutate( "delete {$n} spam comments", function () use ( $spam ) {
				foreach ( $spam as $id ) {
					wp_delete_comment( (int) $id, true );
				}
				return true;
			} );
		}

		// Orphaned postmeta (owning post gone).
		$orphan_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
		);
		if ( $orphan_meta > 0 ) {
			$this->mutate( "delete {$orphan_meta} orphaned postmeta rows", function () use ( $wpdb ) {
				$wpdb->query(
					"DELETE pm FROM {$wpdb->postmeta} pm
					 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
				);
				return true;
			} );
		}

		// Orphaned term relationships.
		$orphan_tr = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL"
		);
		if ( $orphan_tr > 0 ) {
			$this->mutate( "delete {$orphan_tr} orphaned term relationships", function () use ( $wpdb ) {
				$wpdb->query(
					"DELETE tr FROM {$wpdb->term_relationships} tr
					 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL"
				);
				return true;
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Filesystem cleanup (conservative: only regenerable/temp locations)  */
	/* ------------------------------------------------------------------ */

	private function cleanup_stale_files( $s, $deadline = 0 ) {
		$this->log( 'INFO', '--- Cache / temp file cleanup ---' );
		$content = untrailingslashit( WP_CONTENT_DIR );
		$targets = array( $content . '/cache', $content . '/et-cache', $content . '/litespeed', $content . '/wphb-cache' );
		if ( ! empty( $s['clean_ewww_backups'] ) ) {
			// EWWW Image Optimizer's pre-optimization originals. They exist only
			// so an optimization can be undone — the images the site serves are
			// in uploads/, so pruning these does not affect the live site. EWWW
			// itself expires them after 30 days; we prune at the same 7-day age
			// as the cache dirs.
			$targets[] = $content . '/ewww/image-backup';
		}
		$cutoff  = time() - 7 * DAY_IN_SECONDS;

		foreach ( $targets as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$stale = array();
			$bytes = 0;
			$i     = 0;
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				foreach ( $it as $f ) {
					if ( $deadline && 0 === ( ++$i % 2048 ) && time() > $deadline ) {
						$this->log( 'WARN', 'Runtime budget reached while scanning ' . basename( $dir ) . ' — remaining files next night.' );
						break;
					}
					try {
						if ( $f->isFile() && $f->getMTime() < $cutoff ) {
							$stale[] = $f->getPathname();
							$bytes  += $f->getSize();
						}
					} catch ( RuntimeException $e ) {
						continue;
					}
				}
			} catch ( Exception $e ) {
				continue;
			}
			if ( ! $stale ) {
				continue;
			}
			$n = count( $stale );
			$counts = &$this->counts;
			$this->mutate(
				sprintf( 'delete %d stale cache files under %s (%s GB)', $n, basename( $dir ), self::gb( $bytes ) ),
				function () use ( $stale, &$counts, $deadline ) {
					$i = 0;
					foreach ( $stale as $path ) {
						if ( $deadline && 0 === ( ++$i % 2048 ) && time() > $deadline + 300 ) {
							// Grace past the soft budget: finishing deletes frees
							// space, but never run unbounded.
							break;
						}
						if ( @unlink( $path ) ) {
							$counts['files_deleted']++;
						}
					}
					return true;
				}
			);
		}

		// Oversized debug.log: truncate, never delete.
		$dbg = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $dbg ) && filesize( $dbg ) > 50 * 1024 * 1024 ) {
			$sz = self::gb( filesize( $dbg ) );
			$this->mutate( "truncate oversized debug.log ({$sz} GB)", function () use ( $dbg ) {
				$fh = @fopen( $dbg, 'w' );
				if ( $fh ) {
					fclose( $fh );
					return true;
				}
				return false;
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Vehicle purge: auction date is the authoritative clock              */
	/* ------------------------------------------------------------------ */

	/** Parse the stored auction-date value. Returns a timestamp or false. */
	public static function parse_auction_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0' === $value ) {
			return false;
		}
		// Unix timestamp — but only within a plausible auction window
		// (now +/- 10 years). Any other 9-11 digit number is junk (an ID, a
		// price, a phone fragment) and junk preserves the vehicle, never
		// deletes it.
		if ( ctype_digit( $value ) && strlen( $value ) >= 9 && strlen( $value ) <= 11 ) {
			$ts  = (int) $value;
			$now = time();
			if ( $ts < $now - 10 * YEAR_IN_SECONDS || $ts > $now + 10 * YEAR_IN_SECONDS ) {
				return false;
			}
			return $ts;
		}
		// ISO-ish: YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. Nothing else is guessed.
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $value, $m ) ) {
			// checkdate() rejects MySQL zero-dates ('0000-00-00') and other
			// impossible values, which would otherwise parse to a huge negative
			// timestamp and make a vehicle look ancient — i.e. instantly
			// deletable. Reject; an invalid date always preserves the vehicle.
			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[1] < 1970 ) {
				return false;
			}
			try {
				$dt = new DateTime( $value, wp_timezone() );
				$ts = $dt->getTimestamp();
				return $ts > 0 ? $ts : false;
			} catch ( Exception $e ) {
				return false;
			}
		}
		return false;
	}

	private function purge_vehicles( $s, $deadline ) {
		global $wpdb;
		$this->log( 'INFO', '--- Vehicle retention purge ---' );

		$ptype = sanitize_key( $s['vehicle_post_type'] );
		$meta  = sanitize_text_field( $s['auction_date_meta'] );
		if ( '' === $ptype || '' === $meta ) {
			$this->log( 'WARN', 'Vehicle post type / auction-date meta key not configured. Purge disabled — set them under Tools > Nightly Maintenance.' );
			return;
		}
		if ( ! post_type_exists( $ptype ) ) {
			$this->log( 'ERROR', "Configured post type '{$ptype}' does not exist. Purge disabled." );
			return;
		}

		// Sanity check: most vehicles must carry the date key, or it is wrong.
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$ptype
		) );
		$dated = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> ''
			 WHERE p.post_type = %s AND p.post_status = 'publish'",
			$meta, $ptype
		) );
		$this->log( 'INFO', "Model check: {$total} '{$ptype}' posts, {$dated} carry '{$meta}'." );
		if ( 0 === $total ) {
			return;
		}
		if ( $dated < $total / 2 ) {
			$this->log( 'ERROR', "Only {$dated}/{$total} vehicles have '{$meta}' — the key looks wrong. Refusing to purge." );
			return;
		}

		// All candidates with their raw dates; parsed and filtered in PHP so
		// unparseable dates are PRESERVED, never guessed.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value <> ''",
			$meta, $ptype
		) );

		$retention = max( 1, (int) $s['retention_days'] );
		$now       = time();
		$eligible  = array();
		$unparsed  = 0;
		$future    = 0;
		foreach ( $rows as $row ) {
			$ts = self::parse_auction_date( $row->meta_value );
			if ( false === $ts ) {
				$unparsed++;
				continue;
			}
			if ( $ts > $now ) {
				$future++;
			}
			$age_days = (int) floor( ( $now - $ts ) / DAY_IN_SECONDS );
			if ( $age_days > $retention ) {
				$eligible[ (int) $row->ID ] = $ts;
			}
		}
		asort( $eligible ); // oldest auction date first
		$this->counts['vehicles_eligible'] = count( $eligible );
		if ( $unparsed > 0 ) {
			$this->log( 'WARN', "{$unparsed} vehicle(s) have unparseable '{$meta}' values — preserved, not guessed." );
		}
		$this->log( 'INFO', count( $eligible ) . " vehicle(s) past the {$retention}-day retention window ({$future} have future auction dates)." );
		if ( ! $eligible ) {
			return;
		}

		// Plausibility refusal (guards against a wrong-but-parseable key):
		// a genuine auction-date field on a live auction site always carries
		// some upcoming dates. A key where NOTHING is in the future and nearly
		// every vehicle is "expired" is almost certainly a created/listing
		// date — purging on it would drain the entire inventory.
		if ( 0 === $future && count( $eligible ) > 0.9 * $total ) {
			$this->log( 'ERROR', "Refusing to purge: no '{$meta}' values lie in the future and " . count( $eligible ) . "/{$total} vehicles would be eligible. '{$meta}' looks like a listing/created date, not an auction date. Nothing was deleted." );
			return;
		}

		$menu_ids = $this->menu_object_ids();
		$front    = (int) get_option( 'page_on_front' );
		$batch    = max( 1, (int) $s['purge_batch'] );

		$done = 0;
		foreach ( $eligible as $id => $ts ) {
			if ( time() > $deadline ) {
				$this->log( 'WARN', "Runtime budget reached after {$done} deletions; remaining vehicles will be handled next night." );
				break;
			}
			// Re-verify immediately before deletion.
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== $ptype ) {
				continue;
			}
			if ( 'publish' !== $post->post_status ) {
				continue; // status changed since the list was built — preserve
			}
			if ( $id === $front || isset( $menu_ids[ $id ] ) ) {
				$this->log( 'WARN', "ID={$id} is the front page or in a menu — preserved." );
				continue;
			}
			if ( get_post_meta( $id, '_maintenance_keep', true ) ) {
				$this->log( 'INFO', "ID={$id} carries a keep flag — preserved." );
				continue;
			}
			$fresh = self::parse_auction_date( get_post_meta( $id, $meta, true ) );
			if ( false === $fresh || ( $now - $fresh ) / DAY_IN_SECONDS <= $retention ) {
				continue; // date changed since the list was built
			}

			$attachments = $this->vehicle_attachment_ids( $id );
			$when        = wp_date( 'Y-m-d', $ts );
			$title       = $post->post_title;
			$counts      = &$this->counts;

			$ok = $this->mutate(
				"permanently delete vehicle ID={$id} (auction {$when}) \"{$title}\"",
				function () use ( $id ) {
					return (bool) wp_delete_post( $id, true );
				}
			);
			if ( $ok && ! $this->dry_run ) {
				$counts['vehicles_deleted']++;
				$counts['pages_deleted']++;
				if ( ! empty( $s['delete_vehicle_media'] ) ) {
					$this->delete_unreferenced_attachments( $attachments, $deadline );
				}
			} elseif ( $ok && $this->dry_run ) {
				$counts['vehicles_deleted']++; // counted as "would delete"
				if ( ! empty( $s['delete_vehicle_media'] ) && $attachments ) {
					$this->log( 'INFO', 'DRY-RUN: ' . count( $attachments ) . " attached image(s) of ID={$id} would be checked for deletion (referenced images are always preserved)." );
				}
			}
			$done++;
			if ( 0 === $done % 10 ) {
				$this->heartbeat_lock();
			}
			if ( $done >= $batch ) {
				$this->log( 'WARN', "Per-run cap of {$batch} vehicles reached; the rest continue next night." );
				break;
			}
		}
	}

	/** IDs of every nav-menu-item target, as a lookup set. */
	private function menu_object_ids() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object_id'"
		);
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	private function vehicle_attachment_ids( $post_id ) {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_parent'    => $post_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		return array_unique( array_map( 'intval', $ids ) );
	}

	/**
	 * Is this attachment referenced ANYWHERE besides its own rows?
	 * Deliberately over-matches: a false positive merely preserves a file,
	 * a false negative destroys live imagery. Covers ID-based references
	 * (featured images, gallery meta "512,513", serialized i:512;, JSON
	 * "id":512 / "512", classic wp-image-512) and filename-stem references
	 * (so every generated size like -300x200 counts), in both post content
	 * and postmeta (Elementor/builder data lives in postmeta).
	 */
	private function attachment_is_referenced( $att ) {
		global $wpdb;
		$att  = (int) $att;
		$file = get_post_meta( $att, '_wp_attached_file', true );
		$base = $file ? wp_basename( $file ) : '';
		$stem = $base ? preg_replace( '/\.[^.]+$/', '', $base ) : '';

		$meta_conds  = array(
			'meta_value = %s',        // plain ID (covers _thumbnail_id and ACF image fields)
			'meta_value LIKE %s',     // JSON string "512"
			'meta_value LIKE %s',     // PHP-serialized int i:512;
			'meta_value LIKE %s',     // CSV first: 512,...
			'meta_value LIKE %s',     // CSV last: ...,512
			'meta_value LIKE %s',     // CSV middle: ...,512,...
			'meta_value LIKE %s',     // JSON "id":512
		);
		$meta_params = array(
			(string) $att,
			'%"' . $att . '"%',
			'%i:' . $att . ';%',
			$att . ',%',
			'%,' . $att,
			'%,' . $att . ',%',
			'%"id":' . $att . '%',
		);
		if ( '' !== $stem ) {
			$meta_conds[]  = 'meta_value LIKE %s'; // builder URLs stored in meta
			$meta_params[] = '%' . $wpdb->esc_like( $stem ) . '%';
		}
		$hit = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND ( " . implode( ' OR ', $meta_conds ) . ' )',
			array_merge( array( $att ), $meta_params )
		) );
		if ( $hit > 0 ) {
			return true;
		}

		$content_conds  = array(
			'post_content LIKE %s', // classic editor: wp-image-512
			'post_content LIKE %s', // Gutenberg block JSON: "id":512
			'post_content LIKE %s', // [gallery ids="...,512,..."]
		);
		$content_params = array(
			'%wp-image-' . $att . '%',
			'%"id":' . $att . '%',
			'%,' . $att . ',%',
		);
		if ( '' !== $stem ) {
			$content_conds[]  = 'post_content LIKE %s';
			$content_params[] = '%' . $wpdb->esc_like( $stem ) . '%';
		}
		$hit = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE ID <> %d AND post_status IN ('publish','future','private','draft','pending')
			   AND ( " . implode( ' OR ', $content_conds ) . ' )',
			array_merge( array( $att ), $content_params )
		) );
		return $hit > 0;
	}

	/** Delete attachments only when nothing else still references them. */
	private function delete_unreferenced_attachments( array $ids, $deadline = 0 ) {
		foreach ( $ids as $att ) {
			if ( $deadline && time() > $deadline + 300 ) {
				$this->log( 'WARN', 'Runtime budget reached during media cleanup — remaining attachments next night.' );
				break;
			}
			$post = get_post( $att );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			if ( $this->attachment_is_referenced( $att ) ) {
				$this->log( 'INFO', "Attachment {$att} is still referenced elsewhere — preserved." );
				continue;
			}
			if ( wp_delete_attachment( $att, true ) ) {
				$this->counts['images_deleted']++;
				$this->counts['files_deleted']++;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Verification                                                        */
	/* ------------------------------------------------------------------ */

	private function verify_site( $s ) {
		$this->log( 'INFO', '--- Verification ---' );
		$ok            = true;
		$db_ok         = true;
		$homepage_code = 0;
		$homepage_body = '';
		$archive_code  = null;
		$cron_ok       = true;

		// Database reachable?
		global $wpdb;
		if ( null === $wpdb->get_var( 'SELECT 1' ) ) {
			$this->log( 'ERROR', 'Database check failed.' );
			$ok    = false;
			$db_ok = false;
		}

		// Homepage responds? (loopback request)
		$resp = wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'redirection' => 5, 'sslverify' => false ) );
		if ( is_wp_error( $resp ) ) {
			$this->log( 'ERROR', 'Homepage check failed: ' . $resp->get_error_message() );
			$ok = false;
		} else {
			$homepage_code = (int) wp_remote_retrieve_response_code( $resp );
			// Kept (capped) for the SEO checks so they need no second request.
			$homepage_body = substr( (string) wp_remote_retrieve_body( $resp ), 0, 262144 );
			$this->log( 'INFO', "Homepage: HTTP {$homepage_code}" );
			if ( $homepage_code >= 400 ) {
				$ok = false;
				$this->log( 'ERROR', "Homepage returned HTTP {$homepage_code}." );
			}
		}

		// Vehicle archive still up (when configured)?
		$ptype = sanitize_key( $s['vehicle_post_type'] );
		if ( $ptype && post_type_exists( $ptype ) ) {
			$archive = get_post_type_archive_link( $ptype );
			if ( $archive ) {
				$resp         = wp_remote_get( $archive, array( 'timeout' => 20, 'sslverify' => false ) );
				$archive_code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
				$this->log( 'INFO', "Vehicle archive: HTTP {$archive_code} ({$archive})" );
				if ( $archive_code >= 400 || 0 === $archive_code ) {
					$ok = false;
					$this->log( 'ERROR', 'Vehicle archive check failed.' );
				}
			}
		}

		// Our own cron still scheduled?
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
			$cron_ok = false;
			$this->log( 'WARN', 'Cron event was missing — rescheduled.' );
		}

		return array(
			'ok'            => $ok,
			'db_ok'         => $db_ok,
			'homepage_code' => $homepage_code,
			'homepage_body' => $homepage_body,
			'archive_code'  => $archive_code,
			'cron_ok'       => $cron_ok,
		);
	}

	/* ------------------------------------------------------------------ */
	/* SEO checks                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse the SEO-relevant bits out of homepage HTML. Pure (no WordPress
	 * calls), regex-based on purpose: tolerant of broken markup, no libxml
	 * dependency, and directly unit-testable.
	 */
	public static function seo_parse_homepage( $html ) {
		$out  = array(
			'title' => null, 'title_len' => 0,
			'meta_description' => null, 'desc_len' => 0,
			'canonical' => null, 'noindex' => false,
		);
		$html = (string) $html;
		if ( '' === $html ) {
			return $out;
		}
		if ( preg_match( '~<title[^>]*>(.*?)</title>~is', $html, $m ) ) {
			$out['title']     = trim( html_entity_decode( $m[1] ) );
			$out['title_len'] = strlen( $out['title'] );
		}
		if ( preg_match_all( '~<meta\b[^>]*>~i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$name    = '';
				$content = '';
				if ( preg_match( '~name\s*=\s*["\']([^"\']+)["\']~i', $tag, $nm ) ) {
					$name = strtolower( $nm[1] );
				}
				if ( preg_match( '~content\s*=\s*["\']([^"\']*)["\']~i', $tag, $cm ) ) {
					$content = $cm[1];
				}
				if ( 'description' === $name && null === $out['meta_description'] ) {
					$out['meta_description'] = trim( html_entity_decode( $content ) );
					$out['desc_len']         = strlen( $out['meta_description'] );
				}
				if ( 'robots' === $name && false !== stripos( $content, 'noindex' ) ) {
					$out['noindex'] = true;
				}
			}
		}
		if ( preg_match( '~<link\b[^>]*rel\s*=\s*["\']canonical["\'][^>]*>~i', $html, $lm )
			&& preg_match( '~href\s*=\s*["\']([^"\']+)["\']~i', $lm[0], $hm ) ) {
			$out['canonical'] = $hm[1];
		}
		return $out;
	}

	/**
	 * Nightly SEO audit. Everything here is in-WordPress: options, the
	 * homepage HTML the health check already fetched, and two bounded
	 * loopback probes. Rankings, backlinks, PageSpeed, and crawl coverage
	 * are deliberately excluded — they require external APIs, and nothing
	 * in this plugin may depend on a third-party service.
	 * Returns rows of array( 'label', 'ok' (bool|null=informational), 'detail' ).
	 */
	private function seo_checks( $s, array $health ) {
		$rows = array();

		$blog_public = (string) get_option( 'blog_public' );
		$rows[] = array(
			'label'  => 'Search engine visibility',
			'ok'     => '0' !== $blog_public,
			'detail' => '0' === $blog_public ? '"Discourage search engines" is ENABLED in Settings > Reading' : 'indexing allowed',
		);

		$permalinks = (string) get_option( 'permalink_structure' );
		$rows[] = array(
			'label'  => 'Permalink structure',
			'ok'     => '' !== $permalinks,
			'detail' => '' === $permalinks ? 'plain (?p=123) permalinks — bad for SEO' : $permalinks,
		);

		$yoast = defined( 'WPSEO_VERSION' );
		$rank  = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
		$rows[] = array(
			'label'  => 'SEO plugin',
			'ok'     => ( $yoast || $rank ) ? true : null,
			'detail' => $yoast ? 'Yoast SEO active' : ( $rank ? 'Rank Math active' : 'none detected' ),
		);

		$body = isset( $health['homepage_body'] ) ? $health['homepage_body'] : '';
		if ( '' !== $body ) {
			$hp = self::seo_parse_homepage( $body );
			$rows[] = array(
				'label'  => 'Homepage title',
				'ok'     => null !== $hp['title'] && $hp['title_len'] >= 10 && $hp['title_len'] <= 70,
				'detail' => null === $hp['title'] ? 'missing <title>' : $hp['title_len'] . ' chars (10-70 recommended): ' . $hp['title'],
			);
			$rows[] = array(
				'label'  => 'Homepage meta description',
				'ok'     => null !== $hp['meta_description'] && $hp['desc_len'] >= 50 && $hp['desc_len'] <= 160,
				'detail' => null === $hp['meta_description'] ? 'missing' : $hp['desc_len'] . ' chars (50-160 recommended)',
			);
			$rows[] = array(
				'label'  => 'Canonical link',
				'ok'     => null !== $hp['canonical'],
				'detail' => null === $hp['canonical'] ? 'no rel=canonical on homepage' : $hp['canonical'],
			);
			$rows[] = array(
				'label'  => 'Homepage indexable',
				'ok'     => ! $hp['noindex'],
				'detail' => $hp['noindex'] ? 'homepage carries a NOINDEX robots meta tag!' : 'no noindex directive',
			);
		} else {
			$rows[] = array( 'label' => 'Homepage HTML checks', 'ok' => null, 'detail' => 'homepage was not fetched this run' );
		}

		$resp = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10, 'sslverify' => false ) );
		$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		$rb   = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
		$blocked = ( 200 === $code && preg_match( '~^Disallow:\s*/\s*$~mi', $rb ) );
		$rows[] = array(
			'label'  => 'robots.txt',
			'ok'     => 200 === $code && ! $blocked,
			'detail' => 200 !== $code ? 'HTTP ' . $code : ( $blocked ? 'contains a blanket "Disallow: /" (heuristic — review it)' : 'reachable' ),
		);

		$sitemap = '';
		foreach ( array( 'sitemap_index.xml', 'sitemap.xml', 'wp-sitemap.xml' ) as $cand ) {
			$r = wp_remote_head( home_url( '/' . $cand ), array( 'timeout' => 10, 'sslverify' => false ) );
			$c = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( $c > 0 && $c < 400 ) {
				$sitemap = $cand;
				break;
			}
		}
		$rows[] = array(
			'label'  => 'XML sitemap',
			'ok'     => '' !== $sitemap,
			'detail' => '' !== $sitemap ? '/' . $sitemap : 'none found at the usual paths',
		);

		if ( $yoast || $rank ) {
			global $wpdb;
			$key     = $yoast ? '_yoast_wpseo_metadesc' : 'rank_math_description';
			$missing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> ''
				 WHERE p.post_status = 'publish' AND p.post_type IN ('post','page') AND pm.post_id IS NULL",
				$key
			) );
			// Posts and pages only: vehicles are transient inventory and would
			// swamp the count with noise.
			$rows[] = array(
				'label'  => 'Meta descriptions (posts/pages)',
				'ok'     => null,
				'detail' => $missing . ' published post(s)/page(s) missing a meta description',
			);
		}

		foreach ( $rows as $r ) {
			$mark = ( null === $r['ok'] ) ? 'info' : ( $r['ok'] ? 'OK' : 'FAIL' );
			$this->log( 'INFO', "SEO [{$mark}] {$r['label']}: {$r['detail']}" );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Growth projection + important notices                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Average day-over-day storage delta from run history (one entry per
	 * night). Pure static, unit-testable. days_to_ceiling is null when there
	 * are fewer than 3 data points or the site is not growing.
	 */
	public static function growth_projection( array $history, $limit_gb ) {
		$vals = array();
		foreach ( $history as $h ) {
			if ( isset( $h['after'] ) && is_numeric( $h['after'] ) ) {
				$vals[] = (float) $h['after'];
			}
		}
		$vals = array_slice( $vals, -8 );
		$n    = count( $vals );
		$out  = array(
			'rate_gb_day'     => 0.0,
			'current_gb'      => $n ? $vals[ $n - 1 ] : 0.0,
			'days_to_ceiling' => null,
		);
		if ( $n < 3 ) {
			return $out;
		}
		$deltas = array();
		for ( $i = 1; $i < $n; $i++ ) {
			$deltas[] = $vals[ $i ] - $vals[ $i - 1 ];
		}
		$rate               = array_sum( $deltas ) / count( $deltas );
		$out['rate_gb_day'] = round( $rate, 3 );
		if ( $rate > 0.001 ) {
			$out['days_to_ceiling'] = round( ( (float) $limit_gb - $out['current_gb'] ) / $rate, 1 );
		}
		return $out;
	}

	/** The "important information" section: anomalies worth waking up for. */
	private function important_notices( $s, array $report, array $history, array $prev_report ) {
		$notices = array();

		if ( 'EMERGENCY' === $report['tier'] ) {
			$notices[] = array( 'severity' => 'critical', 'text' => 'Storage is AT OR ABOVE the ' . $report['storage_limit'] . ' GB ceiling after cleanup. Eligible content is exhausted or the purge is misconfigured — review now.' );
		}

		$proj = self::growth_projection( $history, $report['storage_limit'] );
		if ( null !== $proj['days_to_ceiling'] && $proj['days_to_ceiling'] < 7 ) {
			$notices[] = array( 'severity' => 'critical', 'text' => sprintf( 'Growth anomaly: averaging +%.2f GB/day — the %d GB ceiling is ~%.1f days away at this rate. Identify the growth source.', $proj['rate_gb_day'], $report['storage_limit'], $proj['days_to_ceiling'] ) );
		}
		$hn = count( $history );
		if ( $hn >= 2 && isset( $history[ $hn - 1 ]['after'], $history[ $hn - 2 ]['after'] )
			&& is_numeric( $history[ $hn - 1 ]['after'] ) && is_numeric( $history[ $hn - 2 ]['after'] )
			&& ( (float) $history[ $hn - 1 ]['after'] - (float) $history[ $hn - 2 ]['after'] ) > 2 ) {
			$notices[] = array( 'severity' => 'warning', 'text' => sprintf( 'Single-night jump of +%.2f GB since the previous run.', (float) $history[ $hn - 1 ]['after'] - (float) $history[ $hn - 2 ]['after'] ) );
		}

		if ( '' === $s['vehicle_post_type'] || '' === $s['auction_date_meta'] ) {
			$notices[] = array( 'severity' => 'warning', 'text' => 'Vehicle purge is NOT configured (post type / auction-date key missing) — the largest storage lever is disabled. Configure it under Tools > Nightly Maintenance.' );
		}

		if ( 'DRY RUN' === $report['mode'] ) {
			$notices[] = array( 'severity' => 'info', 'text' => 'This was a DRY RUN — nothing was actually deleted.' );
		}

		if ( isset( $prev_report['status'] ) && 'ABORTED' === $prev_report['status'] ) {
			$notices[] = array( 'severity' => 'warning', 'text' => 'The PREVIOUS run aborted mid-flight (fatal error or PHP time limit). Check the log.' );
		}

		foreach ( (array) $report['breakdown'] as $dir => $gb_val ) {
			foreach ( array( 'wp-content/ewww', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backup' ) as $prefix ) {
				if ( 0 === strpos( $dir, $prefix ) && (float) $gb_val >= 0.5 ) {
					$notices[] = array( 'severity' => 'warning', 'text' => sprintf( 'Backup directory regrowing: %s is at %.2f GB. Disable the plugin feature that refills it.', $dir, (float) $gb_val ) );
				}
			}
		}

		if ( ! empty( $report['partial'] ) ) {
			$notices[] = array( 'severity' => 'warning', 'text' => 'A storage walk hit the runtime budget — reported totals are a LOWER BOUND.' );
		}

		$order = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
		usort( $notices, function ( $a, $b ) use ( $order ) {
			return $order[ $a['severity'] ] - $order[ $b['severity'] ];
		} );
		return $notices;
	}

	/* ------------------------------------------------------------------ */
	/* Nightly email report                                                */
	/* ------------------------------------------------------------------ */

	/** Pure static so tests can pin the exact subject format. */
	public static function email_subject( array $report, $host ) {
		return sprintf(
			'[%s] Maintenance %s — %s/%s GB%s',
			$host,
			isset( $report['status'] ) ? $report['status'] : 'UNKNOWN',
			isset( $report['storage_after'] ) ? $report['storage_after'] : '?',
			isset( $report['storage_limit'] ) ? $report['storage_limit'] : '?',
			( isset( $report['mode'] ) && 'DRY RUN' === $report['mode'] ) ? ' (dry run)' : ''
		);
	}

	/**
	 * Self-contained HTML email. Inline styles only — email clients strip
	 * stylesheets — and esc_html() on EVERY dynamic value: log lines embed
	 * post titles and meta values, which are user-controlled.
	 */
	public static function build_email_html( array $report ) {
		$colors = array( 'HEALTHY' => '#2e7d32', 'WARNING' => '#b26a00', 'CRITICAL' => '#c62828', 'ABORTED' => '#c62828' );
		$status = isset( $report['status'] ) ? $report['status'] : 'UNKNOWN';
		$color  = isset( $colors[ $status ] ) ? $colors[ $status ] : '#555555';
		$mode   = isset( $report['mode'] ) ? $report['mode'] : '';
		$counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
		$n      = function ( $k ) use ( $counts ) {
			return isset( $counts[ $k ] ) ? (int) $counts[ $k ] : 0;
		};
		$limit  = isset( $report['storage_limit'] ) ? (float) $report['storage_limit'] : 30.0;
		$after  = isset( $report['storage_after'] ) && is_numeric( $report['storage_after'] ) ? (float) $report['storage_after'] : 0.0;
		$pct    = $limit > 0 ? min( 100, (int) round( 100 * $after / $limit ) ) : 0;

		$td  = 'padding:6px 10px;border-bottom:1px solid #eeeeee;font-size:13px;color:#333333';
		$hd  = 'padding:12px 20px 4px;font-size:14px;font-weight:bold;color:#111111';

		$h  = '<div style="font-family:Arial,Helvetica,sans-serif;background:#f4f4f4;padding:16px">';
		$h .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="margin:0 auto;background:#ffffff;border:1px solid #dddddd">';

		$h .= '<tr><td style="background:' . $color . ';color:#ffffff;padding:14px 20px">'
			. '<div style="font-size:17px;font-weight:bold">Nightly Maintenance — ' . esc_html( $status ) . ( $mode ? ' — ' . esc_html( $mode ) : '' ) . '</div>'
			. '<div style="font-size:12px;margin-top:2px">' . esc_html( isset( $report['time'] ) ? $report['time'] : '' ) . '</div>'
			. '</td></tr>';

		// --- Storage -----------------------------------------------------
		$h .= '<tr><td style="' . $hd . '">Storage</td></tr>';
		$h .= '<tr><td style="padding:4px 20px 0">'
			. '<div style="font-size:22px;font-weight:bold;color:' . $color . '">' . esc_html( (string) $after ) . ' GB <span style="font-size:13px;font-weight:normal;color:#666666">of ' . esc_html( (string) $limit ) . ' GB ceiling (' . $pct . '%)</span></div>'
			. '<div style="background:#eeeeee;width:100%;height:12px;margin:6px 0 4px"><div style="background:' . $color . ';height:12px;width:' . $pct . '%"></div></div>'
			. '<div style="font-size:12px;color:#666666">Before: ' . esc_html( isset( $report['storage_before'] ) ? (string) $report['storage_before'] : '?' ) . ' GB'
			. ' &nbsp;|&nbsp; Reclaimed: ' . esc_html( isset( $report['reclaimed_gb'] ) ? (string) $report['reclaimed_gb'] : '0' ) . ' GB'
			. ' &nbsp;|&nbsp; Tier: ' . esc_html( isset( $report['tier'] ) ? $report['tier'] : '?' ) . '</div>'
			. '</td></tr>';

		if ( ! empty( $report['breakdown'] ) && is_array( $report['breakdown'] ) ) {
			$h .= '<tr><td style="padding:8px 20px 0"><table width="100%" cellpadding="0" cellspacing="0">';
			$h .= '<tr><td style="' . $td . ';font-weight:bold">Largest directories</td><td style="' . $td . ';font-weight:bold;text-align:right">GB</td></tr>';
			foreach ( array_slice( $report['breakdown'], 0, 8, true ) as $dir => $gb_val ) {
				$h .= '<tr><td style="' . $td . '"><code style="font-size:12px">' . esc_html( $dir ) . '</code></td><td style="' . $td . ';text-align:right">' . esc_html( (string) $gb_val ) . '</td></tr>';
			}
			$h .= '</table></td></tr>';
		}

		// --- Deletions ---------------------------------------------------
		$h .= '<tr><td style="' . $hd . '">Cleanup</td></tr>';
		$h .= '<tr><td style="padding:0 20px"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
			. '<td style="' . $td . '">Files: <b>' . $n( 'files_deleted' ) . '</b></td>'
			. '<td style="' . $td . '">Pages: <b>' . $n( 'pages_deleted' ) . '</b></td>'
			. '<td style="' . $td . '">Vehicles: <b>' . $n( 'vehicles_deleted' ) . '</b> of ' . $n( 'vehicles_eligible' ) . ' eligible</td>'
			. '<td style="' . $td . '">Images: <b>' . $n( 'images_deleted' ) . '</b></td>'
			. '<td style="' . $td . '">Errors: <b style="color:' . ( $n( 'errors' ) ? '#c62828' : '#2e7d32' ) . '">' . $n( 'errors' ) . '</b></td>'
			. '</tr></table></td></tr>';

		// --- Site health -------------------------------------------------
		$health = isset( $report['health'] ) && is_array( $report['health'] ) ? $report['health'] : array();
		$h .= '<tr><td style="' . $hd . '">Site health</td></tr>';
		$h .= '<tr><td style="padding:0 20px"><table width="100%" cellpadding="0" cellspacing="0">';
		$hrow = function ( $label, $ok, $detail ) use ( $td ) {
			$mark = null === $ok ? '<span style="color:#666666">—</span>'
				: ( $ok ? '<b style="color:#2e7d32">OK</b>' : '<b style="color:#c62828">FAIL</b>' );
			return '<tr><td style="' . $td . ';width:220px">' . esc_html( $label ) . '</td><td style="' . $td . ';width:50px">' . $mark . '</td><td style="' . $td . '">' . esc_html( $detail ) . '</td></tr>';
		};
		if ( $health ) {
			$h .= $hrow( 'Database', ! empty( $health['db_ok'] ), ! empty( $health['db_ok'] ) ? 'reachable' : 'check failed' );
			$hc = isset( $health['homepage_code'] ) ? (int) $health['homepage_code'] : 0;
			$h .= $hrow( 'Homepage', $hc > 0 && $hc < 400, $hc > 0 ? 'HTTP ' . $hc : 'no response' );
			if ( isset( $health['archive_code'] ) && null !== $health['archive_code'] ) {
				$ac = (int) $health['archive_code'];
				$h .= $hrow( 'Vehicle listings', $ac > 0 && $ac < 400, $ac > 0 ? 'HTTP ' . $ac : 'no response' );
			}
			$h .= $hrow( '4:00 AM schedule', ! empty( $health['cron_ok'] ), ! empty( $health['cron_ok'] ) ? 'scheduled' : 'was missing — re-scheduled this run' );
		} else {
			$h .= $hrow( 'Health checks', null, 'not captured this run' );
		}
		$h .= '</table></td></tr>';

		// --- SEO ---------------------------------------------------------
		if ( ! empty( $report['seo'] ) && is_array( $report['seo'] ) ) {
			$h .= '<tr><td style="' . $hd . '">SEO</td></tr>';
			$h .= '<tr><td style="padding:0 20px"><table width="100%" cellpadding="0" cellspacing="0">';
			foreach ( $report['seo'] as $r ) {
				$h .= $hrow(
					isset( $r['label'] ) ? $r['label'] : '',
					isset( $r['ok'] ) ? $r['ok'] : null,
					isset( $r['detail'] ) ? $r['detail'] : ''
				);
			}
			$h .= '</table></td></tr>';
		}

		// --- Important information ---------------------------------------
		if ( ! empty( $report['notices'] ) && is_array( $report['notices'] ) ) {
			$h .= '<tr><td style="' . $hd . ';color:#c62828">IMPORTANT INFORMATION</td></tr>';
			$h .= '<tr><td style="padding:4px 20px 8px">';
			foreach ( $report['notices'] as $ntc ) {
				$sev  = isset( $ntc['severity'] ) ? $ntc['severity'] : 'info';
				$bg   = 'critical' === $sev ? '#fdecea' : ( 'warning' === $sev ? '#fff8e1' : '#eef4fb' );
				$bd   = 'critical' === $sev ? '#c62828' : ( 'warning' === $sev ? '#b26a00' : '#1a6bb0' );
				$h   .= '<div style="background:' . $bg . ';border-left:4px solid ' . $bd . ';padding:8px 12px;margin:4px 0;font-size:13px;color:#333333">'
					. '<b>' . esc_html( strtoupper( $sev ) ) . ':</b> ' . esc_html( isset( $ntc['text'] ) ? $ntc['text'] : '' ) . '</div>';
			}
			$h .= '</td></tr>';
		}

		// --- Log tail + footer -------------------------------------------
		if ( ! empty( $report['log'] ) && is_array( $report['log'] ) ) {
			$h .= '<tr><td style="' . $hd . '">Log (last ' . count( array_slice( $report['log'], -40 ) ) . ' lines)</td></tr>';
			$h .= '<tr><td style="padding:0 20px 12px"><pre style="background:#f7f7f7;border:1px solid #e0e0e0;padding:8px;font-size:11px;overflow:auto;white-space:pre-wrap;color:#444444">'
				. esc_html( implode( "\n", array_slice( $report['log'], -40 ) ) ) . '</pre></td></tr>';
		}
		$h .= '<tr><td style="padding:10px 20px;background:#fafafa;border-top:1px solid #eeeeee;font-size:11px;color:#888888">'
			. 'Afimex Nightly Maintenance v' . esc_html( self::VERSION ) . ' — manage under wp-admin &gt; Tools &gt; Nightly Maintenance</td></tr>';
		$h .= '</table></div>';
		return $h;
	}

	/**
	 * Send the nightly report. Runs AFTER the report/history/cycle are
	 * persisted and is wrapped in its own catch-all: a mail failure must
	 * never cost a night of cleanup.
	 */
	private function send_report_email( $s, array $report ) {
		if ( empty( $s['email_enabled'] ) ) {
			$this->log( 'INFO', 'Report email disabled in settings.' );
			return;
		}
		$outcome = array( 'to' => '', 'sent' => false, 'error' => '', 'time' => wp_date( 'Y-m-d H:i:s T' ) );
		try {
			$to            = ( isset( $s['email_to'] ) && is_email( $s['email_to'] ) ) ? $s['email_to'] : get_option( 'admin_email' );
			$outcome['to'] = $to;
			$host          = wp_parse_url( home_url(), PHP_URL_HOST );
			$subject       = self::email_subject( $report, $host ? $host : 'site' );
			$body          = self::build_email_html( $report );

			// Capture the precise failure reason (wp_mail alone returns bool).
			$mail_error = '';
			$capture    = function ( $wp_error ) use ( &$mail_error ) {
				$mail_error = $wp_error->get_error_message();
			};
			add_action( 'wp_mail_failed', $capture );
			// No custom From header: on shared hosting a mismatched From
			// breaks SPF and lands everything in spam.
			$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
			remove_action( 'wp_mail_failed', $capture );

			$outcome['sent'] = (bool) $sent;
			if ( $sent ) {
				$this->log( 'INFO', 'Report email sent to ' . $to . '.' );
			} else {
				$outcome['error'] = $mail_error ? $mail_error : 'wp_mail returned false';
				$this->log( 'ERROR', 'Report email to ' . $to . ' FAILED: ' . $outcome['error'] );
			}
		} catch ( Throwable $e ) {
			$outcome['error'] = $e->getMessage();
			$this->log( 'ERROR', 'Report email crashed: ' . $e->getMessage() );
		}
		$report['email'] = $outcome;
		update_option( self::OPT_REPORT, $report, false );
	}

	/* ------------------------------------------------------------------ */
	/* Admin UI: Tools > Nightly Maintenance                               */
	/* ------------------------------------------------------------------ */

	public function admin_menu() {
		add_management_page(
			'Nightly Maintenance', 'Nightly Maintenance',
			'manage_options', 'anm-maintenance', array( $this, 'render_admin_page' )
		);
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'anm_save_settings' ) ) {
			wp_die( 'Not allowed.' );
		}
		$in  = wp_unslash( $_POST );
		$old = self::settings();
		$s   = $old;
		$s['dry_run']              = empty( $in['execute_mode'] ) ? 1 : 0;
		$s['vehicle_post_type']    = sanitize_key( $in['vehicle_post_type'] ?? '' );
		$s['auction_date_meta']    = sanitize_text_field( $in['auction_date_meta'] ?? '' );
		$s['retention_days']       = max( 1, (int) ( $in['retention_days'] ?? 7 ) );
		$s['storage_limit_gb']     = max( 5, (int) ( $in['storage_limit_gb'] ?? 30 ) );
		$s['delete_vehicle_media'] = empty( $in['delete_vehicle_media'] ) ? 0 : 1;
		$s['clean_ewww_backups']   = empty( $in['clean_ewww_backups'] ) ? 0 : 1;
		$s['purge_batch']          = max( 1, (int) ( $in['purge_batch'] ?? $old['purge_batch'] ) );
		$s['email_enabled']        = empty( $in['email_enabled'] ) ? 0 : 1;
		$s['email_to']             = sanitize_email( $in['email_to'] ?? '' );

		// A new purge target must prove itself in a dry run first, even on a
		// site already running in execute mode: the first run after changing
		// the post type or the auction-date key is always report-only.
		$forced = 0;
		if ( ( $s['vehicle_post_type'] !== $old['vehicle_post_type'] || $s['auction_date_meta'] !== $old['auction_date_meta'] )
			&& '' !== $s['vehicle_post_type'] && ! $s['dry_run'] ) {
			$s['dry_run'] = 1;
			$forced       = 1;
		}
		update_option( self::OPT_SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'tools.php?page=anm-maintenance&saved=1' . ( $forced ? '&forced_dry=1' : '' ) ) );
		exit;
	}

	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'anm_run_now' ) ) {
			wp_die( 'Not allowed.' );
		}
		// Manual runs never mark the nightly cycle done and honour dry-run.
		$this->run_maintenance( true );
		wp_safe_redirect( admin_url( 'tools.php?page=anm-maintenance&ran=1' ) );
		exit;
	}

	/** Re-send the last stored report so mail delivery can be debugged now. */
	public function handle_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'anm_send_test_email' ) ) {
			wp_die( 'Not allowed.' );
		}
		$report = (array) get_option( self::OPT_REPORT, array() );
		if ( ! $report ) {
			wp_safe_redirect( admin_url( 'tools.php?page=anm-maintenance&mailtest=0' ) );
			exit;
		}
		$this->counts = array( 'errors' => 0 ); // log() touches this
		$s            = self::settings();
		$s['email_enabled'] = 1; // an explicit test always tries to send
		$this->send_report_email( $s, $report );
		$report = (array) get_option( self::OPT_REPORT, array() );
		$ok     = ! empty( $report['email']['sent'] );
		wp_safe_redirect( admin_url( 'tools.php?page=anm-maintenance&mailtest=' . ( $ok ? '1' : '0' ) ) );
		exit;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s      = self::settings();
		$report = get_option( self::OPT_REPORT );
		$next   = wp_next_scheduled( self::CRON_HOOK );

		// Survey data for configuration: post types + date-like meta keys.
		$types = $wpdb->get_results(
			"SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('auto-draft')
			 GROUP BY post_type ORDER BY n DESC LIMIT 20"
		);
		$meta_rows = array();
		if ( $s['vehicle_post_type'] || ! empty( $_GET['inspect'] ) ) {
			$pt = $s['vehicle_post_type'] ?: sanitize_key( $_GET['inspect'] );
			$meta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm.meta_key, COUNT(*) AS n,
				        SUBSTRING( MAX(pm.meta_value), 1, 40 ) AS sample
				 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_value <> ''
				   AND ( pm.meta_key LIKE '%%date%%' OR pm.meta_key LIKE '%%auction%%'
				         OR pm.meta_key LIKE '%%sale%%' OR pm.meta_key LIKE '%%end%%' )
				 GROUP BY pm.meta_key ORDER BY n DESC LIMIT 20",
				$pt
			) );
		}
		?>
		<div class="wrap">
			<h1>Nightly Maintenance</h1>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['ran'] ) ) : ?><div class="notice notice-success"><p>Run finished — report below.</p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['forced_dry'] ) ) : ?><div class="notice notice-warning"><p>The vehicle purge target changed, so the plugin switched itself back to <strong>DRY RUN</strong>. Review the next dry-run report, then re-enable Execute mode.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['mailtest'] ) ) : ?><div class="notice notice-<?php echo '1' === $_GET['mailtest'] ? 'success' : 'error'; ?>"><p><?php echo '1' === $_GET['mailtest'] ? 'Test email handed to the mail system — check the inbox (and spam folder).' : 'Test email FAILED — see the "Report email" row below for the reason.'; ?></p></div><?php endif; ?>

			<p>
				<strong>Mode:</strong>
				<?php echo $s['dry_run'] ? '<span style="color:#2271b1">DRY RUN (nothing is deleted)</span>' : '<span style="color:#d63638">EXECUTE (deletions are live)</span>'; ?>
				&nbsp;|&nbsp; <strong>Next scheduled run:</strong>
				<?php echo $next ? esc_html( wp_date( 'Y-m-d H:i T', $next ) ) : '<em>not scheduled!</em>'; ?>
			</p>

			<?php if ( $report ) : ?>
			<h2>Last run — <?php echo esc_html( $report['status'] ); ?></h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><td>Time</td><td><?php echo esc_html( $report['time'] ); ?> (<?php echo esc_html( $report['mode'] ); ?>)</td></tr>
					<tr><td>Storage</td><td><?php echo esc_html( $report['storage_before'] ); ?> GB &rarr; <?php echo esc_html( $report['storage_after'] ); ?> GB / <?php echo esc_html( $report['storage_limit'] ); ?> GB (tier <?php echo esc_html( $report['tier'] ); ?>)</td></tr>
					<tr><td>Deleted</td><td><?php echo (int) $report['counts']['pages_deleted']; ?> pages, <?php echo (int) $report['counts']['files_deleted']; ?> files, <?php echo esc_html( $report['reclaimed_gb'] ); ?> GB</td></tr>
					<tr><td>Vehicles removed</td><td><?php echo (int) $report['counts']['vehicles_deleted']; ?> (of <?php echo (int) $report['counts']['vehicles_eligible']; ?> eligible)</td></tr>
					<tr><td>Errors</td><td><?php echo (int) $report['counts']['errors']; ?></td></tr>
					<tr><td>Health</td><td><?php echo $report['health_ok'] ? 'PASSED' : '<strong style="color:#d63638">FAILED</strong>'; ?></td></tr>
					<tr><td>Report email</td><td><?php
						if ( empty( $report['email'] ) ) {
							echo '&mdash;';
						} elseif ( ! empty( $report['email']['sent'] ) ) {
							echo 'Sent to ' . esc_html( $report['email']['to'] ) . ' at ' . esc_html( $report['email']['time'] );
						} else {
							echo '<strong style="color:#d63638">FAILED</strong>: ' . esc_html( $report['email']['error'] ) . ' (to ' . esc_html( $report['email']['to'] ) . ')';
						}
					?></td></tr>
				</tbody>
			</table>
			<?php if ( ! empty( $report['breakdown'] ) ) : ?>
			<h3>Where the space is</h3>
			<table class="widefat striped" style="max-width:560px">
				<thead><tr><th>Directory</th><th style="width:110px">Size</th></tr></thead>
				<tbody>
				<?php foreach ( $report['breakdown'] as $bd_dir => $bd_gb ) : ?>
					<tr><td><code><?php echo esc_html( $bd_dir ); ?></code></td><td><?php echo esc_html( $bd_gb ); ?> GB</td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<details style="margin-top:8px;max-width:760px"><summary>Run log (last <?php echo count( $report['log'] ); ?> lines)</summary>
				<pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;overflow:auto;max-height:420px"><?php echo esc_html( implode( "\n", $report['log'] ) ); ?></pre>
			</details>
			<?php endif; ?>

			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'anm_save_settings' ); ?>
				<input type="hidden" name="action" value="anm_save_settings">
				<table class="form-table" style="max-width:760px">
					<tr>
						<th>Vehicle post type</th>
						<td>
							<input name="vehicle_post_type" value="<?php echo esc_attr( $s['vehicle_post_type'] ); ?>" class="regular-text" placeholder="e.g. vehicle">
							<p class="description">Post types on this site:
								<?php foreach ( $types as $t ) : ?>
									<code><a href="<?php echo esc_url( add_query_arg( 'inspect', $t->post_type ) ); ?>"><?php echo esc_html( $t->post_type ); ?></a> (<?php echo (int) $t->n; ?>)</code>
								<?php endforeach; ?>
								— click one to list its date-like meta keys.</p>
						</td>
					</tr>
					<tr>
						<th>Auction date meta key</th>
						<td>
							<input name="auction_date_meta" value="<?php echo esc_attr( $s['auction_date_meta'] ); ?>" class="regular-text" placeholder="e.g. auction_date">
							<?php if ( $meta_rows ) : ?>
							<p class="description">Date-like keys found (key / rows / sample):</p>
							<ul style="margin:4px 0 0 0">
								<?php foreach ( $meta_rows as $m ) : ?>
									<li><code><?php echo esc_html( $m->meta_key ); ?></code> — <?php echo (int) $m->n; ?> rows — sample: <code><?php echo esc_html( $m->sample ); ?></code></li>
								<?php endforeach; ?>
							</ul>
							<?php endif; ?>
							<p class="description">Accepted formats: <code>YYYY-MM-DD</code>, <code>YYYY-MM-DD HH:MM[:SS]</code>, unix timestamp. Anything else is preserved, never guessed.</p>
						</td>
					</tr>
					<tr><th>Retention (days)</th><td><input name="retention_days" type="number" min="1" value="<?php echo (int) $s['retention_days']; ?>" style="width:80px"> Vehicles are deleted only when the auction date is MORE than this many days old.</td></tr>
				<tr><th>Vehicles per night (cap)</th><td><input name="purge_batch" type="number" min="1" value="<?php echo (int) $s['purge_batch']; ?>" style="width:80px"> Hard cap on vehicles permanently deleted in a single nightly run.</td></tr>
					<tr><th>Storage ceiling (GB)</th><td><input name="storage_limit_gb" type="number" min="1" value="<?php echo (int) $s['storage_limit_gb']; ?>" style="width:80px"></td></tr>
					<tr><th>Cleanup tiers</th><td><em>Derived from the ceiling:</em> aggressive at <?php echo (int) $s['storage_limit_gb'] - 5; ?> GB, remove old content at <?php echo (int) $s['storage_limit_gb'] - 2; ?> GB, hard clean at <?php echo (int) $s['storage_limit_gb'] - 1; ?> GB, emergency at <?php echo (int) $s['storage_limit_gb']; ?> GB.</td></tr>
					<tr><th>Nightly email report</th><td><label><input type="checkbox" name="email_enabled" <?php checked( $s['email_enabled'] ); ?>> Email the report after every run</label></td></tr>
					<tr><th>Email recipient</th><td><input name="email_to" type="email" class="regular-text" value="<?php echo esc_attr( $s['email_to'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"> <span class="description">Blank or invalid falls back to the admin email.</span></td></tr>
					<tr><th>Delete vehicle media</th><td><label><input type="checkbox" name="delete_vehicle_media" <?php checked( $s['delete_vehicle_media'] ); ?>> Remove a deleted vehicle's images when nothing else references them</label></td></tr>
				<tr><th>Prune EWWW backups</th><td><label><input type="checkbox" name="clean_ewww_backups" <?php checked( $s['clean_ewww_backups'] ); ?>> Delete EWWW pre-optimization image backups older than 7 days (wp-content/ewww/image-backup — safe: the served images live in uploads/)</label></td></tr>
					<tr>
						<th>Execute mode</th>
						<td>
							<label><input type="checkbox" name="execute_mode" <?php checked( ! $s['dry_run'] ); ?>> <strong style="color:#d63638">Enable real deletions</strong></label>
							<p class="description">Leave OFF until you have reviewed several dry-run reports above and the vehicle counts look right.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<?php wp_nonce_field( 'anm_run_now' ); ?>
				<input type="hidden" name="action" value="anm_run_now">
				<?php submit_button( $s['dry_run'] ? 'Run now (dry run)' : 'Run now (LIVE — will delete)', $s['dry_run'] ? 'secondary' : 'delete', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( 'anm_send_test_email' ); ?>
				<input type="hidden" name="action" value="anm_send_test_email">
				<?php submit_button( 'Send test email (re-sends last report)', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

Afimex_Nightly_Maintenance::instance();
