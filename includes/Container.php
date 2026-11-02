<?php
/**
 * Small service container with lazy factories.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Services are created on first use and shared afterwards. Keys are class names or short ids.
 */
final class Container {

	/**
	 * Factories by key.
	 *
	 * @var array<string,callable>
	 */
	private array $factories = array();

	/**
	 * Created instances by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $instances = array();

	/**
	 * Register a factory.
	 *
	 * @param string   $id      Class name or id.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register a ready instance (used in tests).
	 *
	 * @param string $id       Class name or id.
	 * @param mixed  $instance Service.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Class name or id.
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Get a service.
	 *
	 * @param string $id Class name or id.
	 * @return mixed
	 *
	 * @throws \LogicException When the service is not registered.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \LogicException( sprintf( 'Service %s is not registered.', esc_html( $id ) ) );
		}
		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->instances[ $id ];
	}
}
