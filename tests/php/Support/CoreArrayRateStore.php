<?php
/**
 * Rate counters in memory.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Core\Queue\RateStore;

/**
 * A RateStore for tests. Can be told to fail the next compare-and-set calls, to simulate collisions.
 */
final class CoreArrayRateStore implements RateStore {

	/**
	 * Values by key.
	 *
	 * @var array<string,float>
	 */
	public array $values = array();

	/**
	 * How many compare-and-set calls to refuse before accepting again; -1 refuses all.
	 */
	public int $failures = 0;

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?float {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Replace a value if it is still what the caller read.
	 *
	 * @param string     $key      Key.
	 * @param float|null $expected Value read.
	 * @param float      $new      New value.
	 */
	public function compareAndSet( string $key, ?float $expected, float $new ): bool {
		if ( $this->failures !== 0 ) {
			if ( $this->failures > 0 ) {
				--$this->failures;
			}
			return false;
		}
		$current = $this->values[ $key ] ?? null;
		if ( ( null === $current ) !== ( null === $expected ) || ( null !== $current && abs( $current - (float) $expected ) > 0.0000005 ) ) {
			return false;
		}
		$this->values[ $key ] = $new;
		return true;
	}
}
