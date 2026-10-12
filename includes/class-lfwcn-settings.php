<?php
/**
 * Settings: the stored option, its sanitizer, and the Settings > Change
 * Notifications screen.
 *
 * Only users with manage_options can open the screen or save it. Every value
 * is rebuilt from an allowlist on save; nothing submitted is stored as given.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage and screen.
 */
class LFWCN_Settings {

	const OPTION         = 'lfwcn_settings';
	const GROUP          = 'lfwcn';
	const PAGE           = 'lfw-change-notifications';
	const MAX_RECIPIENTS = 20;

	/**
	 * Set when the sanitizer has already run in this request. WordPress runs
	 * it twice when the option is first created; this keeps notices single.
	 *
	 * @var bool
	 */
	private static $sanitized = false;

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lfwcn_test', array( __CLASS__, 'send_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LFWCN_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Events a person can choose, with their labels.
	 *
	 * @return array<string,string>
	 */
	public static function event_choices() {
		return array(
			'published'   => __( 'Published (including scheduled items going live)', 'lfw-change-notifications' ),
			'updated'     => __( 'Updated after publishing', 'lfw-change-notifications' ),
			'unpublished' => __( 'Unpublished, made private, moved to trash or deleted', 'lfw-change-notifications' ),
			'scheduled'   => __( 'Scheduled to publish later', 'lfw-change-notifications' ),
		);
	}

	/**
	 * Delivery choices, with their labels.
	 *
	 * @return array<string,string>
	 */
	public static function delivery_choices() {
		return array(
			'immediate' => __( 'Right away', 'lfw-change-notifications' ),
			'hourly'    => __( 'Hourly digest', 'lfw-change-notifications' ),
			'daily'     => __( 'Daily digest', 'lfw-change-notifications' ),
		);
	}

