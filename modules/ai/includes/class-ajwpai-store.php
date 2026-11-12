<?php
/**
 * Storage: registered Claude clients, access/refresh tokens, and the activity log.
 *
 * Tokens are random and only their SHA-256 hash is stored, so a database leak does not leak working
 * tokens. Clients are public (no secret; PKCE protects the code exchange) and live in one option.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Store {

	const CLIENTS_OPTION  = 'ajwpai_clients';
	const MAX_CLIENTS     = 25;
	const ACCESS_TTL      = HOUR_IN_SECONDS;
	const REFRESH_TTL     = 30 * DAY_IN_SECONDS;
	const LOG_KEEP_DAYS   = 30;
	const LOG_KEEP_ROWS   = 5000;

	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajwpai_tokens';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajwpai_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tokens  = self::tokens_table();
		$log     = self::log_table();

		dbDelta( "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(100) NOT NULL DEFAULT '',
			access_hash char(64) NOT NULL,
			refresh_hash char(64) NOT NULL,
			access_expires datetime NOT NULL,
			refresh_expires datetime NOT NULL,
			created datetime NOT NULL,
			last_used datetime DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY access_hash (access_hash),
			UNIQUE KEY refresh_hash (refresh_hash),
			KEY user_id (user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool varchar(60) NOT NULL DEFAULT '',
			args text NULL,
			ok tinyint(1) NOT NULL DEFAULT 1,
			ms int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created (created)
		) {$charset};" );

		dbDelta( AJWPAI_Changes::schema( $charset ) );

		update_option( 'ajwpai_schema', AJWPAI_SCHEMA, false );
	}

	// ── Clients ─────────────────────────────────────────────────────────────────

	public static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/** @return string New client ID. */
	public static function register_client( $name, array $redirect_uris ) {
		$clients   = self::clients();
		$client_id = 'ajwc_' . bin2hex( random_bytes( 12 ) );
		$clients[ $client_id ] = array(
			'name'          => sanitize_text_field( $name ) ?: 'Claude',
			'redirect_uris' => array_values( $redirect_uris ),
			'created'       => time(),
		);
		// Keep the list small: drop the oldest registrations.
		if ( count( $clients ) > self::MAX_CLIENTS ) {
			uasort( $clients, function ( $a, $b ) { return $a['created'] <=> $b['created']; } );
			$clients = array_slice( $clients, -self::MAX_CLIENTS, null, true );
		}
		update_option( self::CLIENTS_OPTION, $clients, false );
		return $client_id;
	}

	public static function get_client( $client_id ) {
		$clients = self::clients();
		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	// ── Tokens ──────────────────────────────────────────────────────────────────

	private static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Creates a token pair for a user. Returns the plain tokens once; only hashes are stored. */
	public static function issue_tokens( $client_id, $user_id, $scope ) {
		global $wpdb;
		$access  = 'ajw_' . bin2hex( random_bytes( 24 ) );
		$refresh = 'ajwr_' . bin2hex( random_bytes( 24 ) );
		$wpdb->insert(
			self::tokens_table(),
			array(
				'client_id'       => $client_id,
				'user_id'         => (int) $user_id,
				'scope'           => $scope,
				'access_hash'     => self::hash( $access ),
				'refresh_hash'    => self::hash( $refresh ),
				'access_expires'  => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
				'refresh_expires' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
				'created'         => self::now(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::ACCESS_TTL,
			'scope'         => $scope,
		);
	}

	/** Token row for a valid (unrevoked, unexpired) access token, or null. */
	public static function find_by_access( $token ) {
		global $wpdb;
		$table = self::tokens_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE access_hash = %s AND revoked = 0 AND access_expires > %s", self::hash( $token ), self::now() ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Exchanges a refresh token for a new pair (the old pair stops working). Null if invalid. */
	public static function rotate_refresh( $refresh, $client_id ) {
		global $wpdb;
		$table = self::tokens_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE refresh_hash = %s AND revoked = 0 AND refresh_expires > %s", self::hash( $refresh ), self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || ! hash_equals( (string) $row->client_id, (string) $client_id ) ) {
			return null;
		}
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
		return self::issue_tokens( $row->client_id, (int) $row->user_id, $row->scope );
	}

	public static function touch( $token_id ) {
		global $wpdb;
		$wpdb->update( self::tokens_table(), array( 'last_used' => self::now() ), array( 'id' => (int) $token_id ), array( '%s' ), array( '%d' ) );
	}

	public static function revoke( $token_id ) {
		global $wpdb;
		$wpdb->update( self::tokens_table(), array( 'revoked' => 1 ), array( 'id' => (int) $token_id ), array( '%d' ), array( '%d' ) );
	}

	public static function revoke_all() {
		global $wpdb;
		$table = self::tokens_table();
		$wpdb->query( "UPDATE `{$table}` SET revoked = 1 WHERE revoked = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Active connections for the admin page. */
	public static function active_tokens() {
		global $wpdb;
		$table = self::tokens_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, client_id, user_id, scope, created, last_used, refresh_expires FROM `{$table}` WHERE revoked = 0 AND refresh_expires > %s ORDER BY id DESC LIMIT 50", self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Log ─────────────────────────────────────────────────────────────────────

	public static function log( $user_id, $token_id, $tool, $args, $ok, $ms ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'created'  => self::now(),
				'user_id'  => (int) $user_id,
				'token_id' => (int) $token_id,
				'tool'     => substr( (string) $tool, 0, 60 ),
				'args'     => substr( wp_json_encode( $args ), 0, 500 ),
				'ok'       => $ok ? 1 : 0,
				'ms'       => (int) $ms,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d' )
		);
		if ( 1 === wp_rand( 1, 50 ) ) {
			self::prune_log();
		}
	}

	public static function recent_log( $limit = 50 ) {
		global $wpdb;
		$table = self::log_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function prune_log() {
		global $wpdb;
		$table = self::log_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - self::LOG_KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET %d", self::LOG_KEEP_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id <= %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
