<?php
/**
 * Narrow, approval-gated AJCore tools for the local Codex companion.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_AJCore {

	private const ACTIONS = array(
		'leads', 'lead', 'lead-note', 'lead-tags', 'lead-status', 'lead-email',
		'lead-campaign', 'chats', 'chat', 'chat-reply', 'chat-escalate',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'ajwpai/v1',
			'/code/ajcore/(?P<action>[a-z-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function handle( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request['action'] );
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'ajwpai_action_not_allowed', 'AJCore action is not allowed.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'AJCore_REST_API' ) ) {
			return new WP_Error( 'ajwpai_ajcore_missing', 'AJCore is not active.', array( 'status' => 503 ) );
		}

		switch ( $action ) {
			case 'leads':
				return self::leads( $request );
			case 'lead':
				return self::lead( $request );
			case 'lead-note':
				return self::lead_note( $request );
			case 'lead-tags':
				return self::lead_tags( $request );
			case 'lead-status':
				return self::lead_status( $request );
			case 'lead-email':
				return self::lead_email( $request );
			case 'lead-campaign':
				return self::lead_campaign( $request );
			case 'chats':
				return self::chats( $request );
			case 'chat':
				return self::chat( $request );
			case 'chat-reply':
				return self::chat_reply( $request );
			case 'chat-escalate':
				return self::chat_escalate( $request );
		}
		return new WP_Error( 'ajwpai_action_not_found', 'AJCore action not found.', array( 'status' => 404 ) );
	}

	/** Read from AJCore's OPS API as the current user. Returns the reply array or a WP_Error. */
	public static function read( $path, array $params = array() ) {
		if ( ! class_exists( 'AJCore_REST_API' ) ) {
			return new WP_Error( 'ajwpai_ajcore_missing', 'AJCore is not active.' );
		}
		return self::ajcore_request( 'GET', $path, $params );
	}

	/** Run one of the narrow AJCore actions (lead-note, lead-status, chat-reply, ...) as the current administrator. */
	public static function run( $action, array $params ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ajwpai_forbidden', 'Not allowed.' );
		}
		$request = new WP_REST_Request( 'POST', '/ajwpai/v1/code/ajcore/' . $action );
		$request->set_param( 'action', $action );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = self::handle( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	}

	private static function ajcore_request( $method, $path, array $params = array() ) {
		$inner = new WP_REST_Request( $method, '/ajcore/v1/' . ltrim( $path, '/' ) );
		if ( 'GET' === $method ) {
			$inner->set_query_params( $params );
		} else {
			$inner->set_body_params( $params );
		}
		$response = rest_do_request( $inner );
		$data     = $response->get_data();
		if ( $response->is_error() ) {
			return new WP_Error(
				isset( $data['code'] ) ? sanitize_key( $data['code'] ) : 'ajwpai_ajcore_error',
				isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'AJCore request failed.',
				array( 'status' => $response->get_status() )
			);
		}
		return $data;
	}

	private static function respond( $data ) {
		return is_wp_error( $data ) ? $data : rest_ensure_response( $data );
	}

	private static function leads( WP_REST_Request $request ) {
		$data = self::ajcore_request(
			'GET',
			'ops/leads',
			array(
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'per_page' => min( 50, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$leads = array_map(
			static function ( $lead ) {
				$email  = sanitize_email( (string) ( $lead['email'] ?? '' ) );
				$masked = $email ? preg_replace( '/(^.).*(@.*$)/', '$1***$2', $email ) : '';
				return array(
					'id'          => (int) ( $lead['id'] ?? 0 ),
					'name'        => sanitize_text_field( (string) ( $lead['name'] ?? '' ) ),
					'email'       => $masked,
					'company'     => sanitize_text_field( (string) ( $lead['company'] ?? '' ) ),
					'status'      => sanitize_key( (string) ( $lead['lead_status'] ?? 'new' ) ),
					'site'        => sanitize_text_field( (string) ( $lead['site_label'] ?? '' ) ),
					'updated_at'  => sanitize_text_field( (string) ( $lead['updated_at'] ?? '' ) ),
				);
			},
			(array) ( $data['leads'] ?? array() )
		);
		return rest_ensure_response( array( 'leads' => $leads ) );
	}

	private static function lead( WP_REST_Request $request ) {
		$data = self::lead_data( absint( $request->get_param( 'lead_id' ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$lead = $data['lead'];
		return rest_ensure_response(
			array(
				'lead' => array_intersect_key(
					$lead,
					array_flip( array( 'id', 'name', 'email', 'phone', 'company', 'source', 'notes', 'notes_list', 'status', 'lead_status', 'lead_follow_up_at', 'site_label', 'created_at', 'updated_at' ) )
				),
			)
		);
	}

	private static function lead_data( $lead_id ) {
		if ( ! $lead_id ) {
			return new WP_Error( 'ajwpai_lead_required', 'A lead ID is required.', array( 'status' => 400 ) );
		}
		return self::ajcore_request( 'GET', 'ops/leads/' . $lead_id );
	}

	private static function lead_note( WP_REST_Request $request ) {
		$lead_id = absint( $request->get_param( 'lead_id' ) );
		$note    = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $note ) {
			return new WP_Error( 'ajwpai_note_required', 'A note is required.', array( 'status' => 400 ) );
		}
		return self::respond( self::ajcore_request( 'POST', 'ops/leads/' . $lead_id . '/notes', array( 'note' => $note ) ) );
	}

	private static function lead_tags( WP_REST_Request $request ) {
		if ( ! function_exists( 'ajcore_get_leads_db' ) ) {
			return new WP_Error( 'ajwpai_leads_unavailable', 'AJCore lead storage is unavailable.', array( 'status' => 503 ) );
		}
		$db      = ajcore_get_leads_db();
		$table   = $db->prefix . 'aj_forms_leads';
		$lead_id = absint( $request->get_param( 'lead_id' ) );
		$row     = $db->get_row( $db->prepare( "SELECT lead_data FROM `{$table}` WHERE id = %d", $lead_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'ajwpai_lead_not_found', 'Lead not found.', array( 'status' => 404 ) );
		}
		$tags = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'tags' ) ) ) ) );
		$data = json_decode( (string) $row['lead_data'], true );
		$data = is_array( $data ) ? $data : array();
		$data['_meta'] = isset( $data['_meta'] ) && is_array( $data['_meta'] ) ? $data['_meta'] : array();
		$data['_meta']['tags'] = array_slice( $tags, 0, 20 );
		$db->update( $table, array( 'lead_data' => wp_json_encode( $data ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $lead_id ), array( '%s', '%s' ), array( '%d' ) );
		self::ajcore_request( 'POST', 'ops/leads/' . $lead_id . '/notes', array( 'note' => 'Tags updated: ' . implode( ', ', $data['_meta']['tags'] ) ) );
		return rest_ensure_response( array( 'lead_id' => $lead_id, 'tags' => $data['_meta']['tags'] ) );
	}

	private static function lead_status( WP_REST_Request $request ) {
		$lead_id = absint( $request->get_param( 'lead_id' ) );
		$params  = array(
			'lead_status'             => sanitize_key( (string) $request->get_param( 'status' ) ),
			'note'                    => sanitize_textarea_field( (string) $request->get_param( 'note' ) ),
			'follow_up_at'            => sanitize_text_field( (string) $request->get_param( 'follow_up_at' ) ),
			'non_stripe_customer_name' => sanitize_text_field( (string) $request->get_param( 'customer_name' ) ),
		);
		return self::respond( self::ajcore_request( 'PATCH', 'ops/leads/' . $lead_id . '/pipeline-status', $params ) );
	}

	private static function lead_email( WP_REST_Request $request ) {
		$result = self::send_email(
			absint( $request->get_param( 'lead_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'subject' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'body' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function send_email( $lead_id, $subject, $body ) {
		$data = self::lead_data( $lead_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$lead  = $data['lead'];
		$email = sanitize_email( (string) ( $lead['email'] ?? '' ) );
		if ( ! is_email( $email ) || '' === $subject || '' === $body ) {
			return new WP_Error( 'ajwpai_email_invalid', 'The lead needs a valid email, subject, and message.', array( 'status' => 400 ) );
		}
		$name = sanitize_text_field( (string) ( $lead['name'] ?? '' ) );
		$replacements = array( '{name}' => $name, '{email}' => $email, '{site_name}' => get_bloginfo( 'name' ) );
		$subject = strtr( $subject, $replacements );
		$body    = strtr( $body, $replacements );
		if ( function_exists( 'ajcore_set_mail_profile' ) ) {
			ajcore_set_mail_profile( function_exists( 'ajcore_customer_mail_profile' ) ? ajcore_customer_mail_profile() : 'smtp2' );
		}
		$sent = wp_mail( $email, $subject, wpautop( esc_html( $body ) ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( ! $sent ) {
			return new WP_Error( 'ajwpai_email_failed', 'WordPress could not send the email.', array( 'status' => 502 ) );
		}
		self::ajcore_request( 'POST', 'ops/leads/' . $lead_id . '/notes', array( 'note' => 'AJ WP AI email sent: ' . $subject ) );
		return array( 'lead_id' => $lead_id, 'sent' => true, 'subject' => $subject );
	}

	private static function lead_campaign( WP_REST_Request $request ) {
		$ids     = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'lead_ids' ) ) ) ) ), 0, 50 );
		$subject = sanitize_text_field( (string) $request->get_param( 'subject' ) );
		$body    = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'ajwpai_campaign_empty', 'Select at least one lead.', array( 'status' => 400 ) );
		}
		$sent = array();
		$failed = array();
		foreach ( $ids as $lead_id ) {
			$result = self::send_email( $lead_id, $subject, $body );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'lead_id' => $lead_id, 'error' => $result->get_error_message() );
			} else {
				$sent[] = $lead_id;
			}
		}
		return rest_ensure_response( array( 'sent' => $sent, 'failed' => $failed ) );
	}

	private static function chats( WP_REST_Request $request ) {
		$data = self::ajcore_request(
			'GET',
			'ops/chat/sessions',
			array(
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'per_page' => min( 50, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$sessions = array_map(
			static function ( $chat ) {
				return array_intersect_key( $chat, array_flip( array( 'id', 'visitor_name', 'status', 'assigned_staff_name', 'automation_muted', 'site_domain', 'created_at', 'last_message_at' ) ) );
			},
			(array) ( $data['sessions'] ?? array() )
		);
		return rest_ensure_response( array( 'sessions' => $sessions ) );
	}

	private static function chat( WP_REST_Request $request ) {
		$chat_id = absint( $request->get_param( 'chat_id' ) );
		return self::respond( self::ajcore_request( 'GET', 'ops/chat/sessions/' . $chat_id . '/messages' ) );
	}

	private static function chat_reply( WP_REST_Request $request ) {
		$chat_id = absint( $request->get_param( 'chat_id' ) );
		$body    = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
		if ( '' === $body ) {
			return new WP_Error( 'ajwpai_chat_body_required', 'A reply is required.', array( 'status' => 400 ) );
		}
		return self::respond( self::ajcore_request( 'POST', 'ops/chat/sessions/' . $chat_id . '/reply', array( 'body' => $body ) ) );
	}

	private static function chat_escalate( WP_REST_Request $request ) {
		$chat_id = absint( $request->get_param( 'chat_id' ) );
		$claimed = self::ajcore_request( 'POST', 'ops/chat/sessions/' . $chat_id . '/claim' );
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		$muted = self::ajcore_request( 'POST', 'ops/chat/sessions/' . $chat_id . '/mute-automation', array( 'muted' => true ) );
		return is_wp_error( $muted ) ? $muted : rest_ensure_response( array( 'escalated' => true, 'session' => $claimed['session'] ?? array() ) );
	}
}
