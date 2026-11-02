<?php
/**
 * Options in memory.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Core\Support\OptionStore;

/**
 * An OptionStore for tests that do not need WordPress.
 */
final class CoreArrayOptionStore implements OptionStore {

	/**
	 * Values by name.
	 *
	 * @var array<string,mixed>
	 */
	public array $values = array();

	/**
	 * Names written with autoload on.
	 *
	 * @var array<string,bool>
	 */
	public array $autoloaded = array();

	/**
	 * Read an option.
	 *
	 * @param string $name    Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( string $name, mixed $default = null ): mixed {
		return array_key_exists( $name, $this->values ) ? $this->values[ $name ] : $default;
	}

	/**
	 * Write an option.
	 *
	 * @param string $name     Name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Autoload flag.
	 */
	public function set( string $name, mixed $value, bool $autoload = false ): void {
		$this->values[ $name ]     = $value;
		$this->autoloaded[ $name ] = $autoload;
	}

	/**
	 * Remove an option.
	 *
	 * @param string $name Name.
	 */
	public function delete( string $name ): void {
		unset( $this->values[ $name ], $this->autoloaded[ $name ] );
	}
}
