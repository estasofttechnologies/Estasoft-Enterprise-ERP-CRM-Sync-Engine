<?php
/**
 * The contract rules of the specification, run against the Sage X3 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageX3;

use Estasoft\SyncEngine\Connectors\SageX3\SageX3Connector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeSageX3Server;

final class SageX3ConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeSageX3Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server      = new FakeSageX3Server( $this->kit->http );
		$this->server->vat = 0.0;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new SageX3Connector( $this->kit->deps );
	}

	/**
	 * A connection to the fake web service.
	 *
	 * @param string $password Password.
	 * @param int    $id       Connection id.
	 */
	private function connectionWith( string $password, int $id ): Connection {
		return $this->kit->connection(
			'sagex3',
			array(
				'address'        => FakeSageX3Server::ADDRESS,
				'folder'         => FakeSageX3Server::FOLDER,
				'username'       => FakeSageX3Server::USER,
				'customer_code'  => 'WEB001',
				'total_property' => 'ORDINVATI',
			),
			array( 'password' => $password ),
			$id
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( FakeSageX3Server::PASSWORD, 1 );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'wrong-password-0000', 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$payload = array(
			// Sage X3 takes the currency of the customer, so the currency the contract asks for is not used; the amounts are.
			'currency'   => 'AED',
			'date_order' => '2026-11-02',
			'partner'    => array(
				'name'  => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email' => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
			),
			'order_line' => array(
				array(
					'product'    => (string) ( $options['sku'] ?? 'CS-BOWL-02' ),
					'quantity'   => $options['quantity'] ?? 1,
					'price_unit' => $options['unit_minor'] ?? 21900,
				),
			),
		);
		return array( $item, $payload );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( FakeSageX3Server::PASSWORD, 'wrong-password-0000' );
	}

	/**
	 * Sage X3 puts every web order on one customer and sends no name, address or note, so the only text that travels is the item code. The same
	 * property is kept for it: an Arabic item code comes back exactly as it went in.
	 */
	public function test_arabic_text_comes_back_exactly_as_it_went_in(): void {
		$code = 'كوب-٠١';
		$this->server->records['ITMMASTER'][] = array( 'ITMREF' => $code );
		list( $item, $payload )               = $this->orderFixture( '9600', array( 'sku' => $code ) );
		$sent                                 = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );

		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( $code, $this->server->all( 'SORDER' )[0]['SORDERQ'][0]['ITMREF'], 'No escaping, no transliteration, no normalisation.' );
	}
}
