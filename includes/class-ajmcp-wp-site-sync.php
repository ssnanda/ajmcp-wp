<?php
/**
 * Lets the chatbot know the website: published pages and posts are turned into plain-text knowledge documents
 * (marked "website") on the AJMCP server. A sync replaces only those documents; manual ones are never touched.
 * It runs on demand, and a couple of minutes after a page or post changes when automatic sync is on.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Site_Sync {
    const OPTION     = 'ajmcp_wp_sync';
    const EVENT      = 'ajmcp_wp_site_sync';
    const DOC_CHARS  = 8000;

    public static function init(): void {
        add_action( 'save_post', array( __CLASS__, 'queue' ), 20 );
        add_action( 'trashed_post', array( __CLASS__, 'queue' ) );
        add_action( 'deleted_post', array( __CLASS__, 'queue' ) );
        add_action( self::EVENT, array( __CLASS__, 'run' ) );
    }

    public static function settings(): array {
        $saved = get_option( self::OPTION, array() );
        return array_merge( array( 'enabled' => 0, 'types' => array( 'page', 'post' ), 'max' => 60, 'exclude' => '', 'last' => array() ), is_array( $saved ) ? $saved : array() );
    }

    /** Public post types that make sense as knowledge (never attachments). */
    public static function available_types(): array {
        $types = get_post_types( array( 'public' => true ), 'objects' );
        unset( $types['attachment'] );
        $out = array();
        foreach ( $types as $slug => $type ) {
            $out[ $slug ] = (string) $type->labels->singular_name;
        }
        return $out;
    }

    public static function save_settings( array $in ): void {
        $allowed = array_keys( self::available_types() );
        $types   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['types'] ?? array() ) ), $allowed ) );
        $ids     = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $in['exclude'] ?? '' ) ) ) );
        $old     = self::settings();
        update_option( self::OPTION, array(
            'enabled' => empty( $in['enabled'] ) ? 0 : 1,
            'types'   => $types,
            'max'     => min( 150, max( 1, absint( $in['max'] ?? 60 ) ) ),
            'exclude' => implode( ', ', $ids ),
            'last'    => $old['last'],
        ), false );
    }

    /** Page or post changed: schedule one sync a couple of minutes out (changes in between are batched). */
    public static function queue( $post_id ): void {
        $settings = self::settings();
        if ( empty( $settings['enabled'] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( in_array( get_post_type( $post_id ), (array) $settings['types'], true ) && ! wp_next_scheduled( self::EVENT ) ) {
            wp_schedule_single_event( time() + 120, self::EVENT );
        }
    }

    /** HTML → readable plain text: paragraphs kept, tags, shortcodes and comments removed. */
    public static function clean_text( string $html ): string {
        $text = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $html ) : $html;
        $text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text );
        $text = preg_replace( '#<br\s*/?>|</(p|h[1-6]|li|div|tr|section|blockquote)>#i', "\n", (string) $text );
        $text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( "/[ \t\xC2\xA0]+/", ' ', $text );
        $text = preg_replace( "/\s*\n\s*/", "\n", (string) $text );
        return trim( preg_replace( "/\n{2,}/", "\n\n", (string) $text ) );
    }

    /** @return array<int,array{ref:string,title:string,text:string}> */
    public static function build_docs(): array {
        $settings = self::settings();
        if ( empty( $settings['types'] ) ) {
            return array();
        }
        $query = new WP_Query( array(
            'post_type' => $settings['types'], 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => (int) $settings['max'],
            'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'post__not_in' => array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $settings['exclude'] ) ) ),
        ) );
        $docs = array();
        foreach ( $query->posts as $post ) {
            $body = self::clean_text( (string) $post->post_content );
            if ( '' === $body ) {
                continue;
            }
            $type   = get_post_type_object( $post->post_type );
            $title  = trim( wp_strip_all_tags( get_the_title( $post ) ) );
            $docs[] = array(
                'ref'   => 'post-' . $post->ID,
                'title' => ( $type ? $type->labels->singular_name : 'Page' ) . ': ' . ( '' !== $title ? $title : '(untitled)' ),
                'text'  => mb_substr( 'Web page: ' . get_permalink( $post ) . "\n\n" . $body, 0, self::DOC_CHARS ),
            );
        }
        return $docs;
    }

    /** Sends the current pages to the server. Returns the result and remembers it for the Knowledge screen. */
    public static function run(): array {
        $docs   = self::build_docs();
        $result = AJMCP_WP_Client::request( '/v1/site/knowledge/sync', 'PUT', array( 'source' => 'website', 'docs' => $docs ) );
        $last   = is_wp_error( $result )
            ? array( 'time' => time(), 'ok' => false, 'error' => AJMCP_WP_Behavior::reason( $result ) )
            : array( 'time' => time(), 'ok' => true, 'count' => (int) ( $result['count'] ?? 0 ), 'removed' => (int) ( $result['removed'] ?? 0 ) );
        $settings         = self::settings();
        $settings['last'] = $last;
        update_option( self::OPTION, $settings, false );
        return $last;
    }
}
