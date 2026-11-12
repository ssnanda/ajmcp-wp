<?php
/**
 * AJCore chat integration without patching AJCore: observe successful visitor-message REST calls,
 * dispatch a background job, then use AJCore's protected auto-reply endpoint to deliver.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Chat {
    public static function init(): void {
        add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'observe' ), 10, 3 );
        add_action( 'ajmcp_wp_process_chat', array( __CLASS__, 'process' ), 10, 3 );
        // Optional explicit integration hook for AJCore or other trusted plugins in future.
        add_action( 'ajmcp_wp_visitor_chat_message', array( __CLASS__, 'schedule' ), 10, 2 );
    }

    public static function observe( $response, $handler, $request ) {
        $settings = AJMCP_WP_Client::options();
        if ( empty( $settings['enabled'] ) || empty( $settings['chat_enabled'] ) ) {
            return $response;
        }
        if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
            return $response;
        }
        if ( ! preg_match( '~^/ajcore/v1/chat/sessions/([1-9][0-9]*)/messages/?$~D', $request->get_route(), $match ) ) {
            return $response;
        }
        if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response || $response->get_status() >= 300 ) {
            return $response;
        }
        $data = $response->get_data();
        $message = is_array( $data ) && isset( $data['message'] ) && is_array( $data['message'] ) ? $data['message'] : array();
        $message_id = absint( $message['id'] ?? 0 );
        if ( $message_id && ( $message['senderType'] ?? '' ) === 'visitor' ) {
            self::schedule( absint( $match[1] ), $message_id );
        }
        return $response;
    }

    public static function schedule( int $session_id, int $message_id ): void {
        if ( $session_id < 1 || $message_id < 1 || get_transient( self::done_key( $message_id ) ) ) {
            return;
        }
        $args = array( $session_id, $message_id, 0 );
        if ( false === wp_next_scheduled( 'ajmcp_wp_process_chat', $args ) ) {
            // The original message has already been stored; the AI network call does not delay chat ingestion.
            wp_schedule_single_event( time() + 1, 'ajmcp_wp_process_chat', $args );
        }
    }

    public static function process( int $session_id, int $message_id, int $attempt = 0 ): void {
        if ( $session_id < 1 || $message_id < 1 || $attempt > 2 || get_transient( self::done_key( $message_id ) ) ) {
            return;
        }
        $settings = AJMCP_WP_Client::options();
        if ( empty( $settings['enabled'] ) || empty( $settings['chat_enabled'] ) ) {
            return;
        }
        $lock = 'ajmcp_wp_running_' . $message_id;
        $existing = (int) get_option( $lock, 0 );
        if ( $existing && $existing < time() - 180 ) {
            delete_option( $lock ); // Recover a previous worker terminated by PHP.
        }
        if ( ! add_option( $lock, time(), '', false ) ) {
            return;
        }
        try {
            $db = self::db();
            if ( ! $db ) {
                self::retry( $session_id, $message_id, $attempt, 'ajcore_database_unavailable' );
                return;
            }
            if ( ! self::eligible( $db, $session_id, $message_id ) ) {
                self::mark_done( $message_id );
                return;
            }
            $message = self::message( $db, $session_id, $message_id );
            if ( ! $message || 'visitor' !== $message['sender_type'] ) {
                self::mark_done( $message_id );
                return;
            }
            $history = self::history( $db, $session_id, $message_id );
            $result = AJMCP_WP_Client::request( '/v1/ajcore/reply', 'POST', array(
                'sessionId' => $session_id,
                'message'   => self::truncate( (string) $message['body'], 6000 ),
                'history'   => $history,
            ) );
            if ( is_wp_error( $result ) ) {
                $status = (array) $result->get_error_data();
                self::retry( $session_id, $message_id, $attempt, $result->get_error_code() . ( isset( $status['status'] ) ? '_' . (int) $status['status'] : '' ), ! empty( $status['status'] ) && (int) $status['status'] >= 400 && (int) $status['status'] < 500 && 429 !== (int) $status['status'] );
                return;
            }
            $reply = sanitize_textarea_field( (string) ( $result['reply'] ?? '' ) );
            $reply = self::truncate( trim( $reply ), 4000 );
            if ( '' === $reply || (int) ( $result['sessionId'] ?? 0 ) !== $session_id ) {
                self::mark_done( $message_id );
                return;
            }
            // Recheck AFTER the AI call. Staff or rule-based automations may have replied meanwhile.
            if ( ! self::eligible( $db, $session_id, $message_id ) ) {
                self::mark_done( $message_id );
                return;
            }
            $sent = self::reply_through_ajcore( $session_id, $reply, absint( $settings['ops_user_id'] ) );
            if ( is_wp_error( $sent ) ) {
                self::retry( $session_id, $message_id, $attempt, $sent->get_error_code() );
                return;
            }
            self::mark_done( $message_id );
        } catch ( Throwable $error ) {
            // Do not record prompts, customer data, or response content in application logs.
            self::retry( $session_id, $message_id, $attempt, 'exception' );
        } finally {
            delete_option( $lock );
        }
    }

    public static function db() {
        if ( function_exists( 'ajcore_get_portal_db' ) ) {
            return ajcore_get_portal_db();
        }
        return null; // Never guess the location of AJCore's shared database.
    }

    public static function table( $db, string $suffix ): string {
        $table = $db->prefix . $suffix;
        return preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ? $table : '';
    }

    private static function eligible( $db, int $session_id, int $message_id ): bool {
        $s = self::table( $db, 'aj_portal_chat_sessions' );
        $m = self::table( $db, 'aj_portal_chat_messages' );
        if ( ! $s || ! $m ) {
            return false;
        }
        $session = $db->get_row( $db->prepare( "SELECT site_uuid, status, assigned_staff_id, assigned_staff_name, automation_muted FROM `{$s}` WHERE id = %d", $session_id ), ARRAY_A );
        if ( ! is_array( $session ) || 'open' !== (string) $session['status'] || ! empty( $session['automation_muted'] )
            || ! empty( $session['assigned_staff_id'] ) || ! empty( $session['assigned_staff_name'] ) ) {
            return false;
        }
        $expected_site_uuid = AJMCP_WP_Client::site_uuid();
        if ( '' === $expected_site_uuid || ! hash_equals( $expected_site_uuid, (string) $session['site_uuid'] ) ) {
            return false;
        }
        $latest = (int) $db->get_var( $db->prepare( "SELECT id FROM `{$m}` WHERE session_id = %d ORDER BY id DESC LIMIT 1", $session_id ) );
        return $latest === $message_id;
    }

    private static function message( $db, int $session_id, int $message_id ): ?array {
        $table = self::table( $db, 'aj_portal_chat_messages' );
        if ( ! $table ) {
            return null;
        }
        $row = $db->get_row( $db->prepare( "SELECT sender_type, body FROM `{$table}` WHERE session_id = %d AND id = %d", $session_id, $message_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function history( $db, int $session_id, int $before_id ): array {
        $table = self::table( $db, 'aj_portal_chat_messages' );
        if ( ! $table ) {
            return array();
        }
        $rows = $db->get_results( $db->prepare(
            "SELECT sender_type, body FROM `{$table}` WHERE session_id = %d AND id < %d AND sender_type IN ('visitor','staff') ORDER BY id DESC LIMIT 8",
            $session_id, $before_id
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        // The server rejects empty messages (e.g. a file-only chat message), so leave them out.
        $rows = array_values( array_filter( $rows, static function ( $row ) {
            return '' !== trim( (string) $row['body'] );
        } ) );
        return array_map( static function ( $row ) {
            return array(
                'role'    => 'visitor' === $row['sender_type'] ? 'user' : 'assistant',
                'content' => self::truncate( (string) $row['body'], 2500 ),
            );
        }, array_reverse( $rows ) );
    }

    private static function reply_through_ajcore( int $session_id, string $body, int $service_user_id ) {
        if ( ! $service_user_id || ! function_exists( 'rest_do_request' ) ) {
            return new WP_Error( 'ajmcp_ops_user_missing', 'AJCore operations user is not configured.' );
        }
        $user = get_user_by( 'id', $service_user_id );
        if ( ! $user || ( ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'ajcore_ops_access' ) && ! in_array( 'aj_ops_user', (array) $user->roles, true ) ) ) {
            return new WP_Error( 'ajmcp_ops_user_invalid', 'AJCore operations service user is not permitted.' );
        }
        $previous_user_id = get_current_user_id();
        try {
            // Invoke AJCore's OWN permission checks and auto-reply side effects (including AJOps WS notification).
            wp_set_current_user( $service_user_id );
            $request = new WP_REST_Request( 'POST', '/ajcore/v1/ops/chat/sessions/' . $session_id . '/auto-reply' );
            $request->set_param( 'body', $body );
            $request->set_param( 'senderName', 'AJMCP AI' );
            $response = rest_do_request( $request );
            if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response || $response->get_status() >= 300 ) {
                return new WP_Error( 'ajmcp_ajcore_send_failed', 'AJCore did not accept the automated reply.' );
            }
            return true;
        } finally {
            wp_set_current_user( $previous_user_id );
        }
    }

    private static function retry( int $session_id, int $message_id, int $attempt, string $reason = 'error', bool $permanent = false ): void {
        if ( $permanent || $attempt >= 2 ) {
            // Stop after three attempts, or at once for a permanent 4xx (bad token, model, request).
            AJMCP_WP_Replies::note_failure( $session_id, $message_id, $reason );
            self::mark_done( $message_id );
            return;
        }
        $delay = ( $attempt + 1 ) * 60;
        wp_schedule_single_event( time() + $delay, 'ajmcp_wp_process_chat', array( $session_id, $message_id, $attempt + 1 ) );
    }

    private static function truncate( string $value, int $max ): string {
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    private static function done_key( int $message_id ): string {
        return 'ajmcp_wp_done_' . $message_id;
    }

    private static function mark_done( int $message_id ): void {
        // Auto-expire deduplication records with a periodic lightweight cleanup (not needed for correctness).
        set_transient( self::done_key( $message_id ), 1, 7 * DAY_IN_SECONDS );
    }
}
