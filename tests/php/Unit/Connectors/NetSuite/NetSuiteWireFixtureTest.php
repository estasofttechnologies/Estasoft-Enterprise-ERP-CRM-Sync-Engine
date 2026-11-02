<?php
/**
 * The connector against wire shapes kept as files, with no fake account behind it: an unrouted request fails the test.
 *
 * The files in tests/php/fixtures/netsuite are synthetic. Their shape follows the NetSuite pages listed in docs/connectors/netsuite.md (the
 * token answer, the SuiteQL page with `items`, `hasMore` and `totalResults`, the REST error body with `o:errorDetails`); the values are made up.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Connectors\NetSuite\NetSuiteConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class NetSuiteWireFixtureTest extends TestCase {

	private ApiTestKit $kit;

	private GuardedConnector $connector;

	private string $privateKey = '';

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->connector = GuardedConnector::wrap( new NetSuiteConnector( $this->kit->deps ), $this->kit->tokens );
		$key             = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		openssl_pkey_export( $key, $this->privateKey );
		$this->kit->http->route( 'POST', '#^https://1234567\.suitetalk\.api\.netsuite\.com/services/rest/auth/oauth2/v1/token$#', FakeHttpClient::json( $this->fixture( 'token.json' ) ) );
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
		return json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/netsuite/' . $name ), true );
	}

	private function connection(): Connection {
		return $this->kit->connection(
			'netsuite',
			array(
				'account_id'     => '1234567',
				'certificate_id' => 'fixture-cert',
				'algorithm'      => 'ES256',
				'client_id'      => 'fixture-client',
			),
			array( 'private_key' => $this->privateKey )
		);
	}

	public function test_a_suiteql_page_is_read_and_the_token_answer_is_used_as_a_bearer_header(): void {
		$this->kit->http->route( 'POST', '#/services/rest/query/v1/suiteql#', FakeHttpClient::json( $this->fixture( 'suiteql-items-page.json' ) ) );
		$found = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( '501', $found->id );
		$query = $this->kit->http->matching( '#/suiteql#' )[0];
		$this->assertSame( 'Bearer fixture-access-token-not-real', $query['headers']['Authorization'] );
		$this->assertSame( 'transient', $query['headers']['Prefer'] );
		$token = $this->kit->http->matching( '#oauth2/v1/token#' )[0];
		$this->assertStringContainsString( 'grant_type=client_credentials', (string) $token['body'] );
		$this->assertStringContainsString( 'client_assertion_type=', (string) $token['body'] );
	}

	public function test_the_documented_error_body_gives_the_path_and_the_detail(): void {
		$this->kit->http->route( 'POST', '#/services/rest/query/v1/suiteql#', FakeHttpClient::json( $this->fixture( 'error-400.json' ), 400 ) );
		$result = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$context = json_encode( $result->context );
		$this->assertStringContainsString( 'entity', $context );
		$this->assertStringContainsString( 'Invalid value for property entity.', $context );
	}

	public function test_the_concurrency_limit_body_is_throttling_with_the_default_wait(): void {
		$this->kit->http->route( 'POST', '#/services/rest/query/v1/suiteql#', FakeHttpClient::json( $this->fixture( 'error-concurrency-429.json' ), 429 ) );
		$result = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( ErrorCode::Rate001, $result->code );
		$this->assertSame( 10, $result->retryAfter );
	}
}
