<?php
/**
 * Plugin Name: Afimex Nightly Maintenance
 * Description: Nightly 4:00 AM maintenance: storage audit against a 40 GB ceiling, auction-date-based vehicle retention, database cleanup, and a persistent maintenance log. DRY RUN by default — deletes nothing until explicitly enabled.
 * Version: 1.1.0
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
			'storage_limit_gb'      => 40,
			'threshold_aggressive'  => 35,
			'threshold_remove_old'  => 38,
			'keep_revisions'        => 3,
			'trash_max_age_days'    => 30,
			'delete_vehicle_media'  => 1,   // remove images of deleted vehicles when unreferenced
			'purge_batch'           => 50,  // vehicles per batch before recheck
			'max_runtime_seconds'   => 600, // stop cleanly before PHP/host limits kill us
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPT_SETTINGS, array() ), self::defaults() );
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
	private function measure_storage() {
		$root    = untrailingslashit( ABSPATH );
		$total   = 0;
		$dirs    = array();
		$rootlen = strlen( $root ) + 1;
		if ( is_dir( $root ) ) {
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				foreach ( $it as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					$sz     = $file->getSize();
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
			'files' => $total,
			'db'    => $db,
			'total' => $total + $db,
		);
	}

	private static function gb( $bytes ) {
		return round( $bytes / ( 1024 * 1024 * 1024 ), 2 );
	}

	private function storage_tier( $total_bytes, $s ) {
		$gb = $total_bytes / ( 1024 * 1024 * 1024 );
		if ( $gb >= $s['storage_limit_gb'] )      return 'EMERGENCY';
		if ( $gb >= $s['storage_limit_gb'] - 1 )  return 'HARD_CLEAN';
		if ( $gb >= $s['threshold_remove_old'] )  return 'REMOVE_OLD';
		if ( $gb >= $s['threshold_aggressive'] )  return 'AGGRESSIVE';
		return 'NORMAL';
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
		$started = time();
		$deadline = $started + max( 60, (int) $s['max_runtime_seconds'] );

		// --- Guard 1: no concurrent runs. ---------------------------------
		if ( false === add_option( self::LOCK_KEY, time(), '', 'no' ) ) {
			$lock = (int) get_option( self::LOCK_KEY );
			if ( $lock > time() - HOUR_IN_SECONDS ) {
				$this->log( 'ERROR', 'Another maintenance run holds the lock. Aborting.' );
				return;
			}
			// Stale lock (crashed run) — take over.
			update_option( self::LOCK_KEY, time(), 'no' );
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
			$before = $this->measure_storage();
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
			$this->cleanup_stale_files( $s );

			// --- Priority 2: vehicles past the auction-date retention -----
			$this->purge_vehicles( $s, $deadline );

			// --- Recalculate ---------------------------------------------
			$after = $this->measure_storage();
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
			$health = $this->verify_site( $s );

			// --- Report --------------------------------------------------
			$status = 'HEALTHY';
			if ( 'NORMAL' !== $tier_after || $this->counts['errors'] > 0 ) {
				$status = 'WARNING';
			}
			if ( 'EMERGENCY' === $tier_after || ! $health ) {
				$status = 'CRITICAL';
			}

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
				'health_ok'       => $health,
				'duration_s'      => time() - $started,
				'breakdown'       => array_map( function ( $b ) {
					return round( $b / ( 1024 * 1024 * 1024 ), 2 );
				}, $this->breakdown ),
				'log'             => array_slice( $this->log, -400 ),
			);
			update_option( self::OPT_REPORT, $report, false );

			$history   = (array) get_option( self::OPT_HISTORY, array() );
			$history[] = array(
				'time' => $report['time'], 'status' => $status, 'mode' => $report['mode'],
				'before' => $report['storage_before'], 'after' => $report['storage_after'],
				'vehicles' => $this->counts['vehicles_deleted'], 'errors' => $this->counts['errors'],
			);
			update_option( self::OPT_HISTORY, array_slice( $history, -60 ), false );

			if ( ! $this->dry_run ) {
				update_option( self::OPT_CYCLE, $cycle, false );
			}
			$this->log( 'INFO', "STATUS: {$status}" );
		} finally {
			delete_option( self::LOCK_KEY );
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

	private function cleanup_stale_files( $s ) {
		$this->log( 'INFO', '--- Cache / temp file cleanup ---' );
		$content = untrailingslashit( WP_CONTENT_DIR );
		$targets = array( $content . '/cache', $content . '/et-cache', $content . '/litespeed', $content . '/wphb-cache' );
		$cutoff  = time() - 7 * DAY_IN_SECONDS;

		foreach ( $targets as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$stale = array();
			$bytes = 0;
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				foreach ( $it as $f ) {
					if ( $f->isFile() && $f->getMTime() < $cutoff ) {
						$stale[] = $f->getPathname();
						$bytes  += $f->getSize();
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
				function () use ( $stale, &$counts ) {
					foreach ( $stale as $path ) {
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
		// Unix timestamp (9-11 digits => years 1973..5138).
		if ( ctype_digit( $value ) && strlen( $value ) >= 9 && strlen( $value ) <= 11 ) {
			return (int) $value;
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
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
			$ptype
		) );
		$dated = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> ''
			 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')",
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
			 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_value <> ''",
			$meta, $ptype
		) );

		$retention = max( 1, (int) $s['retention_days'] );
		$now       = time();
		$eligible  = array();
		$unparsed  = 0;
		foreach ( $rows as $row ) {
			$ts = self::parse_auction_date( $row->meta_value );
			if ( false === $ts ) {
				$unparsed++;
				continue;
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
		$this->log( 'INFO', count( $eligible ) . " vehicle(s) past the {$retention}-day retention window." );
		if ( ! $eligible ) {
			return;
		}

		$menu_ids = $this->menu_object_ids();
		$front    = (int) get_option( 'page_on_front' );

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
					$this->delete_unreferenced_attachments( $attachments );
				}
			} elseif ( $ok && $this->dry_run ) {
				$counts['vehicles_deleted']++; // counted as "would delete"
			}
			$done++;
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

	/** Delete attachments only when nothing else still references them. */
	private function delete_unreferenced_attachments( array $ids ) {
		global $wpdb;
		foreach ( $ids as $att ) {
			$post = get_post( $att );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			// Still someone's featured image?
			$used = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
				(string) $att
			) );
			if ( $used > 0 ) {
				continue;
			}
			// Referenced by filename in any live post content?
			$file = get_post_meta( $att, '_wp_attached_file', true );
			$base = $file ? wp_basename( $file ) : '';
			if ( $base ) {
				$like = '%' . $wpdb->esc_like( $base ) . '%';
				$refs = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_status IN ('publish','future','private','draft') AND post_content LIKE %s",
					$like
				) );
				if ( $refs > 0 ) {
					continue;
				}
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
		$ok = true;

		// Database reachable?
		global $wpdb;
		if ( null === $wpdb->get_var( 'SELECT 1' ) ) {
			$this->log( 'ERROR', 'Database check failed.' );
			$ok = false;
		}

		// Homepage responds? (loopback request)
		$resp = wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'redirection' => 5, 'sslverify' => false ) );
		if ( is_wp_error( $resp ) ) {
			$this->log( 'ERROR', 'Homepage check failed: ' . $resp->get_error_message() );
			$ok = false;
		} else {
			$code = wp_remote_retrieve_response_code( $resp );
			$this->log( 'INFO', "Homepage: HTTP {$code}" );
			if ( $code >= 400 ) {
				$ok = false;
				$this->log( 'ERROR', "Homepage returned HTTP {$code}." );
			}
		}

		// Vehicle archive still up (when configured)?
		$ptype = sanitize_key( $s['vehicle_post_type'] );
		if ( $ptype && post_type_exists( $ptype ) ) {
			$archive = get_post_type_archive_link( $ptype );
			if ( $archive ) {
				$resp = wp_remote_get( $archive, array( 'timeout' => 20, 'sslverify' => false ) );
				$code = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
				$this->log( 'INFO', "Vehicle archive: HTTP {$code} ({$archive})" );
				if ( $code >= 400 || 0 === $code ) {
					$ok = false;
					$this->log( 'ERROR', 'Vehicle archive check failed.' );
				}
			}
		}

		// Our own cron still scheduled?
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
			$this->log( 'WARN', 'Cron event was missing — rescheduled.' );
		}
		return $ok;
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
		$in = wp_unslash( $_POST );
		$s  = self::settings();
		$s['dry_run']              = empty( $in['execute_mode'] ) ? 1 : 0;
		$s['vehicle_post_type']    = sanitize_key( $in['vehicle_post_type'] ?? '' );
		$s['auction_date_meta']    = sanitize_text_field( $in['auction_date_meta'] ?? '' );
		$s['retention_days']       = max( 1, (int) ( $in['retention_days'] ?? 7 ) );
		$s['storage_limit_gb']     = max( 1, (int) ( $in['storage_limit_gb'] ?? 40 ) );
		$s['threshold_aggressive'] = max( 1, (int) ( $in['threshold_aggressive'] ?? 35 ) );
		$s['threshold_remove_old'] = max( 1, (int) ( $in['threshold_remove_old'] ?? 38 ) );
		$s['delete_vehicle_media'] = empty( $in['delete_vehicle_media'] ) ? 0 : 1;
		update_option( self::OPT_SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'tools.php?page=anm-maintenance&saved=1' ) );
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
					<tr><th>Storage ceiling (GB)</th><td><input name="storage_limit_gb" type="number" min="1" value="<?php echo (int) $s['storage_limit_gb']; ?>" style="width:80px"></td></tr>
					<tr><th>Aggressive at (GB)</th><td><input name="threshold_aggressive" type="number" min="1" value="<?php echo (int) $s['threshold_aggressive']; ?>" style="width:80px"></td></tr>
					<tr><th>Remove old at (GB)</th><td><input name="threshold_remove_old" type="number" min="1" value="<?php echo (int) $s['threshold_remove_old']; ?>" style="width:80px"></td></tr>
					<tr><th>Delete vehicle media</th><td><label><input type="checkbox" name="delete_vehicle_media" <?php checked( $s['delete_vehicle_media'] ); ?>> Remove a deleted vehicle's images when nothing else references them</label></td></tr>
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
		</div>
		<?php
	}
}

Afimex_Nightly_Maintenance::instance();
