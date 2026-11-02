<?php
/**
 * The API client: encoding, authentication, error mapping, pacing and redaction.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BearerAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\NoAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\QueryKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Connectors\Support\Redaction;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase {

	private const KEY = 'sk-test-0123456789abcdef';

	private ApiTestKit $kit;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connection(): Connection {
		return new Connection( 5, 'demo', ConnectorType::Accounting, 'Demo', ConnectionStatus::Active, array( 'tenant' => 'tenant-98765' ), array(), array( 'api_key' => self::KEY ) );
	}

	/**
	 * A client with the usual setup.
	 *
	 * @param AuthStrategy|null $auth       Authentication.
	 * @param \Closure|null     $vendorError Vendor error mapper.
	 * @param bool              $paced      Whether to pace.
	 * @param array<string,string> $headers Default headers.
	 */
	private function client( ?AuthStrategy $auth = null, ?\Closure $vendorError = null, bool $paced = false, array $headers = array() ): ApiClient {
		$connection = $this->connection();
		return new ApiClient(
			$this->kit->http,
			$connection,
			'https://api.demo.test/v1',
			$auth ?? new HeaderKeyAuth( 'X-Api-Key', 'api_key' ),
			new HostPolicy( array( 'api.demo.test', 'files.demo.test' ) ),
			'Demo',
			Redaction::forConnection( $connection, array( 'api_key' ), array( 'tenant-98765' ) )->addHost( 'https://api.demo.test' ),
			new RateLimitHints( 2.0 ),
			$paced ? $this->kit->pacer : null,
			$headers,
			$vendorError,
			'X-Idempotency'
		);
	}

	private function failure( ApiClient $client, string $method = 'get', string $path = '/x', array $options = array() ): ConnectorError {
		try {
			$client->$method( $path, array(), null, $options );
		} catch ( ApiException $exception ) {
			return $exception->error();
		}
		$this->fail( 'Expected an ApiException.' );
	}

	public function test_a_get_builds_the_url_with_the_query_and_sends_the_headers(): void {
		$this->kit->http->queue( FakeHttpClient::json( array( 'ok' => true ) ) );
		$response = $this->client( null, null, false, array( 'X-Version' => '2' ) )->get( '/items', array( 'q' => 'مصباح', 'page' => 2, 'flag' => true, 'skip' => null ) );

		$this->assertTrue( $response->isSuccess() );
		$this->assertSame( array( 'ok' => true ), $response->json() );
		$request = $this->kit->http->last();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.demo.test/v1/items?q=%D9%85%D8%B5%D8%A8%D8%A7%D8%AD&page=2&flag=true', $request['url'] );
		$this->assertSame( self::KEY, $request['headers']['X-Api-Key'] );
		$this->assertSame( '2', $request['headers']['X-Version'] );
		$this->assertSame( 'application/json', $request['headers']['Accept'] );
		$this->assertStringStartsWith( 'Estasoft-Sync/', $request['headers']['User-Agent'] );
		$this->assertNull( $request['body'] );
		$this->assertSame( 30, $request['timeout'] );
	}

	public function test_json_bodies_keep_unicode_and_whole_floats(): void {
		$this->kit->http->queue( FakeHttpClient::json( array() ) );
		$this->client()->post( '/orders', array(), array( 'name' => 'نور المنصوري', 'qty' => 2.0, 'url' => 'https://a.test/x' ) );
		$request = $this->kit->http->last();
		$this->assertSame( '{"name":"نور المنصوري","qty":2.0,"url":"https://a.test/x"}', $request['body'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
	}

	public function test_form_xml_and_raw_bodies(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::status( 200, '' ) );
		$client = $this->client();
		$client->post( '/a', array(), array( 'a' => 'x y', 'b' => array( 'c' => '1' ) ), array( 'format' => 'form' ) );
		$this->assertSame( 'a=x+y&b%5Bc%5D=1', $this->kit->http->last()['body'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $this->kit->http->last()['headers']['Content-Type'] );

		$client->post( '/b', array(), array( 'Invoice' => array( 'Id' => 'WC-1', 'Note' => 'a < b & c', 'Line' => array( array( 'Qty' => 1 ), array( 'Qty' => 2 ) ) ) ), array( 'format' => 'xml' ) );
		$this->assertSame( '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Invoice><Id>WC-1</Id><Note>a &lt; b &amp; c</Note><Line><Qty>1</Qty></Line><Line><Qty>2</Qty></Line></Invoice>', $this->kit->http->last()['body'] );
		$this->assertSame( 'application/xml', $this->kit->http->last()['headers']['Content-Type'] );
		$this->assertSame( 'application/xml', $this->kit->http->last()['headers']['Accept'] );

		$client->put( '/c', array(), '<x/>', array( 'format' => 'raw', 'content_type' => 'text/xml' ) );
		$this->assertSame( '<x/>', $this->kit->http->last()['body'] );
		$this->assertSame( 'text/xml', $this->kit->http->last()['headers']['Content-Type'] );
		$this->assertSame( 'PUT', $this->kit->http->last()['method'] );
	}

	public function test_patch_delete_and_the_idempotency_and_timeout_options(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::status( 204 ) );
		$client = $this->client();
		$client->patch( '/o/1', array(), array( 'a' => 1 ), array( 'idempotency_key' => 'wc-1-created', 'timeout' => 99, 'headers' => array( 'X-Extra' => 'y' ) ) );
		$request = $this->kit->http->last();
		$this->assertSame( 'PATCH', $request['method'] );
		$this->assertSame( 'wc-1-created', $request['headers']['X-Idempotency'] );
		$this->assertSame( 30, $request['timeout'], 'Never more than thirty seconds.' );
		$this->assertSame( 'y', $request['headers']['X-Extra'] );

		$client->delete( '/o/1', array(), null, array( 'timeout' => 5 ) );
		$this->assertSame( 'DELETE', $this->kit->http->last()['method'] );
		$this->assertSame( 5, $this->kit->http->last()['timeout'] );
	}

	public function test_an_absolute_next_page_link_is_followed_only_on_an_allowed_host(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$this->client()->get( 'https://files.demo.test/page/2?cursor=abc' );
		$this->assertSame( 'https://files.demo.test/page/2?cursor=abc', $this->kit->http->last()['url'] );

		$before = $this->kit->http->count();
		$error  = $this->failure( $this->client(), 'get', 'https://evil.test/steal' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( $before, $this->kit->http->count(), 'Nothing was sent.' );
		$this->assertStringNotContainsString( 'steal', $error->message() . wp_json_encode( $error->context ) );
	}

	public function test_a_hostile_location_or_next_link_is_refused_before_any_credential_is_applied(): void {
		$this->kit->http->queue( new HttpResponse( 302, '', array( 'location' => 'https://evil.test/collect?x=1' ) ) );
		$first    = $this->client( new BearerAuth( 'api_key' ) )->get( '/export/1', array(), null, array( 'allow' => array( 302 ) ) );
		$location = (string) $first->header( 'Location' );
		$this->assertSame( 'https://evil.test/collect?x=1', $location );
		$sent = $this->kit->http->count();

		foreach ( array( $location, 'https://api.demo.test.evil.test/x', 'https://api.demo.test@evil.test/x', 'https://api.demo.test:8443/x', 'http://api.demo.test/x', 'https://files.demo.test:8443/x', 'https://files.demo.test.evil.test/x' ) as $hostile ) {
			$error = $this->failure( $this->client( new BearerAuth( 'api_key' ) ), 'get', $hostile );
			$this->assertSame( ErrorCode::Val002, $error->code, $hostile );
			$this->assertSame( 'address', $error->context['field'], $hostile );
		}
		$this->assertSame( $sent, $this->kit->http->count(), 'Not one request went out.' );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( 'evil.test', $request['url'] );
		}
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $this->kit->http->requests[ $sent - 1 ]['url'] ?? '' ) );
	}

	public function test_a_link_on_the_same_origin_or_a_declared_host_is_followed_with_the_usual_credentials(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$client = $this->client( new BearerAuth( 'api_key' ) );
		$client->get( 'https://api.demo.test/v1/items?page=2' );
		$this->assertSame( 'Bearer ' . self::KEY, $this->kit->http->last()['headers']['Authorization'] );
		$client->get( 'https://API.DEMO.TEST/v1/items?page=3' );
		$this->assertSame( 'https://API.DEMO.TEST/v1/items?page=3', $this->kit->http->last()['url'], 'The host is compared in lower case.' );
		$client->get( 'https://api.demo.test:443/v1/items?page=4' );
		$client->get( 'https://files.demo.test/dl/9' );
		$this->assertSame( 4, $this->kit->http->count() );
	}

	public function test_a_merchant_host_follows_links_only_on_its_own_origin(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$connection = $this->connection();
		$client     = new ApiClient( $this->kit->http, $connection, 'https://erp.acme-trading.test:8443/api', new BearerAuth( 'api_key' ), new HostPolicy( array(), true ), 'Demo', new Redaction(), new RateLimitHints() );
		$client->get( 'https://erp.acme-trading.test:8443/api/orders?page=2' );
		$this->assertSame( 1, $this->kit->http->count() );
		foreach ( array( 'https://other.acme-trading.test:8443/x', 'https://erp.acme-trading.test/x', 'https://erp.acme-trading.test:9443/x', 'https://93.184.216.34/x' ) as $other ) {
			$this->assertSame( ErrorCode::Val002, $this->failure( $client, 'get', $other )->code, $other . ' is public, but it is not this server.' );
		}
		$this->assertSame( 1, $this->kit->http->count() );
	}

	public function test_a_query_key_goes_in_the_url_and_a_bearer_in_the_header(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$this->client( new QueryKeyAuth( 'key', 'api_key' ) )->get( '/x', array( 'a' => '1' ) );
		$this->assertSame( 'https://api.demo.test/v1/x?a=1&key=' . self::KEY, $this->kit->http->last()['url'] );
		$this->client( new BearerAuth( 'api_key' ) )->get( '/x' );
		$this->assertSame( 'Bearer ' . self::KEY, $this->kit->http->last()['headers']['Authorization'] );
		$this->client( new NoAuth() )->get( '/x' );
		$this->assertArrayNotHasKey( 'Authorization', $this->kit->http->last()['headers'] );
	}

	public function test_a_missing_secret_is_auth_001_and_nothing_is_sent(): void {
		$connection = new Connection( 5, 'demo', ConnectorType::Crm, 'Demo', ConnectionStatus::Active );
		$client     = new ApiClient( $this->kit->http, $connection, 'https://api.demo.test', new BearerAuth( 'api_key' ), new HostPolicy( array( 'api.demo.test' ) ), 'Demo', new Redaction(), new RateLimitHints() );
		$error      = $this->failure( $client );
		$this->assertSame( ErrorCode::Auth001, $error->code );
		$this->assertStringContainsString( 'Demo', $error->message(), 'The system name is filled in.' );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_allowed_statuses_come_back_as_normal_responses(): void {
		$this->kit->http->queue( FakeHttpClient::status( 404, '{"message":"none"}' ) );
		$response = $this->client()->get( '/items/9', array(), null, array( 'allow' => array( 404 ) ) );
		$this->assertSame( 404, $response->status() );
		$this->assertFalse( $response->isSuccess() );
		$this->assertSame( array( 'message' => 'none' ), $response->json() );
	}

	/**
	 * @return array<string,array{0:HttpResponse,1:ErrorCode,2:int|null}>
	 */
	public static function provide_failures(): array {
		return array(
			'timeout'      => array( FakeHttpClient::timeout(), ErrorCode::Net001, null ),
			'unreachable'  => array( FakeHttpClient::unreachable(), ErrorCode::Net001, null ),
			'certificate'  => array( FakeHttpClient::certificateError(), ErrorCode::Net003, null ),
			'request timeout' => array( FakeHttpClient::status( 408 ), ErrorCode::Net001, 408 ),
			'401'          => array( FakeHttpClient::status( 401, '{"error":"bad key"}' ), ErrorCode::Auth001, 401 ),
			'403'          => array( FakeHttpClient::status( 403, '{"message":"no access"}' ), ErrorCode::Auth004, 403 ),
			'500'          => array( FakeHttpClient::status( 500, 'oops' ), ErrorCode::Net002, 500 ),
			'503'          => array( FakeHttpClient::status( 503 ), ErrorCode::Net002, 503 ),
			'404'          => array( FakeHttpClient::status( 404, '{"message":"No such invoice"}' ), ErrorCode::Val002, 404 ),
			'422'          => array( FakeHttpClient::status( 422, '{"errors":[{"message":"Name is required"}]}' ), ErrorCode::Val002, 422 ),
			'redirect'     => array( FakeHttpClient::status( 302, '', array( 'location' => 'https://login.demo.test/' ) ), ErrorCode::Val002, 302 ),
		);
	}

	/**
	 * @dataProvider provide_failures
	 *
	 * @param HttpResponse $answer Answer.
	 * @param ErrorCode    $code   Expected code.
	 * @param int|null     $status Expected status.
	 */
	public function test_failures_map_to_the_catalog_codes( HttpResponse $answer, ErrorCode $code, ?int $status ): void {
		$this->kit->http->queue( $answer );
		$error = $this->failure( $this->client() );
		$this->assertSame( $code, $error->code );
		$this->assertSame( $status, $error->httpStatus );
		$this->assertSame( 'Demo', $error->context['system'] );
	}

	public function test_a_rate_limit_carries_the_wait_from_seconds_or_from_a_date(): void {
		$this->kit->http->queue( FakeHttpClient::tooManyRequests( 37 ) );
		$error = $this->failure( $this->client() );
		$this->assertSame( ErrorCode::Rate001, $error->code );
		$this->assertSame( 37, $error->retryAfter );
		$this->assertSame( 37, $error->context['n'] );

		$this->kit->http->queue( FakeHttpClient::status( 429, '', array( 'Retry-After' => gmdate( 'D, d M Y H:i:s', time() + 90 ) . ' GMT' ) ) );
		$wait = $this->failure( $this->client() )->retryAfter;
		$this->assertGreaterThanOrEqual( 85, $wait );
		$this->assertLessThanOrEqual( 91, $wait );

		$this->kit->http->queue( FakeHttpClient::status( 429 ) );
		$this->assertSame( 30, $this->failure( $this->client() )->retryAfter, 'A default wait when the header is missing.' );
	}

	public function test_the_vendor_message_is_short_redacted_and_never_holds_the_key_the_tenant_or_the_host(): void {
		$body = (string) wp_json_encode( array( 'message' => 'Invalid field for key ' . self::KEY . ' in tenant tenant-98765 at api.demo.test ' . str_repeat( 'long ', 100 ) ) );
		$this->kit->http->queue( FakeHttpClient::status( 400, $body ) );
		$error = $this->failure( $this->client() );
		$text  = $error->message() . '|' . $error->detail . '|' . wp_json_encode( $error->context );
		$this->assertStringNotContainsString( self::KEY, $text );
		$this->assertStringNotContainsString( 'tenant-98765', $text );
		$this->assertStringNotContainsString( 'api.demo.test', $text );
		$this->assertLessThan( 400, strlen( $error->message() ) );
	}

	public function test_a_vendor_mapper_wins_and_its_text_is_cleaned(): void {
		$mapper = static fn( $response ): ?ConnectorError => 409 === $response->status()
			? new ConnectorError( ErrorCode::Dup001, array( 'ref' => 'INV-77' ), 409 )
			: ( 402 === $response->status() ? new ConnectorError( ErrorCode::Auth006, array(), 402 ) : null );
		$this->kit->http->queue( FakeHttpClient::status( 409 ) )->queue( FakeHttpClient::status( 402 ) )->queue( FakeHttpClient::status( 400, '{"message":"plain"}' ) );
		$one = $this->failure( $this->client( null, $mapper ) );
		$this->assertSame( ErrorCode::Dup001, $one->code );
		$this->assertSame( 'Demo', $one->context['system'], 'The system name is added when the mapper leaves it out.' );
		$this->assertSame( ErrorCode::Auth006, $this->failure( $this->client( null, $mapper ) )->code );
		$this->assertSame( ErrorCode::Val002, $this->failure( $this->client( null, $mapper ) )->code, 'null means the default mapping.' );
	}

	public function test_a_401_asks_the_strategy_once_and_sends_the_request_again(): void {
		$auth = new class() implements AuthStrategy {
			public int $applied = 0;
			public int $recovered = 0;
			public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
				++$this->applied;
				return $request->withHeader( 'Authorization', 'Bearer token-' . $this->applied . '-abcdefgh' );
			}
			public function onUnauthorized( Connection $connection ): bool {
				++$this->recovered;
				return true;
			}
		};
		$this->kit->http->queue( FakeHttpClient::status( 401 ) )->queue( FakeHttpClient::json( array( 'ok' => 1 ) ) );
		$response = $this->client( $auth )->get( '/x' );
		$this->assertSame( array( 'ok' => 1 ), $response->json() );
		$this->assertSame( 1, $auth->recovered );
		$this->assertSame( 2, $auth->applied );
		$this->assertSame( 'Bearer token-2-abcdefgh', $this->kit->http->last()['headers']['Authorization'] );

		$this->kit->http->queue( FakeHttpClient::status( 401 ) )->queue( FakeHttpClient::status( 401 ) );
		$error = $this->failure( $this->client( $auth ) );
		$this->assertSame( ErrorCode::Auth001, $error->code, 'A second 401 is a refusal, not a loop.' );
		$this->assertSame( 2, $auth->recovered );
		$this->assertSame( 4, $this->kit->http->count() );
	}

	public function test_an_oversized_answer_is_refused(): void {
		$this->kit->http->queue( FakeHttpClient::status( 200, str_repeat( 'a', ApiClient::MAX_RESPONSE_BYTES + 1 ) ) );
		$error = $this->failure( $this->client() );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertStringContainsString( '5 MB', (string) $error->context['reason'] );
	}

	public function test_calls_are_paced_and_a_long_wait_stops_with_rate_001(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$client = $this->client( null, null, true );
		$client->get( '/a' );
		$client->get( '/b' );
		$client->get( '/c' );
		$this->assertSame( array( 500000, 500000 ), $this->kit->slept, 'Two calls a second: half a second between calls.' );

		// A burst far ahead of the slot is not slept off inside the request.
		$this->kit->rates->values['api:5'] = (float) $this->kit->clock->now()->format( 'U' ) + 100.0;
		$error = $this->failure( $client );
		$this->assertSame( ErrorCode::Rate001, $error->code );
		$this->assertGreaterThan( 5, $error->retryAfter );
	}

	public function test_the_daily_cap_stops_with_rate_001_until_midnight(): void {
		$limits = new RateLimitHints( 100.0, 1, null, 2 );
		$this->kit->pacer->wait( 7, $limits, 'Demo' );
		$this->kit->pacer->wait( 7, $limits, 'Demo' );
		$this->assertSame( 0, $this->kit->pacer->remainingToday( 7, $limits ) );
		try {
			$this->kit->pacer->wait( 7, $limits, 'Demo' );
			$this->fail( 'Expected the daily cap to stop the call.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ErrorCode::Rate001, $exception->error()->code );
			$this->assertSame( 12 * 3600, $exception->error()->retryAfter, 'The clock stands at noon UTC.' );
		}
		$this->kit->clock->advance( 12 * 3600 + 5 );
		$this->kit->pacer->wait( 7, $limits, 'Demo' );
		$this->assertSame( 1, 2 - (int) $this->kit->pacer->remainingToday( 7, $limits ), 'A new day starts a new count.' );
	}

	public function test_a_call_stopped_for_a_long_wait_costs_neither_a_slot_nor_a_part_of_the_daily_quota(): void {
		$limits = new RateLimitHints( 1.0, 1, null, 10 );
		$ahead  = (float) $this->kit->clock->now()->format( 'U' ) + 100.0;
		$this->kit->rates->values['api:9'] = $ahead;
		for ( $i = 0; $i < 4; ++$i ) {
			try {
				$this->kit->pacer->wait( 9, $limits, 'Demo' );
				$this->fail( 'Expected the long wait to stop the call.' );
			} catch ( ApiException $exception ) {
				$this->assertSame( ErrorCode::Rate001, $exception->error()->code );
				$this->assertGreaterThan( 5, $exception->error()->retryAfter );
			}
		}
		$this->assertSame( 10, $this->kit->pacer->remainingToday( 9, $limits ), 'Refused calls used none of the day.' );
		$this->assertSame( $ahead, $this->kit->rates->values['api:9'], 'And did not push the next slot further away.' );
	}

	public function test_a_burst_against_a_daily_cap_counts_only_the_calls_that_were_allowed(): void {
		$limits  = new RateLimitHints( 1000.0, 1, null, 3 );
		$allowed = 0;
		$refused = 0;
		for ( $i = 0; $i < 8; ++$i ) {
			try {
				$this->kit->pacer->wait( 11, $limits, 'Demo' );
				++$allowed;
			} catch ( ApiException $exception ) {
				++$refused;
				$this->assertSame( ErrorCode::Rate001, $exception->error()->code );
			}
		}
		$this->assertSame( 3, $allowed );
		$this->assertSame( 5, $refused );
		$this->assertSame( 0, $this->kit->pacer->remainingToday( 11, $limits ) );
		$slot = $this->kit->rates->values['api:11'];
		try {
			$this->kit->pacer->wait( 11, $limits, 'Demo' );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
		$this->assertSame( $slot, $this->kit->rates->values['api:11'], 'A call refused by the cap gives its slot back.' );
	}

	public function test_printing_a_request_or_response_shows_no_headers_or_body(): void {
		$request = new ApiRequest( 'POST', 'https://a.test/x?key=abc', array( 'Authorization' => 'Bearer top-secret-token' ), '{"p":"top-secret-token"}' );
		$this->assertStringNotContainsString( 'top-secret', print_r( $request, true ) );
		$this->assertStringNotContainsString( 'abc', print_r( $request, true ) );
	}
}
