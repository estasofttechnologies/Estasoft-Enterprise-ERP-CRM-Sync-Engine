<?php
/**
 * Customers for the readiness checks.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\ShopCustomers;

/**
 * Compares each customer's billing state with the list WooCommerce keeps for the billing country. The three lookups can be
 * replaced (tests do) so the comparison runs without WordPress.
 */
final class WooShopCustomers implements ShopCustomers {

	/**
	 * Most customers looked at.
	 */
	private const MAX_USERS = 2000;

	/**
	 * Customers: function( int $limit ): array of array{id:int,name:string,state:string,country:string}.
	 *
	 * @var \Closure
	 */
	private \Closure $customers;

	/**
	 * States of a country: function( string $country ): array of code => name (empty when the country has no list).
	 *
	 * @var \Closure
	 */
	private \Closure $states;

	/**
	 * Set up with what it depends on.
	 *
	 * @param \Closure|null $customers Reads customers; uses get_users() and user meta by default.
	 * @param \Closure|null $states    Reads the states of a country; uses WooCommerce by default.
	 */
	public function __construct( ?\Closure $customers = null, ?\Closure $states = null ) {
		$this->customers = $customers ?? static function ( int $limit ): array {
			$users = get_users(
				array(
					'role'   => 'customer',
					'number' => $limit,
					'fields' => array( 'ID', 'display_name' ),
				)
			);
			$rows  = array();
			foreach ( $users as $user ) {
				$rows[] = array(
					'id'      => (int) $user->ID,
					'name'    => (string) $user->display_name,
					'state'   => trim( (string) get_user_meta( (int) $user->ID, 'billing_state', true ) ),
					'country' => strtoupper( trim( (string) get_user_meta( (int) $user->ID, 'billing_country', true ) ) ),
				);
			}
			return $rows;
		};
		$this->states    = $states ?? static function ( string $country ): array {
			if ( ! function_exists( 'WC' ) || ! is_object( WC()->countries ) ) {
				return array();
			}
			$states = WC()->countries->get_states( $country );
			return is_array( $states ) ? $states : array();
		};
	}

	/**
	 * Customers whose billing state is not in the country's list.
	 *
	 * @param int $limit Most rows to list.
	 * @return array{total:int,items:array<int,array{id:int,name:string,state:string,country:string}>}
	 */
	public function withFreeTextState( int $limit ): array {
		$found = array();
		$cache = array();
		foreach ( ( $this->customers )( self::MAX_USERS ) as $customer ) {
			if ( '' === $customer['state'] || '' === $customer['country'] ) {
				continue;
			}
			$country = $customer['country'];
			if ( ! array_key_exists( $country, $cache ) ) {
				$cache[ $country ] = array_change_key_case( ( $this->states )( $country ), CASE_UPPER );
			}
			if ( array() === $cache[ $country ] ) {
				continue;
			}
			if ( ! isset( $cache[ $country ][ strtoupper( $customer['state'] ) ] ) ) {
				$found[] = $customer;
			}
		}
		return array(
			'total' => count( $found ),
			'items' => array_slice( $found, 0, max( 0, $limit ) ),
		);
	}
}
