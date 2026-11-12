<?php
/**
 * Staff assistant for wp-admin. The AJMCP server decides the next step (answer or call a tool); the tools
 * run here in WordPress, as the logged-in administrator, through the existing /ajwpai/v1/code/* routes.
 * State-changing tools are shown to the administrator for approval before they run (see the page script).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Assistant {
    const MAX_MESSAGES = 60;
    const MAX_CHARS    = 70000;

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes(): void {
        $args = array( 'permission_callback' => static function () { return current_user_can( 'manage_options' ); } );
        register_rest_route( 'ajmcp/v1', '/assistant/config', array_merge( $args, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'config' ) ) ) );
        register_rest_route( 'ajmcp/v1', '/assistant/status', array_merge( $args, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ) ) ) );
        register_rest_route( 'ajmcp/v1', '/assistant/turn', array_merge( $args, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'turn' ) ) ) );
    }

    private static function obj( array $properties = array(), array $required = array() ): array {
        $schema = array( 'type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false );
        if ( $required ) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** name => description, JSON schema, REST route, and (for tools that change something) the approval card text. */
    public static function tools(): array {
        $post_type = array( 'type' => 'string', 'enum' => array( 'post', 'page' ) );
        $limit     = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 );
        $int       = array( 'type' => 'integer' );
        $str       = array( 'type' => 'string' );
        $tools     = array(
            'list_posts' => array( 'description' => 'List posts or pages of this site.', 'route' => 'posts', 'schema' => self::obj( array( 'post_type' => $post_type, 'search' => $str, 'limit' => $limit ) ) ),
            'get_design_catalog' => array( 'description' => 'Get the active theme patterns, blog block styles and layout rules. Call before creating a designed blog.', 'route' => 'design-catalog', 'schema' => self::obj() ),
            'create_draft' => array(
                'description' => 'Create a post or page as a DRAFT (never published).', 'route' => 'draft',
                'schema' => self::obj( array( 'post_type' => $post_type, 'title' => $str, 'content' => $str, 'excerpt' => $str ), array( 'title' ) ),
                'approve' => array( 'Create draft', 'Create this WordPress draft?', 'Create draft' ),
            ),
            'create_designed_draft' => array(
                'description' => 'Create a polished DRAFT made only from native Gutenberg core/AJNanda blocks (headings, lists, callouts, cards, FAQ, CTA). Never Custom HTML or inline CSS. Call get_design_catalog first. content is serialized block markup.', 'route' => 'designed-draft',
                'schema' => self::obj( array( 'post_type' => $post_type, 'title' => $str, 'excerpt' => $str, 'content' => $str ), array( 'title', 'content' ) ),
                'approve' => array( 'Create designed draft', 'Create this block-based WordPress draft?', 'Create draft' ),
            ),
        );
        if ( class_exists( 'AJCore_REST_API' ) ) {
            $tools += array(
                'search_leads' => array( 'description' => 'Search AJCore leads (contact data minimized).', 'route' => 'ajcore/leads', 'schema' => self::obj( array( 'search' => $str, 'limit' => $limit ) ) ),
                'get_lead' => array( 'description' => 'Get one AJCore lead and its notes.', 'route' => 'ajcore/lead', 'schema' => self::obj( array( 'lead_id' => $int ), array( 'lead_id' ) ) ),
                'add_lead_note' => array( 'description' => 'Add an internal note to a lead.', 'route' => 'ajcore/lead-note', 'schema' => self::obj( array( 'lead_id' => $int, 'note' => $str ), array( 'lead_id', 'note' ) ), 'approve' => array( 'Add lead note', 'Add this internal note to the lead?', 'Add note' ) ),
                'tag_lead' => array( 'description' => "Replace a lead's internal tags.", 'route' => 'ajcore/lead-tags', 'schema' => self::obj( array( 'lead_id' => $int, 'tags' => array( 'type' => 'array', 'items' => $str, 'maxItems' => 20 ) ), array( 'lead_id', 'tags' ) ), 'approve' => array( 'Update lead tags', "Replace this lead's internal tags?", 'Update tags' ) ),
                'update_lead_status' => array( 'description' => "Update a lead's pipeline status (new, auto_reached, reached, engaged, tour, customer, future_follow_up, lost).", 'route' => 'ajcore/lead-status', 'schema' => self::obj( array( 'lead_id' => $int, 'status' => $str, 'note' => $str, 'follow_up_at' => $str, 'customer_name' => $str ), array( 'lead_id', 'status' ) ), 'approve' => array( 'Update lead status', "Change this lead's pipeline status?", 'Update status' ) ),
                'send_lead_email' => array( 'description' => 'Send one email to a lead through AJ Core Mail. Supports {name}, {email}, {site_name}.', 'route' => 'ajcore/lead-email', 'schema' => self::obj( array( 'lead_id' => $int, 'subject' => $str, 'body' => $str ), array( 'lead_id', 'subject', 'body' ) ), 'approve' => array( 'Send lead email', 'Send this email through AJ Core Mail?', 'Send email' ) ),
                'send_lead_campaign' => array( 'description' => 'Send one message to up to 50 selected leads. Supports {name}, {email}, {site_name}.', 'route' => 'ajcore/lead-campaign', 'schema' => self::obj( array( 'lead_ids' => array( 'type' => 'array', 'items' => $int, 'maxItems' => 50 ), 'subject' => $str, 'body' => $str ), array( 'lead_ids', 'subject', 'body' ) ), 'approve' => array( 'Send lead campaign', 'Send this message to every selected lead?', 'Send campaign' ) ),
                'list_live_chats' => array( 'description' => 'List AJCore live-chat sessions.', 'route' => 'ajcore/chats', 'schema' => self::obj( array( 'status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ), 'search' => $str, 'limit' => $limit ) ) ),
                'get_live_chat' => array( 'description' => 'Read one live-chat conversation.', 'route' => 'ajcore/chat', 'schema' => self::obj( array( 'chat_id' => $int ), array( 'chat_id' ) ) ),
                'reply_live_chat' => array( 'description' => 'Send a staff reply to a live chat (pauses automation).', 'route' => 'ajcore/chat-reply', 'schema' => self::obj( array( 'chat_id' => $int, 'body' => $str ), array( 'chat_id', 'body' ) ), 'approve' => array( 'Send live-chat reply', 'Send this reply to the visitor now?', 'Send reply' ) ),
                'escalate_live_chat' => array( 'description' => 'Claim a live chat for the current administrator and pause automation.', 'route' => 'ajcore/chat-escalate', 'schema' => self::obj( array( 'chat_id' => $int ), array( 'chat_id' ) ), 'approve' => array( 'Escalate live chat', 'Claim this chat and pause automation?', 'Escalate' ) ),
            );
        }
        return $tools;
    }

    /** Tool list for the page script (labels and routes) — the server only ever sees name, description and schema. */
    public static function config(): WP_REST_Response {
        $out = array();
        foreach ( self::tools() as $name => $tool ) {
            $out[ $name ] = array( 'route' => $tool['route'], 'approve' => $tool['approve'] ?? null );
        }
        return new WP_REST_Response( array( 'tools' => $out ) );
    }

    public static function status(): WP_REST_Response {
        $settings = AJMCP_WP_Client::options();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ) );
        }
        $result = AJMCP_WP_Client::request( '/v1/capabilities' );
        return new WP_REST_Response( array( 'ok' => ! is_wp_error( $result ), 'reason' => is_wp_error( $result ) ? $result->get_error_code() : '' ) );
    }

    public static function turn( WP_REST_Request $request ) {
        $settings = AJMCP_WP_Client::options();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_Error( 'ajmcp_disabled', 'The AJMCP connector is switched off (AJMCP → Settings).', array( 'status' => 409 ) );
        }
        $raw      = (array) $request->get_param( 'messages' );
        $messages = array();
        $chars    = 0;
        foreach ( array_slice( $raw, -self::MAX_MESSAGES ) as $m ) {
            $role = isset( $m['role'] ) ? (string) $m['role'] : '';
            if ( ! in_array( $role, array( 'user', 'assistant', 'tool' ), true ) || ! isset( $m['content'] ) || ! is_string( $m['content'] ) ) {
                return new WP_Error( 'ajmcp_bad_request', 'Invalid message.', array( 'status' => 400 ) );
            }
            $chars += strlen( $m['content'] );
            $entry  = array( 'role' => $role, 'content' => $m['content'] );
            if ( 'tool' === $role ) {
                $entry['name'] = sanitize_key( (string) ( $m['name'] ?? '' ) );
            }
            $messages[] = $entry;
        }
        if ( ! $messages || $chars > self::MAX_CHARS ) {
            return new WP_Error( 'ajmcp_bad_request', 'The conversation is empty or too long. Start a new chat.', array( 'status' => 400 ) );
        }
        $tools = array();
        foreach ( self::tools() as $name => $tool ) {
            $tools[] = array( 'name' => $name, 'description' => $tool['description'], 'parameters' => $tool['schema'] );
        }
        $context  = sprintf( 'Site: %s (%s). Active theme: %s. Today: %s.', get_bloginfo( 'name' ), home_url(), wp_get_theme()->get( 'Name' ), wp_date( 'Y-m-d' ) );
        $response = AJMCP_WP_Client::request( '/v1/assistant', 'POST', array( 'messages' => $messages, 'tools' => $tools, 'context' => $context ) );
        if ( is_wp_error( $response ) ) {
            $data = (array) $response->get_error_data();
            $msg  = 'ajmcp_not_configured' === $response->get_error_code() ? 'AJMCP server or site token is not configured (AJMCP → Settings).' : $response->get_error_message();
            return new WP_Error( $response->get_error_code(), $msg, array( 'status' => 502, 'remote' => $data['status'] ?? 0 ) );
        }
        $type = (string) ( $response['type'] ?? '' );
        if ( 'tool' === $type && isset( $response['name'] ) && isset( self::tools()[ $response['name'] ] ) ) {
            return new WP_REST_Response( array( 'type' => 'tool', 'name' => (string) $response['name'], 'arguments' => (object) ( is_array( $response['arguments'] ?? null ) ? $response['arguments'] : array() ) ) );
        }
        return new WP_REST_Response( array( 'type' => 'text', 'text' => (string) ( $response['text'] ?? '' ) ) );
    }
}
