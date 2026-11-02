<?php
/**
 * Rate limiter counters in memory, for the capacity test only.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Capacity;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Queue\RateStore;

/**
 * The real limiter keeps its counters in the object cache or an options row; the test must not write to those, so it uses its own.
 */
final class MemoryRateStore implements RateStore {

	/**
	 * Counters.
	 *
	 * @var array<string,float>
	 */
	private array $values = array();

	/**
	 * Read a value.
	 *
	 * @param string $key Counter name.
	 */
	public function get( string $key ): ?float {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Compare and set.
	 *
	 * @param string     $key      Counter name.
	 * @param float|null $expected Value the caller read.
	 * @param float      $new      New value.
	 */
	public function compareAndSet( string $key, ?float $expected, float $new ): bool {
		if ( ( $this->values[ $key ] ?? null ) !== $expected ) {
			return false;
		}
		$this->values[ $key ] = $new;
		return true;
	}
}
