<?php
/**
 * Watches content changes and adds the ones that matter to the queue.
 *
 * Only changes made by a signed-in user, by WP-CLI, or by WordPress itself
 * publishing a scheduled item are recorded. A change made in a request with no
 * signed-in user (a front-end form plugin that creates posts, for example) is
 * ignored, so nothing a visitor submits can make the site send mail.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content change watcher.
 */
class LFWCN_Watcher {

	/**
	 * Which setting turns each event on.
	 *
	 * @var array<string,string>
	 */
	const EVENT_SETTING = array(
		'published'   => 'published',
		'updated'     => 'updated',
		'unpublished' => 'unpublished',
		'trashed'     => 'unpublished',
		'deleted'     => 'unpublished',
		'scheduled'   => 'scheduled',
	);

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 10, 2 );
	}

	/**
	 * Name the event for a status change, or null if it is not one we report.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @return string|null
	 */
	public static function event_for( $new_status, $old_status ) {
		if ( 'publish' === $new_status ) {
			return 'publish' === $old_status ? 'updated' : 'published';
		}
		if ( 'publish' === $old_status ) {
			return 'trash' === $new_status ? 'trashed' : 'unpublished';
		}
		if ( 'future' === $new_status && 'future' !== $old_status ) {
			return 'scheduled';
		}
		return null;
	}

	/**
	 * Status change handler.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		$event = self::event_for( $new_status, $old_status );
		if ( $event ) {
			self::maybe_record( $post, $event, array( 'from_schedule' => 'future' === $old_status ) );
		}
	}

	/**
	 * Permanent deletion of a published item that skipped the trash. Title
	 * and type are kept with the entry because the post will be gone.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public static function on_delete( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( $post && 'publish' === $post->post_status ) {
			self::maybe_record( $post, 'deleted', array() );
		}
	}

	/**
	 * Decide whether a change is skipped.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $event   Event name.
	 * @param array   $context Extra facts about the change.
	 * @return bool
	 */
	public static function should_skip( $post, $event, $context ) {
		$settings = LFWCN_Settings::get();
		$skip     = false;

		if ( ! $post instanceof WP_Post
			|| 'revision' === $post->post_type
			|| 'auto-draft' === $post->post_status
			|| wp_is_post_revision( $post )
			|| wp_is_post_autosave( $post )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		) {
			$skip = true;
		} elseif ( ! in_array( $post->post_type, $settings['post_types'], true ) || ! is_post_type_viewable( $post->post_type ) ) {
			$skip = true;
		} elseif ( ! in_array( self::EVENT_SETTING[ $event ], $settings['events'], true ) ) {
			$skip = true;
		} elseif ( $settings['ignore_imports'] && ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) ) {
			$skip = true;
		} elseif ( self::is_meta_box_resave() ) {
			// The block editor saves classic meta boxes in a second request right
			// after the real save. It is the same change, so it is not reported twice.
			$skip = true;
		} elseif ( ! get_current_user_id() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( 'published' === $event && ! empty( $context['from_schedule'] ) ) ) {
			// No signed-in user, not WP-CLI, not WordPress publishing a scheduled
			// item: this change did not come from someone who edits the site.
			$skip = true;
		} elseif ( ! LFWCN_Settings::recipients() ) {
			$skip = true;
		}

		/**
		 * Filters whether a content change is left out of notifications.
		 *
		 * @param bool    $skip    Whether the change is skipped.
		 * @param WP_Post $post    The post.
		 * @param string  $event   published, updated, unpublished, trashed, deleted or scheduled.
		 * @param array   $context Extra facts about the change.
		 */
		return (bool) apply_filters( 'lfwcn_skip_change', $skip, $post, $event, $context );
	}

	/**
	 * Record a change unless it is skipped.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $event   Event name.
	 * @param array   $context Extra facts about the change.
	 */
	private static function maybe_record( $post, $event, $context ) {
		if ( self::should_skip( $post, $event, $context ) ) {
			return;
		}
		$snapshot = array();
		if ( 'deleted' === $event ) {
			$snapshot = array(
				'title' => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 200 ),
				'type'  => $post->post_type,
			);
		}
		$immediate = 'immediate' === LFWCN_Settings::get()['delivery'];
		$due       = LFWCN_Queue::record( $post->ID, $event, get_current_user_id(), $snapshot, $immediate );
		if ( $immediate && null !== $due ) {
			LFWCN_Delivery::queued( $due );
		}
	}

	/**
	 * Whether this request is the block editor's follow-up save of classic
	 * meta boxes.
	 *
	 * @return bool
	 */
	private static function is_meta_box_resave() {
		// Only checks for the flag WordPress adds to its own follow-up request; no data is used.
		return is_admin() && ! empty( $_GET['meta-box-loader'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
