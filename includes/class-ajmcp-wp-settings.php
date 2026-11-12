<?php
/** AJMCP settings page, strictly administrator only. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Settings {
    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_ajmcp_wp_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_ajmcp_wp_test', array( __CLASS__, 'test_connection' ) );
    }

    public static function menu(): void {
        // One top-level menu: Conversations, Chat (from the AI module), then Settings.
        add_menu_page( 'AJMCP Conversations', 'AJMCP', 'manage_options', 'ajmcp-wp', array( 'AJMCP_WP_Replies', 'page' ), 'dashicons-format-chat', 58 );
        add_submenu_page( 'ajmcp-wp', 'AJMCP Conversations', 'Conversations', 'manage_options', 'ajmcp-wp', array( 'AJMCP_WP_Replies', 'page' ) );
        add_submenu_page( 'ajmcp-wp', 'AJMCP Leads', 'Leads', 'manage_options', 'ajmcp-wp-leads', array( 'AJMCP_WP_Leads_Page', 'page' ) );
        // Only the merged module has register_menus(); the old standalone AJ WP AI plugin keeps its own menu.
        if ( class_exists( 'AJWPAI_Admin' ) && method_exists( 'AJWPAI_Admin', 'register_menus' ) ) {
            AJWPAI_Admin::register_menus( 'ajmcp-wp' );
        }
        add_submenu_page( 'ajmcp-wp', 'AJMCP Settings', 'Settings', 'manage_options', 'ajmcp-wp-settings', array( __CLASS__, 'page' ) );
    }

    private static function verify_admin( string $nonce_action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to configure AJMCP.', 'ajmcp-wp' ) );
        }
        check_admin_referer( $nonce_action );
    }

    public static function save(): void {
        self::verify_admin( 'ajmcp_wp_save' );
        $old = AJMCP_WP_Client::options();
        $url = esc_url_raw( trim( (string) wp_unslash( $_POST['server_url'] ?? '' ) ) );
        if ( '' !== $url && ! AJMCP_WP_Client::valid_server_url( $url ) ) {
            self::redirect( 'invalid_url' );
        }
        $new_token = trim( (string) wp_unslash( $_POST['site_token'] ?? '' ) );
        $token = $old['site_token'];
        if ( ! empty( $_POST['clear_site_token'] ) ) {
            $token = '';
        } elseif ( '' !== $new_token ) {
            if ( strlen( $new_token ) < 32 || strlen( $new_token ) > 512 || preg_match( '/\s/', $new_token ) ) {
                self::redirect( 'invalid_token' );
            }
            $token = $new_token;
        }
        $new = array(
            'enabled'       => ! empty( $_POST['enabled'] ),
            'chat_enabled'  => ! empty( $_POST['chat_enabled'] ),
            'server_url'    => $url,
            'site_token'    => $token,
            'site_uuid'     => sanitize_text_field( (string) wp_unslash( $_POST['site_uuid'] ?? '' ) ),
            'ops_user_id'   => absint( wp_unslash( $_POST['ops_user_id'] ?? 0 ) ),
        );
        if ( $new['ops_user_id'] ) {
            $user = get_user_by( 'id', $new['ops_user_id'] );
            if ( ! $user || ( ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'ajcore_ops_access' ) && ! in_array( 'aj_ops_user', (array) $user->roles, true ) ) ) {
                self::redirect( 'invalid_user' );
            }
        }
        // Disable autoload: a stored bearer token should not be loaded into every WP request.
        update_option( 'ajmcp_wp_settings', $new, false );
        delete_transient( 'ajmcp_wp_server_info' );
        self::redirect( 'saved' );
    }

    public static function test_connection(): void {
        self::verify_admin( 'ajmcp_wp_test' );
        $info = AJMCP_WP_Client::server_info( true );
        self::redirect( $info['ok'] ? 'connected' : 'failed' );
    }

    private static function redirect( string $status ): void {
        wp_safe_redirect( add_query_arg( array( 'page' => 'ajmcp-wp-settings', 'ajmcp_status' => $status ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = AJMCP_WP_Client::options();
        $status = sanitize_key( (string) ( $_GET['ajmcp_status'] ?? '' ) );
        $notices = array(
            'saved'         => 'Settings saved.',
            'connected'     => 'AJMCP server authenticated successfully.',
            'failed'        => 'Connection failed; verify the server URL, site token and OpenShift Route.',
            'invalid_url'  => 'Use an HTTPS AJMCP base URL without path, query, or credentials.',
            'invalid_token'=> 'Site token must be 32-512 characters without whitespace.',
            'invalid_user' => 'Select a WordPress administrator or an AJCore operations user.',
        );
        ?>
        <div class="wrap">
            <h1>AJMCP WordPress Connector <small>v<?php echo esc_html( AJMCP_WP_VERSION ); ?></small></h1>
            <?php $server = ! empty( $settings['enabled'] ) ? AJMCP_WP_Client::server_info() : null; ?>
            <p>
                <?php if ( ! $server ) : ?>
                    Server: connector switched off.
                <?php elseif ( $server['ok'] ) : ?>
                    Server: <strong>v<?php echo esc_html( $server['version'] ); ?></strong> &middot; site <code><?php echo esc_html( $server['site'] ); ?></code> &middot; <?php echo esc_html( $server['provider'] ); ?> &middot; <span style="color:#00a32a">connected</span>
                <?php else : ?>
                    Server: <span style="color:#d63638">not reachable</span>
                <?php endif; ?>
            </p>
            <?php if ( isset( $notices[ $status ] ) ) : ?>
                <div class="notice notice-info"><p><?php echo esc_html( $notices[ $status ] ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ajmcp_wp_save" />
                <?php wp_nonce_field( 'ajmcp_wp_save' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th>Enable connector</th><td><label><input name="enabled" type="checkbox" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> Enabled</label></td></tr>
                    <tr><th><label for="ajmcp-url">AJMCP Server URL</label></th><td><input id="ajmcp-url" name="server_url" class="regular-text" type="url" placeholder="https://mcp.example.com" value="<?php echo esc_attr( $settings['server_url'] ); ?>" /></td></tr>
                    <tr><th><label for="ajmcp-token">Site token</label></th><td><input id="ajmcp-token" name="site_token" type="password" autocomplete="new-password" class="regular-text" value="" placeholder="<?php echo esc_attr( AJMCP_WP_Client::token() ? 'Configured (leave blank to keep)' : 'Enter 32+ character site token' ); ?>" /><p class="description">For better security set <code>AJMCP_WP_SITE_TOKEN</code> in wp-config.php. The value is never sent to browsers.</p><label><input type="checkbox" name="clear_site_token" value="1" /> Clear stored site token</label></td></tr>
                    <tr><th><label for="ajmcp-site">AJCore site UUID</label></th><td><input id="ajmcp-site" name="site_uuid" class="regular-text" value="<?php echo esc_attr( $settings['site_uuid'] ); ?>" /><p class="description">Optional; blank uses AJCore's local site UUID. Ensures this connector answers only this website's chats.</p></td></tr>
                    <tr><th>AI auto-replies for AJCore Live Chat</th><td><label><input type="checkbox" name="chat_enabled" value="1" <?php checked( ! empty( $settings['chat_enabled'] ) ); ?> /> Enable AI replies (off by default)</label><p class="description">Runs only where AJCore receives the inbound chat REST request. Human takeover and existing automation take priority.</p></td></tr>
                    <tr><th><label for="ajmcp-ops">AJCore operations service user ID</label></th><td><input type="number" min="0" id="ajmcp-ops" name="ops_user_id" value="<?php echo esc_attr( $settings['ops_user_id'] ); ?>" /><p class="description">A dedicated WordPress user with AJCore OPS write access. Required for sending replies via AJCore's protected internal REST API.</p></td></tr>
                </table>
                <?php submit_button( 'Save AJMCP Settings' ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ajmcp_wp_test" />
                <?php wp_nonce_field( 'ajmcp_wp_test' ); ?>
                <?php submit_button( 'Test Server Connection', 'secondary' ); ?>
            </form>
            <p><strong>Important:</strong> Configure AJMCP site credentials on your OpenShift server first. A successful connection test does not enable automatic replies. On production sites use a reliable system cron for WP-Cron.</p>
        </div>
        <?php
    }
}
