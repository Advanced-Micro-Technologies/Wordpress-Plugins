<?php
/**
 * Watches WordPress's upgrader hooks and records updates in the log.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records core, plugin and theme updates.
 *
 * How one update is kept to one log entry:
 *
 * Every update in progress is held in memory as a "pending" entry keyed by
 * type and item, and is written exactly once. Automatic updates fire the same
 * general upgrader hooks as manual ones, so while WP_Automatic_Updater is
 * running (signalled by `pre_auto_update`) nothing is written from the general
 * hooks. Instead the results WordPress passes to `automatic_updates_complete`
 * are logged, because only they include fatal-error rollbacks, and all pending
 * entries are then discarded. If that action never fires because the request
 * died, the pending entries are written on shutdown instead.
 *
 * Known limits, where WordPress gives plugins nothing reliable to go on:
 *
 * - A manual core update that fails after the download is logged as failed
 *   without a reason or target version; WordPress does not pass either to hooks.
 * - A manual update that stops during download or unpacking is logged as
 *   failed with the note "did not finish"; the actual error is only given to
 *   the screen output.
 * - Whether WordPress restored the previous version after a failed manual
 *   plugin or theme update is not detected. Those are logged as failed.
 * - A plugin or theme skipped in a bulk update before it starts (already up to
 *   date, or incompatible WordPress/PHP version) is not logged.
 */
class Update_Tracker {

	/**
	 * Updates in progress, keyed by "type:item".
	 *
	 * @var array[]
	 */
	private $pending = array();

	/**
	 * Whether WP_Automatic_Updater is running in this request.
	 *
	 * @var bool
	 */
	private $auto = false;

	/**
	 * Core version that was running when this request started.
	 *
	 * @var string
	 */
	private $running_core = '';

	/**
	 * Whether a manual core update reported success in this request.
	 *
	 * @var bool
	 */
	private $core_done = false;

	/**
	 * Versions found in the destination folder before an uploaded ZIP replaced it.
	 *
	 * @var array
	 */
	private $overwrite = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Read now: after a core update, version.php on disk is already the new one.
		$this->running_core = (string) get_bloginfo( 'version' );

