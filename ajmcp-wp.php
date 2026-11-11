<?php
/**
 * Plugin Name: AJMCP WordPress Connector
 * Plugin URI: https://github.com/ssnanda/ajmcp-wp
 * Description: Securely connects WordPress and AJCore Live Chat to an AJMCP AI gateway.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: AJMCP
 * License: GPL-2.0-or-later
 * Text Domain: ajmcp-wp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AJMCP_WP_VERSION', '0.1.0' );
define( 'AJMCP_WP_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-ajmcp-wp-client.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-settings.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-chat.php';

add_action( 'plugins_loaded', static function () {
    AJMCP_WP_Settings::init();
    AJMCP_WP_Chat::init();
} );

register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( 'ajmcp_wp_process_chat' );
} );
