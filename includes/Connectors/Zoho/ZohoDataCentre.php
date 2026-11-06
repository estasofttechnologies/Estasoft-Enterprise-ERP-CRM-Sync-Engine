<?php
/**
 * The Zoho data centres and their hosts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Zoho;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Account host (sign-in and tokens) and API host per data centre. The list follows the token endpoints of the Zoho CRM documentation
 * (US, AU, EU, IN, CN, JP, SA, CA); Canada's account host is not derived from its API host.
 */
final class ZohoDataCentre {

	/**
	 * Data centre id => account host, API host.
	 */
	private const CENTRES = array(
		'com'    => array( 'accounts.zoho.com', 'www.zohoapis.com' ),
		'eu'     => array( 'accounts.zoho.eu', 'www.zohoapis.eu' ),
		'in'     => array( 'accounts.zoho.in', 'www.zohoapis.in' ),
		'com.au' => array( 'accounts.zoho.com.au', 'www.zohoapis.com.au' ),
		'jp'     => array( 'accounts.zoho.jp', 'www.zohoapis.jp' ),
		'sa'     => array( 'accounts.zoho.sa', 'www.zohoapis.sa' ),
		'ca'     => array( 'accounts.zohocloud.ca', 'www.zohoapis.ca' ),
		'com.cn' => array( 'accounts.zoho.com.cn', 'www.zohoapis.com.cn' ),
	);

	/**
	 * Create the object.
	 *
	 * @param string $id Data centre id such as com or eu.
	 */
	private function __construct( private readonly string $id ) {
	}

	/**
	 * The data centre chosen for a connection; the US one when none is chosen.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function forConnection( Connection $connection ): self {
		$id = strtolower( trim( (string) $connection->config( 'data_centre', 'com' ) ) );
		return new self( isset( self::CENTRES[ $id ] ) ? $id : 'com' );
	}

	/**
	 * Whether the id names a known data centre.
	 *
	 * @param string $id Data centre id.
	 */
	public static function known( string $id ): bool {
		return isset( self::CENTRES[ strtolower( trim( $id ) ) ] );
	}

	/**
	 * Select options: id => label.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		return array(
			'com'    => __( 'United States (zoho.com)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'eu'     => __( 'Europe (zoho.eu)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'in'     => __( 'India (zoho.in)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'com.au' => __( 'Australia (zoho.com.au)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'jp'     => __( 'Japan (zoho.jp)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'sa'     => __( 'Saudi Arabia (zoho.sa)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'ca'     => __( 'Canada (zohocloud.ca)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'com.cn' => __( 'China (zoho.com.cn)', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
	}

	/**
	 * Every host the connectors contact, for the host allow-list and the documentation.
	 *
	 * @return array<int,string>
	 */
	public static function allHosts(): array {
		$hosts = array();
		foreach ( self::CENTRES as $pair ) {
			$hosts[] = $pair[0];
		}
		foreach ( self::CENTRES as $pair ) {
			$hosts[] = $pair[1];
		}
		return $hosts;
	}

	/**
	 * Every account host.
	 *
	 * @return array<int,string>
	 */
	public static function accountHosts(): array {
		return array_map( static fn( array $pair ): string => $pair[0], array_values( self::CENTRES ) );
	}

	/**
	 * Data centre id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Host for sign-in and tokens.
	 */
	public function accountsHost(): string {
		return self::CENTRES[ $this->id ][0];
	}

	/**
	 * Host for API calls.
	 */
	public function apiHost(): string {
		return self::CENTRES[ $this->id ][1];
	}
}