	/**
	 * Public content types that can be watched. Attachments are left out:
	 * uploading a file is not publishing content.
	 *
	 * @return WP_Post_Type[] Keyed by name.
	 */
	public static function post_type_choices() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Defaults. Recipients stay empty here and fall back to the site admin
	 * email when read, so a changed admin email is followed.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'recipients'     => array(),
			'post_types'     => array_keys( self::post_type_choices() ),
			'events'         => array_keys( self::event_choices() ),
			'delivery'       => 'immediate',
			'ignore_imports' => false,
		);
	}

	/**
	 * The current settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * The addresses mail goes to: the saved list, or the site admin email.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$list = self::get()['recipients'];
		if ( empty( $list ) ) {
			$admin = get_option( 'admin_email' );
			$list  = is_email( $admin ) ? array( $admin ) : array();
		}
		return array_slice( $list, 0, self::MAX_RECIPIENTS );
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Rebuild submitted settings from allowlists.
	 *
	 * @param mixed $input Submitted value.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		// Recipients: split on commas, semicolons and whitespace; keep valid,
		// unique addresses; cap the list.
		$raw     = isset( $input['recipients'] ) && is_string( $input['recipients'] ) ? $input['recipients'] : '';
		$parts   = preg_split( '/[\s,;]+/', substr( $raw, 0, 5000 ), -1, PREG_SPLIT_NO_EMPTY );
		$valid   = array();
		$invalid = 0;
		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( $part );
			if ( $email && is_email( $email ) && $email === $part ) {
				$valid += array( strtolower( $email ) => $email );
			} else {
				++$invalid;
			}
		}
		$dropped             = max( 0, count( $valid ) - self::MAX_RECIPIENTS );
		$clean['recipients'] = array_slice( array_values( $valid ), 0, self::MAX_RECIPIENTS );

		$allowed_types       = array_keys( self::post_type_choices() );
		$types               = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'strval', $input['post_types'] ) : array();
		$clean['post_types'] = array_values( array_intersect( $allowed_types, $types ) );

		$allowed_events  = array_keys( self::event_choices() );
		$events          = isset( $input['events'] ) && is_array( $input['events'] ) ? array_map( 'strval', $input['events'] ) : array();
		$clean['events'] = array_values( array_intersect( $allowed_events, $events ) );

		$delivery          = isset( $input['delivery'] ) && is_string( $input['delivery'] ) ? $input['delivery'] : '';
		$clean['delivery'] = array_key_exists( $delivery, self::delivery_choices() ) ? $delivery : 'immediate';

		$clean['ignore_imports'] = ! empty( $input['ignore_imports'] );

		if ( ! self::$sanitized && function_exists( 'add_settings_error' ) ) {
			if ( $invalid ) {
				add_settings_error(
					self::OPTION,
					'lfwcn-invalid',
					/* translators: %d: number of addresses removed. */
					sprintf( _n( '%d address was not a valid email address and was removed.', '%d addresses were not valid email addresses and were removed.', $invalid, 'lfw-change-notifications' ), $invalid ),
					'warning'
				);
			}
			if ( $dropped ) {
				add_settings_error(
					self::OPTION,
					'lfwcn-capped',
					/* translators: 1: number of addresses removed, 2: the limit. */
					sprintf( _n( '%1$d address was removed: the limit is %2$d recipients.', '%1$d addresses were removed: the limit is %2$d recipients.', $dropped, 'lfw-change-notifications' ), $dropped, self::MAX_RECIPIENTS ),
					'warning'
				);
			}
		}
		self::$sanitized = true;

		return $clean;
	}

	/**
	 * Add the screen under Settings.
	 */
	public static function menu() {
		add_options_page(
			__( 'Change Notifications', 'lfw-change-notifications' ),
			__( 'Change Notifications', 'lfw-change-notifications' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lfw-change-notifications' ) . '</a>' );
		return $links;
	}

	/**
	 * Render the settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::get();
		$name   = self::OPTION;
		$admin  = get_option( 'admin_email' );
		$window = (int) ceil( LFWCN_Queue::collapse_window() / MINUTE_IN_SECONDS );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Change Notifications', 'lfw-change-notifications' ); ?></h1>
			<p><?php esc_html_e( 'Email the people below when content is published or changed. Mail goes out through this site\'s own mail setup.', 'lfw-change-notifications' ); ?></p>

			<?php self::render_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lfwcn-recipients"><?php esc_html_e( 'Recipients', 'lfw-change-notifications' ); ?></label></th>
						<td>
							<textarea id="lfwcn-recipients" name="<?php echo esc_attr( $name ); ?>[recipients]" rows="4" cols="50" class="large-text code" aria-describedby="lfwcn-recipients-help"><?php echo esc_textarea( implode( "\n", $s['recipients'] ) ); ?></textarea>
							<p class="description" id="lfwcn-recipients-help">
								<?php
								printf(
									/* translators: 1: maximum number of recipients, 2: the site admin email address. */
									esc_html__( 'One address per line, up to %1$d. Leave empty to use the site admin email (%2$s).', 'lfw-change-notifications' ),
									(int) self::MAX_RECIPIENTS,
									esc_html( $admin )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content types', 'lfw-change-notifications' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'lfw-change-notifications' ); ?></legend>
								<?php foreach ( self::post_type_choices() as $type => $obj ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $s['post_types'], true ) ); ?>> <?php echo esc_html( $obj->labels->name ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events', 'lfw-change-notifications' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Events', 'lfw-change-notifications' ); ?></legend>
								<?php foreach ( self::event_choices() as $event => $label ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[events][]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, $s['events'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'lfw-change-notifications' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Delivery', 'lfw-change-notifications' ); ?></legend>
								<?php foreach ( self::delivery_choices() as $key => $label ) : ?>
									<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['delivery'], $key ); ?>> <?php echo esc_html( $label ); ?></label><br>
								<?php endforeach; ?>
								<p class="description">
									<?php
									printf(
										/* translators: %d: minutes. */
										esc_html__( 'Right away: one email per save or bulk action. Further saves of the same item within %d minutes are combined into one follow-up email. A digest sends one email listing everything since the last one.', 'lfw-change-notifications' ),
										(int) $window
									);
									?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Imports', 'lfw-change-notifications' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ignore_imports]" value="1" <?php checked( $s['ignore_imports'] ); ?>> <?php esc_html_e( 'Skip changes made by WP-CLI and the WordPress Importer', 'lfw-change-notifications' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'lfw-change-notifications' ); ?></h2>
			<?php self::render_status( $s ); ?>

			<h2><?php esc_html_e( 'Test email', 'lfw-change-notifications' ); ?></h2>
			<p><?php esc_html_e( 'Send one test message to the saved recipients to check that this site can send mail.', 'lfw-change-notifications' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lfwcn_test">
				<?php wp_nonce_field( 'lfwcn_test' ); ?>
				<?php submit_button( __( 'Send a test email', 'lfw-change-notifications' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show the result of a test email, from a fixed list of messages.
	 */
	private static function render_notice() {
		// Read-only display of a fixed status code set by send_test(); nothing is changed here.
		$code     = isset( $_GET['lfwcn-test'] ) ? sanitize_key( wp_unslash( $_GET['lfwcn-test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'sent'   => array( 'success', __( 'Test email sent. If it does not arrive, check the site\'s mail setup or an SMTP plugin.', 'lfw-change-notifications' ) ),
			'failed' => array( 'error', __( 'WordPress could not send the test email. Check the site\'s mail setup or an SMTP plugin.', 'lfw-change-notifications' ) ),
			'wait'   => array( 'warning', __( 'A test email was sent less than a minute ago. Wait a minute before sending another.', 'lfw-change-notifications' ) ),
			'nobody' => array( 'error', __( 'There is no valid recipient to send to.', 'lfw-change-notifications' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}

	/**
	 * Show what is waiting and when it goes out.
	 *
	 * @param array $s Settings.
	 */
	private static function render_status( $s ) {
		$waiting = LFWCN_Queue::count();
		$hook    = 'immediate' === $s['delivery'] ? LFWCN_Delivery::FLUSH_HOOK : LFWCN_Delivery::DIGEST_HOOK;
		$next    = wp_next_scheduled( $hook );
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<p>';
		printf(
			/* translators: %s: number of changes. */
			esc_html( _n( '%s change is waiting to be sent.', '%s changes are waiting to be sent.', $waiting, 'lfw-change-notifications' ) ),
			esc_html( number_format_i18n( $waiting ) )
		);
		if ( $next ) {
			echo ' ';
			printf(
				/* translators: %s: date and time. */
				esc_html__( 'Next send: %s.', 'lfw-change-notifications' ),
				esc_html( wp_date( $format, $next ) . ' (' . wp_timezone_string() . ')' )
			);
		}
		echo '</p>';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<p>' . esc_html__( 'WP-Cron is turned off on this site, so digests and combined follow-ups go out only when a server cron job runs WordPress\'s scheduled tasks.', 'lfw-change-notifications' ) . '</p>';
		}
	}

	/**
	 * Send a test email. Admins only, nonce checked, one per minute.
	 */
	public static function send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lfw-change-notifications' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lfwcn_test' );

		$code = 'sent';
		if ( get_transient( 'lfwcn_test_sent' ) ) {
			$code = 'wait';
		} elseif ( ! self::recipients() ) {
			$code = 'nobody';
		} else {
			set_transient( 'lfwcn_test_sent', 1, MINUTE_IN_SECONDS );
			$code = LFWCN_Mailer::send_test( self::recipients() ) ? 'sent' : 'failed';
		}
		wp_safe_redirect( add_query_arg( 'lfwcn-test', $code, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}
}
