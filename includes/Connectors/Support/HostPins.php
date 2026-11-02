<?php
/**
 * Addresses that passed the host check, handed to cURL so the connection goes to one of them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * HostPolicy resolves a host name the merchant typed and checks every address. Left alone, the HTTP transport would resolve the name a second
 * time and an attacker's DNS could answer differently (DNS rebinding). The addresses that passed are kept here for thirty seconds, and the
 * `http_api_curl` action sets CURLOPT_RESOLVE for a request to that host and port, so cURL connects to a checked address. Only hosts that
 * HostPolicy checked are pinned; every other request is left alone. With a transport other than cURL the pin has no effect.
 */
final class HostPins {

	/**
	 * Seconds a pin lives.
	 */
	public const TTL = 30;

	/**
	 * Pins by `host:port`: when they expire and the addresses.
	 *
	 * @var array<string,array{until:int,ips:array<int,string>}>
	 */
	private static array $pins = array();

	/**
	 * Remember the checked addresses of a host.
	 *
	 * @param string            $host      Lower-case host name.
	 * @param int               $port      Port of the address.
	 * @param array<int,string> $addresses Addresses that all passed the private-range check.
	 */
	public static function remember( string $host, int $port, array $addresses ): void {
		if ( array() === $addresses ) {
			return;
		}
		self::$pins[ $host . ':' . $port ] = array(
			'until' => time() + self::TTL,
			'ips'   => array_values( $addresses ),
		);
	}

	/**
	 * The CURLOPT_RESOLVE entries for a URL, empty when its host is not pinned.
	 *
	 * @param string $url Request URL.
	 * @return array<int,string>
	 */
	public static function entriesFor( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array();
		}
		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) ? 80 : 443 );
		$pin  = self::$pins[ $host . ':' . $port ] ?? null;
		if ( null === $pin || $pin['until'] < time() ) {
			return array();
		}
		$ips = array_map( static fn( string $ip ): string => str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip, $pin['ips'] );
		return array( $host . ':' . $port . ':' . implode( ',', $ips ) );
	}

	/**
	 * The `http_api_curl` action: pin the connection of a request to a checked host.
	 *
	 * @param mixed $handle The cURL handle (passed by reference by WordPress).
	 * @param mixed $args   Request arguments.
	 * @param mixed $url    Request URL.
	 */
	public static function apply( mixed $handle, mixed $args = null, mixed $url = '' ): void {
		unset( $args );
		if ( ! $handle instanceof \CurlHandle || ! is_string( $url ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		$entries = self::entriesFor( $url );
		if ( array() !== $entries ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, $entries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- sets an option on the handle WordPress is about to use; it makes no request.
		}
	}

	/**
	 * Forget every pin.
	 */
	public static function reset(): void {
		self::$pins = array();
	}
}
