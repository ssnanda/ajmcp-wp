<?php
/**
 * Tools Claude can call. Version 1 is read-only: every tool only reads posts and pages.
 *
 * Each tool runs as the WordPress user who approved the connection (so normal capability checks
 * apply), never touches the database directly, and returns small structured results.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Tools {

	const SCOPE_READ    = 'posts:read';
	const SCOPE_PROPOSE = 'posts:propose';

	/** Post types Claude may see. */
	private static function post_types() {
		return array( 'post', 'page' );
	}

	/** Tool definitions in MCP format (name, description, JSON Schema, hints). */
	public static function definitions() {
		$tools = self::post_tool_definitions();
		if ( AJWPAI_Settings::ajcore_enabled() ) {
			$tools = array_merge( $tools, AJWPAI_AJCore_Tools::definitions() );
		}
		return $tools;
	}

	private static function post_tool_definitions() {
		$read_only = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		return array(
			array(
				'name'        => 'search_posts',
				'title'       => 'Search posts and pages',
				'description' => 'Find posts and pages by keyword, type, status or category. Returns a short list (id, title, status, dates, link, excerpt). Use get_post to read one in full.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string', 'description' => 'Keywords to search for in title and content. Leave empty to list the newest.' ),
						'post_type' => array( 'type' => 'string', 'enum' => array( 'any', 'post', 'page' ), 'default' => 'any' ),
						'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'draft', 'pending', 'private', 'future' ), 'default' => 'any' ),
						'category'  => array( 'type' => 'string', 'description' => 'Category slug or name (posts only).' ),
						'order_by'  => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title' ), 'default' => 'modified' ),
						'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ),
					),
				),
				'annotations' => $read_only,
			),
			array(
				'name'        => 'get_post',
				'title'       => 'Read a post or page',
				'description' => 'Get one post or page by ID: title, status, dates, author, categories, tags, excerpt, SEO description, and the full content (block markup and plain text).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => $read_only,
			),
			array(
				'name'        => 'list_categories',
				'title'       => 'List categories',
				'description' => 'List post categories with their slugs and how many posts each has.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
					),
				),
				'annotations' => $read_only,
			),
			array(
				'name'        => 'list_pending_changes',
				'title'       => 'See proposed changes',
				'description' => 'List recent proposals (pending, applied, rejected) so you can tell the user what is waiting for their approval.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'decided', 'all' ), 'default' => 'pending' ),
					),
				),
				'annotations' => $read_only,
			),
			array(
				'name'        => 'propose_new_post',
				'title'       => 'Propose a new draft',
				'description' => 'Propose a NEW post or page. This does not create anything: it saves a proposal that the site administrator must approve in wp-admin, and approval creates a DRAFT (never published). Content may use WordPress block markup or HTML. Tell the user to approve it under AJ WP AI.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'           => array( 'type' => 'string', 'description' => 'Required.' ),
						'content'         => array( 'type' => 'string', 'description' => 'Body as block markup or HTML.' ),
						'excerpt'         => array( 'type' => 'string' ),
						'slug'            => array( 'type' => 'string' ),
						'post_type'       => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'default' => 'post' ),
						'categories'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Existing category names or slugs only.' ),
						'tags'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'seo_description' => array( 'type' => 'string', 'description' => 'Meta description, about 70-170 characters.' ),
						'note'            => array( 'type' => 'string', 'description' => 'One or two sentences for the reviewer: what this is and why.' ),
					),
					'required'   => array( 'title' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
			),
			array(
				'name'        => 'propose_edit_post',
				'title'       => 'Propose an edit',
				'description' => 'Propose changes to an EXISTING post or page. This does not edit anything: it saves a proposal that the site administrator must approve in wp-admin, where they see before and after. Give only the fields to change. It cannot change the status, date or author, and cannot delete. Read the post with get_post first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
						'title'           => array( 'type' => 'string' ),
						'content'         => array( 'type' => 'string', 'description' => 'The COMPLETE new body (it replaces the old one).' ),
						'excerpt'         => array( 'type' => 'string' ),
						'slug'            => array( 'type' => 'string' ),
						'categories'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Replaces the categories. Existing ones only.' ),
						'tags'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Replaces the tags.' ),
						'seo_description' => array( 'type' => 'string' ),
						'note'            => array( 'type' => 'string', 'description' => 'One or two sentences for the reviewer: what changed and why.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
			),
			array(
				'name'        => 'site_info',
				'title'       => 'Site overview',
				'description' => 'Basic facts about this site: name, address, WordPress version, theme, language, and how many posts and pages exist in each status.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => $read_only,
			),
		);
	}

	/** Scope each tool needs. */
	public static function required_scope( $tool ) {
		if ( AJWPAI_AJCore_Tools::is_tool( $tool ) ) {
			return AJWPAI_AJCore_Tools::required_scope( $tool );
		}
		return in_array( $tool, array( 'propose_new_post', 'propose_edit_post' ), true ) ? self::SCOPE_PROPOSE : self::SCOPE_READ;
	}

	/**
	 * Runs a tool. Returns data (array) or a WP_Error. Never prints.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments from Claude (untrusted).
	 */
	public static function call( $name, array $args, $token = null ) {
		if ( AJWPAI_AJCore_Tools::is_tool( $name ) ) {
			return AJWPAI_AJCore_Tools::call( $name, $args, $token );
		}
		switch ( $name ) {
			case 'propose_new_post':
				return $token ? AJWPAI_Changes::propose_new( $token, $args ) : new WP_Error( 'ajwpai_no_token', 'Not authorized.' );
			case 'propose_edit_post':
				return $token ? AJWPAI_Changes::propose_edit( $token, $args ) : new WP_Error( 'ajwpai_no_token', 'Not authorized.' );
			case 'list_pending_changes':
				return self::list_pending( $args );
			case 'search_posts':
				return self::search_posts( $args );
			case 'get_post':
				return self::get_post( $args );
			case 'list_categories':
				return self::list_categories( $args );
			case 'site_info':
				return self::site_info();
		}
		return new WP_Error( 'ajwpai_unknown_tool', 'Unknown tool.' );
	}

	private static function summary( WP_Post $post ) {
		$cats = 'post' === $post->post_type ? wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) : array();
		return array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'slug'     => $post->post_name,
			'date'     => $post->post_date_gmt . ' UTC',
			'modified' => $post->post_modified_gmt . ' UTC',
			'url'      => 'publish' === $post->post_status ? get_permalink( $post ) : '',
			'author'   => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'excerpt'  => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content ) ), 40 ),
			'categories' => $cats,
		);
	}

	private static function search_posts( array $args ) {
		$type   = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any';
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'any';
		$limit  = isset( $args['limit'] ) ? max( 1, min( 25, (int) $args['limit'] ) ) : 10;
		$order  = isset( $args['order_by'] ) && in_array( $args['order_by'], array( 'date', 'modified', 'title' ), true ) ? $args['order_by'] : 'modified';

		$query = array(
			'post_type'              => in_array( $type, self::post_types(), true ) ? $type : self::post_types(),
			'post_status'            => in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $status : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => $limit,
			'orderby'                => $order,
			'order'                  => 'title' === $order ? 'ASC' : 'DESC',
			'no_found_rows'          => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => false,
		);
		if ( ! empty( $args['query'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['query'] );
		}
		if ( ! empty( $args['category'] ) ) {
			$cat = sanitize_text_field( (string) $args['category'] );
			$term = get_term_by( 'slug', sanitize_title( $cat ), 'category' ) ?: get_term_by( 'name', $cat, 'category' );
			if ( $term ) {
				$query['cat'] = (int) $term->term_id;
			} else {
				return array( 'total' => 0, 'returned' => 0, 'posts' => array(), 'note' => 'No category matched.' );
			}
		}

		$q     = new WP_Query( $query );
		$posts = array();
		foreach ( $q->posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$posts[] = self::summary( $post );
			}
		}
		return array( 'total' => (int) $q->found_posts, 'returned' => count( $posts ), 'posts' => $posts );
	}

	private static function get_post( array $args ) {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'ajwpai_not_found', 'No readable post or page with that ID.' );
		}
		$out = self::summary( $post );
		$out['parent_id'] = (int) $post->post_parent;
		if ( 'post' === $post->post_type ) {
			$out['tags'] = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		}
		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		$out['featured_image'] = $thumb ? $thumb : '';

		$seo = array();
		foreach ( (array) apply_filters( 'ajwpai_seo_meta_keys', array( '_ajnanda_seo_description', '_yoast_wpseo_metadesc', 'rank_math_description' ) ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$seo[ $key ] = $value;
			}
		}
		$out['seo'] = $seo;

		if ( post_password_required( $post ) ) {
			$out['content_note'] = 'Password protected: content is not shared.';
			return $out;
		}
		$limit_blocks = 60000;
		$limit_text   = 20000;
		$raw          = (string) $post->post_content;
		$text         = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $raw ) ) ) );
		$out['word_count']     = str_word_count( $text );
		$out['content_blocks'] = substr( $raw, 0, $limit_blocks );
		$out['content_text']   = substr( $text, 0, $limit_text );
		$out['truncated']      = strlen( $raw ) > $limit_blocks || strlen( $text ) > $limit_text;
		return $out;
	}

	private static function list_pending( array $args ) {
		$status = isset( $args['status'] ) && in_array( $args['status'], array( 'decided', 'all' ), true ) ? $args['status'] : 'pending';
		$out    = array();
		foreach ( AJWPAI_Changes::listing( 'all' === $status ? '' : $status, 25 ) as $c ) {
			$out[] = array(
				'change_id' => (int) $c->id,
				'kind'      => $c->kind,
				'post_id'   => (int) $c->post_id,
				'title'     => isset( $c->payload['title'] ) ? $c->payload['title'] : ( isset( $c->base['title'] ) ? $c->base['title'] : '' ),
				'status'    => $c->status,
				'created'   => $c->created . ' UTC',
				'result_post_id' => (int) $c->result_post_id,
			);
		}
		return array( 'changes' => $out );
	}

	private static function list_categories( array $args ) {
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 50;
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'number' => $limit ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'posts' => (int) $term->count );
		}
		return array( 'categories' => $out );
	}

	private static function site_info() {
		$counts = array();
		foreach ( self::post_types() as $type ) {
			$c = wp_count_posts( $type );
			$counts[ $type ] = array(
				'publish' => (int) ( $c->publish ?? 0 ),
				'draft'   => (int) ( $c->draft ?? 0 ),
				'pending' => (int) ( $c->pending ?? 0 ),
				'private' => (int) ( $c->private ?? 0 ),
				'future'  => (int) ( $c->future ?? 0 ),
			);
		}
		return array(
			'name'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'wordpress' => get_bloginfo( 'version' ),
			'theme'     => wp_get_theme()->get( 'Name' ),
			'language'  => get_locale(),
			'timezone'  => wp_timezone_string(),
			'counts'    => $counts,
			'connector' => 'ajwpai ' . AJWPAI_VERSION . ' (read-only)',
		);
	}
}
