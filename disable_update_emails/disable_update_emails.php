<?php
/**
 * Plugin Name:       Disable Auto-Update Success Emails
 * Description:       Suppresses "update succeeded" emails for core, plugins, and themes while preserving failure and critical notices.
 * Version:           2.1
 * Author:            Advanced Micro Technologies, LLC
 * Author URI:        https://proadvanced.com
 * Update URI:        false
 * Requires at least: 5.5.1
 * Requires PHP:      7.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 Advanced Micro Technologies, LLC
 */

defined( 'ABSPATH' ) || exit;

// Bail if already loaded (e.g. installed both as a must-use plugin and a regular plugin).
if ( defined( 'AMT_DISABLE_UPDATE_EMAILS_LOADED' ) ) {
	return;
}
define( 'AMT_DISABLE_UPDATE_EMAILS_LOADED', true );

// Declared conditionally: PHP hoists unconditional top-level functions, so the
// early return above would not prevent a "cannot redeclare" fatal error.
if ( ! function_exists( 'amt_update_batch_has_failure' ) ) {
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
}

/**
 * Core auto-update emails.
 * $type is one of: success, fail, critical. (The "manual" / new-version-available
 * email bypasses this filter; see send_core_update_notification_email below.)
 *
 * Respects an earlier decision by another plugin to suppress the email.
 */
add_filter(
	'auto_core_update_send_email',
	static function ( $send, $type ) {
		return $send && 'success' !== $type;
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
		return $send && amt_update_batch_has_failure( $update_results );
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
		return $send && amt_update_batch_has_failure( $update_results );
	},
	10,
	2
);

/**
 * Verbose debug email (dev installs / dev-channel core updates).
 */
add_filter( 'automatic_updates_send_debug_email', '__return_false' );

/**
 * "A new version of WordPress is available" email, sent when a core update
 * was not applied automatically (e.g. a major release on a minor-only site).
 * Comment out the line below to keep receiving these notices.
 */
add_filter( 'send_core_update_notification_email', '__return_false' );
