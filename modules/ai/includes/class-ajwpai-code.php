<?php
/**
 * Narrow WordPress tools used by the local Codex companion.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Code {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'ajwpai/v1',
			'/code/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'posts' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			'ajwpai/v1',
			'/code/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'draft' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
		register_rest_route(
			'ajwpai/v1',
			'/code/design-catalog',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'design_catalog' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			'ajwpai/v1',
			'/code/designed-draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'designed_draft' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
	}

	public static function can_read() {
		return current_user_can( 'manage_options' );
	}

	public static function can_write( WP_REST_Request $request ) {
		$type = 'page' === $request->get_param( 'post_type' ) ? 'page' : 'post';
		return current_user_can( 'manage_options' ) && current_user_can( 'page' === $type ? 'edit_pages' : 'edit_posts' );
	}

	public static function posts( WP_REST_Request $request ) {
		$type   = 'page' === $request->get_param( 'post_type' ) ? 'page' : 'post';
		$limit  = min( 50, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$query  = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				's'              => $search,
			)
		);
		$posts = array_map(
			function ( WP_Post $post ) {
				return array(
					'id'       => $post->ID,
					'title'    => get_the_title( $post ),
					'status'   => $post->post_status,
					'modified' => get_date_from_gmt( $post->post_modified_gmt, DATE_ATOM ),
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				);
			},
			$query->posts
		);
		return rest_ensure_response( array( 'posts' => $posts ) );
	}

	public static function draft( WP_REST_Request $request ) {
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'ajwpai_title_required', 'A title is required.', array( 'status' => 400 ) );
		}
		$type    = 'page' === $request->get_param( 'post_type' ) ? 'page' : 'post';
		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		$excerpt = sanitize_textarea_field( (string) $request->get_param( 'excerpt' ) );
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => substr( $title, 0, 200 ),
					'post_content' => $content,
					'post_excerpt' => substr( $excerpt, 0, 600 ),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return rest_ensure_response(
			array(
				'id'       => (int) $post_id,
				'status'   => 'draft',
				'edit_url' => get_edit_post_link( (int) $post_id, 'raw' ),
			)
		);
	}

	public static function design_catalog() {
		$patterns = array();
		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
				$name = isset( $pattern['name'] ) ? (string) $pattern['name'] : '';
				if ( 0 !== strpos( $name, 'ajnanda/' ) && 0 !== strpos( $name, 'ajnanda-pro/' ) ) {
					continue;
				}
				$patterns[] = array(
					'name'        => $name,
					'title'       => wp_strip_all_tags( (string) ( $pattern['title'] ?? '' ) ),
					'description' => wp_strip_all_tags( (string) ( $pattern['description'] ?? '' ) ),
					'categories'  => array_values( (array) ( $pattern['categories'] ?? array() ) ),
				);
			}
		}
		return rest_ensure_response(
			array(
				'theme'       => wp_get_theme()->get( 'Name' ),
				'patterns'    => $patterns,
				'blog_styles' => array(
					'core/group:ajn-callout',
					'core/group:ajn-cta-band',
					'core/column:ajn-info-card',
					'core/details:ajn-faq',
					'core/group:ajnanda-card',
					'core/group:ajnanda-card-elevated',
					'core/group:ajnanda-card-soft',
					'core/list:ajnanda-checklist',
					'core/paragraph:ajnanda-eyebrow',
				),
				'rules'       => array(
					'Use Gutenberg block comments and registered core or AJNanda blocks only.',
					'Never use core/html or unwrapped HTML.',
					'Use theme styles and presets instead of inline CSS.',
				),
			)
		);
	}

	public static function designed_draft( WP_REST_Request $request ) {
		$content = (string) $request->get_param( 'content' );
		$blocks  = parse_blocks( $content );
		$error   = self::validate_blocks( $blocks );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$request->set_param( 'content', serialize_blocks( $blocks ) );
		return self::draft( $request );
	}

	private static function validate_blocks( array $blocks ) {
		if ( empty( $blocks ) ) {
			return new WP_Error( 'ajwpai_blocks_required', 'Designed posts require Gutenberg blocks.', array( 'status' => 400 ) );
		}
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					return new WP_Error( 'ajwpai_freeform_block', 'Unwrapped or classic HTML is not allowed.', array( 'status' => 400 ) );
				}
				continue;
			}
			if ( 'core/html' === $name || ( 0 !== strpos( $name, 'core/' ) && 0 !== strpos( $name, 'ajnanda/' ) ) ) {
				return new WP_Error( 'ajwpai_block_not_allowed', sprintf( 'Block %s is not allowed.', $name ), array( 'status' => 400 ) );
			}
			if ( ! $registry->is_registered( $name ) ) {
				return new WP_Error( 'ajwpai_block_missing', sprintf( 'Block %s is not registered on this site.', $name ), array( 'status' => 400 ) );
			}
			$error = self::validate_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
			if ( is_wp_error( $error ) && ! empty( $block['innerBlocks'] ) ) {
				return $error;
			}
		}
		return true;
	}
}