		add_action( 'pre_auto_update', array( $this, 'on_pre_auto_update' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_complete' ) );

		add_filter( 'upgrader_package_options', array( $this, 'on_package_options' ), PHP_INT_MAX );
		add_filter( 'upgrader_pre_download', array( $this, 'on_pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_post_install', array( $this, 'on_post_install' ), PHP_INT_MAX, 2 );
		add_filter( 'upgrader_install_package_result', array( $this, 'on_install_result' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_process_complete' ), 10, 2 );
		add_action( '_core_updated_successfully', array( $this, 'on_core_updated' ) );

		add_filter( 'upgrader_source_selection', array( $this, 'on_source_selection' ), PHP_INT_MAX, 4 );
		add_action( 'upgrader_overwrote_package', array( $this, 'on_overwrote_package' ), 10, 3 );

		add_action( 'shutdown', array( $this, 'flush_all' ) );
	}

	/**
	 * Fires immediately before WP_Automatic_Updater updates one item.
	 *
	 * @param string $type Type of update: 'core', 'theme', 'plugin' or 'translation'.
	 * @param object $item The update offer.
	 * @return void
	 */
	public function on_pre_auto_update( $type = '', $item = null ) {
		$this->auto = true;

		if ( ! is_object( $item ) ) {
			return;
		}

		if ( 'core' === $type ) {
			$this->open( 'core', '', isset( $item->current ) ? $item->current : '' );
		} elseif ( ( 'plugin' === $type || 'theme' === $type ) && ! empty( $item->{$type} ) && is_string( $item->{$type} ) ) {
			$this->open( $type, $item->{$type}, isset( $item->new_version ) ? $item->new_version : '' );
		}
	}

	/**
	 * Fires after all automatic updates have run. Logs WordPress's own results.
	 *
	 * @param array $results Results of all attempted updates, keyed by type.
	 * @return void
	 */
	public function on_automatic_complete( $results = array() ) {
		$entries = array();

		if ( is_array( $results ) && Update_Log::is_enabled() ) {
			foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
				if ( empty( $results[ $type ] ) || ! is_array( $results[ $type ] ) ) {
					continue;
				}

				foreach ( $results[ $type ] as $update ) {
					$entry = $this->entry_from_auto_result( $type, $update );
					if ( $entry ) {
						$entries[] = $entry;
					}
				}
			}
		}

		// The results above replace everything collected from the general hooks.
		$this->pending = array();
		$this->auto    = false;

		Update_Log::add( $entries );
	}

	/**
	 * Start of WP_Upgrader::run(). Opens a pending entry for plugin and theme updates.
	 *
	 * @param array $options Options used by the upgrader.
	 * @return array Unchanged.
	 */
	public function on_package_options( $options ) {
		if ( ! is_array( $options ) || empty( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
			return $options;
		}

		$extra = $options['hook_extra'];

		// Installs carry 'action' => 'install'; bulk updates carry no action at all.
		if ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) {
			return $options;
		}

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			if ( ! empty( $extra[ $type ] ) && is_string( $extra[ $type ] ) ) {
				$this->open( $type, $extra[ $type ] );
				break;
			}
		}

		return $options;
	}

	/**
	 * The download is about to begin, so the filesystem connection succeeded
	 * and the update has really started.
	 *
	 * @param mixed  $reply      Whether to bail without returning the package.
	 * @param string $package    The package file name.
	 * @param object $upgrader   The WP_Upgrader instance.
	 * @param array  $hook_extra Extra arguments passed to hooked filters.
	 * @return mixed Unchanged.
	 */
	public function on_pre_download( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
		if ( $upgrader instanceof \Core_Upgrader ) {
			$key = $this->open( 'core', '' );
		} else {
			$key = $this->key_from_extra( $hook_extra );
		}

		if ( isset( $this->pending[ $key ] ) ) {
			$this->pending[ $key ]['started']  = true;
			$this->pending[ $key ]['upgrader'] = is_object( $upgrader ) ? $upgrader : null;
		}

		return $reply;
	}

	/**
	 * End of a successful WP_Upgrader::install_package(). Only used as the
	 * success signal on WordPress versions before 5.7, which lack the
	 * upgrader_install_package_result filter.
	 *
	 * @param mixed $response   Installation response.
	 * @param array $hook_extra Extra arguments passed to hooked filters.
	 * @return mixed Unchanged.
	 */
	public function on_post_install( $response, $hook_extra = array() ) {
		$key = $this->key_from_extra( $hook_extra );

		if ( isset( $this->pending[ $key ] ) && ! is_wp_error( $response ) ) {
			$this->pending[ $key ]['installed'] = true;
		}

		return $response;
	}

	/**
	 * Final result of installing one plugin or theme package (WordPress 5.7+).
	 *
	 * @param mixed $result     Result array, or WP_Error on failure.
	 * @param array $hook_extra Extra arguments passed to hooked filters.
	 * @return mixed Unchanged.
	 */
	public function on_install_result( $result, $hook_extra = array() ) {
		$key = $this->key_from_extra( $hook_extra );

		if ( ! isset( $this->pending[ $key ] ) ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->resolve_failure( $key, $result );
		} else {
			$this->resolve_success( $key );
		}

		if ( ! $this->auto ) {
			$this->flush( array( $key ) );
		}

		return $result;
	}

	/**
	 * Fires when an upgrader run is complete.
	 *
	 * @param object $upgrader   The upgrader instance.
	 * @param array  $hook_extra Details of what was processed.
	 * @return void
	 */
	public function on_process_complete( $upgrader = null, $hook_extra = array() ) {
		if ( $this->auto || ! is_array( $hook_extra ) ) {
			return;
		}

		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return;
		}

		if ( isset( $hook_extra['type'] ) && 'core' === $hook_extra['type'] ) {
			$this->on_core_process_complete();
			return;
		}

		$keys = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			if ( ! empty( $hook_extra[ $type ] ) && is_string( $hook_extra[ $type ] ) ) {
				$keys[] = $this->key( $type, $hook_extra[ $type ] );
			}
			if ( ! empty( $hook_extra[ $type . 's' ] ) && is_array( $hook_extra[ $type . 's' ] ) ) {
				foreach ( $hook_extra[ $type . 's' ] as $item ) {
					if ( is_string( $item ) ) {
						$keys[] = $this->key( $type, $item );
					}
				}
			}
		}

		// With exactly one item, the skin holds that item's result.
		$error = null;
		if ( 1 === count( $keys ) && is_object( $upgrader ) && isset( $upgrader->skin->result ) && is_wp_error( $upgrader->skin->result ) ) {
			$error = $upgrader->skin->result;
		}

		foreach ( $keys as $key ) {
			if ( $error
				&& isset( $this->pending[ $key ] )
				&& '' === $this->pending[ $key ]['result']
				&& empty( $this->pending[ $key ]['installed'] )
				&& $this->pending[ $key ]['started']
			) {
				$this->resolve_failure( $key, $error );
			}
		}

		$this->flush( $keys );
	}

