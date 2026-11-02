<?php
/**
 * Where the Sage 200 API is for an edition, and which site and company a connection works in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Standard and Professional (Extra) have their own base address under `api.columbus.sage.com`; the UK is the only country the guides name.
 */
final class Sage200Site {

	/**
	 * The host of the data API.
	 */
	public const HOST = 'api.columbus.sage.com';

	/**
	 * The base address for the edition of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function base( Connection $connection ): string {
		return 'https://' . self::HOST . '/uk/' . ( 'professional' === self::edition( $connection ) ? 'sage200extra' : 'sage200' ) . '/accounts/v1';
	}

	/**
	 * The edition: standard or professional.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function edition( Connection $connection ): string {
		$typed = strtolower( trim( (string) $connection->config( 'edition', '' ) ) );
		$set   = $connection->config( 'options' );
		if ( '' === $typed && is_array( $set ) ) {
			$typed = strtolower( trim( (string) ( $set['edition'] ?? '' ) ) );
		}
		return 'professional' === $typed ? 'professional' : 'standard';
	}

	/**
	 * The site id chosen after sign-in.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function siteId( Connection $connection ): string {
		return trim( (string) $connection->config( 'site_id', '' ) );
	}

	/**
	 * The company id chosen after sign-in.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function companyId( Connection $connection ): string {
		return trim( (string) $connection->config( 'company_id', '' ) );
	}
}
