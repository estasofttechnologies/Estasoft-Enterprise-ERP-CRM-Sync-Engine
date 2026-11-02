<?php
/**
 * Where an Acumatica site is and which endpoint of it is used.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The address the merchant typed (with its instance folder, if any), the tenant that is the part of the client id after the @, and the
 * Default endpoint version. Versions 22.200.001 to 25.200.001 use the syntax this connector writes; 26.200.001 changes it and is
 * refused (docs/connectors/acumatica.md).
 */
final class AcumaticaSite {

	/**
	 * The endpoint version used when the connection names none.
	 */
	public const DEFAULT_VERSION = '24.200.001';

	/**
	 * Path segments at which a pasted address is cut: the application paths of Acumatica.
	 *
	 * @var array<int,string>
	 */
	private const CUT = array( 'entity', 'identity', 'main', 'frames', 'pages', 'api', 't' );

	/**
	 * The site address without the endpoint, sign-in or screen paths.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $system     System name for messages.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	public static function address( Connection $connection, string $system ): string {
		return SiteAddress::checked( (string) $connection->config( 'address', '' ), $system, true, self::CUT );
	}

	/**
	 * The tenant: the text after the @ of the client id.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function tenant( Connection $connection ): string {
		$clientId = (string) $connection->config( 'client_id', '' );
		$at       = strrpos( $clientId, '@' );
		return false === $at ? '' : trim( substr( $clientId, $at + 1 ) );
	}

	/**
	 * The endpoint version: the connection's, else the default.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function version( Connection $connection ): string {
		$typed = trim( (string) $connection->config( 'endpoint_version', '' ) );
		$set   = $connection->config( 'options' );
		if ( '' === $typed && is_array( $set ) ) {
			$typed = trim( (string) ( $set['endpoint_version'] ?? '' ) );
		}
		return 1 === preg_match( '/^\d{2}\.\d{3}\.\d{3}$/', $typed ) ? $typed : self::DEFAULT_VERSION;
	}

	/**
	 * Whether this connector writes the syntax of an endpoint version.
	 *
	 * @param string $version Version such as 24.200.001.
	 */
	public static function supports( string $version ): bool {
		if ( 1 !== preg_match( '/^(\d{2})\.\d{3}\.\d{3}$/', $version, $match ) ) {
			return false;
		}
		$major = (int) $match[1];
		return $major >= 22 && $major <= 25;
	}

	/**
	 * The path of the Default endpoint.
	 *
	 * @param string $version Endpoint version.
	 */
	public static function path( string $version ): string {
		return '/entity/Default/' . $version;
	}

	/**
	 * Scheme and host (with port) of an address, which is where a `Location` path of an answer starts from.
	 *
	 * @param string $address Normalised address.
	 */
	public static function origin( string $address ): string {
		$parts = wp_parse_url( $address );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $address;
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
