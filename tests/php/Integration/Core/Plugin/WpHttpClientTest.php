<?php
/**
 * Outgoing HTTP rules.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Core\Queue\ErrorClassifier;
use Estasoft\SyncEngine\Core\Support\WpHttpClient;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class WpHttpClientTest extends CoreIntegrationTestCase {

	private WpHttpClient $client;

	/**
	 * What wp_remote_request was called with.
	 *
	 * @var array<string,mixed>
	 */
	private array $args = array();

	/**
	 * The URL it was called with.
	 */
	private string $url = '';

	protected function setUp(): void {
		parent::setUp();
		$this->client = new WpHttpClient();
	}

	/**
	 * Answer every request with a fixed result and remember what was asked.
	 *
	 * @param mixed $result Array, WP_Error or false.
	 */
	private function answerWith( mixed $result ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $result ) {
				$this->args = $args;
				$this->url  = $url;
				return $result;
			},
			10,
			3
		);
	}

	public function test_it_implements_the_contract_interface(): void {
		$this->assertInstanceOf( HttpClientInterface::class, $this->client );
	}

	public function test_requests_follow_the_network_rules(): void {
		$this->answerWith( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) );
		$this->client->request( 'post', 'https://erp.example.test/jsonrpc', array( 'X-Test' => '1', 'Content-Type' => 'application/json' ), '{"a":1}', 120 );

		$this->assertSame( 'POST', $this->args['method'] );
		$this->assertSame( 30, $this->args['timeout'], 'never more than 30 seconds' );
		$this->assertTrue( $this->args['sslverify'], 'certificates are always checked' );
		$this->assertTrue( $this->args['reject_unsafe_urls'] );
		$this->assertSame( 0, $this->args['redirection'], 'no redirects, so a header never follows to another host' );
		$this->assertSame( '{"a":1}', $this->args['body'] );
		$this->assertSame( 'application/json', $this->args['headers']['Content-Type'] );
		$this->assertStringStartsWith( 'Estasoft-Sync/', $this->args['user-agent'] );
		$this->assertStringNotContainsString( 'example.test', $this->args['user-agent'], 'the user agent does not give the site away' );
		$this->assertGreaterThan( 0, $this->args['limit_response_size'] );
		$this->assertSame( 'https://erp.example.test/jsonrpc', $this->url );
	}

	public function test_the_timeout_is_at_least_one_second_and_defaults_to_thirty(): void {
		$this->answerWith( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) );
		$this->client->request( 'GET', 'https://erp.example.test/', array(), null, 0 );
		$this->assertSame( 1, $this->args['timeout'] );
		$this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 30, $this->args['timeout'] );
		$this->assertEmpty( $this->args['body'], 'no body for a GET' );
	}

	public function test_the_answer_is_returned_with_lower_case_headers(): void {
		$this->answerWith( array( 'response' => array( 'code' => 429 ), 'body' => '{"error":"slow down"}', 'headers' => array( 'Retry-After' => '30', 'Content-Type' => 'application/json' ) ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 429, $response->status );
		$this->assertSame( '{"error":"slow down"}', $response->body );
		$this->assertSame( 'application/json', $response->headers['content-type'] );
		$this->assertSame( 30, $response->retryAfter() );
		$this->assertSame( array( 'error' => 'slow down' ), $response->json() );
		$this->assertSame( '', $response->error );
		$this->assertFalse( $response->timedOut );
	}

	public function test_a_timeout_is_reported_as_one(): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 0, $response->status );
		$this->assertTrue( $response->timedOut );
		$this->assertStringStartsWith( 'timeout', $response->error );
		$this->assertSame( ErrorCode::Net001, ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' )->code );
	}

	public function test_a_certificate_problem_is_reported_as_sync_net_003(): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 0, $response->status );
		$this->assertFalse( $response->timedOut );
		$this->assertStringStartsWith( 'certificate', $response->error );
		$this->assertSame( ErrorCode::Net003, ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' )->code );
	}

	public function test_other_transport_failures_are_reported_as_unreachable_without_the_address(): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: secret-erp.internal.example' ) );
		$response = $this->client->request( 'GET', 'https://secret-erp.internal.example/?token=abc' );
		$this->assertStringStartsWith( 'connection', $response->error );
		$this->assertStringNotContainsString( 'secret-erp', $response->error );
		$this->assertStringNotContainsString( 'token', $response->error );
		$this->assertSame( ErrorCode::Net001, ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' )->code );
	}

	public function test_addresses_on_the_local_network_are_refused_by_default(): void {
		foreach ( array( 'http://127.0.0.1/x', 'http://192.168.1.20:8069/jsonrpc', 'http://10.0.0.5/', 'http://localhost/' ) as $url ) {
			$response = $this->client->request( 'GET', $url );
			$this->assertSame( 0, $response->status, $url );
			$this->assertStringStartsWith( 'unsafe_url', $response->error, $url );
		}
	}

	public function test_a_site_can_allow_private_hosts_for_development(): void {
		$this->answerWith( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) );
		$seen = array();
		add_filter(
			'estasoft_sync_allow_private_hosts',
			static function ( $allow, $url ) use ( &$seen ) {
				$seen[] = $url;
				return true;
			},
			10,
			2
		);
		$this->client->request( 'GET', 'http://192.168.1.20:8069/jsonrpc' );
		$this->assertFalse( $this->args['reject_unsafe_urls'] );
		$this->assertSame( array( 'http://192.168.1.20:8069/jsonrpc' ), $seen );
	}

	public function test_the_port_of_the_address_is_allowed_for_that_request_only(): void {
		$during = array();
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$during ) {
				$during = apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'erp.example.test', 'https://erp.example.test:8069/jsonrpc' );
				return array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() );
			}
		);
		$this->client->request( 'POST', 'https://erp.example.test:8069/jsonrpc' );
		$this->assertContains( 8069, $during );
		$this->assertSame( array( 80, 443, 8080 ), apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'erp.example.test', 'https://erp.example.test:8069/jsonrpc' ), 'the extra port is gone after the request' );
	}

	public function test_a_closed_port_on_this_machine_is_a_connection_error_not_an_exception(): void {
		add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );
		$response = $this->client->request( 'GET', 'http://127.0.0.1:9/', array(), null, 2 );
		$this->assertSame( 0, $response->status );
		$this->assertNotSame( '', $response->error );
	}
}
