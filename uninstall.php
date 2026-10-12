<?php
/**
 * Uninstall: remove settings, the queue, the state, the lock and scheduled
 * events. Runs on every site of a multisite network.
 *
 * @package lfw-change-notifications
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything this plugin stored on the current site.
 */
function lfwcn_uninstall_site() {
	foreach ( array( 'lfwcn_settings', 'lfwcn_queue', 'lfwcn_state', 'lfwcn_lock' ) as $lfwcn_option ) {
		delete_option( $lfwcn_option );
	}
	delete_transient( 'lfwcn_test_sent' );
	wp_clear_scheduled_hook( 'lfwcn_flush' );
	wp_clear_scheduled_hook( 'lfwcn_digest' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $lfwcn_site_id ) {
		switch_to_blog( $lfwcn_site_id );
		lfwcn_uninstall_site();
		restore_current_blog();
	}
} else {
	lfwcn_uninstall_site();
}