	/**
	 * Fires after WordPress core has been updated successfully.
	 *
	 * @param string $version The new WordPress version.
	 * @return void
	 */
	public function on_core_updated( $version = '' ) {
		$version = is_scalar( $version ) ? (string) $version : '';
		$key     = $this->key( 'core', '' );

		if ( $this->auto ) {
			/*
			 * Only used if automatic_updates_complete never fires. A rollback also
			 * fires this action, with the old version, so compare with the target.
			 */
			if ( isset( $this->pending[ $key ] ) && $version === $this->pending[ $key ]['target'] ) {
				$this->resolve_success( $key, $version );
			}
			return;
		}

		$this->core_done = true;
		$this->open( 'core', '' );

		if ( isset( $this->pending[ $key ] ) ) {
			$this->resolve_success( $key, $version );
			$this->flush( array( $key ) );
		}
	}

	/**
	 * Remembers what an uploaded ZIP is about to replace, so the old version
	 * can be logged. Runs for "Upload Plugin" / "Upload Theme" installs.
	 *
	 * @param mixed  $source        Path of the unpacked package, or WP_Error.
	 * @param string $remote_source Path of the working directory.
	 * @param object $upgrader      The WP_Upgrader instance.
	 * @param array  $hook_extra    Extra arguments passed to hooked filters.
	 * @return mixed Unchanged.
	 */
	public function on_source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		if ( ! is_string( $source )
			|| ! is_array( $hook_extra )
			|| ! isset( $hook_extra['action'], $hook_extra['type'] )
			|| 'install' !== $hook_extra['action']
			|| ! in_array( $hook_extra['type'], array( 'plugin', 'theme' ), true )
			|| ! Update_Log::is_enabled()
		) {
			return $source;
		}

		$folder          = wp_basename( untrailingslashit( $source ) );
		$this->overwrite = array(
			'type'     => $hook_extra['type'],
			'folder'   => $folder,
			'versions' => $this->read_folder_versions( $hook_extra['type'], $folder ),
		);

