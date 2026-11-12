<?php
/**
 * The MCP endpoint Claude talks to (Streamable HTTP, JSON responses): POST /wp-json/ajwpai/v1/mcp.
 *
 * Every request needs a valid Bearer access token. The request then runs as the administrator who
 * approved the connection, and every tool call is logged. Version 1 only exposes read-only tools.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_MCP {

	const SUPPORTED_VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );
	const RATE_PER_MINUTE    = 120;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			'ajwpai/v1',
			'/mcp',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by Bearer token inside handle().
			)
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private static function rpc_error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	private static function http( $body, $status = 200, array $headers = array() ) {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	private static function unauthorized( $message ) {
		return self::http(
			array( 'error' => 'unauthorized', 'error_description' => $message ),
			401,
			array( 'WWW-Authenticate' => 'Bearer realm="ajwpai", resource_metadata="' . AJWPAI_OAuth::resource_metadata_url() . '"' )
		);
	}

	private static function bearer( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		return ( $header && preg_match( '/^Bearer\s+(\S+)$/i', $header, $m ) ) ? $m[1] : '';
	}

	// ── Request handling ────────────────────────────────────────────────────────

	public static function handle( WP_REST_Request $request ) {
		if ( 'POST' !== $request->get_method() ) {
			return self::http( array( 'error' => 'method_not_allowed' ), 405, array( 'Allow' => 'POST' ) );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) && ! apply_filters( 'ajwpai_allow_insecure', false ) ) {
			return self::http( array( 'error' => 'https_required' ), 400 );
		}

		$token_value = self::bearer( $request );
		$token       = $token_value ? AJWPAI_Store::find_by_access( $token_value ) : null;
		if ( ! $token ) {
			return self::unauthorized( 'A valid access token is required.' );
		}
		$user = get_userdata( (int) $token->user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			AJWPAI_Store::revoke( (int) $token->id );
			return self::unauthorized( 'The approving user no longer has access.' );
		}

		$rate_key = 'ajwpai_rate_' . (int) $token->id;
		$hits     = (int) get_transient( $rate_key );
		if ( $hits >= self::RATE_PER_MINUTE ) {
			return self::http( self::rpc_error( null, -32000, 'Rate limit reached. Try again in a minute.' ), 429 );
		}
		set_transient( $rate_key, $hits + 1, MINUTE_IN_SECONDS );

		wp_set_current_user( $user->ID );
		AJWPAI_Store::touch( (int) $token->id );

		$payload = json_decode( $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return self::http( self::rpc_error( null, -32700, 'Parse error' ), 400 );
		}

		// A list is a batch; a single object is the normal case.
		$is_batch = array_keys( $payload ) === range( 0, count( $payload ) - 1 );
		$messages = $is_batch ? $payload : array( $payload );
		$replies  = array();
		foreach ( $messages as $message ) {
			$reply = is_array( $message ) ? self::dispatch( $message, $token ) : self::rpc_error( null, -32600, 'Invalid Request' );
			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}
		if ( ! $replies ) {
			// Notifications only: accepted, with an empty body as the protocol expects.
			status_header( 202 );
			header( 'Cache-Control: no-store' );
			exit;
		}
		return self::http( $is_batch ? $replies : $replies[0] );
	}

	/** One JSON-RPC message. Returns the reply, or null for a notification. */
	private static function dispatch( array $message, $token ) {
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method = isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$notify = ! array_key_exists( 'id', $message );

		if ( '' === $method ) {
			return $notify ? null : self::rpc_error( $id, -32600, 'Invalid Request' );
		}
		if ( 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				$wanted = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				return array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => array(
						'protocolVersion' => in_array( $wanted, self::SUPPORTED_VERSIONS, true ) ? $wanted : self::SUPPORTED_VERSIONS[0],
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array( 'name' => 'ajwpai', 'title' => get_bloginfo( 'name' ), 'version' => AJWPAI_VERSION ),
						'instructions'    => 'Access to this WordPress site\'s posts and pages. Use search_posts to find content and get_post to read one in full. You can propose new drafts and edits, but nothing changes until the site administrator approves each proposal in wp-admin under AJ WP AI: always tell the user to approve it there. You cannot publish or delete.',
					),
				);
			case 'ping':
				return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass() );
			case 'tools/list':
				return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'tools' => AJWPAI_Tools::definitions() ) );
			case 'tools/call':
				return self::call_tool( $id, $params, $token );
		}
		return self::rpc_error( $id, -32601, 'Method not found' );
	}

	private static function call_tool( $id, array $params, $token ) {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$known = wp_list_pluck( AJWPAI_Tools::definitions(), 'name' );
		if ( ! in_array( $name, $known, true ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool.' );
		}
		$scopes = preg_split( '/\s+/', trim( (string) $token->scope ) );
		if ( ! in_array( AJWPAI_Tools::required_scope( $name ), $scopes, true ) ) {
			return self::tool_result( $id, 'This connection does not have permission for that tool. Ask the user to disconnect and reconnect the connector (AJ WP AI → Settings → Disconnect), then approve again.', true );
		}

		$started = microtime( true );
		try {
			$data = AJWPAI_Tools::call( $name, $args, $token );
		} catch ( Throwable $e ) {
			$data = new WP_Error( 'ajwpai_exception', 'The tool failed.' ); // Details stay out of the reply.
		}
		$ok = ! is_wp_error( $data );
		AJWPAI_Store::log( (int) $token->user_id, (int) $token->id, $name, $args, $ok, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		return self::tool_result( $id, $ok ? $data : $data->get_error_message(), ! $ok );
	}

	private static function tool_result( $id, $data, $is_error ) {
		$text = is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content' => array( array( 'type' => 'text', 'text' => $text ) ),
				'isError' => (bool) $is_error,
			),
		);
	}
}
