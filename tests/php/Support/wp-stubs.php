<?php
/**
 * Minimal stand-ins for WordPress functions, for unit tests only.
 * Add a function here only when a unit test genuinely needs it; prefer pure classes that need none.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore
		return esc_html( $text );
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) { // phpcs:ignore
		return htmlspecialchars_decode( (string) $text, $quote_style );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) { // phpcs:ignore
		return json_encode( $data, $flags, $depth );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) { // phpcs:ignore
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { // phpcs:ignore
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( (string) $url, $component ); // phpcs:ignore
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { // phpcs:ignore
		return 'https://shop.example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) { // phpcs:ignore
		return is_dir( $target ) || mkdir( $target, 0777, true ); // phpcs:ignore
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) { // phpcs:ignore
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore
		}
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { // phpcs:ignore
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}
