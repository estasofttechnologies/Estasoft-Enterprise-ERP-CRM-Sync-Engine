<?php
/**
 * An order with only the two meta methods the eraser may call.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Any other method call fails the test, which proves the eraser touches nothing else on an order.
 */
final class CoreFakeOrder {

	/**
	 * Meta by key.
	 *
	 * @var array<string,mixed>
	 */
	public array $meta = array();

	/**
	 * How many times save_meta_data() ran.
	 */
	public int $saves = 0;

	/**
	 * Remove a meta key.
	 *
	 * @param string $key Key.
	 */
	public function delete_meta_data( string $key ): void { // phpcs:ignore
		unset( $this->meta[ $key ] );
	}

	/**
	 * Save meta.
	 */
	public function save_meta_data(): void { // phpcs:ignore
		++$this->saves;
	}
}
