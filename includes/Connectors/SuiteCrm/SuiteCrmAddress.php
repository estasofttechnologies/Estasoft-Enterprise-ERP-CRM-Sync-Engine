<?php
/**
 * The address of a SuiteCRM server and where its API lives.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * SuiteCRM 8 serves the v8 API under `/legacy/Api`, SuiteCRM 7 under `/Api`. The merchant types the address of the site (a folder is
 * allowed); scheme, host, port and folder are kept, a query or fragment is dropped, and a pasted API path is removed. The scheme is
 * completed to https when it is missing; the host policy of the shared base decides whether the host may be contacted.
 */
final class SuiteCrmAddress {

	/**
	 * The API address of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function base( Connection $connection ): string {
		$raw = trim( (string) $connection->config( 'address', '' ) );
		if ( '' === $raw ) {
			self::refuse( __( 'The SuiteCRM address is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( ! str_contains( $raw, '://' ) ) {
			$raw = 'https://' . $raw;
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			self::refuse( __( 'The SuiteCRM address is not a web address.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$path = '/' . trim( (string) ( $parts['path'] ?? '' ), '/' );
		$path = (string) preg_replace( '#/(legacy/)?Api(/.*)?$#i', '', $path );
		$path = '/' === $path ? '' : rtrim( $path, '/' );
		$base = strtolower( (string) $parts['scheme'] ) . '://';
		if ( isset( $parts['user'] ) ) {
			$base .= rawurlencode( (string) $parts['user'] ) . ( isset( $parts['pass'] ) ? ':' . rawurlencode( (string) $parts['pass'] ) : '' ) . '@';
		}
		$base .= strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path;
		return $base . self::prefix( $connection );
	}

	/**
	 * The API folder for the version the merchant chose.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function prefix( Connection $connection ): string {
		return in_array( strtolower( trim( (string) $connection->config( 'version', 'v8' ) ) ), array( '7', 'v7' ), true ) ? '/Api' : '/legacy/Api';
	}

	/**
	 * Stop with a SYNC-VAL-002 failure for the address field.
	 *
	 * @param string $reason Reason.
	 *
	 * @throws ApiException Always.
	 */
	private static function refuse( string $reason ): never {
		$exception = new ApiException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => SuiteCrmConnector::LABEL,
					'field'  => 'address',
					'reason' => $reason,
				),
				null,
				null,
				null,
				'address'
			)
		);
		throw $exception;
	}
}
