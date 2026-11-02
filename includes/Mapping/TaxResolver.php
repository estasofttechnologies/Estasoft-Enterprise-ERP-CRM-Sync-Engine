<?php
/**
 * Finds the connected system's tax code for a WooCommerce tax rate.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by TaxMap. The tax_lookup transform depends on this interface only.
 */
interface TaxResolver {

	/**
	 * The system's tax code for a country, tax class and rate, or null when the rate is not mapped.
	 *
	 * @param int    $connectionId Connection the mapping belongs to.
	 * @param string $country      ISO 3166-1 alpha-2 code.
	 * @param string $taxClass     WooCommerce tax class slug, empty for the standard class.
	 * @param string $rateKey      Rate in percent as written by MinorUnits::rateKey(), for example "5" or "7.5".
	 */
	public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string;
}
