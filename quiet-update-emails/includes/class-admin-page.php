<?php
/**
 * Settings screen, update log screen and admin notices.
 *
 * @package QuietUpdateEmails
 */

namespace ProAdvanced\UpdateEmails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's admin screen.
 *
 * On a single site it lives under Settings and needs manage_options. On
 * multisite it lives under Network Admin > Settings and needs
 * manage_network_options.
 */
class Admin_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'quiet-update-emails';

	/**
	 * Log entries shown per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Hook suffix of the screen, set once the menu is registered.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Result of the test email sent in this request, if any.
	 *
	 * @var array|null
	 */
	private $test_result = null;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( Settings::is_network() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'network_admin_edit_proadvanced_update_emails_save', array( $this, 'save_network_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_old_plugin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_old_plugin_notice' ) );

		$basename = plugin_basename( PROADVANCED_UPDATE_EMAILS_FILE );
		add_filter( 'plugin_action_links_' . $basename, array( $this, 'add_action_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( $this, 'add_action_link' ) );
	}

	/**
	 * Returns the URL of the screen.
	 *
	 * @param array $args Optional. Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$base = Settings::is_network() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );

		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), $base );
	}

	/**
	 * Adds the menu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook_suffix = add_submenu_page(
			Settings::is_network() ? 'settings.php' : 'options-general.php',
			__( 'Quiet Update Emails', 'quiet-update-emails' ),
			__( 'Update Emails', 'quiet-update-emails' ),
			Settings::capability(),
			self::SLUG,
			array( $this, 'render_page' )
		);

		if ( $hook_suffix ) {
			$this->hook_suffix = $hook_suffix;
			add_action( 'load-' . $hook_suffix, array( $this, 'handle_actions' ) );
		}
	}

	/**
	 * Adds a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function add_action_link( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( Settings::capability() ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'quiet-update-emails' ) )
		);

		return $links;
	}

	/**
	 * Loads the stylesheet and script, on this plugin's screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'proadvanced-update-emails-admin',
			plugins_url( 'assets/admin.css', PROADVANCED_UPDATE_EMAILS_FILE ),
			array(),
			PROADVANCED_UPDATE_EMAILS_VERSION
		);

		wp_enqueue_script(
			'proadvanced-update-emails-admin',
			plugins_url( 'assets/admin.js', PROADVANCED_UPDATE_EMAILS_FILE ),
			array(),
			PROADVANCED_UPDATE_EMAILS_VERSION,
			true
		);
	}

	/**
	 * Asks the administrator to remove the predecessor plugin while it is loaded.
	 *
	 * Shown only on the Plugins screen and this plugin's screen, only to users
	 * who can manage plugins, and it disappears once the old plugin is gone.
	 *
	 * @return void
	 */
	public function render_old_plugin_notice() {
		if ( ! Plugin::is_old_plugin_loaded() ) {
			return;
		}

		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screens = array( 'plugins', 'plugins-network' );
		if ( '' !== $this->hook_suffix ) {
			$screens[] = $this->hook_suffix;
			$screens[] = $this->hook_suffix . '-network';
		}

		if ( ! in_array( $screen->id, $screens, true ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Quiet Update Emails:', 'quiet-update-emails' ); ?></strong>
				<?php esc_html_e( 'the older plugin "Disable Auto-Update Success Emails" is still active. It suppresses the "update available" and debug emails no matter what is set in Quiet Update Emails. Please deactivate and delete the older plugin (or remove its file from the mu-plugins folder if it was installed there). This notice goes away once it is gone.', 'quiet-update-emails' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handles the "send test email" and "clear log" buttons.
	 *
	 * Runs on this screen's load hook, before any output.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['proadvanced_update_emails_action'] ) ) {
			return;
		}

		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'quiet-update-emails' ), 403 );
		}

		$action = sanitize_key( wp_unslash( $_POST['proadvanced_update_emails_action'] ) );

		if ( 'test_email' === $action ) {
			check_admin_referer( 'proadvanced_update_emails_test_email' );

			$this->test_result = $this->send_test_email();
		} elseif ( 'clear_log' === $action ) {
			check_admin_referer( 'proadvanced_update_emails_clear_log' );

			Update_Log::clear();

			wp_safe_redirect(
				self::url(
					array(
						'tab'         => 'log',
						'log-cleared' => '1',
					)
				)
			);
			exit;
		}
	}

	/**
	 * Saves the settings on multisite, where options.php cannot be used.
	 *
	 * @return void
	 */
	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'quiet-update-emails' ), 403 );
		}

		check_admin_referer( Settings::GROUP . '-options' );

		$value = array();
		if ( isset( $_POST[ Settings::OPTION ] ) && is_array( $_POST[ Settings::OPTION ] ) ) {
			$value = map_deep( wp_unslash( $_POST[ Settings::OPTION ] ), 'sanitize_text_field' );
		}

		// Validated by Settings::sanitize() through the registered sanitize callback.
		update_site_option( Settings::OPTION, $value );

		if ( ! get_settings_errors() ) {
			add_settings_error( Settings::OPTION, 'settings_updated', __( 'Settings saved.', 'quiet-update-emails' ), 'success' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( self::url( array( 'settings-updated' => 'true' ) ) );
		exit;
	}

	/**
	 * Registers the settings sections and fields.
	 *
	 * @return void
	 */
	public function register_fields() {
		$labels = Settings::email_labels();

		add_settings_section(
			'proadvanced_update_emails_emails',
			__( 'Emails', 'quiet-update-emails' ),
			array( $this, 'render_emails_intro' ),
			self::SLUG
		);

		$descriptions = array(
			'core_critical'  => __( 'Sent when a core update fails part-way and the site may need attention.', 'quiet-update-emails' ),
			'core_available' => __( 'Tells you a new WordPress version exists that was not installed automatically. WordPress only sends it when WordPress.org flags the release for notification.', 'quiet-update-emails' ),
			'plugin_fail'    => __( 'The email contains the full report for the batch, including the updates that succeeded.', 'quiet-update-emails' ),
			'theme_fail'     => __( 'The email contains the full report for the batch, including the updates that succeeded.', 'quiet-update-emails' ),
		);

		foreach ( Settings::email_toggle_keys() as $key ) {
			$this->add_field(
				$key,
				$labels[ $key ],
				'checkbox',
				'proadvanced_update_emails_emails',
				array(
					'checkbox_label' => __( 'Send this email', 'quiet-update-emails' ),
					'description'    => isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '',
				)
			);
		}

		$this->add_field(
			'debug_email',
			$labels['debug_email'],
			'select',
			'proadvanced_update_emails_emails',
			array(
				'choices'     => array(
					'suppress' => __( 'Suppress', 'quiet-update-emails' ),
					'follow'   => __( 'Follow WordPress', 'quiet-update-emails' ),
				),
				'description' => __( 'WordPress only sends this email on development builds. "Follow WordPress" leaves that decision alone; the email cannot be forced on.', 'quiet-update-emails' ),
			)
		);

		add_settings_section(
			'proadvanced_update_emails_delivery',
			__( 'Delivery', 'quiet-update-emails' ),
			'__return_null',
			self::SLUG
		);

		$this->add_field(
			'recipients',
			__( 'Send update emails to', 'quiet-update-emails' ),
			'text',
			'proadvanced_update_emails_delivery',
			array(
				'class'       => 'regular-text',
				'placeholder' => Settings::default_recipient(),
				'description' => __( 'One or more email addresses, separated by commas. Every update email that is sent goes here instead of the site admin address. Leave empty to use the WordPress default.', 'quiet-update-emails' ),
			)
		);

		$this->add_field(
			'subject_tags',
			__( 'Subject tags', 'quiet-update-emails' ),
			'checkbox',
			'proadvanced_update_emails_delivery',
			array(
				'checkbox_label' => __( 'Add a tag to the start of the subject line', 'quiet-update-emails' ),
				'description'    => __( 'Makes inbox rules easy to write. Leave a tag empty to add nothing for that kind of email.', 'quiet-update-emails' ),
			)
		);

		$tags = array(
			'tag_failed'    => __( 'Tag for failures', 'quiet-update-emails' ),
			'tag_available' => __( 'Tag for "update available"', 'quiet-update-emails' ),
			'tag_ok'        => __( 'Tag for successes', 'quiet-update-emails' ),
		);

		foreach ( $tags as $key => $label ) {
			$this->add_field( $key, $label, 'text', 'proadvanced_update_emails_delivery', array( 'maxlength' => Settings::TAG_MAX_LENGTH ) );
		}

		add_settings_section(
			'proadvanced_update_emails_log',
			__( 'Update log', 'quiet-update-emails' ),
			'__return_null',
			self::SLUG
		);

		$this->add_field(
			'log_enabled',
			__( 'Record updates', 'quiet-update-emails' ),
			'checkbox',
			'proadvanced_update_emails_log',
			array(
				'checkbox_label' => __( 'Record every core, plugin and theme update', 'quiet-update-emails' ),
				'description'    => sprintf(
					/* translators: %d: number of days. */
					__( 'Entries older than %d days are deleted automatically.', 'quiet-update-emails' ),
					Update_Log::RETENTION_DAYS
				),
			)
		);
	}

	/**
	 * Intro text of the Emails section.
	 *
	 * @return void
	 */
	public function render_emails_intro() {
		echo '<p>' . esc_html__( 'Ticked: WordPress sends the email as it normally would. Unticked: this plugin suppresses it. The plugin never forces an email on, so an email that WordPress or another plugin has already turned off stays off.', 'quiet-update-emails' ) . '</p>';
	}

	/**
	 * Renders one settings field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field( $args ) {
		$key    = $args['key'];
		$locked = Settings::is_locked( $key );
		$all    = Settings::get_all();
		$stored = Settings::get_stored();
		$value  = $locked ? $all[ $key ] : $stored[ $key ];
		$name   = Settings::OPTION . '[' . $key . ']';
		$id     = 'proadvanced-update-emails-' . str_replace( '_', '-', $key );

		if ( 'checkbox' === $args['type'] ) {
			// The hidden field makes an unticked box arrive as "0" rather than not at all.
			if ( ! $locked ) {
				printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $name ) );
			}
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s /> %5$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				disabled( $locked, true, false ),
				esc_html( $args['checkbox_label'] )
			);
		} elseif ( 'select' === $args['type'] ) {
			printf(
				'<select id="%1$s" name="%2$s" %3$s>',
				esc_attr( $id ),
				esc_attr( $name ),
				disabled( $locked, true, false )
			);
			foreach ( $args['choices'] as $choice => $choice_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $choice ),
					selected( $value, $choice, false ),
					esc_html( $choice_label )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="%4$s" placeholder="%5$s" %6$s %7$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value ),
				esc_attr( isset( $args['class'] ) ? $args['class'] : '' ),
				esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ),
				isset( $args['maxlength'] ) ? 'maxlength="' . esc_attr( (string) $args['maxlength'] ) . '"' : '',
				disabled( $locked, true, false )
			);
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}

		if ( $locked ) {
			echo '<p class="description proadvanced-update-emails-locked">';
			printf(
				/* translators: %s: name of a PHP constant. */
				esc_html__( 'Read-only: this value is set by the %s constant in wp-config.php or a must-use plugin.', 'quiet-update-emails' ),
				'<code>PROADVANCED_UPDATE_EMAILS</code>'
			);
			echo '</p>';
		} elseif ( $all[ $key ] !== $stored[ $key ] ) {
			echo '<p class="description proadvanced-update-emails-locked">';
			printf(
				/* translators: 1: name of a filter hook, 2: the value in effect. */
				esc_html__( 'A %1$s filter currently changes this to: %2$s', 'quiet-update-emails' ),
				'<code>proadvanced_update_emails_settings</code>',
				'<strong>' . esc_html( $this->describe_value( $key, $all[ $key ] ) ) . '</strong>'
			);
			echo '</p>';
		}
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: selects which tab to display.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tab = 'log' === $tab ? 'log' : 'settings';

		$tabs = array(
			'settings' => __( 'Settings', 'quiet-update-emails' ),
			'log'      => __( 'Update log', 'quiet-update-emails' ),
		);

		?>
		<div class="wrap proadvanced-update-emails">
			<h1><?php esc_html_e( 'Quiet Update Emails', 'quiet-update-emails' ); ?></h1>

			<?php
			// Settings pages on a single site print these through options-head.php.
			if ( Settings::is_network() ) {
				settings_errors();
			}
			?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a
						href="<?php echo esc_url( self::url( 'settings' === $tab_key ? array() : array( 'tab' => $tab_key ) ) ); ?>"
						class="nav-tab <?php echo esc_attr( $tab === $tab_key ? 'nav-tab-active' : '' ); ?>"
						<?php echo $tab === $tab_key ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'log' === $tab ) {
				$this->render_log_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Registers one field with the Settings API.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Field label.
	 * @param string $type    'checkbox', 'select' or 'text'.
	 * @param string $section Section ID.
	 * @param array  $args    Optional. Extra arguments for render_field().
	 * @return void
	 */
	private function add_field( $key, $label, $type, $section, array $args = array() ) {
		$args['key']  = $key;
		$args['type'] = $type;

		if ( 'checkbox' !== $type ) {
			$args['label_for'] = 'proadvanced-update-emails-' . str_replace( '_', '-', $key );
		}

		add_settings_field(
			'proadvanced_update_emails_' . $key,
			$label,
			array( $this, 'render_field' ),
			self::SLUG,
			$section,
			$args
		);
	}

	/**
	 * Turns a setting value into text for display.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return string
	 */
	private function describe_value( $key, $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'quiet-update-emails' ) : __( 'off', 'quiet-update-emails' );
		}

		if ( 'debug_email' === $key ) {
			return 'follow' === $value ? __( 'Follow WordPress', 'quiet-update-emails' ) : __( 'Suppress', 'quiet-update-emails' );
		}

		return '' === $value ? __( '(empty)', 'quiet-update-emails' ) : (string) $value;
	}

	/**
	 * Renders the Settings tab.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$form_action = Settings::is_network()
			? network_admin_url( 'edit.php?action=proadvanced_update_emails_save' )
			: admin_url( 'options.php' );

		$this->render_test_result();

		?>
		<form method="post" action="<?php echo esc_url( $form_action ); ?>">
			<?php
			settings_fields( Settings::GROUP );
			do_settings_sections( self::SLUG );
			submit_button();
			?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Test email', 'quiet-update-emails' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: comma-separated list of email addresses. */
				esc_html__( 'Sends a short test message through WordPress to the address update emails currently go to: %s', 'quiet-update-emails' ),
				'<strong>' . esc_html( implode( ', ', Settings::effective_recipients() ) ) . '</strong>'
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Save your settings first if you have just changed the recipient.', 'quiet-update-emails' ); ?></p>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>">
			<?php wp_nonce_field( 'proadvanced_update_emails_test_email' ); ?>
			<input type="hidden" name="proadvanced_update_emails_action" value="test_email" />
			<?php submit_button( __( 'Send test email', 'quiet-update-emails' ), 'secondary', 'proadvanced_update_emails_send_test', true ); ?>
		</form>
		<?php
	}

	/**
	 * Shows the outcome of the test email.
	 *
	 * @return void
	 */
	private function render_test_result() {
		if ( null === $this->test_result ) {
			return;
		}

		$recipients = implode( ', ', $this->test_result['to'] );

		if ( $this->test_result['sent'] ) {
			?>
			<div class="notice notice-success">
				<p>
					<?php
					printf(
						/* translators: %s: comma-separated list of email addresses. */
						esc_html__( 'WordPress handed the test email to the mail system for delivery to %s. Check that inbox (and its spam folder) to confirm it arrives.', 'quiet-update-emails' ),
						'<strong>' . esc_html( $recipients ) . '</strong>'
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of email addresses. */
					esc_html__( 'The test email to %s could not be sent.', 'quiet-update-emails' ),
					'<strong>' . esc_html( $recipients ) . '</strong>'
				);
				?>
			</p>
			<p>
				<?php
				if ( '' !== $this->test_result['error'] ) {
					printf(
						/* translators: %s: error message from the mail system. */
						esc_html__( 'Mail error: %s', 'quiet-update-emails' ),
						'<code>' . esc_html( $this->test_result['error'] ) . '</code>'
					);
				} else {
					esc_html_e( 'WordPress did not report a reason. If another plugin handles email delivery, check its log.', 'quiet-update-emails' );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sends the test email and captures the mail error, if there is one.
	 *
	 * @return array {
	 *     @type bool     $sent  Whether wp_mail() reported success.
	 *     @type string[] $to    Addresses the message was sent to.
	 *     @type string   $error Mail error message, or ''.
	 * }
	 */
	private function send_test_email() {
		$to    = Settings::effective_recipients();
		$error = '';

		$capture = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site title. */
			__( '[%s] Quiet Update Emails test message', 'quiet-update-emails' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: site URL, 2: comma-separated list of email addresses. */
			__( "This is a test message from the Quiet Update Emails plugin on %1\$s.\n\nUpdate emails from this site are currently sent to: %2\$s\n\nIf you received this, WordPress can send email to that address.", 'quiet-update-emails' ),
			home_url( '/' ),
			implode( ', ', $to )
		);

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $capture );

		return array(
			'sent'  => (bool) $sent,
			'to'    => $to,
			'error' => sanitize_text_field( $error ),
		);
	}

	/**
	 * Renders the Update log tab.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		// Keeps the retention promise even when WP-Cron is unreliable.
		Update_Log::prune();

		$entries = array_reverse( Update_Log::get_entries() );
		$total   = count( $entries );
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: selects which page of the log to display.
		$paged = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$paged = min( max( 1, $paged ), $pages );

		$entries  = array_slice( $entries, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$settings = Settings::get_all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: shows a confirmation after a nonce-checked redirect.
		if ( isset( $_GET['log-cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The update log was cleared.', 'quiet-update-emails' ) . '</p></div>';
		}

		echo '<p>';
		printf(
			/* translators: %d: number of days. */
			esc_html__( 'Every core, plugin and theme update is recorded here, automatic or manual. Entries older than %d days are deleted automatically.', 'quiet-update-emails' ),
			(int) Update_Log::RETENTION_DAYS
		);
		echo '</p>';

		if ( ! $settings['log_enabled'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The update log is switched off, so new updates are not being recorded.', 'quiet-update-emails' ) . '</p></div>';
		}

		$pagination = '';
		if ( $pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'      => self::url(
						array(
							'tab'   => 'log',
							'paged' => '%#%',
						)
					),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => __( '&laquo; Newer', 'quiet-update-emails' ),
					'next_text' => __( 'Older &raquo;', 'quiet-update-emails' ),
				)
			);
		}

		?>
		<div class="tablenav top">
			<div class="alignleft actions">
				<?php if ( $total > 0 ) : ?>
					<form
						method="post"
						action="<?php echo esc_url( self::url( array( 'tab' => 'log' ) ) ); ?>"
						class="proadvanced-update-emails-clear-log"
						data-confirm="<?php esc_attr_e( 'Delete every entry in the update log? This cannot be undone.', 'quiet-update-emails' ); ?>"
					>
						<?php wp_nonce_field( 'proadvanced_update_emails_clear_log' ); ?>
						<input type="hidden" name="proadvanced_update_emails_action" value="clear_log" />
						<?php submit_button( __( 'Clear log', 'quiet-update-emails' ), 'delete', 'proadvanced_update_emails_clear', false ); ?>
					</form>
				<?php endif; ?>
			</div>
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of log entries. */
						esc_html( _n( '%s entry', '%s entries', $total, 'quiet-update-emails' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php echo wp_kses_post( (string) $pagination ); ?>
			</div>
			<br class="clear" />
		</div>

		<table class="widefat striped proadvanced-update-emails-log">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Item', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Old version', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'New version', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trigger', 'quiet-update-emails' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'quiet-update-emails' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $entries ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No updates have been recorded yet.', 'quiet-update-emails' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<?php $this->render_log_row( $entry ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one row of the log table.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function render_log_row( array $entry ) {
		$entry = array_merge(
			array(
				't'      => 0,
				'type'   => '',
				'item'   => '',
				'name'   => '',
				'old'    => '',
				'new'    => '',
				'result' => '',
				'mode'   => '',
				'code'   => '',
				'note'   => '',
			),
			$entry
		);

		$types = array(
			'core'   => __( 'Core', 'quiet-update-emails' ),
			'plugin' => __( 'Plugin', 'quiet-update-emails' ),
			'theme'  => __( 'Theme', 'quiet-update-emails' ),
		);

		$results = array(
			'success'     => __( 'Succeeded', 'quiet-update-emails' ),
			'failed'      => __( 'Failed', 'quiet-update-emails' ),
			'rolled_back' => __( 'Failed, rolled back', 'quiet-update-emails' ),
		);

		$result = isset( $results[ $entry['result'] ] ) ? $entry['result'] : 'failed';
		$date   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['t'] );
		$name   = '' !== (string) $entry['name'] ? (string) $entry['name'] : (string) $entry['item'];

		?>
		<tr>
			<td><?php echo esc_html( (string) $date ); ?></td>
			<td><?php echo esc_html( isset( $types[ $entry['type'] ] ) ? $types[ $entry['type'] ] : (string) $entry['type'] ); ?></td>
			<td>
				<strong><?php echo esc_html( $name ); ?></strong>
				<?php if ( '' !== (string) $entry['item'] && $entry['item'] !== $name ) : ?>
					<br /><span class="description"><?php echo esc_html( (string) $entry['item'] ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( '' !== (string) $entry['old'] ? (string) $entry['old'] : __( 'Unknown', 'quiet-update-emails' ) ); ?></td>
			<td><?php echo esc_html( '' !== (string) $entry['new'] ? (string) $entry['new'] : __( 'Unknown', 'quiet-update-emails' ) ); ?></td>
			<td>
				<span class="proadvanced-update-emails-result proadvanced-update-emails-result-<?php echo esc_attr( str_replace( '_', '-', $result ) ); ?>">
					<?php echo esc_html( $results[ $result ] ); ?>
				</span>
			</td>
			<td><?php echo esc_html( 'auto' === $entry['mode'] ? __( 'Automatic', 'quiet-update-emails' ) : __( 'Manual', 'quiet-update-emails' ) ); ?></td>
			<td><?php echo esc_html( $this->describe_details( $entry ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Builds the text of the Details column.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private function describe_details( array $entry ) {
		$own = array(
			'incomplete'     => __( 'The update started but did not finish. WordPress stopped during download or unpacking, or the request ended early.', 'quiet-update-emails' ),
			'core_no_reason' => __( 'WordPress reported an error but does not pass the reason to plugins. The message was shown on the update screen.', 'quiet-update-emails' ),
			'no_reason'      => __( 'WordPress reported this update as failed without passing a reason to plugins.', 'quiet-update-emails' ),
			'uploaded'       => __( 'Replaced with an uploaded ZIP file.', 'quiet-update-emails' ),
		);

		$code = (string) $entry['code'];

		if ( isset( $own[ $code ] ) ) {
			return $own[ $code ];
		}

		$note = (string) $entry['note'];

		if ( '' !== $note && '' !== $code ) {
			return sprintf( '%1$s (%2$s)', $note, $code );
		}

		return '' !== $note ? $note : $code;
	}
}
