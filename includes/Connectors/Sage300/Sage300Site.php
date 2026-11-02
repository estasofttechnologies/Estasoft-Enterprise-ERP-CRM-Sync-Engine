<?php
/**
 * Where the Sage 300 Web API of a merchant is and which company it works in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The Web API lives under an application path of the merchant's IIS server (usually `/Sage300WebApi`). Every URL is that address, the
 * version `v1.0`, a dash, the company ID and then the module and resource.
 */
final class Sage300Site {

	/**
	 * Path segments at which a pasted address is cut: the version and the company part.
	 *
	 * @var array<int,string>
	 */
	private const CUT = array( 'v1.0', 'v1', '-' );

	/**
	 * The address of the application, then `/v1.0/-/{company}`.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $system     System name for messages.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	public static function base( Connection $connection, string $system ): string {
		return SiteAddress::checked( (string) $connection->config( 'address', '' ), $system, true, self::CUT ) . '/v1.0/-/' . rawurlencode( self::company( $connection ) );
	}

	/**
	 * The company ID (the org ID of the URL).
	 *
	 * @param Connection $connection Connection.
	 */
	public static function company( Connection $connection ): string {
		return trim( (string) $connection->config( 'company', '' ) );
	}
}
