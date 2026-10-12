<?php
/**
 * Test harness only. Mounted as an mu-plugin in the throwaway WordPress that
 * test.sh starts; never shipped in the plugin itself.
 *
 * - Captures every wp_mail() call into the lfwcn_test_mail option instead of
 *   sending it.
 * - Moves the plugin's clock forward by the lfwcn_test_offset option, so a
 *   test can let ten minutes pass in an instant.
 * - ?lfwcn_test_visitor_post=1 creates a published post with no signed-in
 *   user, the way a front-end submission plugin would.
 *
 * @package lfw-change-notifications
 */

add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		$log   = get_option( 'lfwcn_test_mail', array() );
		$log[] = array(
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( 'lfwcn_test_mail', $log, false );
		return true;
	},
	10,
	2
);

add_filter(
	'lfwcn_now',
	function ( $now ) {
		return $now + (int) get_option( 'lfwcn_test_offset', 0 );
	}
);

add_action(
	'template_redirect',
	function () {
		if ( isset( $_GET['lfwcn_test_visitor_post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_insert_post(
				array(
					'post_title'  => 'Submitted by a visitor',
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
			echo 'created';
			exit;
		}
	}
);
