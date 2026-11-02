<?php
/**
 * Test connection, the connect form, sign-in and the error mapping of the Acumatica connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Acumatica;

use Estasoft\SyncEngine\Connectors\Acumatica\AcumaticaConnector;
use Estasoft\SyncEngine\Connectors\Acumatica\AcumaticaOAuth;
use Estasoft\SyncEngine\Connectors\Acumatica\AcumaticaSite;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeAcumaticaServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class AcumaticaConnectionTest extends AcumaticaTestCase {

	public function test_the_test_shows_endpoint_tenant_warehouses_permissions_and_setup(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertSame( 'Acumatica Default/24.200.001', $result->version );
		$this->assertSame( 'Acme Trading', $result->company );
		$this->assertSame( array( 'MAIN', 'SHOWROOM' ), array_column( $result->warehouses, 'id' ) );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 6, $result->permissions );
		$this->assertSame( '24.200.001', $result->detected['endpoint_version'] );
		$this->assertContains( 'VAT5', $result->detected['tax_categories'] );
		$this->assertNotContains( 'OLDTAX', $result->detected['tax_categories'] );
		$this->assertTrue( $result->detected['write_not_probed'] );
		foreach ( $this->server->calls as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Test connection writes nothing.' );
		}
	}

	public function test_every_field_the_connector_reads_is_probed_with_select_and_top_one(): void {
		$this->connector()->test( $this->connection() );

		$probe = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => str_ends_with( (string) $call['path'], '/SalesOrder' ) ) )[0];
		$this->assertSame( '1', (string) $probe['query']['$top'] );
		$this->assertStringContainsString( 'CustomerOrder', (string) $probe['query']['$select'] );
	}

	public function test_a_field_the_site_does_not_know_is_reported_by_entity_when_connecting(): void {
		$this->server->failNext( 'GET', '#/SalesInvoice$#', FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => "Could not find the property 'Amount'", 'exceptionType' => 'System.InvalidOperationException' ), 500 ) );
		$result = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'SalesInvoice', (string) $result->error->context['reason'] );
		$this->assertFalse( $result->permissions['read SalesInvoice'] );
	}

	public function test_a_form_the_user_may_not_open_is_a_missing_permission_not_a_failure(): void {
		$this->server->failNext( 'GET', '#/Shipment$#', FakeHttpClient::status( 403, '' ) );
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'read Shipment' ), $result->missingPermissions() );
	}

	public function test_an_endpoint_version_the_site_does_not_offer_names_the_one_to_enter(): void {
		$result = $this->connector()->test( $this->connection( array( 'endpoint_version' => '25.200.001' ) ) );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'endpoint_version', $result->error->context['field'] );
		$this->assertStringContainsString( '24.200.001', (string) $result->error->context['reason'] );
	}

	public function test_a_site_that_offers_only_the_new_syntax_is_refused_with_the_versions_it_has(): void {
		$this->server->failNext( 'GET', '#^/entity$#', FakeHttpClient::json( array( 'endpoints' => array( array( 'name' => 'Default', 'version' => '26.200.001' ) ) ) ) );
		$result = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( '26.200.001', (string) $result->error->context['reason'] );
		$this->assertStringContainsString( '22.200.001 to 25.200.001', (string) $result->error->context['reason'] );
	}

	public function test_an_unsupported_version_is_refused_even_when_the_list_is_empty(): void {
		$this->server->failNext( 'GET', '#^/entity$#', FakeHttpClient::json( array( 'endpoints' => array() ) ) );
		$result = $this->connector()->test( $this->connection( array( 'endpoint_version' => '26.200.001' ) ) );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'endpoint_version', $result->error->context['field'] );
	}

	public function test_a_typed_version_that_is_not_a_version_falls_back_to_the_default(): void {
		$result = $this->connector()->test( $this->connection( array( 'endpoint_version' => 'latest' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( '24.200.001', $result->detected['endpoint_version'] );
	}

	public function test_an_address_that_is_not_an_acumatica_site_gets_a_plain_message(): void {
		$this->kit->http->route( 'GET', '#^https://shop\.example\.test/#', FakeHttpClient::status( 404, '<!DOCTYPE html><html><body>Not Found</body></html>' ) );
		$connection = $this->kit->connection(
			'acumatica',
			array( 'address' => 'https://shop.example.test/Main?ScreenId=SO301000', 'client_id' => FakeAcumaticaServer::CLIENT_ID ),
			$this->server->signedIn( $this->kit->clock->now()->getTimestamp() ),
			5
		);
		$result     = $this->connector()->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'address', $result->error->context['field'] );
		$this->assertSame( 'https://shop.example.test/entity', $this->kit->http->requests[0]['url'], 'The screen path of the address is dropped.' );
	}

	public function test_the_instance_folder_of_the_address_is_kept_on_every_call(): void {
		$connection = $this->kit->connection(
			'acumatica',
			array( 'address' => FakeAcumaticaServer::ADDRESS . '/AcumaticaDB/Main?ScreenId=SO301000', 'client_id' => FakeAcumaticaServer::CLIENT_ID ),
			$this->server->signedIn( $this->kit->clock->now()->getTimestamp() ),
			6
		);
		$result     = $this->connector()->test( $connection );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		foreach ( $this->server->calls as $call ) {
			$this->assertSame( '/AcumaticaDB', $call['folder'], $call['path'] );
		}
	}

	public function test_a_private_or_plain_http_address_is_refused_before_any_request(): void {
		foreach ( array( 'https://10.0.0.5', 'https://localhost', 'http://erp.acme-trading.example.test' ) as $address ) {
			$connection = $this->kit->connection( 'acumatica', array( 'address' => $address, 'client_id' => FakeAcumaticaServer::CLIENT_ID ), $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ), 7 );
			$result     = $this->connector()->test( $connection );
			$this->assertFalse( $result->ok, $address );
		}
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_connection_that_never_signed_in_is_auth_001_or_002_before_any_data_call(): void {
		$connection = $this->kit->connection( 'acumatica', array( 'address' => FakeAcumaticaServer::ADDRESS, 'client_id' => FakeAcumaticaServer::CLIENT_ID ), array( 'client_secret' => FakeAcumaticaServer::CLIENT_SECRET ), 8 );
		$result     = $this->connector()->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertContains( $result->error->code, array( ErrorCode::Auth001, ErrorCode::Auth002 ) );
		$this->assertSame( 0, $this->server->count( 'GET', '#/entity#' ) );
	}

	public function test_an_expired_access_token_is_refreshed_with_the_client_id_including_the_tenant(): void {
		$connection = $this->connection();
		$secrets    = array(
			'client_secret'       => $connection->secret( 'client_secret' ),
			'oauth_access_token'  => $connection->secret( 'oauth_access_token' ),
			'oauth_refresh_token' => $connection->secret( 'oauth_refresh_token' ),
		);
		$this->kit->storage->save( $connection->id, array( 'oauth_expires_at' => (string) ( $this->kit->clock->now()->getTimestamp() - 10 ) ) + $secrets );
		$result = $this->connector()->test( $this->kit->storage->load( $connection->id ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->tokenRequests );
		$form = $this->server->tokenRequests[0];
		$this->assertSame( 'refresh_token', $form['grant_type'] );
		$this->assertSame( FakeAcumaticaServer::CLIENT_ID, $form['client_id'] );
		$this->assertSame( FakeAcumaticaServer::CLIENT_SECRET, $form['client_secret'], 'The secret goes in the body, not a Basic header.' );
		$this->assertArrayNotHasKey( 'code_verifier', $form );
		$saved = $this->kit->storage->load( $connection->id );
		$this->assertNotSame( $secrets['oauth_access_token'], $saved->secret( 'oauth_access_token' ) );
		$this->assertNotSame( $secrets['oauth_refresh_token'], $saved->secret( 'oauth_refresh_token' ), 'The refresh token rotates and the new one is kept.' );
	}

	public function test_a_refused_refresh_token_needs_a_new_sign_in(): void {
		$connection = $this->kit->connection(
			'acumatica',
			array( 'address' => FakeAcumaticaServer::ADDRESS, 'client_id' => FakeAcumaticaServer::CLIENT_ID ),
			array(
				'client_secret'       => FakeAcumaticaServer::CLIENT_SECRET,
				'oauth_access_token'  => 'old-access',
				'oauth_refresh_token' => 'expired-after-30-days',
				'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 10 ),
			),
			11
		);
		$result = $this->connector()->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
	}

	public function test_the_sign_in_settings_use_the_sites_own_identity_server_with_pkce(): void {
		$config = ( new AcumaticaOAuth() )->config( $this->connection( array( 'address' => FakeAcumaticaServer::ADDRESS . '/AcumaticaDB' ) ) );

		$this->assertSame( FakeAcumaticaServer::ADDRESS . '/AcumaticaDB/identity/connect/authorize', $config->authorizeUrl );
		$this->assertSame( FakeAcumaticaServer::ADDRESS . '/AcumaticaDB/identity/connect/token', $config->tokenUrl );
		$this->assertSame( array( 'api', 'offline_access' ), $config->scopes );
		$this->assertTrue( $config->usePkce );
		$this->assertSame( 'body', $config->clientAuth );
		$this->assertSame( 30, $config->refreshTokenLifetimeDays );
		$this->assertSame( array( 'erp.acme-trading.example.test' ), $config->allowedHosts );
	}

	public function test_the_one_organisation_is_the_tenant_of_the_client_id(): void {
		$connector  = new AcumaticaConnector( $this->kit->deps );
		$connection = $this->connection();

		$this->assertSame( array( array( 'id' => 'Acme Trading', 'name' => 'Acme Trading' ) ), $connector->organisations( $connection ) );
		$this->assertSame( array( 'tenant' => 'Acme Trading' ), $connector->selectOrganisation( $connection, 'Acme Trading' ) );
		$single = $this->kit->connection( 'acumatica', array( 'address' => FakeAcumaticaServer::ADDRESS, 'client_id' => 'plain-guid-without-tenant' ), array(), 12 );
		$this->assertSame( 'default', $connector->organisations( $single )[0]['id'] );
		$this->assertSame( array( 'tenant' => '' ), $connector->selectOrganisation( $single, 'default' ) );
	}

	public function test_ping_is_cheap_and_never_throws(): void {
		$this->assertTrue( $this->connector()->ping( $this->connection() ) );
		$this->server->failNext( 'GET', '#/Customer$#', FakeHttpClient::status( 500, 'boom' ) );
		$this->assertFalse( $this->connector()->ping( $this->connection() ) );
	}

	public function test_the_connect_form_names_the_address_the_app_and_the_settings_and_shows_the_redirect_address(): void {
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );

		$this->assertSame( 'url', $fields['address']->type );
		$this->assertSame( 'app', $fields['client_id']->group );
		$this->assertTrue( $fields['client_secret']->secret );
		$this->assertFalse( $fields['branch']->secret );
		$this->assertSame( 'advanced', $fields['refund_state']->group );
		$this->assertSame( array( 'hold', 'release' ), array_keys( $fields['refund_state']->options ) );
		$this->assertSame( 'display', $fields['redirect_uri']->type );
		$this->assertSame( OAuthRedirect::uri(), $fields['redirect_uri']->default );
		$this->assertStringContainsString( 'estasoft-sync/v1/oauth/callback', $fields['redirect_uri']->default );
	}

	public function test_the_flags_say_what_the_connector_really_does(): void {
		$flags = $this->connector()->capabilities();
		foreach ( array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::StockIn, Capability::TrackingIn, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ) as $on ) {
			$this->assertTrue( $flags[ $on->value ], $on->value );
		}
		foreach ( array( Capability::PricesIn, Capability::Webhooks ) as $off ) {
			$this->assertFalse( $flags[ $off->value ], $off->value );
		}
		$this->assertSame( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector()->supportedEntities() );
		$this->assertSame( 1.0, $this->connector()->rateLimitHints()['requests_per_second'] );
		$this->assertSame( 1, $this->connector()->rateLimitHints()['concurrency'] );
	}

	public function test_site_helpers_read_the_tenant_the_version_and_the_support_range(): void {
		$connection = $this->connection( array( 'endpoint_version' => '23.200.001' ) );

		$this->assertSame( 'Acme Trading', AcumaticaSite::tenant( $connection ) );
		$this->assertSame( '23.200.001', AcumaticaSite::version( $connection ) );
		$this->assertTrue( AcumaticaSite::supports( '22.200.001' ) );
		$this->assertTrue( AcumaticaSite::supports( '25.200.001' ) );
		$this->assertFalse( AcumaticaSite::supports( '26.200.001' ) );
		$this->assertFalse( AcumaticaSite::supports( '21.200.001' ) );
		$this->assertFalse( AcumaticaSite::supports( 'x' ) );
		$this->assertSame( '/entity/Default/24.200.001', AcumaticaSite::path( '24.200.001' ) );
		$this->assertSame( 'https://a.example.test:8443', AcumaticaSite::origin( 'https://a.example.test:8443/Inst' ) );
	}

	/**
	 * @return array<string,array{0:int,1:array<string,mixed>|string,2:ErrorCode,3:ErrorClass}>
	 */
	public static function answers(): array {
		$business = static fn( string $text, string $type = 'PX.Data.PXException' ): array => array( 'message' => 'An error has occurred.', 'exceptionMessage' => $text, 'exceptionType' => $type );
		return array(
			'no right'          => array( 403, array( 'message' => 'Forbidden' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'busy'              => array( 503, '<html>Service Unavailable</html>', ErrorCode::Net002, ErrorClass::Retry ),
			'sql timeout'       => array( 500, $business( 'Timeout expired.', 'System.Data.SqlClient.SqlException' ), ErrorCode::Net002, ErrorClass::Retry ),
			'unknown fault'     => array( 500, $business( 'Object reference not set to an instance of an object.', 'System.NullReferenceException' ), ErrorCode::Net002, ErrorClass::Retry ),
			'login limit'       => array( 500, $business( 'API Login Limit', 'PX.Api.PXApiException' ), ErrorCode::Rate001, ErrorClass::Retry ),
			'missing record'    => array( 500, $business( 'Error: Customer cannot be found in the system.' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'required field'    => array( 500, $business( 'Error: \'Customer Name\' cannot be empty.', 'PX.Data.PXSetPropertyException' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'field error'       => array( 422, array( 'CustomerID' => array( 'value' => 'X', 'error' => 'Error: no such customer' ) ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'bad request'       => array( 400, array( 'message' => 'The request is invalid.' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'unknown entity'    => array( 404, $business( 'Endpoint does not contain the entity' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider answers
	 * @param array<string,mixed>|string $body Body.
	 */
	public function test_every_answer_of_the_site_has_a_code_and_a_class( int $status, array|string $body, ErrorCode $code, ErrorClass $class ): void {
		$this->server->failNext( 'PUT', '#/SalesOrder$#', is_array( $body ) ? FakeHttpClient::json( $body, $status ) : FakeHttpClient::status( $status, $body ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->connector()->classifyError( $result->error ) ?? $result->error->errorClass() );
	}

	public function test_a_rate_limit_waits_as_told(): void {
		$this->server->failNext( 'PUT', '#/SalesOrder$#', FakeHttpClient::tooManyRequests( 41 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 41, $result->error->retryAfter );
	}

	public function test_a_certificate_failure_is_net_003_and_a_reset_is_net_001(): void {
		$this->server->failNext( 'GET', '#^/entity$#', FakeHttpClient::certificateError() );
		$this->assertSame( ErrorCode::Net003, $this->connector()->test( $this->connection() )->error->code );
		$this->server->failNext( 'GET', '#^/entity$#', FakeHttpClient::unreachable() );
		$this->assertSame( ErrorCode::Net001, $this->connector()->test( $this->connection() )->error->code );
	}

	public function test_a_message_never_carries_the_secret_the_tenant_or_the_address(): void {
		$text = 'Error: tenant ' . AcumaticaSite::tenant( $this->connection() ) . ' at ' . FakeAcumaticaServer::ADDRESS . ' secret ' . FakeAcumaticaServer::CLIENT_SECRET;
		$this->server->failNext( 'PUT', '#/SalesOrder$#', FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => $text, 'exceptionType' => 'PX.Data.PXException' ), 500 ) );
		$result = $this->sendOrder();
		$shown  = $result->error->message() . ' ' . $result->error->detail . ' ' . (string) json_encode( $result->error->context );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringNotContainsString( FakeAcumaticaServer::CLIENT_SECRET, $shown );
		$this->assertStringNotContainsString( 'Acme Trading', $shown );
		$this->assertStringNotContainsString( 'erp.acme-trading.example.test', $shown );
	}
}
