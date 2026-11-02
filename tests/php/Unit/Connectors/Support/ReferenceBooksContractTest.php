<?php
/**
 * The reference key-based connector against the connector contract.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksVendor;

final class ReferenceBooksContractTest extends ApiConnectorContractTestCase {

	protected ReferenceBooksVendor $vendor;

	protected function setUp(): void {
		parent::setUp();
		$this->vendor = new ReferenceBooksVendor( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new ReferenceBooksConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return new Connection( 1, 'reference-books', ConnectorType::Accounting, 'Acme Books', ConnectionStatus::Active, array(), array(), array( 'api_key' => ReferenceBooksVendor::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return new Connection( 1, 'reference-books', ConnectorType::Accounting, 'Acme Books', ConnectionStatus::Active, array(), array(), array( 'api_key' => 'ak-wrong-0000000000' ) );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'wc-' . $orderId . '-created', ItemState::Syncing );
		return array(
			$item,
			array(
				'invoice' => array( 'reference' => 'WC-' . $orderId, 'currency' => $options['currency'] ?? 'AED' ),
				'contact' => array( 'name' => $options['name'] ?? 'Layla Haddad', 'email' => $options['email'] ?? 'layla@example.test' ),
				'lines'   => array( array( 'sku' => 'CS-MUG-01', 'quantity' => $options['quantity'] ?? 1, 'unit_price' => $options['unit_minor'] ?? 4200 ) ),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->vendor->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( ReferenceBooksVendor::KEY, 'ak-wrong-0000000000' );
	}
}
