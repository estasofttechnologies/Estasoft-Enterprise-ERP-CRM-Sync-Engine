<?php
/**
 * Test connection, the connect form and the error mapping of the ERPNext connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ErpnextConnectionTest extends ErpnextTestCase {

	public function test_the_test_shows_user_version_company_warehouses_roles_and_setup(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'ERPNext 15.52.0 (Frappe 15.125.0)', $result->version );
		$this->assertSame( 'Cairo Sand LLC', $result->company );
		$this->assertSame( array( 'Showroom - CS', 'Stores - CS' ), array_column( $result->warehouses, 'id' ) );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( 'sync@example.test', $result->detected['user'] );
		$this->assertSame( 'AED', $result->detected['currency'] );
		$this->assertContains( 'Standard Selling (AED)', $result->detected['price_lists'] );
		$this->assertContains( 'UAE VAT 5% - CS', $result->detected['tax_templates'] );
		$this->assertTrue( $result->detected['write_not_probed'], 'The test never creates a record to find out.' );
		$this->assertSame( 0, $this->server->count( 'POST', '#.*#' ), 'Test connection writes nothing.' );
	}

	public function test_a_wrong_secret_is_auth_001_and_nothing_is_saved_or_repeated(): void {
		$result = $this->connector()->test( $this->connection( array(), 'wrong-secret-0000' ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wrong-secret-0000', $result->error->message() . $result->error->detail );
	}

	public function test_a_role_that_may_not_read_a_doctype_is_reported_by_name(): void {
		$this->server->failNext( 'GET', '#/api/resource/Bin$#', FakeHttpClient::json( array( 'exc_type' => 'PermissionError' ), 403 ) );
		$result = $this->connector()->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'read Bin' ), $result->missingPermissions() );
	}

	public function test_an_address_that_is_not_an_erpnext_site_gets_a_plain_message(): void {
		$this->kit->http->route( 'GET', '#^https://shop\.example\.test/#', FakeHttpClient::status( 404, '<!DOCTYPE html><html><body>Not Found</body></html>' ) );
		$connection = $this->kit->connection( 'erpnext', array( 'address' => 'https://shop.example.test/app' ), array( 'api_key' => 'k', 'api_secret' => 's' ), 5 );
		$result     = $this->connector()->test( $connection );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'address', $result->error->context['field'] );
		$this->assertSame( 'https://shop.example.test/api/method/frappe.auth.get_logged_user', $this->kit->http->requests[0]['url'], 'The path of the address is dropped.' );
	}

	public function test_an_old_frappe_is_refused(): void {
		$this->server->failNext( 'GET', '#get_versions$#', FakeHttpClient::json( array( 'message' => array( 'frappe' => array( 'version' => '13.4.0' ), 'erpnext' => array( 'version' => '13.4.1' ) ) ) ) );
		$result = $this->connector()->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( 'version', $result->error->context['field'] );
	}

	public function test_a_site_that_hides_the_version_still_connects(): void {
		$this->server->failNext( 'GET', '#get_versions$#', FakeHttpClient::json( array( 'exc_type' => 'PermissionError' ), 403 ) );
		$result = $this->connector()->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'ERPNext', $result->version );
	}

	public function test_a_connection_without_a_secret_is_auth_001_before_any_request(): void {
		$connection = $this->kit->connection( 'erpnext', array( 'address' => \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::ADDRESS ), array( 'api_key' => 'k' ), 9 );
		$result     = $this->connector()->test( $connection );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_ping_is_cheap_and_never_throws(): void {
		$this->assertTrue( $this->connector()->ping( $this->connection() ) );
		$this->assertSame( 1, $this->kit->http->count() );
		$this->assertFalse( $this->connector()->ping( $this->connection( array(), 'wrong-secret-0000' ) ) );
	}

	public function test_the_connect_form_names_the_address_the_pair_and_the_settings(): void {
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );
		$this->assertSame( 'url', $fields['address']->type );
		$this->assertTrue( $fields['api_key']->secret );
		$this->assertTrue( $fields['api_secret']->secret );
		$this->assertFalse( $fields['company']->secret );
		$this->assertSame( 'advanced', $fields['documents']->group );
		$this->assertSame( array( 'order', 'order_invoice', 'order_invoice_payment' ), array_keys( $fields['documents']->options ) );
		$this->assertArrayNotHasKey( 'redirect_uri', $fields, 'A key style connector has no redirect address.' );
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
		$this->assertSame( array( 'requests_per_second' => 2.0, 'concurrency' => 1 ), $this->connector()->rateLimitHints() );
	}

	/**
	 * @return array<string,array{0:int,1:array<string,mixed>|string,2:ErrorCode,3:ErrorClass}>
	 */
	public static function answers(): array {
		$messages = static fn( string $text ): string => (string) json_encode( array( (string) json_encode( array( 'message' => $text ) ) ) );
		return array(
			'wrong key'        => array( 401, array( 'exc_type' => 'AuthenticationError' ), ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'bad token header' => array( 400, array( 'exc_type' => 'InvalidAuthorizationToken' ), ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'no permission'    => array( 403, array( 'exc_type' => 'PermissionError', '_server_messages' => $messages( 'No permission for Sales Order' ) ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'busy'             => array( 417, array( 'exc_type' => 'TimestampMismatchError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'locked'           => array( 417, array( 'exc_type' => 'DocumentLockedError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'deadlock'         => array( 500, array( 'exc_type' => 'QueryDeadlockError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'server error'     => array( 500, array( 'exc_type' => 'KeyError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'bad gateway'      => array( 502, '<html>Bad Gateway</html>', ErrorCode::Net002, ErrorClass::Retry ),
			'validation'       => array( 417, array( 'exc_type' => 'ValidationError', '_server_messages' => $messages( 'Customer is mandatory' ) ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'mandatory'        => array( 417, array( 'exc_type' => 'MandatoryError', '_server_messages' => $messages( 'Missing Values Required: Delivery Date' ) ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'duplicate'        => array( 409, array( 'exc_type' => 'DuplicateEntryError', '_server_messages' => $messages( 'Duplicate entry' ) ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'not found'        => array( 404, array( 'exc_type' => 'DoesNotExistError', '_server_messages' => $messages( 'Customer X not found' ) ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider answers
	 * @param array<string,mixed>|string $body Body.
	 */
	public function test_every_answer_of_the_site_has_a_code_and_a_class( int $status, array|string $body, ErrorCode $code, ErrorClass $class ): void {
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', is_array( $body ) ? FakeHttpClient::json( $body, $status ) : FakeHttpClient::status( $status, $body ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->connector()->classifyError( $result->error ) ?? $result->error->errorClass() );
	}

	public function test_the_first_server_message_is_the_reason_without_tags_or_secrets(): void {
		$body = array( 'exc_type' => 'ValidationError', '_server_messages' => (string) json_encode( array( (string) json_encode( array( 'message' => '<div>Customer <b>Noor</b> is disabled</div>' ) ), (string) json_encode( array( 'message' => 'second' ) ) ) ) );
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', FakeHttpClient::json( $body, 417 ) );
		$result = $this->sendOrder();
		$this->assertSame( 'Customer Noor is disabled', $result->error->context['reason'] );
	}

	public function test_a_rate_limit_waits_as_told(): void {
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', FakeHttpClient::tooManyRequests( 41 ) );
		$result = $this->sendOrder();
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 41, $result->error->retryAfter );
	}

	public function test_a_certificate_failure_is_net_003_and_a_reset_is_net_001(): void {
		$this->server->failNext( 'GET', '#get_logged_user#', FakeHttpClient::certificateError() );
		$this->assertSame( ErrorCode::Net003, $this->connector()->test( $this->connection() )->error->code );
		$this->server->failNext( 'GET', '#get_logged_user#', FakeHttpClient::unreachable() );
		$this->assertSame( ErrorCode::Net001, $this->connector()->test( $this->connection() )->error->code );
	}
}
