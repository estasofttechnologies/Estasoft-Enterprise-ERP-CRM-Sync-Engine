<?php
/**
 * The connector against wire shapes kept as files, with no fake Service Layer behind it: an unrouted request fails the test.
 *
 * The files in tests/php/fixtures/sap-b1 are synthetic. Their shape follows the pages listed in docs/connectors/sap-b1.md (the Login answer
 * with `SessionId`, the 401 "Invalid session or session already timeout." body, OData v4 `value` pages); the values are made up.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Connectors\SapBusinessOne\SapBusinessOneConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\ArraySessionStore;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SapBusinessOneWireFixtureTest extends TestCase {

	private ApiTestKit $kit;

	private GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->connector = GuardedConnector::wrap( new SapBusinessOneConnector( $this->kit->deps, new ArraySessionStore() ), $this->kit->tokens );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A fixture file, raw.
	 *
	 * @param string $name File name.
	 */
	private function raw( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/sap-b1/' . $name );
	}

	private function connection(): Connection {
		return new Connection(
			1,
			'sap-b1',
			ConnectorType::Erp,
			'SAP Business One',
			ConnectionStatus::Active,
			array(
				'address'     => 'https://fixture.example.test:50000',
				'database'    => 'SBO_FIXTURE',
				'user'        => 'manager',
				'api_version' => 'v2',
			),
			array(),
			array( 'password' => 'fixture-password-not-real' )
		);
	}

	public function test_the_login_answer_gives_the_session_cookie_and_the_items_page_is_read(): void {
		$this->kit->http->route( 'POST', '#/b1s/v2/Login$#', new HttpResponse( 200, $this->raw( 'login-ok.json' ), array( 'set-cookie' => 'B1SESSION=fixture-session-id; path=/; HttpOnly, ROUTEID=.node2; path=/b1s' ) ) );
		$this->kit->http->route( 'GET', '#/b1s/v2/Items\?#', new HttpResponse( 200, $this->raw( 'items-page.json' ) ) );
		$found = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( 'CS-MUG-01', $found->id );
		$read = $this->kit->http->matching( '#/b1s/v2/Items\?#' )[0];
		$this->assertSame( 'B1SESSION=fixture-session-id; ROUTEID=.node2', $read['headers']['Cookie'] );
		$login = json_decode( (string) $this->kit->http->matching( '#/Login$#' )[0]['body'], true );
		$this->assertSame( array( 'CompanyDB' => 'SBO_FIXTURE', 'UserName' => 'manager', 'Password' => 'fixture-password-not-real' ), $login );
	}

	public function test_the_documented_401_body_makes_the_connector_log_in_again_once_and_repeat_the_call(): void {
		$logins = 0;
		$this->kit->http->route(
			'POST',
			'#/b1s/v2/Login$#',
			function () use ( &$logins ): HttpResponse {
				++$logins;
				return new HttpResponse( 200, $this->raw( 'login-ok.json' ), array( 'set-cookie' => 'B1SESSION=fixture-session-' . $logins . '; path=/' ) );
			}
		);
		$reads = 0;
		$this->kit->http->route(
			'GET',
			'#/b1s/v2/Items\?#',
			function () use ( &$reads ): HttpResponse {
				++$reads;
				return 1 === $reads ? new HttpResponse( 401, $this->raw( 'invalid-session-401.json' ) ) : new HttpResponse( 200, $this->raw( 'items-page.json' ) );
			}
		);
		$this->assertSame( 'CS-MUG-01', $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' )->id );
		$this->assertSame( 2, $logins );
		$this->assertSame( 2, $reads );
	}

	public function test_an_error_body_reaches_the_person_with_the_systems_own_words(): void {
		$this->kit->http->route( 'POST', '#/b1s/v2/Login$#', new HttpResponse( 200, $this->raw( 'login-ok.json' ), array( 'set-cookie' => 'B1SESSION=fixture-session-id; path=/' ) ) );
		$this->kit->http->route( 'GET', '#/b1s/v2/Items\?#', new HttpResponse( 400, $this->raw( 'error-400.json' ) ) );
		$result = $this->connector->searchProducts( $this->connection(), 'zzz' );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ErrorCode::Val002, $result->code );
		$this->assertStringContainsString( "Item no. 'ZZZ' is not found", json_encode( $result->context ) );
	}
}
