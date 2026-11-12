<?php
/**
 * The Pennylane connector: listed, not selectable, and without any code that contacts Pennylane.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\NoAuth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Pennylane requires a sign-in (OAuth) for production integrations and its API terms limit how an integration may be used, so the plugin does
 * not offer it yet and contains no code that talks to Pennylane. This placeholder exists so Pennylane appears on the Choose a system screen
 * with its reason, and so that anything that still tries to use it gets a plain explanation instead of a request. It has no capability, no
 * field, no mapping and no host, and it makes no request. See docs/connectors/pennylane.md.
 */
final class PennylaneConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'pennylane';

	/**
	 * Identity. No capability is set because there is no API behind any of them.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: 'Pennylane',
			type: ConnectorType::Accounting,
			capabilities: array(),
			entities: array(),
			auth: AuthStyle::None,
			regions: array( 'FR' ),
			rateLimit: new RateLimitHints( 1.0, 1 )
		);
	}

	/**
	 * Whether the system can be selected. It cannot yet.
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * Why the system cannot be used, as a sentence the screens can show.
	 */
	public function unavailableReason(): string {
		return __( 'Pennylane needs a sign-in for production use and limits how integrations may be used, so the plugin cannot send orders to it yet.', 'estasoft-enterprise-erpcrm-sync-engine' );
	}

	/**
	 * Nothing is contacted.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return '';
	}

	/**
	 * No credentials.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new NoAuth();
	}

	/**
	 * Test connection: always refused, with the reason.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		return new TestResult( false, '', $connection->label, array(), array(), $this->refusal() );
	}

	/**
	 * No fields: nothing is mapped while the connector is not offered.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		unset( $mappingType );
		return array();
	}

	/**
	 * No rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		unset( $mappingType );
		return array();
	}

	/**
	 * Nothing can exist in Pennylane through this plugin yet; the answer is the reason.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		unset( $connection, $item );
		return $this->refusal();
	}

	/**
	 * Refuse. Nothing is sent.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		unset( $connection, $item, $payload );
		return SendResult::failure( $this->refusal() );
	}

	/**
	 * The error every attempt gets: needs a person, never retried.
	 */
	private function refusal(): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => 'Pennylane',
				'field'  => 'connection',
				'reason' => $this->unavailableReason(),
			),
			null,
			null,
			null,
			'pennylane is not offered'
		);
	}
}
