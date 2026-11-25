<?php
/**
 * Update log storage and retention.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the update log in a single, non-autoloaded option.
 *
 * Adding an entry means reading the whole log, appending and writing it back.
 * Two requests doing that at the same moment would each start from the same
 * copy and the second write would drop the first one's entry, so every write
 * happens under a short lock and re-reads the log from the database first.
 *
 * Entries are kept oldest first. Each entry is an array:
 *
 *   t      int    Unix timestamp (UTC) of when the entry was recorded.
 *   type   string 'core', 'plugin' or 'theme'.
 *   item   string Plugin file, theme directory, or '' for core.
 *   name   string Display name at the time of the update.
 *   old    string Version before the update, '' when unknown.
 *   new    string Version after the update (or the version attempted), '' when unknown.
 *   result string 'success', 'failed' or 'rolled_back'.
 *   mode   string 'auto' or 'manual'.
 *   code   string Error code or one of this plugin's own detail codes, may be ''.
 *   note   string Error message reported by WordPress, may be ''.
 */
class Update_Log {

	/**
	 * Option name (a site option on multisite).
	 */
	const OPTION = 'proadvanced_update_emails_log';

	/**
	 * Name of the daily pruning event.
	 */
	const CRON_HOOK = 'proadvanced_update_emails_prune_log';

	/**
	 * Entries older than this many days are deleted.
	 */
	const RETENTION_DAYS = 90;

	/**
	 * Hard cap on the number of stored entries, as a safety net.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Maximum stored length of a note.
	 */
	const NOTE_MAX_LENGTH = 300;

	/**
	 * Name of the write lock. WordPress stores it as the option "<name>.lock".
	 */
	const LOCK_NAME = 'proadvanced_update_emails_log';

	/**
	 * Seconds after which a lock left behind by a request that died is ignored.
	 */
	const LOCK_TIMEOUT = 30;

