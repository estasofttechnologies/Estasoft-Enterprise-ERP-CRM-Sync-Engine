<?php
/**
 * The Exact Online hosts, one per country.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

/**
 * An app made in one country cannot connect companies of another, and sign-in and API share the host of that country.
 */
final class ExactRegions {

	/**
	 * Region key to host.
	 *
	 * @var array<string,string>
	 */
	public const HOSTS = array(
		'nl'    => 'start.exactonline.nl',
		'be'    => 'start.exactonline.be',
		'de'    => 'start.exactonline.de',
		'fr'    => 'start.exactonline.fr',
		'co.uk' => 'start.exactonline.co.uk',
		'es'    => 'start.exactonline.es',
		'com'   => 'start.exactonline.com',
	);

	/**
	 * The host of a region, or null for a region that does not exist.
	 *
	 * @param string $region Region key.
	 */
	public static function host( string $region ): ?string {
		return self::HOSTS[ strtolower( trim( $region ) ) ] ?? null;
	}

	/**
	 * The hosts the connector may contact.
	 *
	 * @return array<int,string>
	 */
	public static function allHosts(): array {
		return array_values( self::HOSTS );
	}

	/**
	 * The choices of the Region box.
	 *
	 * @return array<string,string>
	 */
	public static function choices(): array {
		return array(
			'nl'    => __( 'Netherlands (.nl)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'be'    => __( 'Belgium (.be)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'de'    => __( 'Germany (.de)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'fr'    => __( 'France (.fr)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'co.uk' => __( 'United Kingdom (.co.uk)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'es'    => __( 'Spain (.es)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'com'   => __( 'United States (.com)', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
	}
}
