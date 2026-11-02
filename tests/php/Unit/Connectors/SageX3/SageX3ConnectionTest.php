<?php
/**
 * Test connection, the connect form and the error mapping of the Sage X3 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageX3;

use Estasoft\SyncEngine\Connectors\SageX3\SageX3Settings;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSageX3Server;

final class SageX3ConnectionTest extends SageX3TestCase {

	public function test_the_test_proves_the_class_the_properties_and_the_customer_and_writes_nothing(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertSame( 'Sage X3 REST web services', $result->version );
		$this->assertSame( 'SEED', $result->company );
		$this->assertSame( 'SORDER', $result->detected['order_class'] );
		$this->assertSame( 'SOH', $result->detected['order_representation'] );
		$this->assertTrue( $result->detected['prototype_read'] );
		$this->assertTrue( $result->detected['customer_checked'] );
		$this->assertTrue( $result->detected['write_not_probed'] );
		$this->assertSame( 0, $this->server->count( 'POST', '#.*#' ), 'Test connection writes nothing.' );
		$template = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => str_ends_with( (string) $call['path'], '/$template' ) ) );
		$this->assertSame( 'SOH.$create', $template[0]['query']['representation'] );
	}

	public function test_a_class_the_folder_does_not_expose_is_named(): void {
		unset( $this->server->classes['SORDER'] );
		$result = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_class', $result->error->context['field'] );
		$this->assertStringContainsString( 'SORDER', (string) $result->error->context['reason'] );
	}

	public function test_a_representation_that_is_not_the_classes_is_named(): void {
		$result = $this->connector()->test( $this->connection( array( 'order_representation' => 'ZZZ' ) ) );

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'ZZZ', (string) $result->error->context['reason'] );
	}

	public function test_missing_properties_are_listed_by_setting_name_when_connecting(): void {
		$this->server->orderProps = array( 'SOHNUM', 'BPCORD', 'ORDDAT', 'SORDERQ' );
		$result                   = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'order_representation', $result->error->context['field'] );
		$this->assertStringContainsString( 'prop_reference (CUSORDREF)', (string) $result->error->context['reason'] );
		$this->assertStringContainsString( 'prop_currency (CUR)', (string) $result->error->context['reason'] );
	}

	public function test_missing_line_properties_are_listed_too(): void {
		$this->server->lineProps = array( 'ITMREF', 'QTY' );
		$result                  = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'prop_line_price (GROPRI)', (string) $result->error->context['reason'] );
	}

	public function test_a_folder_that_does_not_describe_its_representation_still_connects_and_says_so(): void {
		$this->server->orderProps = null;
		$result                   = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertFalse( $result->detected['prototype_read'] );
	}

	public function test_the_customer_code_must_be_named_and_exist(): void {
		$this->assertSame( 'customer_code', $this->connector()->test( $this->connection( array( 'customer_code' => '' ) ) )->error->context['field'] );
		$this->assertSame( 'customer_code', $this->connector()->test( $this->connection( array( 'customer_code' => 'NOBODY' ) ) )->error->context['field'] );
		$this->assertTrue( $this->connector()->test( $this->connection( array( 'customer_class' => 'none' ) ) )->ok );
	}

	public function test_the_folder_is_required(): void {
		$result = $this->connector()->test( $this->connection( array( 'folder' => '' ) ) );

		$this->assertSame( 'folder', $result->error->context['field'] );
	}

	public function test_a_wrong_password_is_auth_001_and_is_not_repeated_in_the_message(): void {
		$result = $this->connector()->test( $this->connection( array(), 'wrong-password-0000' ) );

		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wrong-password-0000', $result->error->message() . $result->error->detail );
	}

	public function test_a_login_page_instead_of_json_means_basic_authentication_is_not_enabled(): void {
		$this->server->loginPage = true;
		$result                  = $this->connector()->test( $this->connection() );

		$this->assertSame( ErrorCode::Auth001, $result->error->code );
	}

	public function test_a_connection_without_a_password_is_auth_001_before_any_request(): void {
		$connection = $this->kit->connection( 'sagex3', array( 'address' => FakeSageX3Server::ADDRESS, 'folder' => 'SEED', 'username' => 'u', 'customer_code' => 'WEB001' ), array(), 9 );

		$this->assertSame( ErrorCode::Auth001, $this->connector()->test( $connection )->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_private_or_plain_http_address_is_refused_before_any_request(): void {
		foreach ( array( 'https://10.0.0.5:8443', 'https://localhost:8443', 'http://x3.example.test:8443' ) as $address ) {
			$this->assertFalse( $this->connector()->test( $this->connection( array( 'address' => $address ) ) )->ok, $address );
		}
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_the_base_address_is_rebuilt_from_a_pasted_one(): void {
		$connection = $this->connection( array( 'address' => FakeSageX3Server::ADDRESS . '/api1/x3/erp/OTHER/SORDER' ) );
		$this->connector()->test( $connection );

		$this->assertSame( FakeSageX3Server::ADDRESS . '/api1/x3/erp/SEED/SORDER/$template?representation=SOH.%24create', $this->kit->http->requests[0]['url'] );
		$this->assertSame( FakeSageX3Server::ADDRESS . '/api1/x3/erp/SEED', \Estasoft\SyncEngine\Connectors\SageX3\SageX3Settings::base( $connection, 'Sage X3' ) );
	}

	public function test_ping_is_cheap_and_never_throws(): void {
		$this->assertTrue( $this->connector()->ping( $this->connection() ) );
		$this->assertFalse( $this->connector()->ping( $this->connection( array(), 'wrong-password-0000' ) ) );
	}

	public function test_the_connect_form_names_the_address_the_folder_the_user_the_password_the_customer_and_the_settings(): void {
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );

		$this->assertSame( 'url', $fields['address']->type );
		$this->assertTrue( $fields['folder']->required );
		$this->assertTrue( $fields['password']->secret );
		$this->assertTrue( $fields['customer_code']->required );
		$this->assertSame( 'advanced', $fields['order_class']->group );
		$this->assertStringContainsString( 'SORDER', $fields['order_class']->hint );
		$this->assertStringContainsString( 'none', $fields['item_class']->hint );
		foreach ( array_keys( SageX3Settings::DEFAULTS ) as $name ) {
			$this->assertArrayHasKey( $name, $fields, 'Every setting has a field: ' . $name );
		}
		$this->assertArrayNotHasKey( 'redirect_uri', $fields );
	}

	public function test_the_flags_say_what_the_connector_really_does(): void {
		$flags = $this->connector()->capabilities();
		$this->assertTrue( $flags[ Capability::Orders->value ] );
		foreach ( Capability::cases() as $capability ) {
			if ( Capability::Orders !== $capability ) {
				$this->assertFalse( $flags[ $capability->value ], $capability->value );
			}
		}
		$this->assertSame( array( EntityType::Order ), $this->connector()->supportedEntities() );
	}

	public function test_products_are_found_by_item_code_through_the_item_class(): void {
		$ref = $this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' );

		$this->assertSame( 'CS-MUG-01', $ref->id );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), 'NO-SUCH' ) );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection( array( 'item_class' => 'none' ) ), 'CS-MUG-01' ) );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( $r ): string => $r->id, $this->connector()->searchProducts( $this->connection(), 'CS-MUG-01' ) ) );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'a@example.test' ) );
	}

	/**
	 * @return array<string,array{0:int,1:array<mixed>|string,2:ErrorCode,3:ErrorClass}>
	 */
	public static function answers(): array {
		$diagnosis = static fn( string $text ): array => array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => $text ) ) );
		return array(
			'unauthorized' => array( 401, '', ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'forbidden'    => array( 403, $diagnosis( 'No right' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'busy'         => array( 503, '<html>Service Unavailable</html>', ErrorCode::Net002, ErrorClass::Retry ),
			'server fault' => array( 500, $diagnosis( 'Syracuse error' ), ErrorCode::Net002, ErrorClass::Retry ),
			'not found'    => array( 404, $diagnosis( 'The class does not exist' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'bad request'  => array( 400, $diagnosis( 'Bad body <b>here</b>' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'html page'    => array( 400, '<!DOCTYPE html><html><body>Error</body></html>', ErrorCode::Val002, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider answers
	 * @param array<mixed>|string $body Body.
	 */
	public function test_every_answer_of_the_service_has_a_code_and_a_class( int $status, array|string $body, ErrorCode $code, ErrorClass $class ): void {
		$this->server->failNext( 'POST', '#/SORDER$#', is_array( $body ) ? FakeHttpClient::json( $body, $status ) : FakeHttpClient::status( $status, $body ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->connector()->classifyError( $result->error ) ?? $result->error->errorClass() );
	}

	public function test_the_reason_is_the_first_diagnosis_without_tags(): void {
		$this->server->failNext( 'POST', '#/SORDER$#', FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'Customer <b>WEB001</b> is blocked' ) ) ), 400 ) );
		$result = $this->sendOrder();

		$this->assertSame( 'Customer WEB001 is blocked', $result->error->context['reason'] );
	}

	public function test_a_rate_limit_waits_as_told(): void {
		$this->server->failNext( 'POST', '#/SORDER$#', FakeHttpClient::tooManyRequests( 41 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 41, $result->error->retryAfter );
	}

	public function test_a_message_never_carries_the_password_the_user_or_the_folder(): void {
		$text = 'User ' . FakeSageX3Server::USER . ' folder ' . FakeSageX3Server::FOLDER . ' password ' . FakeSageX3Server::PASSWORD;
		$this->server->failNext( 'POST', '#/SORDER$#', FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => $text ) ) ), 400 ) );
		$result = $this->sendOrder();
		$shown  = $result->error->message() . ' ' . $result->error->detail . ' ' . (string) json_encode( $result->error->context );

		$this->assertFalse( $result->isSuccess() );
		foreach ( array( FakeSageX3Server::PASSWORD, FakeSageX3Server::USER, FakeSageX3Server::FOLDER, 'x3.example.test' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $shown );
		}
	}
}
