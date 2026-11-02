<?php
/**
 * Products for the readiness tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\ShopCatalog;
use Estasoft\SyncEngine\Jobs\Ports\ShopCustomers;

final class JobsFakeCatalog implements ShopCatalog, ShopCustomers {

	public int $count = 1204;

	/**
	 * Products without a SKU.
	 *
	 * @var array<int,array{id:int,name:string}>
	 */
	public array $missing = array();

	/**
	 * Duplicate SKUs.
	 *
	 * @var array<int,array{sku:string,ids:array<int,int>}>
	 */
	public array $duplicates = array();

	/**
	 * Customers with a free-text state.
	 *
	 * @var array<int,array{id:int,name:string,state:string,country:string}>
	 */
	public array $freeText = array();

	public function productCount(): int {
		return $this->count;
	}

	public function withoutSku( int $limit ): array {
		return array(
			'total' => count( $this->missing ),
			'items' => array_slice( $this->missing, 0, $limit ),
		);
	}

	public function duplicateSkus( int $limit ): array {
		return array(
			'total' => count( $this->duplicates ),
			'items' => array_slice( $this->duplicates, 0, $limit ),
		);
	}

	public function byId( array $ids ): array {
		return array();
	}

	public function withFreeTextState( int $limit ): array {
		return array(
			'total' => count( $this->freeText ),
			'items' => array_slice( $this->freeText, 0, $limit ),
		);
	}
}
