<?php
/**
 * Settings: defaults, storage, overrides and sanitising.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and validates the plugin's settings.
 *
 * Precedence, highest first: the PROADVANCED_UPDATE_EMAILS constant, the
 * proadvanced_update_emails_settings filter, the saved option, the defaults
 * in code. Nothing is written to the database until the settings are saved.
 */
class Settings {

	/**
	 * Option name (a site option on multisite).
	 */
	const OPTION = 'proadvanced_update_emails_settings';

	/**
	 * Settings API option group.
	 */
	const GROUP = 'proadvanced_update_emails';

	/**
	 * Maximum length of a subject tag.
	 */
	const TAG_MAX_LENGTH = 60;

	/**
	 * Whether sanitize() already reported rejected addresses in this request.
	 *
	 * @var bool
	 */
	private static $reported_invalid = false;

	/**
	 * Default settings. These apply until a setting is saved or overridden.
	 *
	 * For the email toggles, true means "leave WordPress's decision alone" and
	 * false means "suppress".
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'core_success'   => false,
			'core_fail'      => true,
			'core_critical'  => true,
			'core_available' => true,
			'plugin_success' => false,
			'plugin_fail'    => true,
			'theme_success'  => false,
			'theme_fail'     => true,
			'debug_email'    => 'suppress',
			'recipients'     => '',
			'subject_tags'   => false,
			'tag_failed'     => '[UPDATE FAILED]',
			'tag_available'  => '[UPDATE AVAILABLE]',
			'tag_ok'         => '[UPDATE OK]',
			'log_enabled'    => true,
		);
	}

	/**
	 * Keys of the on/off email toggles, in display order.
	 *
	 * @return string[]
	 */
	public static function email_toggle_keys() {
		return array(
			'core_success',
			'core_fail',
			'core_critical',
			'core_available',
			'plugin_success',
			'plugin_fail',
			'theme_success',
			'theme_fail',
		);
	}

	/**
	 * Human-readable names of the emails this plugin controls.
	 *
	 * @return array Setting key => label.
	 */
	public static function email_labels() {
		return array(
			'core_success'   => __( 'Core auto-update succeeded', 'quiet-update-emails' ),
			'core_fail'      => __( 'Core auto-update failed', 'quiet-update-emails' ),
			'core_critical'  => __( 'Core auto-update critical failure', 'quiet-update-emails' ),
			'core_available' => __( 'Core update available (not applied automatically)', 'quiet-update-emails' ),
			'plugin_success' => __( 'Plugin auto-updates, all succeeded', 'quiet-update-emails' ),
			'plugin_fail'    => __( 'Plugin auto-updates, one or more failed', 'quiet-update-emails' ),
			'theme_success'  => __( 'Theme auto-updates, all succeeded', 'quiet-update-emails' ),
			'theme_fail'     => __( 'Theme auto-updates, one or more failed', 'quiet-update-emails' ),
			'debug_email'    => __( 'Automatic update debug email', 'quiet-update-emails' ),
		);
	}

	/**
	 * Whether settings are stored network-wide.
	 *
	 * @return bool
	 */
	public static function is_network() {
		return is_multisite();
	}

	/**
	 * Capability required to manage the settings and the log.
	 *
	 * @return string
	 */
	public static function capability() {
		return self::is_network() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Registers the setting so the Settings API and direct option writes
	 * (for example through WP-CLI) both go through sanitize().
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Returns the saved settings exactly as stored (known keys only, not validated).
	 *
	 * @return array
	 */
	public static function get_saved() {
		$saved = self::is_network() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );

		return is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array();
	}

	/**
	 * Returns the values fixed by the PROADVANCED_UPDATE_EMAILS constant.
	 *
	 * @return array Known keys only, not validated.
	 */
	public static function get_constant() {
		if ( ! defined( 'PROADVANCED_UPDATE_EMAILS' ) ) {
			return array();
		}

		$constant = constant( 'PROADVANCED_UPDATE_EMAILS' );

		return is_array( $constant ) ? array_intersect_key( $constant, self::defaults() ) : array();
	}

	/**
	 * Whether a setting is fixed by the constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_locked( $key ) {
		return array_key_exists( $key, self::get_constant() );
	}

	/**
	 * Returns the saved settings merged over the defaults, before the filter
	 * and the constant are applied. This is what the settings form edits.
	 *
	 * @return array
	 */
	public static function get_stored() {
		return self::merge( self::defaults(), self::get_saved() );
	}

	/**
	 * Returns the effective settings.
	 *
	 * @return array
	 */
	public static function get_all() {
		$settings = self::get_stored();

		/**
		 * Filters the Quiet Update Emails settings.
		 *
		 * Runs after the saved option is merged over the defaults and before the
		 * PROADVANCED_UPDATE_EMAILS constant is applied. Unknown keys and invalid
		 * values are ignored.
		 *
		 * @since 3.0.0
		 *
		 * @param array $settings Settings array. See Settings::defaults() for the keys.
		 */
		$filtered = apply_filters( 'proadvanced_update_emails_settings', $settings );

		if ( is_array( $filtered ) ) {
			$settings = self::merge( $settings, $filtered );
		}

		return self::merge( $settings, self::get_constant() );
	}