	/**
	 * Longest time, in seconds, to wait for another request to finish writing.
	 */
	const LOCK_WAIT = 3;

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Settings::get( 'log_enabled' );
	}

	/**
	 * Returns all stored entries, oldest first.
	 *
	 * @return array[]
	 */
	public static function get_entries() {
		$entries = Settings::is_network() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values( array_filter( $entries, 'is_array' ) );
	}

	/**
	 * Appends entries, then prunes and saves the log.
	 *
	 * @param array[] $new_entries Entries to add.
	 * @return void
	 */
	public static function add( array $new_entries ) {
		if ( ! $new_entries || ! self::is_enabled() ) {
			return;
		}

		$locked = self::lock();

		try {
			$entries = self::get_fresh_entries();

			foreach ( $new_entries as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = self::sanitize_entry( $entry );
				}
			}

			self::save( self::prune_entries( $entries ) );
		} finally {
			self::unlock( $locked );
		}
	}

	/**
	 * Deletes expired entries. Runs on the daily event and before the log is shown.
	 *
	 * @return void
	 */
	public static function prune() {
		// Cheap look first, so viewing the log does not take the lock when nothing has expired.
		$entries = self::get_entries();

		if ( count( self::prune_entries( $entries ) ) === count( $entries ) ) {
			return;
		}

		$locked = self::lock();

		try {
			$entries = self::get_fresh_entries();
			$pruned  = self::prune_entries( $entries );

			if ( count( $pruned ) !== count( $entries ) ) {
				self::save( $pruned );
			}
		} finally {
			self::unlock( $locked );
		}
	}

	/**
	 * Deletes the whole log.
	 *
	 * @return void
	 */
	public static function clear() {
		$locked = self::lock();

		try {
			if ( Settings::is_network() ) {
				delete_site_option( self::OPTION );
			} else {
				delete_option( self::OPTION );
			}
		} finally {
			self::unlock( $locked );
		}
	}

	/**
	 * Keeps the daily pruning event in step with the "log enabled" setting.
	 *
	 * Runs on init because a must-use install has no activation hook. On
	 * multisite the log is network-wide, so only the main site carries the event.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::is_enabled() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Removes the daily pruning event. Runs on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Takes the write lock, waiting briefly if another request holds it.
	 *
	 * Uses WordPress's own upgrader lock, which is an atomic database insert and
	 * so works across processes and servers. On multisite the lock always lives
	 * on the main site, because the log is network-wide.
	 *
	 * @return bool True if the lock was taken. False if it could not be taken in
	 *              time; the caller then writes anyway, which is no worse than
	 *              having no lock, rather than dropping the entry.
	 */
	private static function lock() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$switched = self::switch_to_main_site();
		$deadline = microtime( true ) + self::LOCK_WAIT;
		$locked   = false;

		do {
			// WordPress checks an existing lock's age through the option cache, which may be out of date.
			self::forget_cached( self::LOCK_NAME . '.lock', false );

			$locked = \WP_Upgrader::create_lock( self::LOCK_NAME, self::LOCK_TIMEOUT );
			if ( $locked ) {
				break;
			}

			usleep( 50000 );
		} while ( microtime( true ) < $deadline );

		if ( $switched ) {
			restore_current_blog();
		}

		return (bool) $locked;
	}

	/**
	 * Releases the write lock.
	 *
	 * @param bool $locked Whether lock() took the lock.
	 * @return void
	 */
	private static function unlock( $locked ) {
		if ( ! $locked ) {
			return;
		}

		$switched = self::switch_to_main_site();

		\WP_Upgrader::release_lock( self::LOCK_NAME );

		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * On multisite, switches to the main site unless already there.
	 *
	 * @return bool Whether a switch happened and must be undone.
	 */
	private static function switch_to_main_site() {
		if ( ! is_multisite() || is_main_site() ) {
			return false;
		}

		switch_to_blog( get_main_site_id() );

		return true;
	}

	/**
	 * Reads the log straight from the database, ignoring any copy this request
	 * loaded earlier. Call only while holding the lock.
	 *
	 * @return array[]
	 */
	private static function get_fresh_entries() {
		self::forget_cached( self::OPTION, Settings::is_network() );

		return self::get_entries();
	}

	/**
	 * Drops an option from WordPress's in-memory cache so the next read hits the database.
	 *
	 * @param string $option  Option name.
	 * @param bool   $network Whether it is a network option.
	 * @return void
	 */
	private static function forget_cached( $option, $network ) {
		if ( $network ) {
			$network_id     = get_current_network_id();
			$group          = 'site-options';
			$cache_key      = "$network_id:$option";
			$notoptions_key = "$network_id:notoptions";
		} else {
			$group          = 'options';
			$cache_key      = $option;
			$notoptions_key = 'notoptions';
		}

		wp_cache_delete( $cache_key, $group );

		// WordPress also remembers options it found missing.
		$notoptions = wp_cache_get( $notoptions_key, $group );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( $notoptions_key, $notoptions, $group );
		}
	}

	/**
	 * Drops expired entries and enforces the entry cap.
	 *
	 * @param array[] $entries Entries, oldest first.
	 * @return array[]
	 */
	private static function prune_entries( array $entries ) {
		$cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$kept   = array();

		foreach ( $entries as $entry ) {
			if ( isset( $entry['t'] ) && (int) $entry['t'] >= $cutoff ) {
				$kept[] = $entry;
			}
		}

		if ( count( $kept ) > self::MAX_ENTRIES ) {
			$kept = array_slice( $kept, -self::MAX_ENTRIES );
		}

		return $kept;
	}

	/**
	 * Writes the log without autoloading it.
	 *
	 * @param array[] $entries Entries, oldest first.
	 * @return void
	 */
	private static function save( array $entries ) {
		if ( Settings::is_network() ) {
			// Network options live in the sitemeta table and are never autoloaded.
			update_site_option( self::OPTION, $entries );
		} else {
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * Reduces an entry to the known fields with safe values.
	 *
	 * @param array $entry Raw entry.
	 * @return array
	 */
	private static function sanitize_entry( array $entry ) {
		$type   = isset( $entry['type'] ) ? $entry['type'] : '';
		$result = isset( $entry['result'] ) ? $entry['result'] : '';
		$mode   = isset( $entry['mode'] ) ? $entry['mode'] : '';

		return array(
			't'      => isset( $entry['t'] ) ? (int) $entry['t'] : time(),
			'type'   => in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'item'   => self::clean( isset( $entry['item'] ) ? $entry['item'] : '', 200 ),
			'name'   => self::clean( isset( $entry['name'] ) ? $entry['name'] : '', 200 ),
			'old'    => self::clean( isset( $entry['old'] ) ? $entry['old'] : '', 40 ),
			'new'    => self::clean( isset( $entry['new'] ) ? $entry['new'] : '', 40 ),
			'result' => in_array( $result, array( 'success', 'failed', 'rolled_back' ), true ) ? $result : 'failed',
			'mode'   => 'auto' === $mode ? 'auto' : 'manual',
			'code'   => self::clean( isset( $entry['code'] ) ? $entry['code'] : '', 80 ),
			'note'   => self::clean( isset( $entry['note'] ) ? $entry['note'] : '', self::NOTE_MAX_LENGTH ),
		);
	}

	/**
	 * Turns a value into a plain, single-line string of limited length.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	private static function clean( $value, $length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );

		// WordPress provides mb_substr() even when the mbstring extension is missing.
		return mb_substr( $value, 0, $length );
	}
}
