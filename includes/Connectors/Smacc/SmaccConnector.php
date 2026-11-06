<?php
/**
 * The SMACC connector: listed, not selectable.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Smacc;

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
 * SMACC publishes no API (its documentation portal lists a REST API reference as "Coming Soon"), so nothing can be sent to it. This
 * connector exists so SMACC appears on the Choose a system screen with its reason, and so that anything that still tries to use it gets
 * a plain explanation instead of a request to an address nobody documented. It has no capability, no field, no mapping and no host,
 * and it makes no request. See docs/connectors/smacc.md.
 */
final class SmaccConnector extends AbstractApiConnector {

	/**
	 * Identity. No capability is set because there is no API behind any of them.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'smacc',
			label: 'SMACC',
			type: ConnectorType::Accounting,
			capabilities: array(),
			entities: array(),
			auth: AuthStyle::None,
			regions: array( 'AE', 'SA' ),
			rateLimit: new RateLimitHints( 1.0, 1 )
		);
	}

	/**
	 * Whether the system can be selected. It cannot: there is no API to connect to.
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * Why the system cannot be used, as a sentence the screens can show.
	 */
	public function unavailableReason(): string {
		return __( 'SMACC publishes no public API yet, so the plugin cannot send orders to it.', 'estasoft-enterprise-erpcrm-sync-engine' );
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
	 * No fields: SMACC has no documented object to map to.
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
	 * Nothing can exist in SMACC through this plugin; the answer is the reason.
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
				'system' => 'SMACC',
				'field'  => 'connection',
				'reason' => $this->unavailableReason(),
			),
			null,
			null,
			null,
			'smacc has no public api'
		);
	}
}
