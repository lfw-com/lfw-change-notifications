<?php
/**
 * Mailer: writes the plain-text email and hands it to wp_mail().
 *
 * The wp_mail() function is the only way mail leaves this plugin, so it goes through
 * whatever the site already uses (an SMTP plugin, the host's mail). Titles
 * are plain text with tags and control characters removed, so an item title
 * cannot add headers or markup to the message.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email composition and sending.
 */
class LFWCN_Mailer {

	/**
	 * Most changes listed in one email; the rest are counted.
	 *
	 * @return int
	 */
	public static function max_listed() {
		return max( 1, (int) apply_filters( 'lfwcn_max_listed', 50 ) );
	}

	/**
	 * Send one email about a batch of changes.
	 *
	 * @param string[] $to       Recipients.
	 * @param array    $entries  Queue entries.
	 * @param int      $overflow Changes that did not fit in the queue.
	 * @param bool     $digest   Whether this is a scheduled digest.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send_changes( $to, $entries, $overflow, $digest ) {
		if ( ! $to ) {
			return false;
		}
		$total  = count( $entries ) + (int) $overflow;
		$site   = self::site_name();
		$listed = array_slice( $entries, 0, self::max_listed() );
		$more   = $total - count( $listed );
		$blocks = array_map( array( __CLASS__, 'describe' ), $listed );

		if ( 1 === $total && $listed ) {
			/* translators: 1: site name, 2: what happened, 3: item title. */
			$subject = sprintf( __( '[%1$s] %2$s: %3$s', 'lfw-change-notifications' ), $site, $blocks[0]['label'], $blocks[0]['title'] );
		} elseif ( $digest ) {
			$period = 'daily' === LFWCN_Settings::get()['delivery'] ? __( 'Daily digest', 'lfw-change-notifications' ) : __( 'Hourly digest', 'lfw-change-notifications' );
			/* translators: 1: site name, 2: "Daily digest" or "Hourly digest", 3: number of changes. */
			$subject = sprintf( _n( '[%1$s] %2$s: %3$s content change', '[%1$s] %2$s: %3$s content changes', $total, 'lfw-change-notifications' ), $site, $period, number_format_i18n( $total ) );
		} else {
			/* translators: 1: site name, 2: number of changes. */
			$subject = sprintf( _n( '[%1$s] %2$s content change', '[%1$s] %2$s content changes', $total, 'lfw-change-notifications' ), $site, number_format_i18n( $total ) );
		}

		$lines = array();
		if ( $total > 1 ) {
			/* translators: 1: number of changes, 2: site name. */
			$lines[] = sprintf( _n( '%1$s content change on %2$s:', '%1$s content changes on %2$s:', $total, 'lfw-change-notifications' ), number_format_i18n( $total ), $site );
			$lines[] = '';
		}
		foreach ( $blocks as $block ) {
			$lines   = array_merge( $lines, $block['lines'] );
			$lines[] = '';
		}
		if ( $more > 0 ) {
			/* translators: %s: number of changes not listed. */
			$lines[] = sprintf( _n( 'And %s more change, not listed here.', 'And %s more changes, not listed here.', $more, 'lfw-change-notifications' ), number_format_i18n( $more ) );
			/* translators: %s: link to the list of content in the dashboard. */
			$lines[] = sprintf( __( 'See all content: %s', 'lfw-change-notifications' ), admin_url( 'edit.php' ) );
			$lines[] = '';
		}
		$lines = array_merge( $lines, self::footer() );

		return self::mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Send the test email.
	 *
	 * @param string[] $to Recipients.
	 * @return bool
	 */
	public static function send_test( $to ) {
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Test email from Change Notifications', 'lfw-change-notifications' ), self::site_name() );
		$lines   = array_merge(
			array(
				__( 'This is a test. If you can read it, this site can send change notifications to you.', 'lfw-change-notifications' ),
				'',
			),
			self::footer()
		);
		return self::mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Describe one queue entry.
	 *
	 * @param array $entry Queue entry.
	 * @return array{label: string, title: string, lines: string[]}
	 */
	public static function describe( $entry ) {
		$post   = get_post( (int) $entry['p'] );
		$type   = $post ? $post->post_type : ( isset( $entry['type'] ) ? (string) $entry['type'] : '' );
		$obj    = $type ? get_post_type_object( $type ) : null;
		$title  = self::plain( $post ? $post->post_title : ( isset( $entry['title'] ) ? $entry['title'] : '' ) );
		$title  = '' === $title ? __( '(no title)', 'lfw-change-notifications' ) : $title;
		$label  = self::label( (array) $entry['e'] );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$zone   = wp_timezone_string();

		$lines   = array();
		$lines[] = $label . ': ' . $title;
		if ( $obj ) {
			/* translators: %s: content type, such as Page. */
			$lines[] = sprintf( __( 'Type: %s', 'lfw-change-notifications' ), self::plain( $obj->labels->singular_name ) );
		}
		/* translators: %s: names of the people who made the change. */
		$lines[] = sprintf( __( 'By: %s', 'lfw-change-notifications' ), implode( ', ', array_map( array( __CLASS__, 'person' ), (array) $entry['u'] ) ) );
		/* translators: 1: date and time, 2: time zone. */
		$lines[] = sprintf( __( 'When: %1$s (%2$s)', 'lfw-change-notifications' ), wp_date( $format, (int) $entry['l'] ), $zone );
		if ( (int) $entry['n'] > 1 ) {
			/* translators: 1: number of saves, 2: date and time of the first save. */
			$lines[] = sprintf( __( 'Saves: %1$s, the first at %2$s', 'lfw-change-notifications' ), number_format_i18n( (int) $entry['n'] ), wp_date( $format, (int) $entry['t'] ) );
		}

		if ( ! $post ) {
			$lines[] = __( 'This item has been deleted permanently.', 'lfw-change-notifications' );
		} elseif ( 'trash' === $post->post_status ) {
			/* translators: %s: link to the trash. */
			$lines[] = sprintf( __( 'Restore from the trash: %s', 'lfw-change-notifications' ), admin_url( 'edit.php?post_status=trash&post_type=' . rawurlencode( $type ) ) );
		} else {
			if ( 'publish' === $post->post_status ) {
				/* translators: %s: link to the item on the site. */
				$lines[] = sprintf( __( 'View: %s', 'lfw-change-notifications' ), get_permalink( $post ) );
			}
			if ( $obj && $obj->_edit_link ) {
				/* translators: %s: link to edit the item. */
				$lines[] = sprintf( __( 'Edit: %s', 'lfw-change-notifications' ), admin_url( sprintf( $obj->_edit_link . '&action=edit', $post->ID ) ) );
			}
			$compare = self::compare_link( $post );
			if ( $compare ) {
				/* translators: %s: link to the revision comparison screen. */
				$lines[] = sprintf( __( 'Compare revisions: %s', 'lfw-change-notifications' ), $compare );
			}
		}

		return array(
			'label' => $label,
			'title' => $title,
			'lines' => $lines,
		);
	}

	/**
	 * A readable label for the events of one entry, such as
	 * "Published, then updated".
	 *
	 * @param string[] $events Event names in order.
	 * @return string
	 */
	public static function label( $events ) {
		// The first event starts the sentence; later ones follow ", then".
		$first = array(
			'published'   => __( 'Published', 'lfw-change-notifications' ),
			'updated'     => __( 'Updated', 'lfw-change-notifications' ),
			'unpublished' => __( 'Unpublished', 'lfw-change-notifications' ),
			'trashed'     => __( 'Moved to trash', 'lfw-change-notifications' ),
			'deleted'     => __( 'Deleted', 'lfw-change-notifications' ),
			'scheduled'   => __( 'Scheduled', 'lfw-change-notifications' ),
		);
		$later = array(
			'published'   => _x( 'published', 'later event in a list', 'lfw-change-notifications' ),
			'updated'     => _x( 'updated', 'later event in a list', 'lfw-change-notifications' ),
			'unpublished' => _x( 'unpublished', 'later event in a list', 'lfw-change-notifications' ),
			'trashed'     => _x( 'moved to trash', 'later event in a list', 'lfw-change-notifications' ),
			'deleted'     => _x( 'deleted', 'later event in a list', 'lfw-change-notifications' ),
			'scheduled'   => _x( 'scheduled', 'later event in a list', 'lfw-change-notifications' ),
		);
		$parts = array();
		foreach ( array_slice( $events, 0, 6 ) as $event ) {
			$names = $parts ? $later : $first;
			if ( isset( $names[ $event ] ) ) {
				$parts[] = $names[ $event ];
			}
		}
		/* translators: separator between events, as in "Published, then updated". */
		return implode( __( ', then ', 'lfw-change-notifications' ), $parts );
	}

	/**
	 * Link to compare the latest revision with the one before, if the item
	 * has at least two revisions.
	 *
	 * @param WP_Post $post Post.
	 * @return string Empty when there is nothing to compare.
	 */
	private static function compare_link( $post ) {
		if ( ! wp_revisions_enabled( $post ) ) {
			return '';
		}
		$revisions = array_filter(
			wp_get_post_revisions(
				$post->ID,
				array(
					'posts_per_page' => 3,
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			),
			function ( $rev ) {
				return ! wp_is_post_autosave( $rev );
			}
		);
		if ( count( $revisions ) < 2 ) {
			return '';
		}
		return admin_url( 'revision.php?revision=' . (int) reset( $revisions )->ID );
	}

	/**
	 * Name a user, or say there was none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function person( $user_id ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : false;
		if ( ! $user ) {
			return $user_id
				? __( 'a user who has since been deleted', 'lfw-change-notifications' )
				: __( 'no signed-in user (WP-CLI or a scheduled publish)', 'lfw-change-notifications' );
		}
		// Display name only. A login username is half of a login, so it never goes
		// into an email that may be forwarded or stored outside the site.
		return self::plain( $user->display_name );
	}

	/**
	 * Closing lines: why the reader gets this and where to change it.
	 *
	 * @return string[]
	 */
	private static function footer() {
		return array(
			'--',
			/* translators: 1: site name, 2: site address. */
			sprintf( __( 'You get this email because your address is listed in Settings > Change Notifications on %1$s (%2$s).', 'lfw-change-notifications' ), self::site_name(), home_url( '/' ) ),
			/* translators: %s: link to the settings screen. */
			sprintf( __( 'Change who gets it, or how often: %s', 'lfw-change-notifications' ), admin_url( 'options-general.php?page=' . LFWCN_Settings::PAGE ) ),
		);
	}

	/**
	 * Hand a message to wp_mail().
	 *
	 * @param string[] $to      Recipients.
	 * @param string   $subject Subject.
	 * @param string   $message Plain-text body.
	 * @return bool
	 */
	private static function mail( $to, $subject, $message ) {
		$email = array(
			'to'      => array_values( array_filter( (array) $to, 'is_email' ) ),
			'subject' => self::plain( $subject ),
			'message' => $message,
			'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
		);

		/**
		 * Filters the email before it is handed to wp_mail().
		 *
		 * @param array $email to, subject, message and headers.
		 */
		$email = apply_filters( 'lfwcn_email', $email );
		if ( empty( $email['to'] ) ) {
			return false;
		}
		// One message per recipient, so no recipient sees the others' addresses.
		$sent = false;
		foreach ( array_slice( array_values( (array) $email['to'] ), 0, LFWCN_Settings::MAX_RECIPIENTS ) as $address ) {
			$sent = (bool) wp_mail( $address, $email['subject'], $email['message'], $email['headers'] ) || $sent;
		}
		return $sent;
	}

	/**
	 * The site name as plain text.
	 *
	 * @return string
	 */
	private static function site_name() {
		$name = self::plain( get_option( 'blogname' ) );
		return '' === $name ? wp_parse_url( home_url(), PHP_URL_HOST ) : $name;
	}

	/**
	 * Plain text for one line: entities decoded, tags and control characters
	 * removed, length capped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		return mb_substr( trim( (string) $text ), 0, 200 );
	}
}
