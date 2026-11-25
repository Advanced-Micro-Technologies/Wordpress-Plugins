<?php
/**
 * Removes everything Quiet Update Emails stored when the plugin is deleted.
 *
 * @package QuietUpdateEmails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Single site: regular options.
delete_option( 'proadvanced_update_emails_settings' );
delete_option( 'proadvanced_update_emails_log' );

// Multisite: network options. On a single site these calls fall back to delete_option().
delete_site_option( 'proadvanced_update_emails_settings' );
delete_site_option( 'proadvanced_update_emails_log' );

// A write lock left behind by a request that died, if any.
delete_option( 'proadvanced_update_emails_log.lock' );

// The daily log pruning event.
wp_clear_scheduled_hook( 'proadvanced_update_emails_prune_log' );
