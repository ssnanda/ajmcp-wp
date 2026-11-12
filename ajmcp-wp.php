<?php
/**
 * Plugin Name: AJMCP WordPress Connector
 * Plugin URI: https://github.com/ssnanda/ajmcp-wp
 * Description: Connects WordPress and AJCore Live Chat to an AJMCP AI gateway, and gives Claude and Codex controlled access to the site (formerly AJ WP AI).
 * Version: 0.1.5
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: ITSpector LLC
 * License: GPL-2.0-or-later
 * Text Domain: ajmcp-wp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AJMCP_WP_VERSION', '0.1.5' );
define( 'AJMCP_WP_FILE', __FILE__ );
define( 'AJMCP_WP_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-ajmcp-wp-client.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-settings.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-chat.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-replies.php';
require_once __DIR__ . '/includes/class-ajmcp-wp-assistant.php';

/**
 * The AI module is the Codex chat from the former AJ WP AI plugin (the Claude connector was dropped). It keeps its
 * class names, tables and options, so existing connections and history carry over. It must not load
 * while the separate AJ WP AI plugin is still active, or the classes would be declared twice.
 */
function ajmcp_wp_ai_module_active() {
    return ! in_array( 'ajwpai/ajwpai.php', (array) get_option( 'active_plugins', array() ), true )
        && ! defined( 'AJWPAI_VERSION' );
}

if ( ajmcp_wp_ai_module_active() ) {
    define( 'AJWPAI_VERSION', AJMCP_WP_VERSION );
    define( 'AJWPAI_DIR', AJMCP_WP_DIR . 'modules/ai/' );
    define( 'AJWPAI_FILE', __FILE__ );

    require_once AJWPAI_DIR . 'includes/class-ajwpai-code.php';
    require_once AJWPAI_DIR . 'includes/class-ajwpai-ajcore.php';
    require_once AJWPAI_DIR . 'admin/class-ajwpai-admin.php';
}

add_action( 'plugins_loaded', static function () {
    AJMCP_WP_Settings::init();
    AJMCP_WP_Chat::init();
    AJMCP_WP_Assistant::init();
    if ( defined( 'AJWPAI_DIR' ) && AJWPAI_DIR === AJMCP_WP_DIR . 'modules/ai/' ) {
        AJWPAI_Code::init();
        AJWPAI_AJCore::init();
        if ( is_admin() ) {
            AJWPAI_Admin::init();
        }
    }
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( $links ) {
    array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=ajmcp-wp-settings' ) ) . '">Settings</a>' );
    return $links;
} );

add_action( 'admin_notices', static function () {
    if ( current_user_can( 'activate_plugins' ) && in_array( 'ajwpai/ajwpai.php', (array) get_option( 'active_plugins', array() ), true ) ) {
        echo '<div class="notice notice-warning"><p>AJMCP now includes AJ WP AI. Deactivate the separate AJ WP AI plugin to use the merged version.</p></div>';
    }
} );

register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( 'ajmcp_wp_process_chat' );
} );
