<?php
/**
 * The connector against wire shapes kept as files, with no fake pod behind it: an unrouted request fails the test.
 *
 * The files in tests/php/fixtures/oracle-fusion are synthetic. Their field names follow the Oracle pages listed in
 * docs/connectors/oracle-fusion.md (token answer, `items`/`hasMore` pages, receivablesInvoices attributes); the values are made up.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Connectors\OracleFusion\OracleFusionConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OracleFusionWireFixtureTest extends TestCase {

	private ApiTestKit $kit;

	private GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->connector = GuardedConnector::wrap( new OracleFusionConnector( $this->kit->deps ), $this->kit->tokens );
		$this->kit->http->route( 'POST', '#^https://idcs-fixture\.identity\.oraclecloud\.com/oauth2/v1/token$#', FakeHttpClient::json( $this->fixture( 'token.json' ) ) );
		$this->kit->http->route( 'GET', '#/itemsV2\?#', FakeHttpClient::json( $this->fixture( 'items-page.json' ) ) );
		$this->kit->http->route( 'GET', '#/receivablesInvoices\?#', FakeHttpClient::json( $this->fixture( 'invoices-empty.json' ) ) );
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
		return json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/oracle-fusion/' . $name ), true );
	}

	private function connection(): \Estasoft\SyncEngine\Domain\Connection {
		return $this->kit->connection(
			'oracle-fusion',
			array(
				'address'            => 'https://fixture.fa.us2.oraclecloud.com',
				'auth_method'        => 'oauth',
				'identity_url'       => 'https://idcs-fixture.identity.oraclecloud.com',
				'scope'              => 'urn:opc:resource:fusion:FIXTURE:erp/',
				'client_id'          => 'fixture-client',
				'business_unit'      => 'Shop Business Unit',
				'transaction_source' => 'Web Orders',
				'transaction_type'   => 'Invoice',
				'bill_to_account'    => '1001',
			),
			array( 'client_secret' => 'fixture-secret-not-real' )
		);
	}

	private function item(): \Estasoft\SyncEngine\Domain\Item {
		return new \Estasoft\SyncEngine\Domain\Item( 77, 1, \Estasoft\SyncEngine\Domain\EntityType::Order, '77', 'created', 'order:77', 'h', \Estasoft\SyncEngine\Domain\ItemState::Syncing );
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
			'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Ceramic mug, sand', 'quantity' => 3, 'price_unit' => 1999 ) ),
		);
	}

	public function test_an_item_page_is_read_and_the_token_answer_is_used_as_a_bearer_header(): void {
		$found = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( 'CS-MUG-01', $found->id );
		$request = $this->kit->http->matching( '#/itemsV2\?#' )[0];
		$this->assertSame( 'Bearer fixture-access-token-not-real', $request['headers']['Authorization'] );
		$token = $this->kit->http->matching( '#oauth2/v1/token#' )[0];
		$this->assertStringContainsString( 'grant_type=client_credentials', (string) $token['body'] );
		$this->assertStringContainsString( rawurlencode( 'urn:opc:resource:fusion:FIXTURE:erp/' ), (string) $token['body'] );
	}

	public function test_an_invoice_answer_is_turned_into_a_reference_with_the_total(): void {
		$this->kit->http->route( 'POST', '#/receivablesInvoices$#', FakeHttpClient::json( $this->fixture( 'invoice-created.json' ), 201 ) );
		$result = $this->connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '300100000000100', $result->ref->id );
		$this->assertSame( '1001', $result->ref->ref );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
	}

	public function test_an_error_answer_reaches_the_person_as_val_002_with_the_path_and_the_detail(): void {
		$this->kit->http->route( 'POST', '#/receivablesInvoices$#', FakeHttpClient::json( $this->fixture( 'error-400.json' ), 400 ) );
		$result = $this->connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( '/BusinessUnit', $context );
		$this->assertStringContainsString( 'is not valid.', $context );
	}
}
