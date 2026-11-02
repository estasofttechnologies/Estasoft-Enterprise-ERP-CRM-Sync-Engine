<?php
/**
 * Replays the wire-format fixtures in tests/php/fixtures/odoo through the version detector and the transports.
 *
 * The fixtures follow Odoo's own source (see docs/connectors/odoo.md for the files each one was taken from); they are not
 * recordings of a live server.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\Json2Transport;
use Estasoft\SyncEngine\Connectors\Odoo\JsonRpcTransport;
use Estasoft\SyncEngine\Connectors\Odoo\OdooAddress;
use Estasoft\SyncEngine\Connectors\Odoo\OdooErrorMapper;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Connectors\Odoo\OdooVersionDetector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OdooWireFixturesTest extends TestCase {

	private const KEY   = 'sk-secret-key-12345';
	private const LOGIN = 'sync@example.com';

	/**
	 * Raw text of a fixture.
	 *
	 * @param string $name Path below tests/php/fixtures/odoo.
	 */
	private function raw( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/fixtures/odoo/' . $name;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * Decoded JSON of a fixture.
	 *
	 * @param string $name Path below tests/php/fixtures/odoo.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$data = json_decode( $this->raw( $name ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );
		return $data;
	}

	private function address(): OdooAddress {
		return new OdooAddress( 'https://erp.example.com', 'erp.example.com' );
	}

	private function mapper(): OdooErrorMapper {
		return new OdooErrorMapper( array( self::KEY, self::LOGIN ) );
	}

	/**
	 * A JSON-RPC transport that is already signed in, answering one call with a fixture.
	 *
	 * @param string $fixture Fixture with the answer to the call.
	 */
	private function jsonRpcAnswering( string $fixture ): JsonRpcTransport {
		$http = ( new FakeHttpClient() )
			->queue( FakeHttpClient::json( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => 2 ) ) )
			->queue( FakeHttpClient::json( $this->fixture( $fixture ) ) );
		return new JsonRpcTransport( $http, $this->mapper(), $this->address(), 'testdb', self::LOGIN, self::KEY );
	}

	/**
	 * A JSON-2 transport answering one call with a fixture and a status.
	 *
	 * @param string $fixture Fixture with the error body.
	 * @param int    $status  HTTP status.
	 */
	private function json2Answering( string $fixture, int $status ): Json2Transport {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( $this->fixture( $fixture ), $status ) );
		return new Json2Transport( $http, $this->mapper(), $this->address(), 'testdb', self::KEY );
	}

	public function test_the_odoo_19_web_version_answer_selects_json_2(): void {
		$http    = ( new FakeHttpClient() )->queue( new HttpResponse( 200, $this->raw( 'version/web-version-19.json' ), array( 'content-type' => 'application/json; charset=utf-8' ) ) );
		$version = ( new OdooVersionDetector( $http, $this->mapper() ) )->detect( $this->address(), 'testdb' );
		$this->assertSame( 19, $version->major );
		$this->assertSame( 'json2', $version->transportId() );
		$this->assertCount( 1, $http->requests );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function commonVersionFixtures(): array {
		return array(
			'Odoo 17' => array( 'version/common-version-17.json', 17 ),
			'Odoo 18' => array( 'version/common-version-18.json', 18 ),
		);
	}

	/**
	 * @dataProvider commonVersionFixtures
	 *
	 * @param string $fixture Fixture name.
	 * @param int    $major   Expected major version.
	 */
	public function test_the_common_service_answer_of_odoo_17_and_18_selects_json_rpc( string $fixture, int $major ): void {
		$http    = ( new FakeHttpClient() )
			->queue( FakeHttpClient::status( 404 ) )
			->queue( FakeHttpClient::json( $this->fixture( $fixture ) ) );
		$version = ( new OdooVersionDetector( $http, $this->mapper() ) )->detect( $this->address(), 'testdb' );
		$this->assertSame( $major, $version->major );
		$this->assertSame( 'jsonrpc', $version->transportId() );
	}

	/**
	 * Faults that Odoo 17 and 18 return with HTTP 200 and the error under "error.data".
	 *
	 * @return array<string,array{0:string,1:ErrorCode,2:ErrorClass}>
	 */
	public static function jsonRpcFaults(): array {
		return array(
			'access error'     => array( 'faults/jsonrpc-access-error.json', ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'user error'       => array( 'faults/jsonrpc-user-error.json', ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'validation error' => array( 'faults/jsonrpc-validation-error.json', ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'missing record'   => array( 'faults/jsonrpc-missing-error.json', ErrorCode::Val002, ErrorClass::Retry ),
			'unknown model'    => array( 'faults/jsonrpc-unknown-model.json', ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'session expired'  => array( 'faults/jsonrpc-session-expired.json', ErrorCode::Auth002, ErrorClass::NeedsPerson ),
			'database missing' => array( 'faults/jsonrpc-database-missing.json', ErrorCode::Cfg003, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider jsonRpcFaults
	 *
	 * @param string     $fixture Fixture name.
	 * @param ErrorCode  $code    Expected code.
	 * @param ErrorClass $class   Expected class.
	 */
	public function test_json_rpc_faults_are_mapped( string $fixture, ErrorCode $code, ErrorClass $class ): void {
		try {
			$this->jsonRpcAnswering( $fixture )->searchRead( 'sale.order', array(), array( 'name' ) );
			$this->fail( 'Expected a fault.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( $code, $exception->error->code, $fixture );
			$this->assertSame( $class, $exception->error->errorClass(), $fixture );
		}
	}

	/**
	 * Errors that Odoo 19 returns with a 4xx status and the error object as the whole body.
	 *
	 * @return array<string,array{0:string,1:int,2:ErrorCode,3:ErrorClass}>
	 */
	public static function json2Errors(): array {
		return array(
			'invalid api key' => array( 'faults/json2-401-invalid-apikey.json', 401, ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'access denied'   => array( 'faults/json2-403-access-denied.json', 403, ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'access error'    => array( 'faults/json2-403-access-error.json', 403, ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'unknown model'   => array( 'faults/json2-404-unknown-model.json', 404, ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'record locked'   => array( 'faults/json2-409-lock-error.json', 409, ErrorCode::Net002, ErrorClass::Retry ),
			'refused data'    => array( 'faults/json2-422-validation-error.json', 422, ErrorCode::Val002, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider json2Errors
	 *
	 * @param string     $fixture Fixture name.
	 * @param int        $status  HTTP status.
	 * @param ErrorCode  $code    Expected code.
	 * @param ErrorClass $class   Expected class.
	 */
	public function test_json_2_errors_are_mapped( string $fixture, int $status, ErrorCode $code, ErrorClass $class ): void {
		try {
			$this->json2Answering( $fixture, $status )->searchRead( 'sale.order', array(), array( 'name' ) );
			$this->fail( 'Expected an error.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( $code, $exception->error->code, $fixture );
			$this->assertSame( $class, $exception->error->errorClass(), $fixture );
		}
	}

	public function test_the_page_odoo_19_shows_for_an_unknown_database_is_cfg_003(): void {
		$http = ( new FakeHttpClient() )->queue( new HttpResponse( 404, $this->raw( 'faults/json2-404-no-database.html' ), array( 'content-type' => 'text/html; charset=utf-8' ) ) );
		try {
			( new Json2Transport( $http, $this->mapper(), $this->address(), 'nosuchdb', self::KEY ) )->searchRead( 'sale.order', array(), array( 'name' ) );
			$this->fail( 'Expected an error.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
		}
	}

	public function test_no_fixture_holds_the_test_secrets(): void {
		$paths = array_merge( glob( dirname( __DIR__, 3 ) . '/fixtures/odoo/*/*' ) ?: array(), glob( dirname( __DIR__, 3 ) . '/fixtures/odoo/*' ) ?: array() );
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$this->assertStringNotContainsString( self::KEY, (string) file_get_contents( $path ), $path );
				$this->assertStringNotContainsString( self::LOGIN, (string) file_get_contents( $path ), $path );
			}
		}
		$this->assertNotEmpty( $paths );
	}
}
