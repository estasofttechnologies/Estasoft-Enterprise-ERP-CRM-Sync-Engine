<?php
/**
 * The contract rules of the specification, run against the Sage 300 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage300;

use Estasoft\SyncEngine\Connectors\Sage300\Sage300Connector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeSage300Server;

final class Sage300ConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeSage300Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeSage300Server( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new Sage300Connector( $this->kit->deps );
	}

	/**
	 * A connection to the fake Web API.
	 *
	 * @param string $password Password.
	 * @param int    $id       Connection id.
	 */
	private function connectionWith( string $password, int $id ): Connection {
		return $this->kit->connection(
			'sage300',
			array(
				'address'         => FakeSage300Server::ADDRESS,
				'company'         => FakeSage300Server::COMPANY,
				'username'        => FakeSage300Server::USER,
				'customer_number' => '1200',
				'refund_total_property' => 'NoteTotal',
			),
			array( 'password' => $password ),
			$id
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( FakeSage300Server::PASSWORD, 1 );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'wrong-password-0000', 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$payload = array(
			// Sage 300 orders follow the currency of the customer account, so the currency the contract asks for is not used; the amounts are.
			'currency'   => 'AED',
			'date_order' => '2026-11-02',
			'note'       => (string) ( $options['note'] ?? '' ),
			'partner'    => array(
				'name'  => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email' => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
			),
			'order_line' => array(
				array(
					'product'    => 'CS-BOWL-02',
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
		return array( FakeSage300Server::PASSWORD, 'wrong-password-0000' );
	}

	/**
	 * Sage 300 puts every web order on one customer account and creates no customers, so the buyer's name does not travel. The same property
	 * is kept for the text that does: the order note comes back exactly as it went in.
	 */
	public function test_arabic_text_comes_back_exactly_as_it_went_in(): void {
		$note = 'نور المنصوري، برج ٢٣';
		list( $item, $payload ) = $this->orderFixture( '9600', array( 'note' => $note, 'email' => 'noor.ar@example.test' ) );
		$sent = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );

		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( $note, $this->server->all( 'OE/OEOrders' )[0]['OrderDescription'], 'No escaping, no transliteration, no normalisation.' );
	}
}
