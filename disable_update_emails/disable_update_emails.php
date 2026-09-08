<?php
/**
 * Plugin Name:       Disable Auto-Update Success Emails
 * Description:       Suppresses "update succeeded" emails for core, plugins, and themes while preserving failure and critical notices.
 * Version:           2.0
 * Author:            Advanced Micro Technologies, LLC
 * Author URI:        https://proadvanced.com
 * Update URI:        false
 * Requires at least: 5.5
 * Requires PHP:      7.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * Copyright (C) 2026 Advanced Micro Technologies, LLC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns true if any update in the result set failed.
 *
 * @param array $update_results Array of update result objects.
 * @return bool
 */
function amt_update_batch_has_failure( $update_results ) {
	foreach ( (array) $update_results as $update_result ) {
		if ( ! isset( $update_result->result ) || true !== $update_result->result ) {
			return true;
		}
	}

	return false;
}

/**
 * Core auto-update emails.
 * $type is one of: success, fail, critical, manual.
 */
add_filter(
	'auto_core_update_send_email',
	static function ( $send, $type ) {
		return 'success' !== $type;
	},
	10,
	2
);

/**
 * Plugin auto-update emails. Send only if at least one update failed.
 */
add_filter(
	'auto_plugin_update_send_email',
	static function ( $send, $update_results ) {
		return amt_update_batch_has_failure( $update_results );
	},
	10,
	2
);

/**
 * Theme auto-update emails. Send only if at least one update failed.
 */
add_filter(
	'auto_theme_update_send_email',
	static function ( $send, $update_results ) {
		return amt_update_batch_has_failure( $update_results );
	},
	10,
	2
);

/**
 * Verbose debug email (dev installs / dev-channel core updates).
 */
add_filter( 'automatic_updates_send_debug_email', '__return_false' );

/**
 * Uncomment to also silence the "a new version is available" notice for
 * updates that were not applied automatically.
 */
add_filter( 'send_core_update_notification_email', '__return_false' );