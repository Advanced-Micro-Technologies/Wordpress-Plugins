<?php
/**
 * Site Health integration.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Site Health status entry and a debug information section.
 */
class Site_Health {

	/**
	 * Registers the filters.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * Adds the status test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['proadvanced_update_emails'] = array(
			'label' => __( 'Update emails', 'quiet-update-emails' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Runs the status test.
	 *
	 * @return array
	 */
	public function run_test() {
		$summary    = self::summary();
		$recipients = implode( ', ', Settings::effective_recipients() );

		if ( $summary['suppressed'] ) {
			$label = __( 'Quiet Update Emails is suppressing routine update emails', 'quiet-update-emails' );
		} else {
			$label = __( 'Quiet Update Emails is not suppressing any update emails', 'quiet-update-emails' );
		}

		$description  = '<p>' . esc_html__( 'These update emails are suppressed by this plugin:', 'quiet-update-emails' ) . '</p>';
		$description .= self::html_list( $summary['suppressed'] );
		$description .= '<p>' . esc_html__( 'These update emails are left to WordPress, which sends them when it decides they are needed:', 'quiet-update-emails' ) . '</p>';
		$description .= self::html_list( $summary['sent'] );
		$description .= '<p>' . sprintf(
			/* translators: %s: comma-separated list of email addresses. */
			esc_html__( 'Update emails that are sent go to: %s', 'quiet-update-emails' ),
			'<strong>' . esc_html( $recipients ) . '</strong>'
		) . '</p>';

		$result = array(
			'label'       => $label,
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Updates', 'quiet-update-emails' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'proadvanced_update_emails',
		);

		if ( current_user_can( Settings::capability() ) ) {
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( Admin_Page::url() ),
				esc_html__( 'Review update email settings', 'quiet-update-emails' )
			);
		}

		if ( Plugin::is_old_plugin_loaded() ) {
			$result['status']       = 'recommended';
			$result['label']        = __( 'Two update email plugins are active', 'quiet-update-emails' );
			$result['description'] .= '<p>' . esc_html__( 'The older plugin "Disable Auto-Update Success Emails" is still loaded. It suppresses the "update available" and debug emails no matter what is set here. Deactivate and delete it so that only Quiet Update Emails decides.', 'quiet-update-emails' ) . '</p>';
		}

		return $result;
	}

	/**
	 * Adds a section to Site Health > Info.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$summary  = self::summary();
		$settings = Settings::get_all();
		$locked   = array_keys( Settings::get_constant() );
		$none     = __( 'None', 'quiet-update-emails' );
		$enabled  = __( 'Enabled', 'quiet-update-emails' );
		$disabled = __( 'Disabled', 'quiet-update-emails' );

		$info['quiet-update-emails'] = array(
			'label'  => __( 'Quiet Update Emails', 'quiet-update-emails' ),
			'fields' => array(
				'version'       => array(
					'label' => __( 'Version', 'quiet-update-emails' ),
					'value' => PROADVANCED_UPDATE_EMAILS_VERSION,
				),
				'suppressed'    => array(
					'label' => __( 'Suppressed update emails', 'quiet-update-emails' ),
					'value' => $summary['suppressed'] ? implode( '; ', $summary['suppressed'] ) : $none,
				),
				'sent'          => array(
					'label' => __( 'Update emails left to WordPress', 'quiet-update-emails' ),
					'value' => $summary['sent'] ? implode( '; ', $summary['sent'] ) : $none,
				),
				'recipients'    => array(
					'label'   => __( 'Recipient of update emails', 'quiet-update-emails' ),
					'value'   => implode( ', ', Settings::effective_recipients() ),
					'private' => true,
				),
				'recipient_set' => array(
					'label' => __( 'Recipient override', 'quiet-update-emails' ),
					'value' => Settings::recipients() ? $enabled : __( 'Not set (site admin address is used)', 'quiet-update-emails' ),
				),
				'subject_tags'  => array(
					'label' => __( 'Subject tags', 'quiet-update-emails' ),
					'value' => $settings['subject_tags'] ? $enabled : $disabled,
				),
				'log'           => array(
					'label' => __( 'Update log', 'quiet-update-emails' ),
					'value' => $settings['log_enabled'] ? $enabled : $disabled,
				),
				'constant'      => array(
					'label' => __( 'Settings fixed by the PROADVANCED_UPDATE_EMAILS constant', 'quiet-update-emails' ),
					'value' => $locked ? implode( ', ', $locked ) : $none,
				),
				'old_plugin'    => array(
					'label' => __( 'Disable Auto-Update Success Emails (older plugin) loaded', 'quiet-update-emails' ),
					'value' => Plugin::is_old_plugin_loaded() ? __( 'Yes', 'quiet-update-emails' ) : __( 'No', 'quiet-update-emails' ),
				),
			),
		);

		return $info;
	}

	/**
	 * Splits the controlled emails into suppressed and sent.
	 *
	 * @return array {
	 *     @type string[] $suppressed Labels of suppressed emails.
	 *     @type string[] $sent       Labels of emails left to WordPress.
	 * }
	 */
	public static function summary() {
		$settings = Settings::get_all();
		$labels   = Settings::email_labels();
		$summary  = array(
			'suppressed' => array(),
			'sent'       => array(),
		);

		foreach ( Settings::email_toggle_keys() as $key ) {
			$summary[ $settings[ $key ] ? 'sent' : 'suppressed' ][] = $labels[ $key ];
		}

		if ( 'follow' === $settings['debug_email'] ) {
			$summary['sent'][] = __( 'Automatic update debug email (WordPress only sends it on development builds)', 'quiet-update-emails' );
		} else {
			$summary['suppressed'][] = $labels['debug_email'];
		}

		return $summary;
	}

	/**
	 * Builds an escaped HTML list.
	 *
	 * @param string[] $items Plain-text items.
	 * @return string
	 */
	private static function html_list( array $items ) {
		if ( ! $items ) {
			return '<ul><li>' . esc_html__( 'None', 'quiet-update-emails' ) . '</li></ul>';
		}

		$html = '<ul>';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( $item ) . '</li>';
		}

		return $html . '</ul>';
	}
}
