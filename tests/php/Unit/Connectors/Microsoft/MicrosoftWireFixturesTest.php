<?php
/**
 * Replays the wire-format fixtures in tests/php/fixtures/{microsoft,business-central,dynamics-sales,dynamics-finance} through the connectors.
 *
 * The fixtures follow the shapes in Microsoft's documentation, with synthetic values only (no real tenants, people, tokens or numbers):
 *
 * - microsoft/token-response.json: the client credentials answer of the Microsoft identity platform (token_type, expires_in, access_token).
 * - microsoft/token-error-*.json: the OAuth 2.0 error answer with Entra's AADSTS text and error_codes. The shape and the two codes come from
 *   the Entra error list, which could not be read when the connectors were written (marked [inferred] in the connector pages).
 * - business-central/companies-get.json: learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/api-reference/v2.0/api/dynamics_company_get
 * - dynamics-sales/contact-created-*.json: learn.microsoft.com/en-us/power-apps/developer/data-platform/webapi/create-entity-web-api (the 201
 *   answer with Prefer return=representation and the 204 answer with the OData-EntityId header).
 * - dynamics-sales/error-envelope.json and service-protection-429.json: webapi/compose-http-requests-handle-errors and api-limits.
 * - dynamics-finance/customers-v3-page.json: a list answer of the F&O OData service (odata page: @odata.context and value); column names
 *   from the CustomersV3 entity page.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\BusinessCentral\BusinessCentralConnector;
use Estasoft\SyncEngine\Connectors\DynamicsFinance\DynamicsFinanceConnector;
use Estasoft\SyncEngine\Connectors\DynamicsSales\DynamicsSalesConnector;
use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer;
use Estasoft\SyncEngine\Tests\Support\FakeDynamicsFinanceServer;
use Estasoft\SyncEngine\Tests\Support\FakeDynamicsSalesServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class MicrosoftWireFixturesTest extends TestCase {

	private ApiTestKit $kit;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A fixture decoded.
	 *
	 * @param string $name Path below tests/php/fixtures.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$text = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/' . $name );
		$this->assertIsString( $text, $name );
		$data = json_decode( $text, true );
		$this->assertIsArray( $data, $name );
		return $data;
	}

	/**
	 * A fixture as an HTTP answer.
	 *
	 * @param string $name Path below tests/php/fixtures.
	 */
	private function answer( string $name, int $status = 200 ): \Estasoft\SyncEngine\Contract\HttpResponse {
		return FakeHttpClient::json( $this->fixture( $name ), $status );
	}

	private function item( string $id, EntityType $type = EntityType::Customer ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'x:' . $id, 'key-' . $id, ItemState::Syncing );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buyer(): array {
		return array(
			'currency' => 'AED',
			'partner'  => array(
				'email'      => 'layla.haddad@example.test',
				'first_name' => 'Layla',
				'last_name'  => 'Haddad',
				'country_id' => 'ARE',
			),
		);
	}

	private function sales(): ConnectorInterface {
		new FakeDynamicsSalesServer( $this->kit->http );
		return GuardedConnector::wrap( new DynamicsSalesConnector( $this->kit->deps ) );
	}

	private function salesConnection(): \Estasoft\SyncEngine\Domain\Connection {
		return $this->kit->connection(
			'dynamics-sales',
			array(
				'tenant_id'       => FakeDynamicsSalesServer::TENANT,
				'client_id'       => FakeDynamicsSalesServer::CLIENT_ID,
				'environment_url' => FakeDynamicsSalesServer::ENVIRONMENT,
			),
			array( 'client_secret' => FakeDynamicsSalesServer::SECRET )
		);
	}

	public function test_the_token_answer_is_used_as_a_bearer_token_on_the_next_call(): void {
		$sales = $this->sales();
		$this->kit->http->route( 'POST', '#login\.microsoftonline\.com#', $this->answer( 'microsoft/token-response.json' ) );
		$sales->ping( $this->salesConnection() );
		$api = $this->kit->http->matching( '#/api/data/v9\.2/#' );
		$this->assertNotEmpty( $api );
		$this->assertSame( 'Bearer SYNTHETIC-ACCESS-TOKEN-NOT-A-REAL-JWT', $api[0]['headers']['Authorization'] );
	}

	public function test_the_invalid_and_expired_secret_answers_have_their_own_codes_and_never_echo_the_app(): void {
		$sales = $this->sales();
		$this->kit->http->route( 'POST', '#login\.microsoftonline\.com#', $this->answer( 'microsoft/token-error-invalid-secret.json', 401 ) );
		$wrong = $sales->test( $this->salesConnection() );
		$this->assertSame( ErrorCode::Auth001, $wrong->error->code );

		$kit = ApiTestKit::make();
		new FakeDynamicsSalesServer( $kit->http );
		$kit->http->route( 'POST', '#login\.microsoftonline\.com#', FakeHttpClient::json( $this->fixture( 'microsoft/token-error-expired-secret.json' ), 401 ) );
		$connector = GuardedConnector::wrap( new DynamicsSalesConnector( $kit->deps ) );
		$expired   = $connector->test( $kit->connection( 'dynamics-sales', array( 'tenant_id' => FakeDynamicsSalesServer::TENANT, 'client_id' => FakeDynamicsSalesServer::CLIENT_ID, 'environment_url' => FakeDynamicsSalesServer::ENVIRONMENT ), array( 'client_secret' => FakeDynamicsSalesServer::SECRET ) ) );
		$this->assertSame( ErrorCode::Auth005, $expired->error->code );
		foreach ( array( $wrong->error, $expired->error ) as $error ) {
			$this->assertStringNotContainsString( FakeDynamicsSalesServer::SECRET, $error->message() . $error->detail );
			$this->assertStringNotContainsString( FakeDynamicsSalesServer::CLIENT_ID, $error->message() );
		}
		$kit->cleanup();
	}

	public function test_a_contact_answered_with_its_representation_is_linked_by_its_id(): void {
		$sales = $this->sales();
		$this->kit->http->route( 'POST', '#/api/data/v9\.2/contacts#', $this->answer( 'dynamics-sales/contact-created-representation.json', 201 ) );
		$result = $sales->send( $this->salesConnection(), $this->item( '601' ), $this->buyer() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '5f1c2a9e-3b7d-4e8a-9c01-0a1b2c3d4e5f', $result->ref->id );
		$this->assertSame( 'Layla Haddad', $result->ref->ref );
	}

	public function test_a_contact_answered_with_only_the_entity_id_header_is_linked_by_that_id(): void {
		$sales   = $this->sales();
		$created = $this->fixture( 'dynamics-sales/contact-created-204.json' );
		$this->kit->http->route( 'POST', '#/api/data/v9\.2/contacts#', FakeHttpClient::status( $created['status'], '', $created['headers'] ) );
		$result = $sales->send( $this->salesConnection(), $this->item( '602' ), $this->buyer() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '5f1c2a9e-3b7d-4e8a-9c01-0a1b2c3d4e5f', $result->ref->id );
	}

	public function test_the_service_protection_answer_carries_its_wait(): void {
		$sales   = $this->sales();
		$limited = $this->fixture( 'dynamics-sales/service-protection-429.json' );
		$this->kit->http->route( 'POST', '#/api/data/v9\.2/contacts#', FakeHttpClient::json( $limited['body'], $limited['status'], $limited['headers'] ) );
		$result = $sales->send( $this->salesConnection(), $this->item( '603' ), $this->buyer() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 30, $result->error->retryAfter );
	}

	public function test_the_dataverse_error_envelope_is_read(): void {
		$envelope = $this->fixture( 'dynamics-sales/error-envelope.json' );
		$response = new ApiResponse( 403, (string) wp_json_encode( $envelope ) );
		$this->assertSame( '0x80040265', MicrosoftErrors::code( $response ) );
		$this->assertStringContainsString( 'prvCreateContact', MicrosoftErrors::message( $response ) );
	}

	public function test_the_business_central_error_envelope_is_read_and_its_text_reaches_the_person(): void {
		$response = new ApiResponse( 404, (string) wp_json_encode( $this->fixture( 'business-central/error-envelope.json' ) ) );
		$this->assertSame( 'Internal_RecordNotFound', MicrosoftErrors::code( $response ) );
		$this->assertStringContainsString( 'does not exist', MicrosoftErrors::message( $response ) );
	}

	public function test_the_company_list_answer_gives_the_company_name_on_test_connection(): void {
		new FakeBusinessCentralServer( $this->kit->http );
		$connector = GuardedConnector::wrap( new BusinessCentralConnector( $this->kit->deps ) );
		$this->kit->http->route( 'GET', '#/api/v2\.0/companies(\?[^/]*)?$#', $this->answer( 'business-central/companies-get.json' ) );
		$result = $connector->test(
			$this->kit->connection(
				'business-central',
				array(
					'tenant_id'   => FakeBusinessCentralServer::TENANT,
					'client_id'   => FakeBusinessCentralServer::CLIENT_ID,
					'environment' => FakeBusinessCentralServer::ENVIRONMENT,
				),
				array( 'client_secret' => FakeBusinessCentralServer::SECRET )
			)
		);
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Test Company LLC', $result->company );
		$this->assertSame( FakeBusinessCentralServer::COMPANY_ID, $result->detected['company_id'] );
	}

	public function test_the_customers_list_answer_gives_the_customer_account_and_name(): void {
		new FakeDynamicsFinanceServer( $this->kit->http );
		$connector = GuardedConnector::wrap( new DynamicsFinanceConnector( $this->kit->deps ) );
		$this->kit->http->route( 'GET', '#/data/CustomersV3#', $this->answer( 'dynamics-finance/customers-v3-page.json' ) );
		$connection = $this->kit->connection(
			'dynamics-finance',
			array(
				'tenant_id'       => FakeDynamicsFinanceServer::TENANT,
				'client_id'       => FakeDynamicsFinanceServer::CLIENT_ID,
				'environment_url' => FakeDynamicsFinanceServer::ENVIRONMENT,
				'legal_entity'    => 'usmf',
			),
			array( 'client_secret' => FakeDynamicsFinanceServer::SECRET )
		);
		$found = $connector->lookupCustomer( $connection, 'Layla.Haddad@example.test' );
		$this->assertNotNull( $found );
		$this->assertSame( 'US-901', $found->id );
		$this->assertSame( 'Layla Haddad', $found->ref );
		$this->assertSame( 'AED', $found->extra['currency'] );
	}

	public function test_the_finance_error_envelope_text_reaches_the_person(): void {
		$fo = new FakeDynamicsFinanceServer( $this->kit->http );
		$fo->addProduct( 'CS-MUG-01' );
		$connector = GuardedConnector::wrap( new DynamicsFinanceConnector( $this->kit->deps ) );
		$this->kit->http->route( 'POST', '#/data/CustomersV3#', $this->answer( 'dynamics-finance/error-envelope.json', 400 ) );
		$connection = $this->kit->connection(
			'dynamics-finance',
			array(
				'tenant_id'       => FakeDynamicsFinanceServer::TENANT,
				'client_id'       => FakeDynamicsFinanceServer::CLIENT_ID,
				'environment_url' => FakeDynamicsFinanceServer::ENVIRONMENT,
				'legal_entity'    => 'usmf',
				'customer_group'  => '99',
			),
			array( 'client_secret' => FakeDynamicsFinanceServer::SECRET )
		);
		$result = $connector->send( $connection, $this->item( '604' ), $this->buyer() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Customer group 99 does not exist.', $result->error->message() );
	}
}
