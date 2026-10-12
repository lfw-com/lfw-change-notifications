<?php
/**
 * Plugin Name:       LFW Change Notifications
 * Plugin URI:        https://github.com/lfw-com/lfw-change-notifications
 * Description:       Emails the people you choose when content is published, updated, unpublished or scheduled. Sends right away or as an hourly or daily digest, and folds bulk edits and repeated saves into one message.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            LFW.com LLC
 * Author URI:        https://lfw.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lfw-change-notifications
 *
 * Everything runs on the site itself. Mail goes out through wp_mail(), so it
 * uses whatever mail setup the site already has. Nothing here calls an outside
 * service, and no LFW account is needed.
 *
 * Abuse case: only signed-in content changes reach the queue, so a visitor
 * cannot make the site send mail; only administrators choose recipients
 * (validated, capped at 20); digests, per-post collapse and a burst limit keep
 * a bulk edit or an import to one message. Details in readme.txt.
 *
 * @package lfw-change-notifications
 */

defined( 'ABSPATH' ) || exit;

define( 'LFWCN_VERSION', '1.0.0' );
define( 'LFWCN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-lfwcn-settings.php';
require_once __DIR__ . '/includes/class-lfwcn-queue.php';
require_once __DIR__ . '/includes/class-lfwcn-watcher.php';
require_once __DIR__ . '/includes/class-lfwcn-mailer.php';
require_once __DIR__ . '/includes/class-lfwcn-delivery.php';

LFWCN_Settings::init();
LFWCN_Watcher::init();
LFWCN_Delivery::init();

register_activation_hook( __FILE__, array( 'LFWCN_Delivery', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LFWCN_Delivery', 'deactivate' ) );
