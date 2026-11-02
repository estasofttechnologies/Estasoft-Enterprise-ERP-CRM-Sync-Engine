<?php
/**
 * The connector against wire shapes kept as files, with no fake tenant behind it: an unrouted request fails the test.
 *
 * The files in tests/php/fixtures/sap-s4hana are synthetic. Their shape follows the SAP pages listed in docs/connectors/sap-s4hana.md (the
 * sales order tutorial answer, OData V2 `d.results` envelopes, the OData error body with `innererror.errordetails`); the values are made up.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Connectors\SapS4Hana\SapS4HanaConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SapS4HanaWireFixtureTest extends TestCase {

	private ApiTestKit $kit;

	private GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->connector = GuardedConnector::wrap( new SapS4HanaConnector( $this->kit->deps ), $this->kit->tokens );
		$this->kit->http->route( 'POST', '#^https://fixture-api\.s4hana\.cloud\.sap/sap/bc/sec/oauth2/token$#', FakeHttpClient::json( $this->fixture( 'token.json' ) ) );
		$this->kit->http->route( 'GET', '#/API_PRODUCT_SRV/A_Product\?#', FakeHttpClient::json( $this->fixture( 'products-page.json' ) ) );
		$this->kit->http->route( 'GET', '#/API_SALES_ORDER_SRV/A_SalesOrder\?#', FakeHttpClient::json( $this->fixture( 'sales-orders-empty.json' ) ) );
		$this->kit->http->route( 'GET', '#/API_SALES_ORDER_SRV/$#', new HttpResponse( 200, '{}', array( 'x-csrf-token' => 'fixture-csrf', 'set-cookie' => 'SAP_SESSIONID_FIX_100=abc; path=/; HttpOnly' ) ) );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A fixture file, decoded.
	 *
	 * @param string $name File name.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/sap-s4hana/' . $name ), true );
	}

	private function connection(): Connection {
		return $this->kit->connection(
			'sap-s4hana',
			array(
				'address'              => 'https://fixture-api.s4hana.cloud.sap',
				'auth_method'          => 'oauth',
				'token_url'            => 'https://fixture-api.s4hana.cloud.sap/sap/bc/sec/oauth2/token',
				'scope'                => 'API_SALES_ORDER_SRV_0001',
				'client_id'            => 'fixture-client',
				'sales_organization'   => '1710',
				'distribution_channel' => '10',
				'division'             => '00',
				'sold_to_party'        => '17100001',
			),
			array( 'client_secret' => 'fixture-secret-not-real' )
		);
	}

	private function item(): Item {
		return new Item( 77, 1, EntityType::Order, '77', 'created', 'order:77', 'h', ItemState::Syncing );
	}

	/**
	 * A payload for the item.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		return array(
			'currency'   => 'AED',
			'order_date' => '2026-11-02 07:34:00',
			'total'      => 5997,
			'order_line' => array( array( 'product' => 'CS-MUG-01', 'quantity' => 3, 'price_unit' => 1999 ) ),
		);
	}

	public function test_the_tutorial_shaped_answer_becomes_a_reference_with_the_net_amount(): void {
		$this->kit->http->route( 'POST', '#/API_SALES_ORDER_SRV/A_SalesOrder$#', FakeHttpClient::json( $this->fixture( 'sales-order-created.json' ), 201 ) );
		$result = $this->connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '4500', $result->ref->id );
		$this->assertSame( 5997, $result->ref->extra['net_minor'] );
		$write = $this->kit->http->matching( '#/A_SalesOrder$#' )[0];
		$this->assertSame( 'fixture-csrf', $write['headers']['X-CSRF-Token'] );
		$this->assertStringContainsString( 'SAP_SESSIONID_FIX_100=abc', $write['headers']['Cookie'] );
		$this->assertSame( 'Bearer fixture-access-token-not-real', $write['headers']['Authorization'] );
	}

	public function test_the_error_body_gives_the_first_detail_with_severity_error_not_the_warning_or_the_general_text(): void {
		$this->kit->http->route( 'POST', '#/API_SALES_ORDER_SRV/A_SalesOrder$#', FakeHttpClient::json( $this->fixture( 'error-400.json' ), 400 ) );
		$result = $this->connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context, JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( 'Sold-to party 17100001 is blocked for sales area 1710/10/00', $context );
		$this->assertStringNotContainsString( 'A warning only', $context );
		$this->assertStringNotContainsString( '0123456789ABCDEF', $context, 'The transaction id of the gateway log stays out of the message.' );
	}

	public function test_a_product_page_in_the_v2_envelope_is_read(): void {
		$this->assertSame( 'CS-MUG-01', $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' )->id );
	}
}
