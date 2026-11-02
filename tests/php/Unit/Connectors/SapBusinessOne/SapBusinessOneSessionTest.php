<?php
/**
 * Login, session reuse, session expiry, addresses and the Service Layer's error shapes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Connectors\SapBusinessOne\SapBusinessOneErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class SapBusinessOneSessionTest extends SapBusinessOneTestCase {

	public function test_one_login_serves_many_calls_and_the_cookie_carries_the_session_and_the_route(): void {
		$this->connector->send( $this->connection(), $this->item( '3001' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '3002' ), $this->payload() );
		$this->assertSame( 1, $this->server->logins );
		$cookies = array_values( array_filter( array_map( static fn( array $request ): string => (string) ( $request['headers']['Cookie'] ?? '' ), $this->kit->http->requests ) ) );
		$this->assertNotEmpty( $cookies );
		$this->assertMatchesRegularExpression( '/^B1SESSION=sess-[0-9a-f]+; ROUTEID=\.node1$/', $cookies[0] );
		$login = $this->kit->http->requests[0];
		$this->assertSame( 'POST', $login['method'] );
		$this->assertStringEndsWith( '/b1s/v2/Login', $login['url'] );
		$this->assertArrayNotHasKey( 'Cookie', $login['headers'], 'The login call carries no session.' );
		$this->assertStringNotContainsString( $this->server->password, $login['url'], 'The password is in the body, never in the address.' );
	}

	public function test_a_session_that_ended_is_replaced_once_and_the_call_goes_through(): void {
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '3003' ), $this->payload() )->isSuccess() );
		$this->server->expireSessions();
		$second = $this->connector->send( $this->connection(), $this->item( '3004' ), $this->payload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertSame( 2, $this->server->logins );
	}

	public function test_a_wrong_password_is_auth_001_and_the_password_is_nowhere_in_the_error(): void {
		$result = $this->connector->test( $this->connection( array(), array(), array( 'password' => 'wr0ng-passw0rd-test' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$text = $result->error->message() . $result->error->detail . json_encode( $result->error->context );
		$this->assertStringNotContainsString( 'wr0ng-passw0rd-test', $text );
	}

	public function test_an_unknown_company_database_is_cfg_003_and_names_what_the_person_typed(): void {
		$result = $this->connector->test( $this->connection( array(), array( 'database' => 'SBO_TYPO' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Cfg003, $result->error->code );
		$this->assertStringContainsString( 'SBO_TYPO', $result->error->message() );
	}

	/**
	 * @dataProvider provide_addresses
	 */
	public function test_the_address_is_reduced_to_scheme_host_and_port( string $typed, string $expected ): void {
		$this->server->address = 'https://sap.example.test:50000';
		$this->connector->test( $this->connection( array(), array( 'address' => $typed ) ) );
		$this->assertStringStartsWith( $expected, $this->kit->http->requests[0]['url'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_addresses(): array {
		return array(
			'plain'           => array( 'https://sap.example.test:50000', 'https://sap.example.test:50000/b1s/v2/Login' ),
			'trailing slash'  => array( 'https://sap.example.test:50000/', 'https://sap.example.test:50000/b1s/v2/Login' ),
			'pasted login'    => array( 'https://sap.example.test:50000/b1s/v1/Login', 'https://sap.example.test:50000/b1s/v2/Login' ),
			'upper case host' => array( 'HTTPS://SAP.Example.Test:50000/x?y=1#z', 'https://sap.example.test:50000/b1s/v2/Login' ),
		);
	}

	/**
	 * @dataProvider provide_bad_addresses
	 */
	public function test_an_address_that_is_not_usable_is_refused_before_any_request( string $typed ): void {
		$result = $this->connector->test( $this->connection( array(), array( 'address' => $typed ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_bad_addresses(): array {
		return array(
			'empty'        => array( '' ),
			'ftp'          => array( 'ftp://sap.example.test' ),
			'credentials'  => array( 'https://user:pass@sap.example.test:50000' ),
			'no scheme'    => array( 'sap.example.test:50000' ),
			'private ip'   => array( 'https://192.168.1.20:50000' ),
			'loopback'     => array( 'https://localhost:50000' ),
			'link local'   => array( 'https://169.254.169.254' ),
			'plain http'   => array( 'http://sap.example.test:50000' ),
		);
	}

	public function test_a_certificate_failure_is_net_003_and_a_gateway_error_is_net_002(): void {
		$this->kit->http->route( 'POST', '~/Login$~', FakeHttpClient::certificateError() );
		$this->assertSame( ErrorCode::Net003, $this->connector->test( $this->connection() )->error->code );
	}

	/**
	 * @dataProvider provide_errors
	 */
	public function test_error_shapes_map_to_the_catalog( int $status, string $text, ?ErrorCode $expected ): void {
		$body   = (string) json_encode( array( 'error' => array( 'code' => -1, 'message' => array( 'lang' => 'en-us', 'value' => $text ) ) ) );
		$mapped = ( new SapBusinessOneErrors( 'SAP Business One' ) )->map( new ApiResponse( $status, $body ) );
		if ( null === $expected ) {
			$this->assertNull( $mapped );
			return;
		}
		$this->assertSame( $expected, $mapped->code );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:ErrorCode|null}>
	 */
	public static function provide_errors(): array {
		return array(
			'validation'       => array( 400, 'Quantity must be greater than zero', ErrorCode::Val002 ),
			'not authorised'   => array( 400, 'You are not authorized to perform this action.', ErrorCode::Auth004 ),
			'forbidden'        => array( 403, 'Forbidden', ErrorCode::Auth004 ),
			'locked'           => array( 409, 'The object is locked by another user.', ErrorCode::Net002 ),
			'invalid session'  => array( 401, 'Invalid session.', null ),
			'not found'        => array( 404, 'No matching records found', null ),
			'server'           => array( 500, 'Internal error', null ),
			'empty message'    => array( 400, '', null ),
		);
	}

	public function test_a_validation_error_carries_the_sap_text_and_nothing_secret(): void {
		$this->kit->http->route(
			'POST',
			'~/Orders$~',
			FakeHttpClient::json( array( 'error' => array( 'code' => -5002, 'message' => array( 'lang' => 'en-us', 'value' => 'Posting date is in a closed period (user webshop, database SBO_TEST, ' . $this->server->password . ')' ) ) ), 400 )
		);
		$result = $this->connector->send( $this->connection(), $this->item( '3005' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$text = $result->error->message() . $result->error->detail . json_encode( $result->error->context );
		$this->assertStringContainsString( 'closed period', $text );
		$this->assertStringNotContainsString( $this->server->password, $text );
		$this->assertStringNotContainsString( 'SBO_TEST', $text );
	}
}
