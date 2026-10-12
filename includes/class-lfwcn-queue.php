<?php
/**
 * The queue of pending notifications.
 *
 * Each entry holds a post ID, the user IDs that changed it, the event names
 * and times. Entries are keyed by post ID, so repeated saves of one item merge
 * into one entry. The queue is deleted entry by entry as mail goes out.
 *
 * A small state option remembers which posts were notified in the last few
 * minutes (to collapse follow-up saves) and when recent emails went out (for
 * the burst limit). Both lists are pruned to the collapse window.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pending notifications, stored in two non-autoloaded options.
 */
class LFWCN_Queue {

	const OPTION    = 'lfwcn_queue';
	const STATE     = 'lfwcn_state';
	const LOCK      = 'lfwcn_lock';
	const MAX_ITEMS = 500;
	const MAX_USERS = 5;

	/**
	 * The current time. Filterable so tests can move the clock.
	 *
	 * @return int Unix timestamp.
	 */
	public static function now() {
		return (int) apply_filters( 'lfwcn_now', time() );
	}

	/**
	 * Seconds within which repeated saves of one item are combined.
	 *
	 * @return int
	 */
	public static function collapse_window() {
		return max( 60, (int) apply_filters( 'lfwcn_collapse_window', 10 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Most emails sent right away within one collapse window. Past this,
	 * changes wait and go out together when the window ends.
	 *
	 * @return int
	 */
	public static function burst_limit() {
		return max( 1, (int) apply_filters( 'lfwcn_burst_limit', 5 ) );
	}

	/**
	 * Add a change to the queue, or merge it into the pending entry for the
	 * same post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $event     Event name.
	 * @param int    $user_id   User who made the change (0 for none).
	 * @param array  $snapshot  Title and type, kept only for deleted posts.
	 * @param bool   $immediate Whether delivery is right away.
	 * @return int|null When the entry is due, or null if the queue is full.
	 */
	public static function record( $post_id, $event, $user_id, $snapshot, $immediate ) {
		$locked = self::lock();
		$queue  = self::load( self::OPTION );
		$state  = self::state();
		$now    = self::now();
		$key    = 'p' . (int) $post_id;

		if ( isset( $queue[ $key ] ) ) {
			$entry = $queue[ $key ];
			if ( end( $entry['e'] ) !== $event ) {
				$entry['e'][] = $event;
			}
			if ( ! in_array( (int) $user_id, $entry['u'], true ) && count( $entry['u'] ) < self::MAX_USERS ) {
				$entry['u'][] = (int) $user_id;
			}
			$entry['l'] = $now;
			++$entry['n'];
			$entry         = array_merge( $entry, $snapshot );
			$queue[ $key ] = $entry;
		} elseif ( count( $queue ) >= self::MAX_ITEMS ) {
			$state['overflow'] = (int) $state['overflow'] + 1;
			self::save( self::STATE, $state );
			if ( $locked ) {
				self::unlock();
			}
			return null;
		} else {
			$due = $now;
			if ( $immediate && isset( $state['recent'][ $key ] ) && $state['recent'][ $key ] + self::collapse_window() > $now ) {
				$due = $state['recent'][ $key ] + self::collapse_window();
			}
			$queue[ $key ] = array_merge(
				array(
					'p'   => (int) $post_id,
					'e'   => array( $event ),
					'u'   => array( (int) $user_id ),
					't'   => $now,
					'l'   => $now,
					'n'   => 1,
					'due' => $due,
				),
				$snapshot
			);
		}

		self::save( self::OPTION, $queue );
		if ( $locked ) {
			self::unlock();
		}
		return $queue[ $key ]['due'];
	}

	/**
	 * Take entries that are ready to send and remove them from the queue.
	 *
	 * @param bool $all       True to take everything (digest), false to take
	 *                        only entries that are due (right away).
	 * @param bool $use_burst Apply the burst limit.
	 * @return array{entries: array, overflow: int, retry: int|null} Entries in
	 *               the order they were first changed, the count of changes
	 *               that did not fit in the queue, and when to try again if the
	 *               burst limit held everything back.
	 */
	public static function take( $all, $use_burst ) {
		$out = array(
			'entries'  => array(),
			'overflow' => 0,
			'retry'    => null,
		);
		if ( ! self::lock() ) {
			// Another request is sending right now; it will handle the queue.
			return $out;
		}
		$queue  = self::load( self::OPTION );
		$state  = self::state();
		$now    = self::now();
		$window = self::collapse_window();

		$due = array();
		foreach ( $queue as $key => $entry ) {
			if ( $all || (int) $entry['due'] <= $now ) {
				$due[ $key ] = $entry;
			}
		}

		if ( ( $due || $state['overflow'] ) && $use_burst && count( $state['sent'] ) >= self::burst_limit() ) {
			$out['retry'] = min( $state['sent'] ) + $window;
			self::unlock();
			return $out;
		}

		if ( $due || $state['overflow'] ) {
			foreach ( $due as $key => $entry ) {
				unset( $queue[ $key ] );
				$state['recent'][ $key ] = $now;
			}
			$state['sent'][]   = $now;
			$out['overflow']   = (int) $state['overflow'];
			$state['overflow'] = 0;
			uasort(
				$due,
				function ( $a, $b ) {
					return $a['t'] <=> $b['t'];
				}
			);
			$out['entries'] = array_values( $due );
			self::save( self::OPTION, $queue );
			self::save( self::STATE, $state );
		}
		self::unlock();
		return $out;
	}

	/**
	 * Put entries back after a failed send, to try once more later. An entry
	 * that has already been retried is dropped, so a broken mail setup does
	 * not grow the queue forever.
	 *
	 * @param array $entries Entries returned by take().
	 */
	public static function restore( $entries ) {
		$locked = self::lock();
		$queue  = self::load( self::OPTION );
		$retry  = self::now() + self::collapse_window();
		foreach ( $entries as $entry ) {
			$key = 'p' . (int) $entry['p'];
			if ( ! empty( $entry['retried'] ) || isset( $queue[ $key ] ) || count( $queue ) >= self::MAX_ITEMS ) {
				continue;
			}
			$entry['retried'] = 1;
			$entry['due']     = $retry;
			$queue[ $key ]    = $entry;
		}
		self::save( self::OPTION, $queue );
		if ( $locked ) {
			self::unlock();
		}
	}

	/**
	 * The earliest time a queued entry is due, if any.
	 *
	 * @return int|null
	 */
	public static function next_due() {
		$queue = self::load( self::OPTION );
		if ( ! $queue ) {
			return null;
		}
		return (int) min( wp_list_pluck( $queue, 'due' ) );
	}

	/**
	 * Number of changes waiting.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::load( self::OPTION ) ) + (int) self::state()['overflow'];
	}

	/**
	 * Remove the queue, the state and any lock.
	 */
	public static function clear() {
		delete_option( self::OPTION );
		delete_option( self::STATE );
		self::unlock();
	}

	/**
	 * Read the state, with lists pruned to the collapse window.
	 *
	 * @return array{recent: array<string,int>, sent: int[], overflow: int}
	 */
	private static function state() {
		$state  = array_merge(
			array(
				'recent'   => array(),
				'sent'     => array(),
				'overflow' => 0,
			),
			self::load( self::STATE )
		);
		$cutoff = self::now() - self::collapse_window();
		$keep   = function ( $t ) use ( $cutoff ) {
			return (int) $t > $cutoff;
		};

		$state['recent'] = array_filter( (array) $state['recent'], $keep );
		$state['sent']   = array_values( array_filter( (array) $state['sent'], $keep ) );
		return $state;
	}

	/**
	 * Read an option fresh, skipping any cached copy another request may have
	 * made stale.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	private static function load( $name ) {
		wp_cache_delete( $name, 'options' );
		$value = get_option( $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Save an option without autoloading it; delete it when empty.
	 *
	 * @param string $name  Option name.
	 * @param array  $value Value.
	 */
	private static function save( $name, $value ) {
		if ( empty( $value ) || ( self::STATE === $name && ! $value['recent'] && ! $value['sent'] && ! $value['overflow'] ) ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}

	/**
	 * Take a short lock so two requests do not edit the queue at once. Uses
	 * INSERT IGNORE on the options table, which is atomic, and clears a lock
	 * older than 30 seconds left by a request that died.
	 *
	 * @return bool Whether the lock was taken.
	 */
	private static function lock() {
		global $wpdb;
		for ( $i = 0; $i < 30; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the lock; the options cache cannot provide one.
			$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) ( time() + 30 ) ) );
			if ( $got ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the lock row written above.
			$expires = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
			if ( $expires && $expires < time() ) {
				self::unlock();
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	/**
	 * Release the lock.
	 */
	private static function unlock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Matches the insert in lock().
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) );
	}
}
