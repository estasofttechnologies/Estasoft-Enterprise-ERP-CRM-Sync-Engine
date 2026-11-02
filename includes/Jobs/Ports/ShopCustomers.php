<?php
/**
 * Customers as the readiness checks see them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for billing states typed as free text where WooCommerce has a list of states for the country.
 */
interface ShopCustomers {

	/**
	 * Customers whose billing state is not one of the country's listed states.
	 *
	 * @param int $limit Most rows to list.
	 * @return array{total:int,items:array<int,array{id:int,name:string,state:string,country:string}>}
	 */
	public function withFreeTextState( int $limit ): array;
}
