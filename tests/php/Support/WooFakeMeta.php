<?php
/**
 * Meta storage shared by the WooCommerce fakes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * The same four calls WooCommerce objects offer: get_meta, update_meta_data, delete_meta_data, save_meta_data. save_meta_data() only
 * counts, so a test can prove that the whole object (save()) was never written.
 */
trait WooFakeMeta {

	/**
	 * Meta in memory.
	 *
	 * @var array<string,mixed>
	 */
	public array $meta = array();

	/**
	 * Times save_meta_data() ran.
	 */
	public int $metaSaves = 0;

	/**
	 * Times save() ran.
	 */
	public int $saves = 0;

	public function get_meta( $key = '', $single = true ) { // phpcs:ignore
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) { // phpcs:ignore
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) { // phpcs:ignore
		unset( $this->meta[ $key ] );
	}

	public function save_meta_data() { // phpcs:ignore
		++$this->metaSaves;
	}

	public function save() { // phpcs:ignore
		++$this->saves;
	}
}
