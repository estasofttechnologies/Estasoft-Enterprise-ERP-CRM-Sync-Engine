<?php
/**
 * WooCommerce tax rates through the WooCommerce tax API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Tax;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Rest\Support\KeyValueStore;

/**
 * Reads rates with `WC_Tax` and counts the orders of the last 30 days through `wc_get_orders()`; no table is queried directly.
 * The count is kept for ten minutes because it reads up to 500 orders.
 */
final class WooTaxSource implements TaxSource {

	/**
	 * Most orders looked at for the 30-day count.
	 */
	private const ORDER_LIMIT = 500;

	/**
	 * Seconds the order count is kept.
	 */
	private const CACHE_SECONDS = 600;

	/**
	 * Create the source.
	 *
	 * @param KeyValueStore $cache Short-lived storage.
	 */
	public function __construct( private readonly KeyValueStore $cache ) {
	}

	/**
	 * The tax rates that name a country, in every tax class.
	 *
	 * @return array<int,array{country:string,tax_class:string,tax_name:string,rate_percent:int|float}>
	 */
	public function shopRates(): array {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return array();
		}
		$classes = array_merge( array( '' ), \WC_Tax::get_tax_class_slugs() );
		$rates   = array();
		foreach ( $classes as $class ) {
			foreach ( \WC_Tax::get_rates_for_tax_class( (string) $class ) as $rate ) {
				$country = strtoupper( (string) ( $rate->tax_rate_country ?? '' ) );
				$number  = MinorUnits::rateKey( $rate->tax_rate ?? null );
				if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) || null === $number ) {
					continue;
				}
				$rates[] = array(
					'country'      => $country,
					'tax_class'    => (string) $class,
					'tax_name'     => (string) ( $rate->tax_rate_name ?? '' ),
					'rate_percent' => floor( (float) $number ) === (float) $number ? (int) $number : (float) $number,
				);
			}
		}
		return $rates;
	}

	/**
	 * Orders of the last 30 days by country and rate of their tax lines.
	 *
	 * @return array<string,int>
	 */
	public function ordersLast30Days(): array {
		$cached = $this->cache->get( 'tax_usage_30d' );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}
		$counts = array();
		if ( function_exists( 'wc_get_orders' ) && class_exists( 'WC_Tax' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'        => self::ORDER_LIMIT,
					'status'       => 'any',
					'type'         => 'shop_order',
					'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
					'return'       => 'objects',
				)
			);
			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				$seen = array();
				foreach ( $order->get_items( 'tax' ) as $line ) {
					$rate    = \WC_Tax::_get_tax_rate( (int) $line->get_rate_id() );
					$country = strtoupper( (string) ( $rate['tax_rate_country'] ?? '' ) );
					$number  = MinorUnits::rateKey( $rate['tax_rate'] ?? null );
					if ( '' === $country || null === $number ) {
						continue;
					}
					$seen[ $country . '|' . $number ] = true;
				}
				foreach ( array_keys( $seen ) as $key ) {
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				}
			}
		}
		$this->cache->set( 'tax_usage_30d', $counts, self::CACHE_SECONDS );
		return $counts;
	}
}
