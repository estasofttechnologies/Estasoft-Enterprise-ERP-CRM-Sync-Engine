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
	 * Build from a list of connector objects. A connector with a different contract major version
	 * is listed in rejected() and never activated.
	 *
	 * @param array<int,mixed> $connectors Objects from the filter.
	 */
	public function __construct( array $connectors = array() ) {
		foreach ( $connectors as $connector ) {
			if ( ! $connector instanceof ConnectorInterface ) {
				continue;
			}
			if ( ! self::sameMajor( $connector->contractVersion(), ConnectorInterface::CONTRACT_VERSION ) ) {
				$this->rejected[ $connector->id() ] = 'contract_version';
				continue;
			}
			$this->connectors[ $connector->id() ] = $connector;
		}
	}

	/**
	 * Whether two semantic versions share the major number.
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 */
	public static function sameMajor( string $a, string $b ): bool {
		return (int) explode( '.', $a )[0] === (int) explode( '.', $b )[0];
	}

	/**
	 * A connector by id, or null.
	 *
	 * @param string $id Connector id.
	 */
	public function get( string $id ): ?ConnectorInterface {
		return $this->connectors[ $id ] ?? null;
	}

	/**
	 * All active connectors.
	 *
	 * @return array<string,ConnectorInterface>
	 */
	public function all(): array {
		return $this->connectors;
	}

	/**
	 * Connectors that were refused, with the reason.
	 *
	 * @return array<string,string>
	 */
	public function rejected(): array {
		return $this->rejected;
	}
}
