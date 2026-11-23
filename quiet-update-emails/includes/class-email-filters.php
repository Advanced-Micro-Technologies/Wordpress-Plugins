<?php
/**
 * Filters that decide which update emails are sent, to whom, and with which subject.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into WP_Automatic_Updater's email filters.
 *
 * Every "send" filter here only ever turns an email off. A value that is
 * already false (for example because another plugin suppressed the email) is
 * passed through untouched, and an enabled toggle returns WordPress's own
 * decision unchanged.
 */
class Email_Filters {

	/**
	 * Registers the filters.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'auto_core_update_send_email', array( $this, 'filter_core_send' ), 10, 2 );
		add_filter( 'send_core_update_notification_email', array( $this, 'filter_core_available' ) );
		add_filter( 'auto_plugin_update_send_email', array( $this, 'filter_plugin_send' ), 10, 2 );
		add_filter( 'auto_theme_update_send_email', array( $this, 'filter_theme_send' ), 10, 2 );
		add_filter( 'automatic_updates_send_debug_email', array( $this, 'filter_debug_send' ) );

		add_filter( 'auto_core_update_email', array( $this, 'filter_core_email' ), 10, 2 );
		add_filter( 'auto_plugin_theme_update_email', array( $this, 'filter_plugin_theme_email' ), 10, 2 );
		add_filter( 'automatic_updates_debug_email', array( $this, 'filter_debug_email' ), 10, 2 );
	}

	/**
	 * Core auto-update result emails.
	 *
	 * The "manual" type (a new version is available) never reaches this filter;
	 * WordPress routes it through send_core_update_notification_email instead.
	 *
	 * @param bool   $send Whether to send the email.
	 * @param string $type One of 'success', 'fail' or 'critical'.
	 * @return bool
	 */
	public function filter_core_send( $send, $type = '' ) {
		if ( ! $send ) {
			return $send;
		}

		$map = array(
			'success'  => 'core_success',
			'fail'     => 'core_fail',
			'critical' => 'core_critical',
		);

		if ( ! is_string( $type ) || ! isset( $map[ $type ] ) ) {
			return $send;
		}

		return Settings::get( $map[ $type ] ) ? $send : false;
	}

	/**
	 * "A new version of WordPress is available" email, sent when a core update
	 * was not applied automatically.
	 *
	 * @param bool $notify Whether WordPress intends to notify the administrator.
	 * @return bool
	 */
	public function filter_core_available( $notify ) {
		if ( ! $notify ) {
			return $notify;
		}

		return Settings::get( 'core_available' ) ? $notify : false;
	}

	/**
	 * Plugin auto-update email. Judged for the whole batch.
	 *
	 * @param bool  $send           Whether to send the email.
	 * @param array $update_results Results of the plugin update tasks.
	 * @return bool
	 */
	public function filter_plugin_send( $send, $update_results = array() ) {
		return $this->filter_batch_send( $send, $update_results, 'plugin' );
	}

	/**
	 * Theme auto-update email. Judged for the whole batch.
	 *
	 * @param bool  $send           Whether to send the email.
	 * @param array $update_results Results of the theme update tasks.
	 * @return bool
	 */
	public function filter_theme_send( $send, $update_results = array() ) {
		return $this->filter_batch_send( $send, $update_results, 'theme' );
	}

	/**
	 * Verbose debug email. WordPress only sends it by default on development
	 * builds (a version string containing a hyphen).
	 *
	 * @param bool $send Whether to send the debug email.
	 * @return bool
	 */
	public function filter_debug_send( $send ) {
		// "Follow WordPress" returns the value untouched. The email is never forced on.
		if ( 'follow' === Settings::get( 'debug_email' ) ) {
			return $send;
		}

		return false;
	}

	/**
	 * Core update emails: recipient and subject tag.
	 *
	 * @param array  $email Email arguments for wp_mail().
	 * @param string $type  One of 'success', 'fail', 'manual' or 'critical'.
	 * @return array
	 */
	public function filter_core_email( $email, $type = '' ) {
		$tags = array(
			'success'  => 'tag_ok',
			'fail'     => 'tag_failed',
			'critical' => 'tag_failed',
			'manual'   => 'tag_available',
		);

		$tag_key = ( is_string( $type ) && isset( $tags[ $type ] ) ) ? $tags[ $type ] : '';

		return $this->adjust_email( $email, $tag_key );
	}

	/**
	 * Plugin and theme update emails: recipient and subject tag.
	 *
	 * @param array  $email Email arguments for wp_mail().
	 * @param string $type  One of 'success', 'fail' or 'mixed'.
	 * @return array
	 */
	public function filter_plugin_theme_email( $email, $type = '' ) {
		$tag_key = '';
		if ( 'success' === $type ) {
			$tag_key = 'tag_ok';
		} elseif ( 'fail' === $type || 'mixed' === $type ) {
			$tag_key = 'tag_failed';
		}

		return $this->adjust_email( $email, $tag_key );
	}

	/**
	 * Debug email: recipient and subject tag.
	 *
	 * @param array $email    Email arguments for wp_mail().
	 * @param int   $failures Number of failures in the run.
	 * @return array
	 */
	public function filter_debug_email( $email, $failures = 0 ) {
		return $this->adjust_email( $email, (int) $failures > 0 ? 'tag_failed' : 'tag_ok' );
	}

	/**
	 * Returns true if any update in the result set did not succeed.
	 *
	 * @param mixed $update_results Array of update result objects.
	 * @return bool
	 */
	public static function batch_has_failure( $update_results ) {
		foreach ( (array) $update_results as $update_result ) {
			if ( ! isset( $update_result->result ) || true !== $update_result->result ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shared logic for the plugin and theme batch filters.
	 *
	 * @param bool   $send           Whether to send the email.
	 * @param mixed  $update_results Results for this batch.
	 * @param string $type           'plugin' or 'theme'.
	 * @return bool
	 */
	private function filter_batch_send( $send, $update_results, $type ) {
		if ( ! $send ) {
			return $send;
		}

		$key = self::batch_has_failure( $update_results ) ? $type . '_fail' : $type . '_success';

		return Settings::get( $key ) ? $send : false;
	}

	/**
	 * Applies the recipient override and the subject tag to an email.
	 *
	 * @param mixed  $email   Email arguments for wp_mail().
	 * @param string $tag_key Setting key of the tag to use, or an empty string.
	 * @return mixed
	 */
	private function adjust_email( $email, $tag_key ) {
		if ( ! is_array( $email ) ) {
			return $email;
		}

		$settings   = Settings::get_all();
		$recipients = Settings::parse_recipients( $settings['recipients'] );

		if ( $recipients['valid'] ) {
			$email['to'] = $recipients['valid'];
		}

		if ( $settings['subject_tags']
			&& '' !== $tag_key
			&& '' !== $settings[ $tag_key ]
			&& isset( $email['subject'] )
			&& is_string( $email['subject'] )
		) {
			$email['subject'] = $settings[ $tag_key ] . ' ' . $email['subject'];
		}

		return $email;
	}
}
