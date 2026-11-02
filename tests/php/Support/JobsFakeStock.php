<?php
/**
 * WooCommerce stock in memory.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\ShopStock;

final class JobsFakeStock implements ShopStock {

	/**
	 * Products by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $products = array();

	/**
	 * Units held by unpaid orders, by product id.
	 *
	 * @var array<int,int>
	 */
	public array $held = array();

	/**
	 * Units sold and not yet synced, by product id.
	 *
	 * @var array<int,int>
	 */
	public array $unsynced = array();

	/**
	 * Whether the count of unsynced units covers every order (false: a safety cap was hit).
	 */
	public bool $unsyncedComplete = true;

	/**
	 * Writes: array( id, quantity, system quantity ).
	 *
	 * @var array<int,array{0:int,1:int,2:int}>
	 */
	public array $writes = array();

	/**
	 * Pages asked for.
	 *
	 * @var array<int,int>
	 */
	public array $pagesRead = array();

	/**
	 * Called each time a page is read (a test moves the clock here).
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $onRead = null;

	/**
	 * Add a product.
	 *
	 * @param int                 $id    Id.
	 * @param string              $sku   SKU.
	 * @param int|null            $qty   Quantity.
	 * @param array<string,mixed> $props Values over the defaults.
	 */
	public function add( int $id, string $sku, ?int $qty, array $props = array() ): void {
		$this->products[ $id ] = array_merge(
			array(
				'id'            => $id,
				'sku'           => $sku,
				'name'          => 'Product ' . $sku,
				'manages_stock' => true,
				'quantity'      => $qty,
				'excluded'      => false,
				'buffer'        => null,
			),
			$props
		);
	}

	public function products( int $page, int $perPage ): array {
		$this->pagesRead[] = $page;
		if ( null !== $this->onRead ) {
			( $this->onRead )( $page );
		}
		$all               = array_values( $this->products );
		return array(
			'items'    => array_slice( $all, ( $page - 1 ) * $perPage, $perPage ),
			'has_more' => count( $all ) > $page * $perPage,
		);
	}

	public function setQuantity( int $productId, int $quantity, int $systemQuantity, string $atUtc ): bool {
		if ( ! isset( $this->products[ $productId ] ) ) {
			return false;
		}
		$this->products[ $productId ]['quantity'] = $quantity;
		$this->writes[]                           = array( $productId, $quantity, $systemQuantity );
		return true;
	}

	public function heldUnits( int $productId ): int {
		return $this->held[ $productId ] ?? 0;
	}

	public function unsyncedSoldUnits(): array {
		return $this->unsynced;
	}

	public function countUnsyncedSoldUnits(): array {
		return array(
			'units'    => $this->unsynced,
			'complete' => $this->unsyncedComplete,
		);
	}

	public function product( int $productId ): ?array {
		if ( ! isset( $this->products[ $productId ] ) ) {
			return null;
		}
		$product = $this->products[ $productId ];
		return array(
			'id'       => $productId,
			'sku'      => $product['sku'],
			'name'     => $product['name'],
			'quantity' => $product['quantity'],
			'buffer'   => $product['buffer'] ?? null,
		);
	}
}
