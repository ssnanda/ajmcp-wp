<?php
/**
 * OAuth 2.1 for the Claude connector: discovery documents, client registration, the consent screen
 * and the token endpoint. Authorization-code flow with PKCE (S256) only; clients are public.
 *
 * A connection can only be approved by a logged-in administrator, on this site, on a page that
 * cannot be framed. Redirects go only to redirect URIs that were registered and that match the
 * Claude callback allowlist.
 *
 * Endpoints are plain front-end URLs handled early on `init` (no rewrite rules, nothing to flush):
 *   /.well-known/oauth-protected-resource[/...]   /.well-known/oauth-authorization-server
 *   /ajwpai-oauth/register   /ajwpai-oauth/authorize   /ajwpai-oauth/token
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_OAuth {

	const SCOPE = 'posts:read posts:propose';

	/** Every scope this server understands. */
	public static function all_scopes() {
		return self::SCOPE . ' ajcore:read ajcore:propose';
	}

	/** What a new approval grants: AJCore scopes only while the administrator has switched them on. */
	public static function granted_scope() {
		return AJWPAI_Settings::ajcore_enabled() ? self::all_scopes() : self::SCOPE;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'route' ), 0 );
	}

	// ── URLs ────────────────────────────────────────────────────────────────────

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function mcp_url() {
		return rest_url( 'ajwpai/v1/mcp' );
	}

	public static function endpoint( $name ) {
		return home_url( '/ajwpai-oauth/' . $name );
	}

	/** URL of the protected-resource metadata, for the WWW-Authenticate challenge. */
	public static function resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	// ── Pure helpers (unit-testable without WordPress) ──────────────────────────

	/** RFC 7636 S256: BASE64URL(SHA256(verifier)) must equal the stored challenge. */
	public static function pkce_matches( $verifier, $challenge ) {
		if ( ! is_string( $verifier ) || ! is_string( $challenge ) || strlen( $verifier ) < 43 || strlen( $verifier ) > 128 ) {
			return false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]+$/', $verifier ) ) {
			return false;
		}
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return hash_equals( $challenge, $computed );
	}

	/** Only HTTPS callbacks on the Claude hosts, at the MCP callback path (filterable). */
	public static function redirect_uri_allowed( $uri ) {
		$parts = wp_parse_url( (string) $uri );
		if ( ! $parts || empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) ) {
			return false;
		}
		$hosts = (array) apply_filters( 'ajwpai_allowed_redirect_hosts', array( 'claude.ai', 'claude.com' ) );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		return in_array( strtolower( $parts['host'] ), $hosts, true ) && '/api/mcp/auth_callback' === $path;
	}

	// ── Routing ─────────────────────────────────────────────────────────────────

	/** Request path relative to the site root, without slashes at either end. */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return trim( $path, '/' );
	}

	public static function route() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$path = self::request_path();
		if ( 0 === strpos( $path, '.well-known/oauth-protected-resource' ) ) {
			self::json( array(
				'resource'                 => self::mcp_url(),
				'authorization_servers'    => array( self::issuer() ),
				'scopes_supported'         => explode( ' ', self::all_scopes() ),
				'bearer_methods_supported' => array( 'header' ),
				'resource_name'            => get_bloginfo( 'name' ) . ' (AJ WP AI)',
			) );
		}
		if ( 0 === strpos( $path, '.well-known/oauth-authorization-server' ) ) {
			self::json( array(
				'issuer'                                => self::issuer(),
				'authorization_endpoint'                => self::endpoint( 'authorize' ),
				'token_endpoint'                        => self::endpoint( 'token' ),
				'registration_endpoint'                 => self::endpoint( 'register' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'token_endpoint_auth_methods_supported' => array( 'none' ),
				'scopes_supported'                      => explode( ' ', self::all_scopes() ),
			) );
		}
		switch ( $path ) {
			case 'ajwpai-oauth/register':
				self::handle_register();
				break;
			case 'ajwpai-oauth/authorize':
				self::handle_authorize();
				break;
			case 'ajwpai-oauth/token':
				self::handle_token();
				break;
		}
	}

	private static function json( array $data, $status = 200, array $headers = array() ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function oauth_error( $error, $description, $status = 400 ) {
		self::json( array( 'error' => $error, 'error_description' => $description ), $status );
	}

	/** Allows at most $max hits per minute per IP for a bucket. */
	private static function rate_limited( $bucket, $max ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'ajwpai_rl_' . md5( $bucket . '|' . $ip );
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return $n >= $max;
	}

	private static function https_ready() {
		return 0 === strpos( home_url(), 'https://' ) || (bool) apply_filters( 'ajwpai_allow_insecure', false );
	}

	// ── Dynamic client registration (RFC 7591) ──────────────────────────────────

	private static function handle_register() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::oauth_error( 'invalid_request', 'POST required.', 405 );
		}
		if ( ! self::https_ready() ) {
			self::oauth_error( 'invalid_request', 'The site must use HTTPS.' );
		}
		if ( self::rate_limited( 'register', 10 ) ) {
			self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$uris = is_array( $body ) && ! empty( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_map( 'strval', $body['redirect_uris'] ) : array();
		if ( ! $uris ) {
			self::oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::redirect_uri_allowed( $uri ) ) {
				self::oauth_error( 'invalid_redirect_uri', 'Only the Claude callback URL is accepted.' );
			}
		}
		$name      = is_array( $body ) && ! empty( $body['client_name'] ) ? (string) $body['client_name'] : 'Claude';
		$client_id = AJWPAI_Store::register_client( $name, $uris );
		self::json(
			array(
				'client_id'                  => $client_id,
				'client_name'                => sanitize_text_field( $name ),
				'redirect_uris'              => $uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'scope'                      => self::all_scopes(),
			),
			201
		);
	}

	// ── Authorization (consent screen) ──────────────────────────────────────────

	private static function error_page( $message ) {
		status_header( 400 );
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		wp_die( esc_html( $message ), esc_html__( 'Connection not approved', 'ajwpai' ), array( 'response' => 400 ) );
	}

	private static function handle_authorize() {
		if ( ! self::https_ready() ) {
			self::error_page( 'This site must use HTTPS to accept connections.' );
		}
		$in = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
		$get = function ( $key ) use ( $in ) { return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : ''; };

		$client_id    = $get( 'client_id' );
		$redirect_uri = $get( 'redirect_uri' );
		$challenge    = $get( 'code_challenge' );
		$state        = $get( 'state' );
		$client       = AJWPAI_Store::get_client( $client_id );

		// Never redirect to an unvalidated URI: these errors are shown here instead.
		if ( ! $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) || ! self::redirect_uri_allowed( $redirect_uri ) ) {
			self::error_page( 'Unknown application or callback address.' );
		}
		if ( 'code' !== $get( 'response_type' ) || '' === $challenge || 'S256' !== $get( 'code_challenge_method' ) || ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $challenge ) ) {
			self::redirect_error( $redirect_uri, $state, 'invalid_request', 'PKCE (S256) is required.' );
		}

		if ( ! is_user_logged_in() ) {
			// Come back to this exact authorization request after signing in.
			$keep = array_intersect_key( $in, array_flip( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope' ) ) );
			wp_safe_redirect( wp_login_url( add_query_arg( array_map( 'strval', $keep ), self::endpoint( 'authorize' ) ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::error_page( 'Only a site administrator can connect Claude to this site.' );
		}

		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		$is_post = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $is_post ) {
			if ( empty( $in['_wpnonce'] ) || ! wp_verify_nonce( (string) $in['_wpnonce'], 'ajwpai_authorize' ) ) {
				self::error_page( 'This page expired. Go back to Claude and try again.' );
			}
			if ( 'approve' !== $get( 'decision' ) ) {
				self::redirect_error( $redirect_uri, $state, 'access_denied', 'The administrator declined.' );
			}
			$code = bin2hex( random_bytes( 32 ) );
			set_transient(
				'ajwpai_code_' . hash( 'sha256', $code ),
				array(
					'client_id'    => $client_id,
					'redirect_uri' => $redirect_uri,
					'challenge'    => $challenge,
					'user_id'      => get_current_user_id(),
					'scope'        => self::granted_scope(),
				),
				10 * MINUTE_IN_SECONDS
			);
			$args = array( 'code' => $code );
			if ( '' !== $state ) {
				$args['state'] = $state;
			}
			wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URI is registered and allowlisted above.
			exit;
		}

		self::consent_page( $client, $in );
	}

	private static function redirect_error( $redirect_uri, $state, $error, $description ) {
		$args = array( 'error' => $error, 'error_description' => $description );
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URI validated by the caller.
		exit;
	}

	private static function consent_page( array $client, array $in ) {
		$user   = wp_get_current_user();
		$fields = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( sprintf( 'Connect %s', $client['name'] ) ); ?></title>
<style>
body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;color:#1d2327;display:flex;min-height:100vh;align-items:center;justify-content:center}
.card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px 32px;max-width:440px;width:calc(100% - 32px);box-shadow:0 1px 3px rgba(0,0,0,.06)}
h1{font-size:20px;margin:0 0 4px}.sub{color:#646970;margin:0 0 18px;font-size:13px}
ul{margin:0 0 20px;padding-left:20px}li{margin:4px 0}.no{color:#646970;font-size:13px}
.row{display:flex;gap:10px}button{font:inherit;padding:9px 18px;border-radius:6px;border:1px solid #2271b1;cursor:pointer}
.ok{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}
</style></head><body>
<form class="card" method="post">
	<h1><?php echo esc_html( sprintf( 'Connect %s to %s?', $client['name'], get_bloginfo( 'name' ) ) ); ?></h1>
	<p class="sub"><?php echo esc_html( sprintf( 'Signed in as %s', $user->user_login ) ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Read posts and pages', 'ajwpai' ); ?></li>
		<li><?php esc_html_e( 'Propose drafts and edits (you approve)', 'ajwpai' ); ?></li>
		<?php if ( AJWPAI_Settings::ajcore_enabled() ) : ?>
		<li><?php esc_html_e( 'Read AJCore data: customers, leads, files', 'ajwpai' ); ?></li>
		<li><?php esc_html_e( 'Propose AJCore actions (you approve)', 'ajwpai' ); ?></li>
		<?php endif; ?>
	</ul>
	<?php foreach ( $fields as $f ) : ?>
		<?php if ( isset( $in[ $f ] ) && is_string( $in[ $f ] ) ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( $in[ $f ] ); ?>">
		<?php endif; ?>
	<?php endforeach; ?>
	<?php wp_nonce_field( 'ajwpai_authorize' ); ?>
	<div class="row">
		<button class="ok" type="submit" name="decision" value="approve"><?php esc_html_e( 'Approve', 'ajwpai' ); ?></button>
		<button class="deny" type="submit" name="decision" value="deny"><?php esc_html_e( 'Cancel', 'ajwpai' ); ?></button>
	</div>
</form></body></html>
		<?php
		exit;
	}

	// ── Token endpoint ──────────────────────────────────────────────────────────

	private static function handle_token() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::oauth_error( 'invalid_request', 'POST required.', 405 );
		}
		if ( ! self::https_ready() ) {
			self::oauth_error( 'invalid_request', 'The site must use HTTPS.' );
		}
		if ( self::rate_limited( 'token', 30 ) ) {
			self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}
		$in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$get  = function ( $key ) use ( $in ) { return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : ''; };
		$type = $get( 'grant_type' );
		$cid  = $get( 'client_id' );
		if ( ! AJWPAI_Store::get_client( $cid ) ) {
			self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}

		if ( 'authorization_code' === $type ) {
			$key  = 'ajwpai_code_' . hash( 'sha256', $get( 'code' ) );
			$data = get_transient( $key );
			delete_transient( $key ); // Single use, even when the rest of the request is wrong.
			if ( ! is_array( $data ) || ! hash_equals( (string) $data['client_id'], $cid ) || ! hash_equals( (string) $data['redirect_uri'], $get( 'redirect_uri' ) ) ) {
				self::oauth_error( 'invalid_grant', 'The code is invalid or expired.' );
			}
			if ( ! self::pkce_matches( $get( 'code_verifier' ), (string) $data['challenge'] ) ) {
				self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			if ( ! user_can( (int) $data['user_id'], 'manage_options' ) ) {
				self::oauth_error( 'invalid_grant', 'The approving user no longer has access.' );
			}
			self::json( AJWPAI_Store::issue_tokens( $cid, (int) $data['user_id'], (string) $data['scope'] ) + array( 'token_type' => 'Bearer' ) );
		}

		if ( 'refresh_token' === $type ) {
			$tokens = AJWPAI_Store::rotate_refresh( $get( 'refresh_token' ), $cid );
			if ( ! $tokens ) {
				self::oauth_error( 'invalid_grant', 'The refresh token is invalid or expired.' );
			}
			self::json( $tokens + array( 'token_type' => 'Bearer' ) );
		}

		self::oauth_error( 'unsupported_grant_type', 'Unsupported grant type.' );
	}
}
