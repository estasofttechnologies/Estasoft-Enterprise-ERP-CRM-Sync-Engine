<?php
/**
 * A stand-in for any service object: records calls, returns what the test says.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * The REST, Admin and CLI classes fetch services from the container and call methods on them, so one recording object can stand in
 * for a Jobs or Core service. `$returns` maps a method name to a value, or to a closure that receives the arguments;
 * `$throws` maps a method name to a Throwable.
 */
final class RestFake {

	/**
	 * Calls in order: method name and arguments.
	 *
	 * @var array<int,array{0:string,1:array<int,mixed>}>
	 */
	public array $calls = array();

	/**
	 * @param array<string,mixed>      $returns Value or closure by method name.
	 * @param array<string,\Throwable> $throws  Throwable by method name.
	 */
	public function __construct(
		public array $returns = array(),
		public array $throws = array()
	) {
	}

	/**
	 * Record a call and answer.
	 *
	 * @param string           $name      Method.
	 * @param array<int,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function __call( string $name, array $arguments ): mixed {
		$this->calls[] = array( $name, $arguments );
		if ( isset( $this->throws[ $name ] ) ) {
			throw $this->throws[ $name ];
		}
		$answer = $this->returns[ $name ] ?? null;
		return $answer instanceof \Closure ? $answer( ...$arguments ) : $answer;
	}

	/**
	 * The argument lists of every call to a method.
	 *
	 * @param string $name Method.
	 * @return array<int,array<int,mixed>>
	 */
	public function called( string $name ): array {
		$lists = array();
		foreach ( $this->calls as $call ) {
			if ( $call[0] === $name ) {
				$lists[] = $call[1];
			}
		}
		return $lists;
	}
}
