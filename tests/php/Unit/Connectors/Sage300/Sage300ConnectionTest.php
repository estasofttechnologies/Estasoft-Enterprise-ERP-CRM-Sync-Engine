<?php
/**
 * Test connection, the connect form and the error mapping of the Sage 300 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage300;

use Estasoft\SyncEngine\Connectors\Sage300\Sage300Site;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSage300Server;

final class Sage300ConnectionTest extends Sage300TestCase {

	public function test_the_test_shows_the_company_the_locations_the_permissions_the_customer_and_the_tax_groups(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertSame( 'Sage 300 Web API v1.0', $result->version );
		$this->assertSame( 'SAMLTD', $result->company );
		$this->assertSame( array( 'MAIN', 'SHOW' ), array_column( $result->warehouses, 'id' ) );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 5, $result->permissions );
		$this->assertSame( 'PONumber', $result->detected['reference_property'] );
		$this->assertSame( '1200', $result->detected['customer'] );
		$this->assertSame( 'AED', $result->detected['customer_currency'] );
		$this->assertSame( array( 'UAEVAT', 'NOTAX' ), $result->detected['tax_groups'] );
		$this->assertTrue( $result->detected['write_not_probed'] );
		foreach ( $this->server->calls as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Test connection writes nothing.' );
		}
	}

	public function test_a_wrong_password_is_auth_001_and_is_not_repeated_in_the_message(): void {
		$result = $this->connector()->test( $this->connection( array(), 'wrong-password-0000' ) );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wrong-password-0000', $result->error->message() . $result->error->detail );
	}

	public function test_a_connection_without_a_password_is_auth_001_before_any_request(): void {
		$connection = $this->kit->connection( 'sage300', array( 'address' => FakeSage300Server::ADDRESS, 'company' => 'SAMLTD', 'username' => 'SYNCBOT' ), array(), 9 );
		$result     = $this->connector()->test( $connection );

		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_wrong_company_id_says_the_web_api_does_not_offer_the_resources(): void {
		$result = $this->connector()->test( $this->connection( array( 'company' => 'NOPE' ) ) );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'company', $result->error->context['field'] );
	}

	public function test_a_resource_the_installed_version_lacks_is_named_when_connecting(): void {
		$this->server->missingResources = array( 'OE/OECreditDebitNotes' );
		$result                         = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'OE/OECreditDebitNotes', (string) $result->error->context['reason'] );
		$this->assertFalse( $result->permissions['read OE/OECreditDebitNotes'] );
	}

	public function test_a_module_the_user_may_not_open_is_a_missing_permission_not_a_failure(): void {
		$this->server->failNext( 'GET', '#/IC/ICItems$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'Forbidden', 'message' => array( 'value' => 'No access' ) ) ), 403 ) );
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'read IC/ICItems' ), $result->missingPermissions() );
		$this->assertSame( array(), $result->warehouses );
	}

	public function test_a_server_whose_order_lacks_a_reference_property_or_unit_price_fails_the_test_with_the_setting_named(): void {
		$this->server->orderProps = array( 'OrderUniquifier', 'OrderNumber', 'CustomerNumber', 'OrderDate' );
		$this->assertSame( 'reference_property', $this->connector()->test( $this->connection() )->error->context['field'] );

		$this->server->orderProps  = array( 'OrderUniquifier', 'PONumber' );
		$this->server->detailProps = array( 'LineType', 'Item' );
		$this->assertSame( 'order_line[].price_unit', $this->connector()->test( $this->connection() )->error->context['field'] );
	}

	public function test_the_customer_for_web_orders_must_be_named_and_exist(): void {
		$this->assertSame( 'customer_number', $this->connector()->test( $this->connection( array( 'customer_number' => '' ) ) )->error->context['field'] );
		$this->assertSame( 'customer_number', $this->connector()->test( $this->connection( array( 'customer_number' => '9999' ) ) )->error->context['field'] );
		$this->assertTrue( $this->connector()->test( $this->connection( array( 'customer_policy' => 'match_only', 'customer_number' => '' ) ) )->ok );
	}

	public function test_an_address_that_is_not_the_web_api_gets_a_plain_message(): void {
		$this->kit->http->route( 'GET', '#^https://shop\.example\.test/#', FakeHttpClient::status( 200, '<!DOCTYPE html><html><body>Welcome</body></html>' ) );
		$connection = $this->kit->connection( 'sage300', array( 'address' => 'https://shop.example.test/Sage300WebApi/v1.0/-/SAMLTD/OE/OEOrders', 'company' => 'SAMLTD', 'username' => 'U' ), array( 'password' => 'p' ), 5 );
		$result     = $this->connector()->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'address', $result->error->context['field'] );
		$this->assertStringStartsWith( 'https://shop.example.test/Sage300WebApi/v1.0/-/SAMLTD/OE/OEOrders', $this->kit->http->requests[0]['url'], 'The version and company part of a pasted address are cut and rebuilt.' );
	}

	public function test_a_private_or_plain_http_address_is_refused_before_any_request(): void {
		foreach ( array( 'https://10.0.0.5/Sage300WebApi', 'https://localhost/Sage300WebApi', 'http://sage.example.test/Sage300WebApi' ) as $address ) {
			$result = $this->connector()->test( $this->connection( array( 'address' => $address ) ) );
			$this->assertFalse( $result->ok, $address );
		}
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_the_base_address_keeps_the_application_path_and_cuts_the_rest(): void {
		$connection = $this->connection( array( 'address' => 'sage.example.test/Sage300WebApi/v1.0/-/SAMLTD' ) );

		$this->assertSame( 'https://sage.example.test/Sage300WebApi/v1.0/-/SAMLTD', Sage300Site::base( $connection, 'Sage 300' ) );
		$this->assertSame( 'SAMLTD', Sage300Site::company( $connection ) );
	}

	public function test_ping_is_cheap_and_never_throws(): void {
		$connection = $this->connection();
		$this->assertTrue( $this->connector()->ping( $connection ) );
		$this->assertSame( 1, $this->kit->http->count(), 'One cheap call, not the whole Test connection.' );
		$this->assertFalse( $this->connector()->ping( $this->connection( array(), 'wrong-password-0000' ) ) );
	}

	public function test_the_connect_form_names_the_address_the_company_the_user_the_password_and_the_settings(): void {
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );

		$this->assertSame( 'url', $fields['address']->type );
		$this->assertTrue( $fields['company']->required );
		$this->assertFalse( $fields['username']->secret );
		$this->assertTrue( $fields['password']->secret );
		$this->assertSame( array( 'fixed', 'match_only' ), array_keys( $fields['customer_policy']->options ) );
		$this->assertSame( 'advanced', $fields['shipping_charge_code']->group );
		$this->assertArrayNotHasKey( 'redirect_uri', $fields, 'A server login has no redirect address.' );
	}

	public function test_the_flags_say_what_the_connector_really_does(): void {
		$flags = $this->connector()->capabilities();
		foreach ( array( Capability::Orders, Capability::Refunds, Capability::ShadowLookup ) as $on ) {
			$this->assertTrue( $flags[ $on->value ], $on->value );
		}
		foreach ( array( Capability::Customers, Capability::Reconciliation, Capability::StockIn, Capability::TrackingIn, Capability::InvoiceReadback, Capability::PricesIn, Capability::Webhooks ) as $off ) {
			$this->assertFalse( $flags[ $off->value ], $off->value );
		}
		$this->assertSame( array( EntityType::Order, EntityType::Refund ), $this->connector()->supportedEntities() );
	}

	/**
	 * @return array<string,array{0:int,1:array<mixed>|string,2:ErrorCode,3:ErrorClass}>
	 */
	public static function answers(): array {
		$error = static fn( string $code, string $text = 'Something' ): array => array( 'error' => array( 'code' => $code, 'message' => array( 'lang' => 'en', 'value' => $text ) ) );
		return array(
			'unauthorized'      => array( 401, $error( 'Unauthorized' ), ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'forbidden'         => array( 403, $error( 'Forbidden' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'too many'          => array( 429, $error( 'TooManyRequests' ), ErrorCode::Rate001, ErrorClass::Retry ),
			'internal'          => array( 500, $error( 'InternalServerError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'database link'     => array( 500, $error( 'DBLinkError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'session'           => array( 500, $error( 'SessionError' ), ErrorCode::Net002, ErrorClass::Retry ),
			'bad gateway'       => array( 502, '<html>Bad Gateway</html>', ErrorCode::Net002, ErrorClass::Retry ),
			'duplicate'         => array( 409, $error( 'RecordDuplicate', 'Already there' ), ErrorCode::Dup001, ErrorClass::Handled ),
			'resource missing'  => array( 404, $error( 'ResourceNotFound' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'invalid payload'   => array( 400, $error( 'InvalidPayload', 'Bad property' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'invalid parameter' => array( 400, $error( 'InvalidParameters' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'record invalid'    => array( 400, $error( 'RecordInvalid', 'Customer is on hold' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'general'           => array( 500, $error( 'General', 'Something went wrong' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'html page'         => array( 404, '<!DOCTYPE html><html><body>Not Found</body></html>', ErrorCode::Val002, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider answers
	 * @param array<mixed>|string $body Body.
	 */
	public function test_every_answer_of_the_api_has_a_code_and_a_class( int $status, array|string $body, ErrorCode $code, ErrorClass $class ): void {
		$this->server->failNext( 'POST', '#/OE/OEOrders$#', is_array( $body ) ? FakeHttpClient::json( $body, $status ) : FakeHttpClient::status( $status, $body ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->connector()->classifyError( $result->error ) ?? $result->error->errorClass() );
	}

	public function test_a_rate_limit_waits_as_told_or_sixty_seconds_by_code(): void {
		$this->server->failNext( 'POST', '#/OE/OEOrders$#', FakeHttpClient::tooManyRequests( 41 ) );
		$this->assertSame( 41, $this->sendOrder()->error->retryAfter );

		$this->server->failNext( 'POST', '#/OE/OEOrders$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'TooManyRequests', 'message' => array( 'value' => 'flooded' ) ) ), 400 ) );
		$this->assertSame( 60, $this->sendOrder( array(), array(), '101' )->error->retryAfter );
	}

	public function test_a_message_never_carries_the_password_the_user_the_company_or_the_host(): void {
		$text = 'User ' . FakeSage300Server::USER . ' company ' . FakeSage300Server::COMPANY . ' password ' . FakeSage300Server::PASSWORD . ' at sage.example.test';
		$this->server->failNext( 'POST', '#/OE/OEOrders$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'RecordInvalid', 'message' => array( 'value' => $text ) ) ), 400 ) );
		$result = $this->sendOrder();
		$shown  = $result->error->message() . ' ' . $result->error->detail . ' ' . (string) json_encode( $result->error->context );

		$this->assertFalse( $result->isSuccess() );
		foreach ( array( FakeSage300Server::PASSWORD, FakeSage300Server::USER, FakeSage300Server::COMPANY, 'sage.example.test' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $shown );
		}
	}
}
