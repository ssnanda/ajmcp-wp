<?php
/**
 * Plugin settings: whether Claude may see AJCore data, and the AJ Ops address used for deep links.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Settings {

	const OPTION = 'ajwpai_settings';

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array( 'ajcore_enabled' => 0, 'ajops_url' => '' ) );
	}

	/** Claude can only use AJCore tools when AJCore is active AND the administrator switched this on. */
	public static function ajcore_enabled() {
		$s = self::get();
		return class_exists( 'AJCore_REST_API' ) && ! empty( $s['ajcore_enabled'] );
	}

	/** AJ Ops address without a trailing slash. Empty string when unknown. */
	public static function ajops_base() {
		$s    = self::get();
		$base = trim( (string) $s['ajops_url'] );
		if ( '' === $base ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$host = preg_replace( '/^www\./', '', $host );
			$base = $host ? 'https://ops.' . $host : '';
		}
		return untrailingslashit( (string) apply_filters( 'ajwpai_ajops_base', $base ) );
	}

	public static function save( array $in ) {
		$url = isset( $in['ajops_url'] ) ? esc_url_raw( trim( (string) $in['ajops_url'] ), array( 'https', 'http' ) ) : '';
		update_option(
			self::OPTION,
			array(
				'ajcore_enabled' => empty( $in['ajcore_enabled'] ) ? 0 : 1,
				'ajops_url'      => $url,
			),
			false
		);
	}
}
