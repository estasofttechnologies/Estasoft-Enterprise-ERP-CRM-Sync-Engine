<?php
/**
 * Transients in memory.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\Transients;

/**
 * Honours lifetimes against a fake clock.
 */
final class JobsArrayTransients implements Transients {

	/**
	 * Values and expiry times.
	 *
	 * @var array<string,array{0:mixed,1:int}>
	 */
	public array $values = array();

	/**
	 * Set up with the clock.
	 *
	 * @param CoreFakeClock $clock Clock.
	 */
	public function __construct( private readonly CoreFakeClock $clock ) {
	}

	/**
	 * Read.
	 *
	 * @param string $key Name.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		if ( ! isset( $this->values[ $key ] ) || $this->values[ $key ][1] < $this->clock->now()->getTimestamp() ) {
			return false;
		}
		return $this->values[ $key ][0];
	}

	/**
	 * Write.
	 *
	 * @param string $key     Name.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Lifetime.
	 */
	public function set( string $key, mixed $value, int $seconds ): void {
		$this->values[ $key ] = array( $value, $this->clock->now()->getTimestamp() + $seconds );
	}

	/**
	 * Remove.
	 *
	 * @param string $key Name.
	 */
	public function delete( string $key ): void {
		unset( $this->values[ $key ] );
	}
}
