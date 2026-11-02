<?php
/**
 * Registry of connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the connectors added through the estasoft_sync_register_connectors filter (CMP-1, CMP-2).
 */
final class ConnectorRegistry {

	/**
	 * Active connectors by id.
	 *
	 * @var array<string,ConnectorInterface>
	 */
	private array $connectors = array();

	/**
	 * Connectors that were listed but not activated, with the reason.
	 *
	 * @var array<string,string>
	 */
	private array $rejected = array();

	/**
	 * Connectors that are created the first time they are asked for, by id.
	 *
	 * @var array<string,\Closure>
	 */
	private array $lazy = array();

	/**
	 * Build from the list the filter returns. An entry is a connector object, or a Closure under the connector's id (string key) that creates
	 * the connector the first time it is needed, so a site with 36 connectors builds only the ones it uses. A connector with a different
	 * contract major version is listed in rejected() and never activated.
	 *
	 * @param array<int|string,mixed> $connectors Objects and Closures from the filter.
	 */
	public function __construct( array $connectors = array() ) {
		foreach ( $connectors as $key => $connector ) {
			if ( $connector instanceof \Closure && is_string( $key ) && '' !== $key ) {
				$this->lazy[ $key ] = $connector;
				continue;
			}
			$this->add( $connector );
		}
	}

	/**
	 * Add one connector object.
	 *
	 * @param mixed $connector Candidate.
	 */
	private function add( mixed $connector ): void {
		if ( ! $connector instanceof ConnectorInterface ) {
			return;
		}
		if ( ! self::sameMajor( $connector->contractVersion(), ConnectorInterface::CONTRACT_VERSION ) ) {
			$this->rejected[ $connector->id() ] = 'contract_version';
			return;
		}
		$this->connectors[ $connector->id() ] = $connector;
		unset( $this->lazy[ $connector->id() ] );
	}

	/**
	 * Create a connector that was registered lazily.
	 *
	 * @param string $id Connector id.
	 */
	private function build( string $id ): void {
		if ( ! isset( $this->lazy[ $id ] ) ) {
			return;
		}
		$factory = $this->lazy[ $id ];
		unset( $this->lazy[ $id ] );
		try {
			$connector = $factory();
		} catch ( \Throwable $error ) {
			unset( $error );
			$this->rejected[ $id ] = 'error';
			return;
		}
		$this->add( $connector );
	}

	/**
	 * Every connector id, without creating the lazy ones.
	 *
	 * @return array<int,string>
	 */
	public function ids(): array {
		return array_values( array_unique( array_merge( array_keys( $this->connectors ), array_keys( $this->lazy ) ) ) );
	}

	/**
	 * Whether a connector is registered (active or not yet created).
	 *
	 * @param string $id Connector id.
	 */
	public function has( string $id ): bool {
		return isset( $this->connectors[ $id ] ) || isset( $this->lazy[ $id ] );
	}

	/**
	 * Whether two semantic versions share the major number.
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 */
	public static function sameMajor( string $a, string $b ): bool {
		$major_a = (int) explode( '.', $a )[0];
		$major_b = (int) explode( '.', $b )[0];
		return $major_a === $major_b;
	}

	/**
	 * A connector by id, or null.
	 *
	 * @param string $id Connector id.
	 */
	public function get( string $id ): ?ConnectorInterface {
		$this->build( $id );
		return $this->connectors[ $id ] ?? null;
	}

	/**
	 * All active connectors.
	 *
	 * @return array<string,ConnectorInterface>
	 */
	public function all(): array {
		foreach ( array_keys( $this->lazy ) as $id ) {
			$this->build( (string) $id );
		}
		return $this->connectors;
	}

	/**
	 * Connectors that were refused, with the reason. A connector registered lazily is judged when it is first built, so it shows here after
	 * get() or all() asked for it.
	 *
	 * @return array<string,string>
	 */
	public function rejected(): array {
		return $this->rejected;
	}
}
