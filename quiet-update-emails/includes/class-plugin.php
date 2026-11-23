<?php
/**
 * Plugin bootstrap.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's parts into WordPress.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks were registered already.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		( new Email_Filters() )->register();
		( new Update_Tracker() )->register();
		( new Site_Health() )->register();

		add_action( 'init', array( Settings::class, 'register' ) );
		add_action( 'init', array( Update_Log::class, 'maybe_schedule' ) );
		add_action( 'proadvanced_update_emails_prune_log', array( Update_Log::class, 'prune' ) );

		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}

		// Has no effect for a must-use install, which is why the event is also scheduled lazily.
		register_deactivation_hook( PROADVANCED_UPDATE_EMAILS_FILE, array( Update_Log::class, 'unschedule' ) );
	}

	/**
	 * Whether the predecessor plugin ("Disable Auto-Update Success Emails") is loaded.
	 *
	 * @return bool
	 */
	public static function is_old_plugin_loaded() {
		return defined( 'AMT_DISABLE_UPDATE_EMAILS_LOADED' );
	}
}
