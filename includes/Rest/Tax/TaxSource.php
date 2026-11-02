<?php
/**
 * Where the tax mapping screen gets the store's tax rates.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * A port over WooCommerce, so the assembler is tested without it.
 */
interface TaxSource {

	/**
	 * The tax rates set up in WooCommerce that name a country.
	 *
	 * @return array<int,array{country:string,tax_class:string,tax_name:string,rate_percent:int|float}>
	 */
	public function shopRates(): array;

	/**
	 * How many orders of the last 30 days used a tax rate, by country and rate.
	 *
	 * @return array<string,int> Key is the upper-case country code, a pipe, and the rate as MinorUnits::rateKey() writes it.
	 */
	public function ordersLast30Days(): array;
}
