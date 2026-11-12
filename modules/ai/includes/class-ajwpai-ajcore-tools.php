<?php
/**
 * AJCore tools for the Claude connector.
 *
 * Reads go through AJCore's own OPS API (the same data AJ Ops shows) as the administrator who approved
 * the connection. Sensitive fields are stripped before anything leaves the site. Changes are never made
 * directly: Claude can only PROPOSE an action (a lead note, a status change, an email, a chat reply, a
 * file fix) and an administrator approves each one in wp-admin.
 *
 * Off unless an administrator turns on "Share AJCore data with Claude" in AJ WP AI → Settings.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_AJCore_Tools {

	const SCOPE_READ    = 'ajcore:read';
	const SCOPE_PROPOSE = 'ajcore:propose';

	/** resource => [ AJCore path, key of the list in the reply, AJ Ops page ] */
	const RESOURCES = array(
		'customers'     => array( 'ops/customers', 'customers', '/customers' ),
		'leads'         => array( 'ops/leads', 'leads', '/leads' ),
		'requests'      => array( 'ops/requests', 'requests', '/requests' ),
		'mail'          => array( 'ops/mail', 'mail_items', '/mail' ),
		'subscriptions' => array( 'ops/subscriptions', 'subscriptions', '/customers' ),
		'tasks'         => array( 'ops/tasks', 'tasks', '/tasks' ),
		'ledger'        => array( 'ops/ledger', 'ledger', '/transactions' ),
		'reservations'  => array( 'ops/reservations', 'reservations', '/reservations' ),
		'chats'         => array( 'ops/chat/sessions', 'sessions', '/live-chat' ),
	);

	const ACTIONS = array( 'lead_note', 'lead_tags', 'lead_status', 'lead_email', 'chat_reply' );
	const FIXES   = array( 'reattach', 'attach', 'move_shared' );

	public static function is_tool( $name ) {
		return in_array( $name, array( 'ajcore_overview', 'ajcore_query', 'ajcore_files_health', 'ajcore_file_candidates', 'propose_ajcore_action', 'propose_file_fix' ), true );
	}

	public static function required_scope( $name ) {
		return in_array( $name, array( 'propose_ajcore_action', 'propose_file_fix' ), true ) ? self::SCOPE_PROPOSE : self::SCOPE_READ;
	}

	// ── Definitions ─────────────────────────────────────────────────────────────

	public static function definitions() {
		$read = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		$prop = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );
		return array(
			array(
				'name'        => 'ajcore_overview',
				'title'       => 'AJCore overview',
				'description' => 'Headline numbers from AJCore (the data behind AJ Ops): summary and counts for leads, customers, requests and so on, with a link to AJ Ops.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => $read,
			),
			array(
				'name'        => 'ajcore_query',
				'title'       => 'Look up AJCore records',
				'description' => 'List or search AJCore records: customers, leads, requests, mail, subscriptions, tasks, ledger, reservations or chats. Pass id (customers: Stripe id like cus_..., leads: number) to get one record in full. Sensitive fields are removed. Each record includes a link to AJ Ops.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'resource' => array( 'type' => 'string', 'enum' => array_keys( self::RESOURCES ) ),
						'search'   => array( 'type' => 'string', 'description' => 'Name, email, company, keyword.' ),
						'status'   => array( 'type' => 'string', 'description' => 'Optional status filter, where the resource has one.' ),
						'id'       => array( 'type' => 'string', 'description' => 'One record (customers or leads only).' ),
						'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ),
					),
					'required'   => array( 'resource' ),
				),
				'annotations' => $read,
			),
			array(
				'name'        => 'ajcore_files_health',
				'title'       => 'Client Portal files health',
				'description' => 'Counts of Client Portal files by health (ok, recoverable, missing, wrong file) and the list of problem files, with their storage path. Use ajcore_file_candidates to look for a missing file in storage.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => $read,
			),
			array(
				'name'        => 'ajcore_file_candidates',
				'title'       => 'Find a missing file in storage',
				'description' => 'For a Client Portal file record (file_id from ajcore_files_health), list objects in the storage bucket that could be its file, best match first. Then propose_file_fix to attach one.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'file_id' => array( 'type' => 'integer', 'description' => 'The file record id.' ),
						'search'  => array( 'type' => 'string', 'description' => 'Part of a file name, to search the whole bucket.' ),
					),
					'required'   => array( 'file_id' ),
				),
				'annotations' => $read,
			),
			array(
				'name'        => 'propose_ajcore_action',
				'title'       => 'Propose an AJCore action',
				'description' => 'Propose ONE action: add a note to a lead, set a lead\'s tags, change a lead\'s pipeline status, email a lead, or reply in a live chat. This does nothing by itself: it saves a proposal the administrator must approve in wp-admin (Settings → AJ WP AI), where they see exactly what will happen. Tell the user to approve it there.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action'       => array( 'type' => 'string', 'enum' => self::ACTIONS ),
						'lead_id'      => array( 'type' => 'integer', 'description' => 'For lead_* actions.' ),
						'chat_id'      => array( 'type' => 'integer', 'description' => 'For chat_reply.' ),
						'note'         => array( 'type' => 'string', 'description' => 'lead_note: the note. lead_status: optional note.' ),
						'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'lead_tags: the complete tag list (replaces the old one).' ),
						'status'       => array( 'type' => 'string', 'description' => 'lead_status: the new pipeline status.' ),
						'follow_up_at' => array( 'type' => 'string', 'description' => 'lead_status: optional follow-up date, YYYY-MM-DD.' ),
						'subject'      => array( 'type' => 'string', 'description' => 'lead_email.' ),
						'body'         => array( 'type' => 'string', 'description' => 'lead_email or chat_reply: the message text.' ),
						'reason'       => array( 'type' => 'string', 'description' => 'One sentence for the reviewer: why.' ),
					),
					'required'   => array( 'action' ),
				),
				'annotations' => $prop,
			),
			array(
				'name'        => 'propose_file_fix',
				'title'       => 'Propose a Client Portal file fix',
				'description' => 'Propose fixing a Client Portal file record: reattach (its storage mapping still names the object), attach (give key, an object from ajcore_file_candidates) or move_shared (move a file shared with many customers into the shared storage folder). Nothing is changed until the administrator approves it in wp-admin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'file_id' => array( 'type' => 'integer' ),
						'fix'     => array( 'type' => 'string', 'enum' => self::FIXES ),
						'key'     => array( 'type' => 'string', 'description' => 'attach only: the storage object key.' ),
						'reason'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'file_id', 'fix' ),
				),
				'annotations' => $prop,
			),
		);
	}

	// ── Dispatch ────────────────────────────────────────────────────────────────

	public static function call( $name, array $args, $token ) {
		if ( ! AJWPAI_Settings::ajcore_enabled() ) {
			return new WP_Error( 'ajwpai_ajcore_off', 'AJCore access is switched off on this site.' );
		}
		switch ( $name ) {
			case 'ajcore_overview':
				return self::overview();
			case 'ajcore_query':
				return self::query( $args );
			case 'ajcore_files_health':
				return self::files_health();
			case 'ajcore_file_candidates':
				return self::file_candidates( $args );
			case 'propose_ajcore_action':
				return self::propose_action( $token, $args );
			case 'propose_file_fix':
				return self::propose_file_fix( $token, $args );
		}
		return new WP_Error( 'ajwpai_unknown_tool', 'Unknown tool.' );
	}

	// ── Cleaning what leaves the site (pure, unit-tested) ───────────────────────

	public static function is_sensitive_key( $key ) {
		return (bool) preg_match( '/(token|secret|passw|api[_-]?key|authorization|auth[_-]?key|salt|nonce|card|cvc|cvv|ssn|social|tax[_-]?id|\bein\b|dob|birth|licen[sc]e|passport|routing|account[_-]?number|iban|private)/i', (string) $key );
	}

	/** Removes sensitive keys, trims long text, caps list length and depth. */
	public static function scrub( $value, $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			if ( $depth > 3 ) {
				return '…';
			}
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$out     = array();
			foreach ( $is_list ? array_slice( $value, 0, 25 ) : $value as $k => $v ) {
				if ( ! $is_list && self::is_sensitive_key( $k ) ) {
					continue;
				}
				$out[ $k ] = self::scrub( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$text = trim( wp_strip_all_tags( $value ) );
			return strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '…' : $text;
		}
		return $value;
	}

	// ── Reads ───────────────────────────────────────────────────────────────────

	private static function link( $page ) {
		$base = AJWPAI_Settings::ajops_base();
		return $base ? $base . $page : '';
	}

	private static function record_link( $resource, array $row ) {
		$base = AJWPAI_Settings::ajops_base();
		if ( ! $base ) {
			return '';
		}
		if ( 'customers' === $resource ) {
			$id = isset( $row['stripe_customer_id'] ) ? $row['stripe_customer_id'] : ( isset( $row['id'] ) ? $row['id'] : '' );
			return $id ? $base . '/customers/' . rawurlencode( (string) $id ) : $base . '/customers';
		}
		if ( 'leads' === $resource && ! empty( $row['id'] ) ) {
			return $base . '/leads/' . (int) $row['id'];
		}
		return $base . self::RESOURCES[ $resource ][2];
	}

	private static function overview() {
		$summary = AJWPAI_AJCore::read( 'ops/summary' );
		$counts  = AJWPAI_AJCore::read( 'ops/counts' );
		if ( is_wp_error( $summary ) && is_wp_error( $counts ) ) {
			return $summary;
		}
		return array(
			'summary'  => is_wp_error( $summary ) ? null : self::scrub( $summary ),
			'counts'   => is_wp_error( $counts ) ? null : self::scrub( $counts ),
			'ajops_url' => self::link( '/dashboard' ),
		);
	}

	private static function query( array $args ) {
		$resource = isset( $args['resource'] ) ? (string) $args['resource'] : '';
		if ( ! isset( self::RESOURCES[ $resource ] ) ) {
			return new WP_Error( 'ajwpai_bad_resource', 'Unknown resource.' );
		}
		list( $path, $list_key ) = self::RESOURCES[ $resource ];
		$limit = isset( $args['limit'] ) ? max( 1, min( 25, (int) $args['limit'] ) ) : 10;

		if ( ! empty( $args['id'] ) ) {
			if ( ! in_array( $resource, array( 'customers', 'leads' ), true ) ) {
				return new WP_Error( 'ajwpai_id_unsupported', 'Single records are available for customers and leads.' );
			}
			$id   = 'leads' === $resource ? (string) absint( $args['id'] ) : preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $args['id'] );
			$data = AJWPAI_AJCore::read( $path . '/' . $id );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$clean = self::scrub( $data );
			$row   = is_array( $clean ) ? $clean : array();
			return array( 'resource' => $resource, 'record' => $row, 'ajops_url' => self::record_link( $resource, isset( $row['lead'] ) && is_array( $row['lead'] ) ? $row['lead'] : ( isset( $row['customer'] ) && is_array( $row['customer'] ) ? $row['customer'] : $row ) ) );
		}

		$params = array( 'per_page' => $limit );
		if ( ! empty( $args['search'] ) ) {
			$params['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$params['status'] = sanitize_key( (string) $args['status'] );
		}
		$data = AJWPAI_AJCore::read( $path, $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = array();
		if ( isset( $data[ $list_key ] ) && is_array( $data[ $list_key ] ) ) {
			$rows = $data[ $list_key ];
		} else {
			foreach ( (array) $data as $value ) { // Fall back to the first list in the reply.
				if ( is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
					$rows = $value;
					break;
				}
			}
		}
		$out = array();
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$row   = (array) $row;
			$clean = self::scrub( $row );
			if ( is_array( $clean ) ) {
				$clean['ajops_url'] = self::record_link( $resource, $row );
			}
			$out[] = $clean;
		}
		return array( 'resource' => $resource, 'returned' => count( $out ), 'records' => $out, 'ajops_url' => self::link( self::RESOURCES[ $resource ][2] ) );
	}

	private static function files_health() {
		$data = AJWPAI_AJCore::read( 'ops/files', array( 'per_page' => 500, 'status' => 'active' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$counts   = array( 'ok' => 0, 'recoverable' => 0, 'missing' => 0, 'wrong' => 0 );
		$problems = array();
		$shared   = 0;
		foreach ( (array) ( isset( $data['files'] ) ? $data['files'] : array() ) as $f ) {
			$state = isset( $f['file_state'] ) && isset( $counts[ $f['file_state'] ] ) ? $f['file_state'] : 'ok';
			$counts[ $state ]++;
			$pending = ! empty( $f['shared_folder_pending'] );
			$shared += $pending ? 1 : 0;
			if ( 'ok' !== $state || $pending ) {
				$problems[] = array(
					'file_id'        => (int) $f['id'],
					'title'          => isset( $f['title'] ) ? self::scrub( (string) $f['title'] ) : '',
					'state'          => $state,
					'shared_with'    => isset( $f['assignments'] ) ? count( (array) $f['assignments'] ) : 0,
					'storage_path'   => isset( $f['storage_path'] ) ? (string) $f['storage_path'] : '',
					'needs_shared_folder' => $pending,
				);
			}
		}
		return array(
			'total'             => array_sum( $counts ),
			'by_state'          => $counts,
			'need_shared_folder' => $shared,
			'problems'          => array_slice( $problems, 0, 50 ),
			'ajops_url'         => self::link( '/files' ),
		);
	}

	private static function file_candidates( array $args ) {
		if ( ! class_exists( 'AJCore_Customer_Files' ) ) {
			return new WP_Error( 'ajwpai_files_unavailable', 'This AJCore version has no file recovery.' );
		}
		$found = AJCore_Customer_Files::find_objects( isset( $args['file_id'] ) ? (int) $args['file_id'] : 0, isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '' );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$out = array();
		foreach ( array_slice( (array) $found, 0, 15 ) as $o ) {
			$out[] = array( 'key' => $o['key'], 'size_kib' => (int) round( $o['size'] / 1024 ), 'modified' => substr( (string) $o['modified'], 0, 10 ), 'name_match' => ! empty( $o['name_match'] ), 'customer_folder' => ! empty( $o['same_customer'] ) );
		}
		return array( 'candidates' => $out, 'note' => $out ? 'Prefer name_match. propose_file_fix with fix=attach and key=<key> to attach one.' : 'No match. Try a search term.' );
	}

	// ── Proposals ───────────────────────────────────────────────────────────────

	/** Cleans the parameters of one proposed action. Returns array( params, error|null ). Pure. */
	public static function clean_action( $action, array $a ) {
		$text = function ( $key, $max ) use ( $a ) {
			return isset( $a[ $key ] ) && is_string( $a[ $key ] ) ? substr( sanitize_textarea_field( $a[ $key ] ), 0, $max ) : '';
		};
		$lead = isset( $a['lead_id'] ) ? absint( $a['lead_id'] ) : 0;
		$chat = isset( $a['chat_id'] ) ? absint( $a['chat_id'] ) : 0;
		switch ( $action ) {
			case 'lead_note':
				$p = array( 'lead_id' => $lead, 'note' => $text( 'note', 2000 ) );
				return ( $lead && '' !== $p['note'] ) ? array( $p, null ) : array( array(), 'lead_id and a note are required.' );
			case 'lead_tags':
				$tags = array();
				foreach ( isset( $a['tags'] ) && is_array( $a['tags'] ) ? array_slice( $a['tags'], 0, 20 ) : array() as $t ) {
					if ( is_string( $t ) && '' !== sanitize_key( $t ) ) {
						$tags[] = substr( sanitize_key( $t ), 0, 40 );
					}
				}
				return $lead ? array( array( 'lead_id' => $lead, 'tags' => array_values( array_unique( $tags ) ) ), null ) : array( array(), 'lead_id is required.' );
			case 'lead_status':
				$status = isset( $a['status'] ) && is_string( $a['status'] ) ? substr( sanitize_key( $a['status'] ), 0, 40 ) : '';
				$p      = array( 'lead_id' => $lead, 'status' => $status, 'note' => $text( 'note', 1000 ), 'follow_up_at' => isset( $a['follow_up_at'] ) && is_string( $a['follow_up_at'] ) ? substr( sanitize_text_field( $a['follow_up_at'] ), 0, 30 ) : '' );
				return ( $lead && '' !== $status ) ? array( $p, null ) : array( array(), 'lead_id and status are required.' );
			case 'lead_email':
				$p = array( 'lead_id' => $lead, 'subject' => substr( sanitize_text_field( isset( $a['subject'] ) && is_string( $a['subject'] ) ? $a['subject'] : '' ), 0, 150 ), 'body' => $text( 'body', 5000 ) );
				return ( $lead && '' !== $p['subject'] && '' !== $p['body'] ) ? array( $p, null ) : array( array(), 'lead_id, subject and body are required.' );
			case 'chat_reply':
				$p = array( 'chat_id' => $chat, 'body' => $text( 'body', 2000 ) );
				return ( $chat && '' !== $p['body'] ) ? array( $p, null ) : array( array(), 'chat_id and body are required.' );
		}
		return array( array(), 'Unknown action.' );
	}

	private static function propose_action( $token, array $args ) {
		$action = isset( $args['action'] ) ? (string) $args['action'] : '';
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'ajwpai_bad_action', 'Unknown action.' );
		}
		list( $params, $error ) = self::clean_action( $action, $args );
		if ( $error ) {
			return new WP_Error( 'ajwpai_bad_params', $error );
		}
		$target = array();
		$title  = '';
		if ( 0 === strpos( $action, 'lead_' ) ) {
			$lead = AJWPAI_AJCore::read( 'ops/leads/' . (int) $params['lead_id'] );
			if ( is_wp_error( $lead ) || empty( $lead['lead'] ) ) {
				return new WP_Error( 'ajwpai_lead_not_found', 'No lead with that ID.' );
			}
			$target = array(
				'type'  => 'lead',
				'id'    => (int) $params['lead_id'],
				'name'  => sanitize_text_field( (string) ( $lead['lead']['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $lead['lead']['email'] ?? '' ) ),
				'url'   => self::record_link( 'leads', array( 'id' => (int) $params['lead_id'] ) ),
			);
			if ( 'lead_email' === $action && ! is_email( $target['email'] ) ) {
				return new WP_Error( 'ajwpai_lead_no_email', 'That lead has no valid email address.' );
			}
			$title = ucfirst( str_replace( '_', ' ', $action ) ) . ' — ' . ( $target['name'] ? $target['name'] : 'lead #' . $target['id'] );
		} else {
			$target = array( 'type' => 'chat', 'id' => (int) $params['chat_id'], 'url' => self::link( '/live-chat' ) );
			$title  = 'Reply in live chat #' . (int) $params['chat_id'];
		}
		return AJWPAI_Changes::propose_ajcore( $token, $action, $params, $title, $target, isset( $args['reason'] ) ? $args['reason'] : '' );
	}

	private static function file_row( $file_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_files';
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id, title FROM `{$table}` WHERE id = %d", (int) $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function propose_file_fix( $token, array $args ) {
		$fix = isset( $args['fix'] ) ? (string) $args['fix'] : '';
		if ( ! in_array( $fix, self::FIXES, true ) || ! class_exists( 'AJCore_Customer_Files' ) ) {
			return new WP_Error( 'ajwpai_bad_fix', 'Unknown fix, or this AJCore version has no file recovery.' );
		}
		$row = self::file_row( isset( $args['file_id'] ) ? (int) $args['file_id'] : 0 );
		if ( ! $row ) {
			return new WP_Error( 'ajwpai_file_not_found', 'No file record with that ID.' );
		}
		$key = '';
		if ( 'attach' === $fix ) {
			$key = isset( $args['key'] ) && is_string( $args['key'] ) ? ltrim( sanitize_text_field( $args['key'] ), '/' ) : '';
			if ( '' === $key || false !== strpos( $key, '..' ) ) {
				return new WP_Error( 'ajwpai_key_required', 'attach needs the storage object key.' );
			}
		}
		$title = array( 'reattach' => 'Reattach file', 'attach' => 'Attach file', 'move_shared' => 'Move to shared folder' );
		return AJWPAI_Changes::propose_file_fix(
			$token,
			array( 'fix' => $fix, 'file_id' => (int) $row->id, 'key' => $key, 'file_title' => $row->title, 'summary' => $title[ $fix ] . ' — ' . $row->title, 'url' => self::link( '/files' ) ),
			isset( $args['reason'] ) ? $args['reason'] : ''
		);
	}

	// ── Applying (an administrator approved) ────────────────────────────────────

	/** @return true|WP_Error */
	public static function apply_action( array $payload ) {
		if ( ! current_user_can( 'manage_options' ) || ! AJWPAI_Settings::ajcore_enabled() ) {
			return new WP_Error( 'ajwpai_forbidden', 'AJCore actions are switched off, or you cannot run them.' );
		}
		$p      = isset( $payload['params'] ) ? (array) $payload['params'] : array();
		$routes = array( 'lead_note' => 'lead-note', 'lead_tags' => 'lead-tags', 'lead_status' => 'lead-status', 'lead_email' => 'lead-email', 'chat_reply' => 'chat-reply' );
		$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';
		if ( ! isset( $routes[ $action ] ) ) {
			return new WP_Error( 'ajwpai_bad_action', 'Unknown action.' );
		}
		if ( 'lead_status' === $action ) {
			$p['customer_name'] = '';
		}
		$result = AJWPAI_AJCore::run( $routes[ $action ], $p );
		return is_wp_error( $result ) ? $result : true;
	}

	/** @return true|WP_Error */
	public static function apply_file_fix( array $payload ) {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'AJCore_Customer_Files' ) ) {
			return new WP_Error( 'ajwpai_forbidden', 'You cannot run file fixes.' );
		}
		$row = self::file_row( isset( $payload['file_id'] ) ? (int) $payload['file_id'] : 0 );
		if ( ! $row ) {
			return new WP_Error( 'ajwpai_file_not_found', 'The file record no longer exists.' );
		}
		switch ( isset( $payload['fix'] ) ? $payload['fix'] : '' ) {
			case 'reattach':
				$r = AJCore_Customer_Files::reattach( (int) $row->id );
				break;
			case 'attach':
				$r = AJCore_Customer_Files::attach_object( (int) $row->id, (string) $payload['key'] );
				break;
			case 'move_shared':
				if ( ! class_exists( 'AJCore_Storage_Service' ) || ! AJCore_Storage_Service::belongs_in_shared_folder( (int) $row->attachment_id ) ) {
					return new WP_Error( 'ajwpai_not_shared', 'This file does not belong in the shared folder.' );
				}
				$r = AJCore_Storage_Service::relocate_to_expected( (int) $row->attachment_id );
				break;
			default:
				return new WP_Error( 'ajwpai_bad_fix', 'Unknown fix.' );
		}
		return is_wp_error( $r ) ? $r : true;
	}
}
