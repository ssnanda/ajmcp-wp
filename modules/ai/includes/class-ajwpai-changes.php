<?php
/**
 * Proposed changes: what Claude may suggest, and what happens when an administrator approves.
 *
 * Claude never writes to the site. It stores a *proposal* (a pending change). Nothing touches a post
 * until an administrator clicks Approve in wp-admin. Rules that cannot be bypassed from the tools:
 *   - new posts are always created as DRAFTS; no tool can publish, schedule or set a date or author
 *   - edits never change a post's status
 *   - nothing is ever deleted
 *   - content is cleaned with wp_kses_post (no scripts), only existing categories are used
 *   - an edit is refused if the post changed after it was proposed (unless the admin chooses to force it)
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Changes {

	const MAX_PENDING   = 20;
	const MAX_CONTENT   = 200000;
	const FIELD_KEYS    = array( 'title', 'content', 'excerpt', 'slug', 'categories', 'tags', 'seo_description' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ajwpai_changes';
	}

	public static function schema( $charset ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			token_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(10) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(20) NOT NULL DEFAULT 'post',
			payload longtext NOT NULL,
			base longtext NULL,
			base_modified datetime DEFAULT NULL,
			note text NULL,
			status varchar(10) NOT NULL DEFAULT 'pending',
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided datetime DEFAULT NULL,
			result_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			error text NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";
	}

	// ── Reading ─────────────────────────────────────────────────────────────────

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/** @param string $status 'pending', 'decided' (applied/rejected/failed) or '' for all. */
	public static function listing( $status = '', $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		$where = 'pending' === $status ? "WHERE status = 'pending'" : ( 'decided' === $status ? "WHERE status <> 'pending'" : '' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	public static function pending_count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function hydrate( $row ) {
		$row->payload = json_decode( (string) $row->payload, true ) ?: array();
		$row->base    = json_decode( (string) $row->base, true ) ?: array();
		return $row;
	}

	public static function review_url( $id ) {
		return add_query_arg( array( 'page' => 'ajwpai-settings', 'change' => (int) $id ), admin_url( 'admin.php' ) ) . '#ajwpai-change-' . (int) $id;
	}

	// ── Cleaning what Claude sends (untrusted) ──────────────────────────────────

	/**
	 * Keeps only the allowed fields and cleans each one. Unknown keys (status, date, author, ...) are
	 * dropped, so a proposal cannot carry them. Returns the clean fields or a WP_Error.
	 */
	public static function clean_fields( array $args ) {
		$out = array();
		if ( isset( $args['title'] ) && is_string( $args['title'] ) ) {
			$out['title'] = substr( sanitize_text_field( $args['title'] ), 0, 200 );
		}
		if ( isset( $args['content'] ) && is_string( $args['content'] ) ) {
			if ( strlen( $args['content'] ) > self::MAX_CONTENT ) {
				return new WP_Error( 'ajwpai_too_long', 'The content is too long (limit ' . self::MAX_CONTENT . ' characters).' );
			}
			$out['content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['excerpt'] ) && is_string( $args['excerpt'] ) ) {
			$out['excerpt'] = substr( sanitize_textarea_field( $args['excerpt'] ), 0, 600 );
		}
		if ( isset( $args['slug'] ) && is_string( $args['slug'] ) ) {
			$out['slug'] = substr( sanitize_title( $args['slug'] ), 0, 100 );
		}
		if ( isset( $args['seo_description'] ) && is_string( $args['seo_description'] ) ) {
			$out['seo_description'] = substr( sanitize_text_field( $args['seo_description'] ), 0, 300 );
		}
		foreach ( array( 'categories' => 10, 'tags' => 20 ) as $key => $max ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$list = array();
				foreach ( array_slice( $args[ $key ], 0, $max ) as $item ) {
					if ( is_string( $item ) && '' !== trim( $item ) ) {
						$list[] = substr( sanitize_text_field( $item ), 0, 80 );
					}
				}
				$out[ $key ] = array_values( array_unique( $list ) );
			}
		}
		return $out;
	}

	private static function clean_note( $args ) {
		return isset( $args['note'] ) && is_string( $args['note'] ) ? substr( sanitize_textarea_field( $args['note'] ), 0, 500 ) : '';
	}

	// ── Proposing ───────────────────────────────────────────────────────────────

	private static function insert( $token, $kind, $post_id, $post_type, array $payload, array $base, $base_modified, $note ) {
		global $wpdb;
		if ( self::pending_count() >= self::MAX_PENDING ) {
			return new WP_Error( 'ajwpai_too_many', 'There are already ' . self::MAX_PENDING . ' proposals waiting for approval. Ask the administrator to review them first.' );
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created'       => gmdate( 'Y-m-d H:i:s' ),
				'token_id'      => (int) $token->id,
				'user_id'       => (int) $token->user_id,
				'kind'          => $kind,
				'post_id'       => (int) $post_id,
				'post_type'     => $post_type,
				'payload'       => wp_json_encode( $payload ),
				'base'          => wp_json_encode( $base ),
				'base_modified' => $base_modified,
				'note'          => $note,
				'status'        => 'pending',
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'ajwpai_store_failed', 'Could not save the proposal.' );
		}
		$id = (int) $wpdb->insert_id;
		return array(
			'change_id'  => $id,
			'status'     => 'pending',
			'review_url' => self::review_url( $id ),
			'message'    => 'Saved for approval. Nothing on the site has changed. An administrator must approve it in wp-admin under AJ WP AI.',
		);
	}

	/** An AJCore action (lead note, status, email, chat reply). Stored, never run until approved. */
	public static function propose_ajcore( $token, $action, array $params, $summary, array $target, $reason ) {
		$note = is_string( $reason ) ? substr( sanitize_textarea_field( $reason ), 0, 500 ) : '';
		return self::insert( $token, 'ajcore', 0, 'ajcore', array( 'action' => $action, 'params' => $params, 'summary' => $summary, 'target' => $target ), array(), null, $note );
	}

	/** A Client Portal file fix (reattach, attach, move to shared folder). */
	public static function propose_file_fix( $token, array $payload, $reason ) {
		$note = is_string( $reason ) ? substr( sanitize_textarea_field( $reason ), 0, 500 ) : '';
		return self::insert( $token, 'file', 0, 'file', $payload, array(), null, $note );
	}

	public static function propose_new( $token, array $args ) {
		$fields = self::clean_fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( empty( $fields['title'] ) ) {
			return new WP_Error( 'ajwpai_title_required', 'A title is required.' );
		}
		$type = isset( $args['post_type'] ) && 'page' === $args['post_type'] ? 'page' : 'post';
		if ( 'page' === $type ) {
			unset( $fields['categories'], $fields['tags'] );
		}
		return self::insert( $token, 'new', 0, $type, $fields, array(), null, self::clean_note( $args ) );
	}

	public static function propose_edit( $token, array $args ) {
		$post = isset( $args['id'] ) ? get_post( (int) $args['id'] ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'ajwpai_not_found', 'No editable post or page with that ID.' );
		}
		$fields = self::clean_fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( 'page' === $post->post_type ) {
			unset( $fields['categories'], $fields['tags'] );
		}
		if ( ! $fields ) {
			return new WP_Error( 'ajwpai_nothing', 'Nothing to change: give at least one of title, content, excerpt, slug, categories, tags or seo_description.' );
		}
		return self::insert( $token, 'edit', $post->ID, $post->post_type, $fields, self::snapshot( $post ), $post->post_modified_gmt, self::clean_note( $args ) );
	}

	/** The current values of every editable field, so the review card can show before/after. */
	public static function snapshot( WP_Post $post ) {
		$snap = array(
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'slug'    => $post->post_name,
			'status'  => $post->post_status,
			'seo_description' => (string) get_post_meta( $post->ID, self::seo_key( $post->ID ), true ),
		);
		if ( 'post' === $post->post_type ) {
			$snap['categories'] = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$snap['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		}
		return $snap;
	}

	/** Which meta field holds the SEO description for this site (filterable). */
	public static function seo_key( $post_id ) {
		$keys = (array) apply_filters( 'ajwpai_seo_meta_keys', array( '_ajnanda_seo_description', '_yoast_wpseo_metadesc', 'rank_math_description' ) );
		foreach ( $keys as $key ) {
			if ( '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				return $key;
			}
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_metadesc';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rank_math_description';
		}
		return (string) apply_filters( 'ajwpai_seo_write_key', '_ajnanda_seo_description', $post_id );
	}

	// ── Deciding ────────────────────────────────────────────────────────────────

	private static function mark( $id, $status, $user_id, $result_post_id = 0, $error = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'status' => $status, 'decided_by' => (int) $user_id, 'decided' => gmdate( 'Y-m-d H:i:s' ), 'result_post_id' => (int) $result_post_id, 'error' => $error ),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	public static function reject( $id, $user_id ) {
		$change = self::get( $id );
		if ( ! $change || 'pending' !== $change->status ) {
			return new WP_Error( 'ajwpai_not_pending', 'That proposal is no longer pending.' );
		}
		self::mark( $id, 'rejected', $user_id );
		return true;
	}

	/** Existing category IDs for the names Claude gave; unknown names are skipped (never created). */
	private static function category_ids( array $names ) {
		$ids = array();
		foreach ( $names as $name ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), 'category' ) ?: get_term_by( 'name', $name, 'category' );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Applies a pending proposal as the approving administrator.
	 *
	 * @param bool $force Apply an edit even though the post changed since it was proposed.
	 * @return int|WP_Error The affected post ID.
	 */
	public static function apply( $id, $user_id, $force = false ) {
		$change = self::get( $id );
		if ( ! $change || 'pending' !== $change->status ) {
			return new WP_Error( 'ajwpai_not_pending', 'That proposal is no longer pending.' );
		}
		$f = $change->payload;

		if ( 'ajcore' === $change->kind || 'file' === $change->kind ) {
			$result = 'ajcore' === $change->kind ? AJWPAI_AJCore_Tools::apply_action( $f ) : AJWPAI_AJCore_Tools::apply_file_fix( $f );
			if ( is_wp_error( $result ) ) {
				self::mark( $id, 'failed', $user_id, 0, $result->get_error_message() );
				return $result;
			}
			self::mark( $id, 'applied', $user_id, 0 );
			return 0; // No post involved.
		}

		$postarr = array();
		if ( isset( $f['title'] ) ) {
			$postarr['post_title'] = $f['title'];
		}
		if ( isset( $f['content'] ) ) {
			$postarr['post_content'] = $f['content'];
		}
		if ( isset( $f['excerpt'] ) ) {
			$postarr['post_excerpt'] = $f['excerpt'];
		}
		if ( isset( $f['slug'] ) ) {
			$postarr['post_name'] = $f['slug'];
		}

		if ( 'new' === $change->kind ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error( 'ajwpai_forbidden', 'You cannot create posts.' );
			}
			$postarr['post_type']   = $change->post_type;
			$postarr['post_status'] = 'draft'; // Always a draft, whatever was asked.
			$postarr['post_author'] = (int) $user_id;
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		} else {
			$post = get_post( (int) $change->post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				$error = 'The post no longer exists or you cannot edit it.';
				self::mark( $id, 'failed', $user_id, 0, $error );
				return new WP_Error( 'ajwpai_gone', $error );
			}
			if ( ! $force && $change->base_modified && $post->post_modified_gmt !== $change->base_modified ) {
				return new WP_Error( 'ajwpai_conflict', 'This post was changed after the edit was proposed. Review it again, or apply anyway.' );
			}
			$postarr['ID'] = $post->ID;
			$post_id       = $postarr ? wp_update_post( wp_slash( $postarr ), true ) : $post->ID; // Status is never in $postarr.
		}
		if ( is_wp_error( $post_id ) ) {
			self::mark( $id, 'failed', $user_id, 0, $post_id->get_error_message() );
			return $post_id;
		}

		$post_id = (int) $post_id;
		if ( 'post' === get_post_type( $post_id ) ) {
			if ( isset( $f['categories'] ) ) {
				$ids = self::category_ids( $f['categories'] );
				if ( $ids ) {
					wp_set_post_categories( $post_id, $ids );
				}
			}
			if ( isset( $f['tags'] ) ) {
				wp_set_post_tags( $post_id, $f['tags'], false );
			}
		}
		if ( isset( $f['seo_description'] ) ) {
			update_post_meta( $post_id, self::seo_key( $post_id ), wp_slash( $f['seo_description'] ) );
		}

		self::mark( $id, 'applied', $user_id, $post_id );
		return $post_id;
	}
}