	/**
	 * Returns one effective setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null for an unknown key.
	 */
	public static function get( $key ) {
		$settings = self::get_all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Returns the effective override recipients.
	 *
	 * @return string[] Empty when WordPress's default recipient applies.
	 */
	public static function recipients() {
		$parsed = self::parse_recipients( self::get( 'recipients' ) );

		return $parsed['valid'];
	}

	/**
	 * Returns the address WordPress itself sends update emails to.
	 *
	 * @return string
	 */
	public static function default_recipient() {
		return (string) get_site_option( 'admin_email' );
	}

	/**
	 * Returns the addresses update emails currently go to.
	 *
	 * @return string[]
	 */
	public static function effective_recipients() {
		$recipients = self::recipients();

		return $recipients ? $recipients : array( self::default_recipient() );
	}

	/**
	 * Splits a recipient list into valid and rejected addresses.
	 *
	 * @param mixed $raw Comma-separated string or array of addresses.
	 * @return array {
	 *     @type string[] $valid   Valid, unique addresses.
	 *     @type string[] $invalid Rejected entries.
	 * }
	 */
	public static function parse_recipients( $raw ) {
		$result = array(
			'valid'   => array(),
			'invalid' => array(),
		);

		if ( is_array( $raw ) ) {
			$parts = $raw;
		} elseif ( is_string( $raw ) ) {
			$parts = preg_split( '/[,;\r\n]+/', $raw );
		} else {
			return $result;
		}

		foreach ( (array) $parts as $part ) {
			if ( ! is_scalar( $part ) ) {
				continue;
			}

			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}

			$email = sanitize_email( $part );
			if ( '' !== $email && $email === $part && is_email( $email ) ) {
				$result['valid'][] = $email;
			} else {
				$result['invalid'][] = sanitize_text_field( $part );
			}
		}

		$result['valid'] = array_values( array_unique( $result['valid'] ) );

		return $result;
	}

	/**
	 * Sanitize callback for the option.
	 *
	 * Keys missing from the input are left out of the stored array, so they
	 * keep following the defaults. That makes partial writes such as
	 * `wp option update` with a single key behave as expected. Keys fixed by
	 * the constant are disabled in the form and therefore never submitted;
	 * their previously saved value is kept.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$saved    = self::get_saved();
		$defaults = self::defaults();
		$output   = array();
		$rejected = array();

		foreach ( $defaults as $key => $default ) {
			if ( array_key_exists( $key, $input ) ) {
				if ( 'recipients' === $key ) {
					$parsed   = self::parse_recipients( $input[ $key ] );
					$rejected = $parsed['invalid'];
				}
				$output[ $key ] = self::normalize( $key, $input[ $key ], $default );
			} elseif ( self::is_locked( $key ) && array_key_exists( $key, $saved ) ) {
				$output[ $key ] = self::normalize( $key, $saved[ $key ], $default );
			}
		}

		if ( $rejected && ! self::$reported_invalid && function_exists( 'add_settings_error' ) ) {
			self::$reported_invalid = true;

			add_settings_error(
				self::OPTION,
				'proadvanced_update_emails_invalid_recipient',
				sprintf(
					/* translators: %s: comma-separated list of rejected email addresses. */
					__( 'Settings saved, but these recipient addresses are not valid and were left out: %s', 'quiet-update-emails' ),
					implode( ', ', $rejected )
				),
				'warning'
			);
		}

		return $output;
	}

	/**
	 * Overlays known keys from $overrides onto $base, validating each value.
	 *
	 * @param array $base      Complete, valid settings.
	 * @param array $overrides Values to overlay.
	 * @return array
	 */
	private static function merge( array $base, array $overrides ) {
		foreach ( $base as $key => $current ) {
			if ( array_key_exists( $key, $overrides ) ) {
				$base[ $key ] = self::normalize( $key, $overrides[ $key ], $current );
			}
		}

		return $base;
	}

	/**
	 * Validates one value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $value    Raw value.
	 * @param mixed  $fallback Value to use when $value is not acceptable.
	 * @return mixed
	 */
	private static function normalize( $key, $value, $fallback ) {
		switch ( $key ) {
			case 'debug_email':
				// There is deliberately no value that forces the debug email on.
				return in_array( $value, array( 'suppress', 'follow' ), true ) ? $value : $fallback;

			case 'recipients':
				if ( ! is_string( $value ) && ! is_array( $value ) ) {
					return $fallback;
				}
				$parsed = self::parse_recipients( $value );

				return implode( ', ', $parsed['valid'] );

			case 'tag_failed':
			case 'tag_available':
			case 'tag_ok':
				if ( ! is_scalar( $value ) ) {
					return $fallback;
				}
				// WordPress provides mb_substr() even when the mbstring extension is missing.
				$tag = mb_substr( sanitize_text_field( (string) $value ), 0, self::TAG_MAX_LENGTH );

				return trim( $tag );

			default:
				if ( ! is_scalar( $value ) && null !== $value ) {
					return $fallback;
				}

				return (bool) wp_validate_boolean( $value );
		}
	}
}
