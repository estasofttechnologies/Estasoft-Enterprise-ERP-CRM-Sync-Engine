<?php
/**
 * Cleans the address of a merchant's own ERP site.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The merchant types the address of their own site. This keeps scheme and host (and the path when the system lives in a folder, such as
 * an Acumatica instance or the Sage 300 Web API application), removes credentials, queries and fragments, and refuses anything that is
 * not a web address. Whether the host may be contacted (https only, no private networks) is decided by the shared HostPolicy when the
 * request is built; this class only makes the address well formed.
 */
final class SiteAddress {

	/**
	 * Normalise an address.
	 *
	 * @param string            $raw      What the merchant typed.
	 * @param bool              $keepPath Keep the path (an instance folder) instead of dropping it.
	 * @param array<int,string> $cutAt    Path segments (lower case) at which the path stops, for example entity: the folder before it is kept.
	 * @return string|null Scheme, host, optional port and optional path without a trailing slash; null when the text is not a web address.
	 */
	public static function normalise( string $raw, bool $keepPath = false, array $cutAt = array() ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 !== preg_match( '/^[a-z0-9._\[\]:-]+$/', $host ) ) {
			return null;
		}
		$address = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		if ( $keepPath && isset( $parts['path'] ) ) {
			$segments = array();
			foreach ( explode( '/', trim( (string) $parts['path'], '/' ) ) as $segment ) {
				if ( in_array( strtolower( $segment ), $cutAt, true ) ) {
					break;
				}
				$segments[] = $segment;
			}
			$path = '/' . implode( '/', $segments );
			if ( '/' !== $path && 1 === preg_match( '#^/[A-Za-z0-9._~/-]+$#', $path ) ) {
				$address .= $path;
			}
		}
		return $address;
	}

	/**
	 * The normalised address, or an ApiException that tells the person what is wrong.
	 *
	 * @param string            $raw      What the merchant typed.
	 * @param string            $system   System name for the message.
	 * @param bool              $keepPath Keep the path.
	 * @param array<int,string> $cutAt    Path segments at which the path stops.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the text is not a web address.
	 */
	public static function checked( string $raw, string $system, bool $keepPath = false, array $cutAt = array() ): string {
		$address = self::normalise( $raw, $keepPath, $cutAt );
		if ( null === $address ) {
			$exception = new ApiException(
				Problems::refused(
					$system,
					'address',
					__( 'The address must be a web address such as https://example.com, without a user name or password.', 'estasoft-erp-crm-sync-engine' ),
					'bad address'
				)
			);
			throw $exception;
		}
		return $address;
	}
}
