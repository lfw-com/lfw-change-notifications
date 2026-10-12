<?php
/**
 * Delivery: when queued changes are sent.
 *
 * Right away: changes made in one request go out in one email at the end of
 * that request, so a bulk edit or an import is one message. A follow-up save
 * of an item notified in the last ten minutes waits until those ten minutes
 * are up, and every follow-up in that time shares one email. Past five emails
 * in ten minutes, everything waits and goes out together.
 *
 * Digest: everything waits for the hourly or daily WP-Cron event.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling and sending.
 */
class LFWCN_Delivery {

	const FLUSH_HOOK  = 'lfwcn_flush';
	const DIGEST_HOOK = 'lfwcn_digest';

	/**
	 * Whether a send is already set to run at the end of this request.
	 *
	 * @var bool
	 */
	private static $on_shutdown = false;

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		add_action( self::DIGEST_HOOK, array( __CLASS__, 'digest' ) );
		add_action( 'add_option_' . LFWCN_Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 0 );
		add_action( 'update_option_' . LFWCN_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	/**
	 * A change was queued for right-away delivery.
	 *
	 * @param int $due When it is due.
	 */
	public static function queued( $due ) {
		if ( $due <= LFWCN_Queue::now() ) {
			if ( ! self::$on_shutdown ) {
				self::$on_shutdown = true;
				add_action( 'shutdown', array( __CLASS__, 'flush' ) );
			}
			// A backstop in case this request ends before shutdown runs.
			$due = LFWCN_Queue::now() + MINUTE_IN_SECONDS;
		}
		self::schedule_flush( $due );
	}

	/**
	 * Send the changes that are due (right-away delivery).
	 */
	public static function flush() {
		if ( 'immediate' !== LFWCN_Settings::get()['delivery'] ) {
			return;
		}
		$batch = LFWCN_Queue::take( false, true );
		self::send( $batch, false );

		$next = LFWCN_Queue::next_due();
		if ( null !== $batch['retry'] ) {
			$next = max( (int) $next, $batch['retry'] );
		}
		if ( $next ) {
			self::schedule_flush( max( $next, LFWCN_Queue::now() + 1 ) );
		}
	}

	/**
	 * Send everything waiting (digest delivery).
	 */
	public static function digest() {
		self::send( LFWCN_Queue::take( true, false ), true );
	}

	/**
	 * Send one batch, and put it back once if wp_mail() reports a failure.
	 *
	 * @param array $batch  Result of LFWCN_Queue::take().
	 * @param bool  $digest Whether this is a digest.
	 */
	private static function send( $batch, $digest ) {
		if ( ! $batch['entries'] && ! $batch['overflow'] ) {
			return;
		}
		$ok = LFWCN_Mailer::send_changes( LFWCN_Settings::recipients(), $batch['entries'], $batch['overflow'], $digest );
		if ( ! $ok && $batch['entries'] ) {
			LFWCN_Queue::restore( $batch['entries'] );
		}
	}

	/**
	 * Make sure a flush is scheduled no later than $when.
	 *
	 * @param int $when Unix timestamp.
	 */
	private static function schedule_flush( $when ) {
		$existing = wp_next_scheduled( self::FLUSH_HOOK );
		if ( $existing && $existing <= $when ) {
			return;
		}
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
		wp_schedule_single_event( $when, self::FLUSH_HOOK );
	}

	/**
	 * Set the digest event to match the delivery setting.
	 *
	 * @param bool $restart Restart the digest timer even if one is scheduled.
	 */
	public static function reschedule( $restart = true ) {
		$delivery = LFWCN_Settings::get()['delivery'];
		if ( 'immediate' === $delivery ) {
			wp_clear_scheduled_hook( self::DIGEST_HOOK );
			if ( LFWCN_Queue::count() ) {
				// Changes held for a digest go out now that delivery is right away.
				self::schedule_flush( LFWCN_Queue::now() + 1 );
			}
			return;
		}
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
		if ( $restart || ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
			wp_clear_scheduled_hook( self::DIGEST_HOOK );
			$step = 'hourly' === $delivery ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
			wp_schedule_event( time() + $step, $delivery, self::DIGEST_HOOK );
		}
	}

	/**
	 * Settings saved for the first time.
	 */
	public static function settings_added() {
		self::reschedule( true );
	}

	/**
	 * Settings changed. The digest timer restarts only when delivery changed.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 */
	public static function settings_changed( $old_value, $value ) {
		$old = is_array( $old_value ) && isset( $old_value['delivery'] ) ? $old_value['delivery'] : 'immediate';
		$new = is_array( $value ) && isset( $value['delivery'] ) ? $value['delivery'] : 'immediate';
		self::reschedule( $old !== $new );
	}

	/**
	 * Activation: schedule the digest if one is set.
	 */
	public static function activate() {
		self::reschedule( false );
	}

	/**
	 * Deactivation: stop scheduled sends and drop pending changes, so a
	 * reactivated plugin does not send stale news. Settings are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
		wp_clear_scheduled_hook( self::DIGEST_HOOK );
		LFWCN_Queue::clear();
	}
}
