<?php
/**
 * Decides whether an address a server handed back may be followed with the connection's credentials.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

/**
 * A `Location` header or a `next page` link comes from the server, and every request the connector sends carries the merchant's
 * credentials. An address on another host would send them there, so only an address with the same scheme, host and port as the address the
 * merchant typed is followed; a path alone is read as being on that origin.
 */
final class SameOrigin {

	/**
	 * The address to request, or null when it must not be followed.
	 *
	 * @param string $url     What the server sent: a full address or a path.
	 * @param string $address The address the merchant typed (scheme, host, optional port and folder).
	 * @return string|null A full address on the same origin, or null.
	 */
	public static function resolve( string $url, string $address ): ?string {
		$url    = trim( $url );
		$origin = self::parts( $address );
		if ( '' === $url || null === $origin || 1 === preg_match( '/[\x00-\x1f\s\\\\]/', $url ) ) {
			return null;
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = $origin['scheme'] . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return $origin['scheme'] . '://' . $origin['host'] . ( $origin['explicit'] ? ':' . $origin['port'] : '' ) . $url;
		}
		$target = self::parts( $url );
		if ( null === $target || $target['scheme'] !== $origin['scheme'] || $target['host'] !== $origin['host'] || $target['port'] !== $origin['port'] || $target['user'] ) {
			return null;
		}
		return $url;
	}

	/**
	 * Scheme, host and effective port of an address.
	 *
	 * @param string $address Address.
	 * @return array{scheme:string,host:string,port:int,explicit:bool,user:bool}|null
	 */
	private static function parts( string $address ): ?array {
		$parts = wp_parse_url( $address );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return null;
		}
		return array(
			'scheme'   => $scheme,
			'host'     => strtolower( (string) $parts['host'] ),
			'port'     => isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 ),
			'explicit' => isset( $parts['port'] ),
			'user'     => isset( $parts['user'] ) || isset( $parts['pass'] ),
		);
	}
}
