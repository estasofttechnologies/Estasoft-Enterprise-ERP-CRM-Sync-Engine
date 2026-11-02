<?php
/**
 * WordPress functions the Woo classes call when they render, for unit tests. Each is guarded.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

use Estasoft\SyncEngine\Tests\Support\WooRegistry;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { // phpcs:ignore
		WooRegistry::$capsAsked[] = (string) $capability;
		return WooRegistry::$can;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) { // phpcs:ignore
		return esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { // phpcs:ignore
		return preg_match( '#^(https?://|/|\#|\?)#', (string) $url ) ? htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ) : '';
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $html, $allowed ) { // phpcs:ignore
		return $html;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { // phpcs:ignore
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) { // phpcs:ignore
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = (string) ( $args[1] ?? '' );
		} else {
			$params = array( $args[0] => $args[1] );
			$url    = (string) ( $args[2] ?? '' );
		}
		$fragment = '';
		if ( false !== strpos( $url, '#' ) ) {
			list( $url, $fragment ) = explode( '#', $url, 2 );
			$fragment               = '#' . $fragment;
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $params ) . $fragment;
	}
}
if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $keys, $url = '' ) { // phpcs:ignore
		$url = (string) $url;
		foreach ( (array) $keys as $key ) {
			$url = (string) preg_replace( '/([?&])' . preg_quote( (string) $key, '/' ) . '=[^&]*(&|$)/', '$1', $url );
		}
		return rtrim( $url, '?&' );
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1 ) { // phpcs:ignore
		return $url . '&_wpnonce=nonce-for-' . $action;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { // phpcs:ignore
		return 'nonce-for-' . $action;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) { // phpcs:ignore
		return 'nonce-for-' . $action === $nonce ? 1 : false;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class, $fallback = '' ) { // phpcs:ignore
		$clean = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $class );
		return '' === $clean ? $fallback : $clean;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) { // phpcs:ignore
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore
		return number_format( (float) $number, (int) $decimals );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $display = true ) { // phpcs:ignore
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore
		}
		return $result;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { // phpcs:ignore
		return WooRegistry::$options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) { // phpcs:ignore
		return gmdate( (string) $format, (int) $timestamp );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) { // phpcs:ignore
		return WooRegistry::$transients[ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) { // phpcs:ignore
		WooRegistry::$transients[ $name ]    = $value;
		WooRegistry::$transientTtls[ $name ] = (int) $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) { // phpcs:ignore
		unset( WooRegistry::$transients[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { // phpcs:ignore
		return new class() {
			public string $display_name = 'Layla Haddad';

			public function exists(): bool {
				return true;
			}
		};
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore
		return true;
	}
}