		return $source;
	}

	/**
	 * Fires when an uploaded ZIP has replaced an installed plugin or theme.
	 *
	 * @param string $package      The package file.
	 * @param array  $data         The new plugin or theme data.
	 * @param string $package_type 'plugin' or 'theme'.
	 * @return void
	 */
	public function on_overwrote_package( $package = '', $data = array(), $package_type = '' ) {
		if ( ! in_array( $package_type, array( 'plugin', 'theme' ), true ) || ! is_array( $data ) ) {
			return;
		}

		$name     = isset( $data['Name'] ) && is_scalar( $data['Name'] ) ? (string) $data['Name'] : '';
		$new      = isset( $data['Version'] ) && is_scalar( $data['Version'] ) ? (string) $data['Version'] : '';
		$old      = '';
		$folder   = '';
		$versions = array();

		if ( $this->overwrite && $package_type === $this->overwrite['type'] ) {
			$folder   = $this->overwrite['folder'];
			$versions = $this->overwrite['versions'];
		}

		if ( '' !== $name && isset( $versions[ $name ] ) ) {
			$old = $versions[ $name ];
		} elseif ( 1 === count( $versions ) ) {
			$old = reset( $versions );
		}

		$this->overwrite = array();

		Update_Log::add(
			array(
				array(
					't'      => time(),
					'type'   => $package_type,
					'item'   => $folder,
					'name'   => '' !== $name ? $name : $folder,
					'old'    => $old,
					'new'    => $new,
					'result' => 'success',
					'mode'   => 'manual',
					'code'   => 'uploaded',
					'note'   => '',
				),
			)
		);
	}

	/**
	 * Writes whatever is still pending. Runs on shutdown.
	 *
	 * @return void
	 */
	public function flush_all() {
		$this->flush( array_keys( $this->pending ) );
	}

	/**
	 * Handles the end of a manual core update.
	 *
	 * Core_Upgrader fires upgrader_process_complete whether update_core()
	 * succeeded or returned an error. Success always fires
	 * _core_updated_successfully first, so reaching this point without it
	 * means the update failed.
	 *
	 * @return void
	 */
	private function on_core_process_complete() {
		$key = $this->key( 'core', '' );

		if ( ! $this->core_done ) {
			$this->open( 'core', '' );

			if ( isset( $this->pending[ $key ] ) ) {
				$this->pending[ $key ]['started'] = true;
				$this->resolve_failure( $key, null, 'core_no_reason' );
			}
		}

		$this->core_done = false;
		$this->flush( array( $key ) );
	}

	/**
	 * Builds a log entry from one of WP_Automatic_Updater's results.
	 *
	 * @param string $type   'core', 'plugin' or 'theme'.
	 * @param mixed  $update Result object with item, result, name and messages.
	 * @return array|null
	 */
	private function entry_from_auto_result( $type, $update ) {
		if ( ! is_object( $update ) || ! isset( $update->item ) || ! is_object( $update->item ) ) {
			return null;
		}

		$item   = $update->item;
		$result = isset( $update->result ) ? $update->result : null;

		if ( 'core' === $type ) {
			$id      = '';
			$name    = 'WordPress';
			$target  = isset( $item->current ) && is_scalar( $item->current ) ? (string) $item->current : '';
			$old     = $this->running_core;
			$success = ! is_wp_error( $result );
			if ( $success && is_string( $result ) && '' !== $result ) {
				$target = $result;
			}
		} else {
			if ( empty( $item->{$type} ) || ! is_string( $item->{$type} ) ) {
				return null;
			}
			$id      = $item->{$type};
			$name    = isset( $update->name ) && is_scalar( $update->name ) && '' !== (string) $update->name ? (string) $update->name : $id;
			$target  = isset( $item->new_version ) && is_scalar( $item->new_version ) ? (string) $item->new_version : '';
			$old     = isset( $item->current_version ) && is_string( $item->current_version ) ? $item->current_version : '';
			$success = ( true === $result );
		}

		$key = $this->key( $type, $id );
		if ( isset( $this->pending[ $key ] ) && '' !== $this->pending[ $key ]['old'] ) {
			// Read from disk just before the update, so preferred over the offer's data.
			$old = $this->pending[ $key ]['old'];
		}

		$entry = array(
			't'      => time(),
			'type'   => $type,
			'item'   => $id,
			'name'   => $name,
			'old'    => $old,
			'new'    => $target,
			'result' => 'success',
			'mode'   => 'auto',
			'code'   => '',
			'note'   => '',
		);

		if ( ! $success ) {
			$entry = array_merge( $entry, $this->describe_error( $result ) );

			/*
			 * Some failures (a failed download, for example) reach this point as null
			 * rather than as a WP_Error. WordPress keeps the reason only as free text
			 * among the item's progress messages, with nothing marking which line is
			 * the error, so no reason is recorded rather than guessing at one.
			 */
			if ( '' === $entry['code'] && '' === $entry['note'] ) {
				$entry['code'] = 'no_reason';
			}
		}

		return $entry;
	}

	/**
	 * Works out result, code and note from an update error.
	 *
	 * @param mixed $error WP_Error, or anything else for an unexplained failure.
	 * @return array
	 */
	private function describe_error( $error ) {
		$described = array(
			'result' => 'failed',
			'code'   => '',
			'note'   => '',
		);

		if ( ! is_wp_error( $error ) ) {
			return $described;
		}

		$codes             = $error->get_error_codes();
		$described['code'] = (string) $error->get_error_code();
		$described['note'] = (string) $error->get_error_message();

		// An automatic plugin update caused a fatal error and the previous version was restored.
		if ( in_array( 'plugin_update_fatal_error_rollback_successful', $codes, true ) ) {
			$described['result'] = 'rolled_back';
			$described['code']   = 'plugin_update_fatal_error_rollback_successful';
			$described['note']   = (string) $error->get_error_message( 'plugin_update_fatal_error_rollback_successful' );

			return $described;
		}

		// An automatic core update failed part-way and WordPress attempted a rollback.
		if ( in_array( 'rollback_was_required', $codes, true ) ) {
			$data = $error->get_error_data( 'rollback_was_required' );

			if ( is_object( $data ) ) {
				if ( isset( $data->rollback ) && $data->rollback && ! is_wp_error( $data->rollback ) ) {
					$described['result'] = 'rolled_back';
				}
				if ( isset( $data->update ) && is_wp_error( $data->update ) ) {
					$described['code'] = (string) $data->update->get_error_code();
					$described['note'] = (string) $data->update->get_error_message();
				}
			}
		}

		return $described;
	}

	/**
	 * Opens a pending entry unless one exists already.
	 *
	 * @param string $type   'core', 'plugin' or 'theme'.
	 * @param string $item   Plugin file or theme directory; '' for core.
	 * @param mixed  $target Version being installed, when known.
	 * @return string The pending key.
	 */
	private function open( $type, $item, $target = '' ) {
		$key = $this->key( $type, $item );

		if ( isset( $this->pending[ $key ] ) || ! Update_Log::is_enabled() ) {
			return $key;
		}

		$installed = $this->read_installed( $type, $item );
		$target    = is_scalar( $target ) ? (string) $target : '';

		if ( '' === $target ) {
			$target = $this->offered_version( $type, $item );
		}

		$this->pending[ $key ] = array(
			't'         => 0,
			'type'      => $type,
			'item'      => $item,
			'name'      => $installed['name'],
			'old'       => $installed['version'],
			'new'       => '',
			'result'    => '',
			'mode'      => $this->auto ? 'auto' : 'manual',
			'code'      => '',
			'note'      => '',
			'target'    => $target,
			'started'   => false,
			'installed' => false,
			'upgrader'  => null,
		);

		return $key;
	}

	/**
	 * Marks a pending entry as succeeded.
	 *
	 * @param string $key Pending key.
	 * @param string $new_version Optional. New version, when the caller knows it.
	 * @return void
	 */
	private function resolve_success( $key, $new_version = '' ) {
		$pending = $this->pending[ $key ];

		if ( '' === $new_version && 'core' !== $pending['type'] ) {
			$installed   = $this->read_installed( $pending['type'], $pending['item'] );
			$new_version = $installed['version'];
		}

		if ( '' === $new_version ) {
			$new_version = $pending['target'];
		}

		$pending['t']      = time();
		$pending['new']    = $new_version;
		$pending['result'] = 'success';
		$pending['code']   = '';
		$pending['note']   = '';

		$this->pending[ $key ] = $pending;
	}

	/**
	 * Marks a pending entry as failed.
	 *
	 * @param string $key   Pending key.
	 * @param mixed  $error Optional. WP_Error describing the failure.
	 * @param string $code  Optional. Detail code to use when there is no WP_Error.
	 * @return void
	 */
	private function resolve_failure( $key, $error = null, $code = '' ) {
		$pending   = $this->pending[ $key ];
		$described = $this->describe_error( $error );

		if ( '' === $described['code'] ) {
			$described['code'] = $code;
		}

		$pending['t']   = time();
		$pending['new'] = $pending['target'];

		$this->pending[ $key ] = array_merge( $pending, $described );
	}

	/**
	 * Writes the given pending entries to the log and forgets them.
	 *
	 * @param string[] $keys Pending keys.
	 * @return void
	 */
	private function flush( array $keys ) {
		$entries = array();

		foreach ( array_unique( $keys ) as $key ) {
			if ( ! isset( $this->pending[ $key ] ) ) {
				continue;
			}

			if ( '' === $this->pending[ $key ]['result'] ) {
				if ( $this->pending[ $key ]['installed'] ) {
					$this->resolve_success( $key );
				} elseif ( $this->pending[ $key ]['started'] ) {
					// Started downloading but never reported a result.
					$this->resolve_failure( $key, $this->skin_error( $this->pending[ $key ]['upgrader'] ), 'incomplete' );
				}
			}

			$pending = $this->pending[ $key ];
			unset( $this->pending[ $key ] );

			// Never got as far as downloading (for example, filesystem credentials were requested).
			if ( '' === $pending['result'] ) {
				continue;
			}

			unset( $pending['target'], $pending['started'], $pending['installed'], $pending['upgrader'] );
			$entries[] = $pending;
		}

		Update_Log::add( $entries );
	}

	/**
	 * Returns the errors an upgrader's skin collected, when the skin keeps them.
	 *
	 * The skins used for background and AJAX updates store their errors; the
	 * ones that print straight to the screen do not.
	 *
	 * @param mixed $upgrader The WP_Upgrader instance, or null.
	 * @return \WP_Error|null
	 */
	private function skin_error( $upgrader ) {
		if ( ! is_object( $upgrader ) || ! isset( $upgrader->skin ) || ! is_object( $upgrader->skin ) ) {
			return null;
		}

		if ( ! method_exists( $upgrader->skin, 'get_errors' ) ) {
			return null;
		}

		$errors = $upgrader->skin->get_errors();

		return ( is_wp_error( $errors ) && $errors->has_errors() ) ? $errors : null;
	}

	/**
	 * Builds a pending key.
	 *
	 * @param string $type 'core', 'plugin' or 'theme'.
	 * @param string $item Plugin file or theme directory; '' for core.
	 * @return string
	 */
	private function key( $type, $item ) {
		return $type . ':' . $item;
	}

	/**
	 * Finds the pending key for an upgrader's $hook_extra.
	 *
	 * @param mixed $hook_extra Extra arguments passed to hooked filters.
	 * @return string Key, or '' when there is no matching pending entry.
	 */
	private function key_from_extra( $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return '';
		}

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			if ( ! empty( $hook_extra[ $type ] ) && is_string( $hook_extra[ $type ] ) ) {
				$key = $this->key( $type, $hook_extra[ $type ] );

				return isset( $this->pending[ $key ] ) ? $key : '';
			}
		}

		return '';
	}

	/**
	 * Reads the name and version of what is installed right now, from disk.
	 *
	 * File headers are read directly rather than through get_plugin_data() or
	 * wp_get_theme(), which may be unavailable or cached during an update.
	 *
	 * @param string $type 'core', 'plugin' or 'theme'.
	 * @param string $item Plugin file or theme directory; '' for core.
	 * @return array {
	 *     @type string $name    Display name, falling back to $item.
	 *     @type string $version Version, or '' when it cannot be read.
	 * }
	 */
	private function read_installed( $type, $item ) {
		if ( 'core' === $type ) {
			return array(
				'name'    => 'WordPress',
				'version' => $this->running_core,
			);
		}

		$installed = array(
			'name'    => $item,
			'version' => '',
		);

		if ( '' === $item || 0 !== validate_file( $item ) ) {
			return $installed;
		}

		if ( 'plugin' === $type ) {
			$file    = WP_PLUGIN_DIR . '/' . $item;
			$headers = array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
			);
		} else {
			$file    = get_theme_root( $item ) . '/' . $item . '/style.css';
			$headers = array(
				'name'    => 'Theme Name',
				'version' => 'Version',
			);
		}

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return $installed;
		}

		$data = get_file_data( $file, $headers, $type );

		if ( ! empty( $data['name'] ) ) {
			$installed['name'] = $data['name'];
		}
		if ( ! empty( $data['version'] ) ) {
			$installed['version'] = $data['version'];
		}

		return $installed;
	}

	/**
	 * Returns the version WordPress has on offer for a plugin or theme.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $item Plugin file or theme directory.
	 * @return string Version, or '' when unknown.
	 */
	private function offered_version( $type, $item ) {
		if ( 'plugin' !== $type && 'theme' !== $type ) {
			return '';
		}

		$updates = get_site_transient( 'update_' . $type . 's' );

		if ( ! is_object( $updates ) || ! isset( $updates->response[ $item ] ) ) {
			return '';
		}

		$offer = (array) $updates->response[ $item ];

		return isset( $offer['new_version'] ) && is_scalar( $offer['new_version'] ) ? (string) $offer['new_version'] : '';
	}

	/**
	 * Lists the plugins or the theme found in an installed folder.
	 *
	 * @param string $type   'plugin' or 'theme'.
	 * @param string $folder Folder name inside the plugins or themes directory.
	 * @return array Name => version.
	 */
	private function read_folder_versions( $type, $folder ) {
		$versions = array();

		if ( '' === $folder || 0 !== validate_file( $folder ) ) {
			return $versions;
		}

		if ( 'theme' === $type ) {
			$installed = $this->read_installed( 'theme', $folder );
			if ( '' !== $installed['version'] ) {
				$versions[ $installed['name'] ] = $installed['version'];
			}

			return $versions;
		}

		$files = glob( WP_PLUGIN_DIR . '/' . $folder . '/*.php' );

		foreach ( array_slice( (array) $files, 0, 100 ) as $file ) {
			$item      = $folder . '/' . wp_basename( $file );
			$installed = $this->read_installed( 'plugin', $item );

			// read_installed() falls back to the path when there is no "Plugin Name" header.
			if ( $installed['name'] !== $item && '' !== $installed['version'] ) {
				$versions[ $installed['name'] ] = $installed['version'];
			}
		}

		return $versions;
	}
}
