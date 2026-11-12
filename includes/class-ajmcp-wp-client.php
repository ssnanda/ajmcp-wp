<?php
/**
 * Server-side AJMCP HTTP client. Never exposes a site bearer token to front-end code.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Client {
    public static function options(): array {
        $defaults = array(
            'enabled'         => false,
            'chat_enabled'    => false,
            'server_url'      => '',
            'site_token'      => '',
            'site_uuid'       => '',
            'ops_user_id'     => 0,
        );
        $options = get_option( 'ajmcp_wp_settings', array() );
        return array_merge( $defaults, is_array( $options ) ? $options : array() );
    }

    public static function token(): string {
        // Prefer a wp-config.php constant; a saved token is optional for easier small-site setup.
        if ( defined( 'AJMCP_WP_SITE_TOKEN' ) && is_string( AJMCP_WP_SITE_TOKEN ) && '' !== AJMCP_WP_SITE_TOKEN ) {
            return AJMCP_WP_SITE_TOKEN;
        }
        $settings = self::options();
        return is_string( $settings['site_token'] ) ? $settings['site_token'] : '';
    }

    public static function site_uuid(): string {
        $settings = self::options();
        $expected = sanitize_text_field( (string) $settings['site_uuid'] );
        if ( '' !== $expected ) {
            return $expected;
        }
        return sanitize_text_field( (string) get_option( 'ajcore_site_uuid', '' ) );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function request( string $path, string $method = 'GET', array $payload = array() ) {
        $settings = self::options();
        $base = trim( (string) $settings['server_url'] );
        $token = self::token();
        if ( ! self::valid_server_url( $base ) || strlen( $token ) < 32 ) {
            return new WP_Error( 'ajmcp_not_configured', __( 'AJMCP server or site token is not configured.', 'ajmcp-wp' ) );
        }
        // No arbitrary endpoints, hosts, or URL paths supplied by users.
        $allowed_paths = array( '/v1/capabilities', '/v1/chat', '/v1/ajcore/reply', '/v1/assistant' );
        $is_assistant  = '/v1/assistant' === $path;
        if ( ! in_array( $path, $allowed_paths, true ) ) {
            return new WP_Error( 'ajmcp_forbidden_path', __( 'This AJMCP operation is not permitted.', 'ajmcp-wp' ) );
        }
        $url = untrailingslashit( $base ) . $path;
        $args = array(
            'method'             => $method,
            'timeout'            => 100, // Above the server's 90s CLI limit, so a slow reply is not retried mid-flight.
            'redirection'        => 0,
            'reject_unsafe_urls' => true,
            'headers'            => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'limit_response_size' => $is_assistant ? 262144 : 131072,
        );
        if ( 'POST' === $method ) {
            $encoded = wp_json_encode( $payload );
            if ( false === $encoded || strlen( $encoded ) > ( $is_assistant ? 200000 : 32768 ) ) {
                return new WP_Error( 'ajmcp_payload_size', __( 'AI request is too large.', 'ajmcp-wp' ) );
            }
            $args['body'] = $encoded;
        }
        $response = wp_safe_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            // Do not add token, prompt, or raw HTTP response details to errors/logs.
            return new WP_Error( 'ajmcp_unreachable', __( 'AJMCP server could not be reached.', 'ajmcp-wp' ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'ajmcp_remote_error', __( 'AJMCP server rejected the request.', 'ajmcp-wp' ), array( 'status' => $status ) );
        }
        return $data;
    }

    public static function valid_server_url( string $url ): bool {
        if ( '' === $url || strlen( $url ) > 300 ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
            return false;
        }
        if ( empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
            return false;
        }
        $path = (string) ( $parts['path'] ?? '' );
        return '' === $path || '/' === $path;
    }
}
