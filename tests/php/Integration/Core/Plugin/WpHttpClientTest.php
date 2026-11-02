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
use Estasoft\SyncEngine\Core\Queue\FailureAction;
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

	/**
	 * @dataProvider verificationFailures
	 */
	public function test_only_real_certificate_check_failures_are_sync_net_003( string $message ): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', $message ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 0, $response->status );
		$this->assertFalse( $response->timedOut, $message );
		$this->assertStringStartsWith( 'certificate', $response->error, $message );
		$error = ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' );
		$this->assertSame( ErrorCode::Net003, $error->code, $message );
		$this->assertSame( FailureAction::Fail, ( new ErrorClassifier() )->classify( $error, null, 'Odoo' )->action, 'a person has to fix a certificate: ' . $message );
		$this->assertStringNotContainsString( 'erp.example.test', $response->error );
	}

	/**
	 * The texts WordPress puts in the WP_Error: "cURL error {number}: {libcurl text}" for the cURL transport, and the PHP
	 * warning text for the streams transport.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function verificationFailures(): array {
		return array(
			'60 unknown issuer'       => array( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ),
			'60 self signed'          => array( 'cURL error 60: SSL certificate problem: self signed certificate' ),
			'60 self signed in chain' => array( 'cURL error 60: SSL certificate problem: self-signed certificate in certificate chain' ),
			'60 expired'              => array( 'cURL error 60: SSL certificate problem: certificate has expired' ),
			'60 first certificate'    => array( 'cURL error 60: SSL certificate problem: unable to verify the first certificate' ),
			'51 name does not match'  => array( "cURL error 51: SSL: no alternative certificate subject name matches target host name 'erp.example.test'" ),
			'51 old wording'          => array( "cURL error 51: SSL: certificate subject name 'other.example.test' does not match target host name 'erp.example.test'" ),
			'58 local certificate'    => array( "cURL error 58: unable to set private key file: '/etc/ssl/private/client.key' type PEM" ),
			'58 client certificate'   => array( 'cURL error 58: could not load PEM client certificate, OpenSSL error error:02001002:system library:fopen:No such file or directory' ),
			'77 trusted authorities'  => array( 'cURL error 77: error setting certificate file: /etc/ssl/certs/ca-certificates.crt' ),
			'77 verify locations'     => array( 'cURL error 77: error setting certificate verify locations:  CAfile: /nope/ca.pem CApath: none' ),
			'83 issuer check'         => array( 'cURL error 83: Issuer check against peer certificate failed' ),
			'90 pinned key'           => array( 'cURL error 90: SSL: public key does not match pinned public key' ),
			'91 invalid chain'        => array( 'cURL error 91: SSL: invalid certificate chain' ),
			'streams verify failed'   => array( "stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages:\nerror:14090086:SSL routines:ssl3_get_server_certificate:certificate verify failed" ),
			'text only'               => array( 'SSL: certificate verify failed' ),
		);
	}

	/**
	 * @dataProvider transientSecureFailures
	 */
	public function test_a_secure_connection_that_was_reset_or_dropped_is_sync_net_001_and_is_tried_again( string $message ): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', $message ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertSame( 0, $response->status );
		$this->assertStringStartsWith( 'connection', $response->error, $message );
		$this->assertStringNotContainsString( 'certificate', $response->error, $message );
		$this->assertStringNotContainsString( 'erp.example.test', $response->error );
		$error = ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' );
		$this->assertSame( ErrorCode::Net001, $error->code, $message );
		$this->assertSame( FailureAction::Retry, ( new ErrorClassifier() )->classify( $error, null, 'Odoo' )->action, $message );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function transientSecureFailures(): array {
		return array(
			'35 reset during connect'  => array( 'cURL error 35: OpenSSL SSL_connect: Connection reset by peer in connection to erp.example.test:443' ),
			'35 plain'                 => array( 'cURL error 35: SSL connect error' ),
			'35 syscall'               => array( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to erp.example.test:443' ),
			'35 unexpected eof'        => array( 'cURL error 35: error:0A000126:SSL routines::unexpected eof while reading' ),
			'35 handshake alert'       => array( 'cURL error 35: error:0A000410:SSL routines::sslv3 alert handshake failure' ),
			'35 unknown protocol'      => array( 'cURL error 35: Unknown SSL protocol error in connection to erp.example.test:443' ),
			'35 old openssl'           => array( 'cURL error 35: error:14094410:SSL routines:ssl3_read_bytes:sslv3 alert handshake failure' ),
			'56 reset while reading'   => array( 'cURL error 56: OpenSSL SSL_read: Connection reset by peer, errno 104' ),
			'55 broken pipe'           => array( 'cURL error 55: OpenSSL SSL_write: Broken pipe, errno 32' ),
			'52 empty reply'           => array( 'cURL error 52: Empty reply from server' ),
			'7 refused'                => array( 'cURL error 7: Failed to connect to erp.example.test port 443 after 3 ms: Connection refused' ),
			'6 no such host'           => array( 'cURL error 6: Could not resolve host: erp.example.test' ),
			'streams reset'            => array( 'stream_socket_client(): SSL: Connection reset by peer' ),
		);
	}

	/**
	 * @dataProvider timeouts
	 */
	public function test_timeouts_are_sync_net_001_and_say_so( string $message ): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', $message ) );
		$response = $this->client->request( 'GET', 'https://erp.example.test/' );
		$this->assertTrue( $response->timedOut, $message );
		$this->assertStringStartsWith( 'timeout', $response->error, $message );
		$this->assertSame( ErrorCode::Net001, ( new ErrorClassifier() )->fromResponse( $response, 'Odoo' )->code, $message );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function timeouts(): array {
		return array(
			'28 no answer'      => array( 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' ),
			'28 connect'        => array( 'cURL error 28: Connection timed out after 10001 milliseconds' ),
			'28 resolving'      => array( 'cURL error 28: Resolving timed out after 5001 milliseconds' ),
			'28 secure connect' => array( 'cURL error 28: SSL connection timeout' ),
			'streams handshake' => array( 'fopen(): SSL: Handshake timed out' ),
		);
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
