<?php
/**
 * Version detection.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\OdooAddress;
use Estasoft\SyncEngine\Connectors\Odoo\OdooErrorMapper;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Connectors\Odoo\OdooVersionDetector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;
use PHPUnit\Framework\TestCase;

final class OdooVersionDetectorTest extends TestCase {

	private function detector( \Estasoft\SyncEngine\Contract\HttpClientInterface $http ): OdooVersionDetector {
		return new OdooVersionDetector( $http, new OdooErrorMapper() );
	}

	private function address(): OdooAddress {
		return new OdooAddress( 'https://erp.example.com', 'erp.example.com' );
	}

	public function test_odoo_19_answers_the_web_version_route_and_nothing_else_is_asked(): void {
		$server  = new FakeOdooServer( 19 );
		$version = $this->detector( $server )->detect( new OdooAddress( $server->baseUrl(), $server->host ), 'testdb' );
		$this->assertSame( 19, $version->major );
		$this->assertSame( 'json2', $version->transportId() );
		$this->assertCount( 1, $server->requests );
		$this->assertSame( 'GET', $server->requests[0]['method'] );
		$this->assertSame( 'https://erp.example.com/web/version', $server->requests[0]['url'] );
		$this->assertSame( 'testdb', $server->requests[0]['headers']['X-Odoo-Database'] );
	}

	public function test_odoo_17_and_18_have_no_web_version_route_so_the_common_service_is_asked(): void {
		foreach ( array( 17, 18 ) as $major ) {
			$server  = new FakeOdooServer( $major );
			$version = $this->detector( $server )->detect( new OdooAddress( $server->baseUrl(), $server->host ), 'testdb' );
			$this->assertSame( $major, $version->major );
			$this->assertSame( 'jsonrpc', $version->transportId() );
			$this->assertCount( 2, $server->requests );
			$this->assertSame( 'https://erp.example.com/jsonrpc', $server->requests[1]['url'] );
			$body = json_decode( (string) $server->requests[1]['body'], true );
			$this->assertSame( 'common', $body['params']['service'] );
			$this->assertSame( 'version', $body['params']['method'] );
			$this->assertSame( array(), $body['params']['args'] );
			$this->assertSame( array( 'common.version' ), $server->services, 'The database service is never called.' );
		}
	}

	public function test_an_odoo_online_release_before_19_uses_json_rpc(): void {
		$server  = new FakeOdooServer( 18, 'erp.example.com', 'testdb', 'sync@example.com', 'k', array(), 4 );
		$version = $this->detector( $server )->detect( new OdooAddress( $server->baseUrl(), $server->host ), 'testdb' );
		$this->assertTrue( $version->saas );
		$this->assertSame( 'jsonrpc', $version->transportId() );
	}

	public function test_odoo_16_is_refused_with_a_clear_error(): void {
		$http = ( new FakeHttpClient() )
			->queue( FakeHttpClient::status( 404 ) )
			->queue( FakeHttpClient::json( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => array( 'server_version' => '16.0', 'server_version_info' => array( 16, 0, 0, 'final', 0 ), 'protocol_version' => 1 ) ) ) );
		try {
			$this->detector( $http )->detect( $this->address(), 'testdb' );
			$this->fail( 'Expected an unsupported version.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Val002, $exception->error->code );
			$this->assertStringContainsString( 'Odoo 17 or later', $exception->error->message() );
		}
	}

	public function test_an_unreachable_server_stops_at_the_first_request(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::timeout() );
		try {
			$this->detector( $http )->detect( $this->address(), 'testdb' );
			$this->fail( 'Expected a time-out.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Net001, $exception->error->code );
			$this->assertSame( 1, $http->count() );
		}
	}

	public function test_a_certificate_error_is_net_003(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::certificateError() );
		$this->expectExceptionObject( new OdooException( ( new OdooErrorMapper() )->transportFailure( FakeHttpClient::certificateError() )->error ) );
		$this->detector( $http )->detect( $this->address(), 'testdb' );
	}

	public function test_a_429_on_the_version_route_is_reported_with_its_wait(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::tooManyRequests( 20 ) );
		try {
			$this->detector( $http )->detect( $this->address(), 'testdb' );
			$this->fail( 'Expected a rate limit.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Rate001, $exception->error->code );
			$this->assertSame( 20, $exception->error->retryAfter );
		}
	}

	public function test_a_site_that_is_not_odoo_is_net_001(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::status( 404, 'Not found' ) )->queue( FakeHttpClient::status( 404, 'Not found' ) );
		try {
			$this->detector( $http )->detect( $this->address(), '' );
			$this->fail( 'Expected an error.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Net001, $exception->error->code );
		}
	}

	public function test_a_page_with_status_200_that_is_not_a_version_answer_is_net_001(): void {
		$http = ( new FakeHttpClient() )->queue( new HttpResponse( 200, '<html>Welcome</html>' ) )->queue( new HttpResponse( 200, '<html>Welcome</html>' ) );
		$this->expectException( OdooException::class );
		$this->detector( $http )->detect( $this->address(), 'testdb' );
	}

	public function test_a_future_odoo_without_the_old_endpoint_still_answers_through_web_version(): void {
		$server                 = new FakeOdooServer( 19 );
		$server->jsonrpcRemoved = true;
		$this->assertSame( 19, $this->detector( $server )->detect( new OdooAddress( $server->baseUrl(), $server->host ), 'testdb' )->major );
	}

	public function test_no_database_header_is_sent_when_none_is_configured(): void {
		$server = new FakeOdooServer( 19 );
		$this->detector( $server )->detect( new OdooAddress( $server->baseUrl(), $server->host ), '' );
		$this->assertArrayNotHasKey( 'X-Odoo-Database', $server->requests[0]['headers'] );
	}

	public function test_responds_is_true_for_a_live_server_and_for_an_old_one(): void {
		$server = new FakeOdooServer( 18 );
		$this->assertTrue( $this->detector( $server )->responds( new OdooAddress( $server->baseUrl(), $server->host ), 'testdb' ) );

		$old = ( new FakeHttpClient() )->queue( FakeHttpClient::status( 404 ) )->queue( FakeHttpClient::json( array( 'result' => array( 'server_version' => '15.0', 'server_version_info' => array( 15, 0, 0, 'final', 0 ) ) ) ) );
		$this->assertTrue( $this->detector( $old )->responds( $this->address(), 'testdb' ), 'An old Odoo is up; it is just not supported.' );
	}

	public function test_responds_is_false_when_nothing_answers(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::timeout() );
		$this->assertFalse( $this->detector( $http )->responds( $this->address(), 'testdb' ) );
	}
}
