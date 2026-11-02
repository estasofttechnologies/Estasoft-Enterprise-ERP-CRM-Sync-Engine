<?php
/**
 * The JSON-RPC transport (Odoo 17 and 18).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\JsonRpcTransport;
use Estasoft\SyncEngine\Connectors\Odoo\OdooAddress;
use Estasoft\SyncEngine\Connectors\Odoo\OdooErrorMapper;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class JsonRpcTransportTest extends TestCase {

	private function transport( FakeHttpClient $http, string $login = 'sync@example.com', string $key = 'sk-secret-key-12345' ): JsonRpcTransport {
		return new JsonRpcTransport( $http, new OdooErrorMapper( array( $key, $login ) ), new OdooAddress( 'https://erp.example.com', 'erp.example.com' ), 'testdb', $login, $key );
	}

	/**
	 * @param mixed $result Result.
	 */
	private function result( mixed $result ): \Estasoft\SyncEngine\Contract\HttpResponse {
		return FakeHttpClient::json( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => $result ) );
	}

	/**
	 * Decoded body of a recorded request.
	 *
	 * @param FakeHttpClient $http  Client.
	 * @param int            $index Request index.
	 * @return array<string,mixed>
	 */
	private function body( FakeHttpClient $http, int $index ): array {
		return json_decode( (string) $http->requests[ $index ]['body'], true );
	}

	public function test_it_signs_in_once_with_the_key_as_the_password_and_reuses_the_user_id(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array() ) )->queue( $this->result( array() ) );
		$t    = $this->transport( $http );
		$t->searchRead( 'res.partner', array(), array( 'name' ) );
		$t->searchRead( 'res.partner', array(), array( 'name' ) );

		$this->assertSame( 3, $http->count() );
		$login = $this->body( $http, 0 );
		$this->assertSame( 'https://erp.example.com/jsonrpc', $http->requests[0]['url'] );
		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertSame( 'common', $login['params']['service'] );
		$this->assertSame( 'authenticate', $login['params']['method'] );
		$this->assertSame( array( 'testdb', 'sync@example.com', 'sk-secret-key-12345' ), array_slice( $login['params']['args'], 0, 3 ) );
		$this->assertSame( array(), $login['params']['args'][3], 'The user agent environment must be an object, not null.' );
		$this->assertStringContainsString( '"args":["testdb","sync@example.com","sk-secret-key-12345",{}]', (string) $http->requests[0]['body'] );
		$this->assertSame( 2, $t->userId() );
		$this->assertSame( 'jsonrpc', $t->id() );
	}

	public function test_requests_are_json_with_a_thirty_second_limit_and_no_authorization_header(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array() ) );
		$this->transport( $http )->searchRead( 'res.partner', array(), array( 'name' ) );
		$this->assertSame( 30, $http->requests[1]['timeout'] );
		$this->assertStringStartsWith( 'application/json', $http->requests[1]['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Authorization', $http->requests[1]['headers'] );
	}

	public function test_search_read_passes_the_domain_by_name_and_the_context_in_the_keywords(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array( array( 'id' => 5, 'default_code' => 'A' ) ) ) );
		$rows = $this->transport( $http )->searchRead( 'product.product', array( array( 'default_code', 'in', array( 'A' ) ) ), array( 'default_code' ), array( 'limit' => 3, 'order' => 'id asc' ), array( 'warehouse' => array( 1 ) ) );

		$this->assertSame( array( array( 'id' => 5, 'default_code' => 'A' ) ), $rows );
		$call = $this->body( $http, 1 )['params'];
		$this->assertSame( 'object', $call['service'] );
		$this->assertSame( 'execute_kw', $call['method'] );
		$this->assertSame( array( 'testdb', 2, 'sk-secret-key-12345', 'product.product', 'search_read', array() ), array_slice( $call['args'], 0, 6 ) );
		$this->assertSame(
			array(
				'domain'  => array( array( 'default_code', 'in', array( 'A' ) ) ),
				'fields'  => array( 'default_code' ),
				'limit'   => 3,
				'order'   => 'id asc',
				'context' => array( 'warehouse' => array( 1 ) ),
			),
			$call['args'][6]
		);
	}

	public function test_create_sends_the_values_as_the_first_positional_argument(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array( 77 ) ) );
		$id   = $this->transport( $http )->create( 'res.partner', array( 'name' => 'نور المنصوري', 'email' => 'noor@example.com' ) );

		$this->assertSame( 77, $id );
		$call = $this->body( $http, 1 )['params']['args'];
		$this->assertSame( 'create', $call[4] );
		$this->assertSame( array( array( array( 'name' => 'نور المنصوري', 'email' => 'noor@example.com' ) ) ), $call[5], 'The first positional argument is the list of values.' );
		$this->assertStringContainsString( 'نور المنصوري', (string) $http->requests[1]['body'], 'Arabic is sent as it is, not as \\u escapes.' );
	}

	public function test_create_accepts_a_plain_id_in_the_answer(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( 12 ) );
		$this->assertSame( 12, $this->transport( $http )->create( 'res.partner', array( 'name' => 'x' ) ) );
	}

	public function test_empty_values_are_sent_as_an_object(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array( 1 ) ) );
		$this->transport( $http )->create( 'account.payment.register', array() );
		$this->assertStringContainsString( '"create",[[{}]]', (string) $http->requests[1]['body'] );
	}

	public function test_write_and_record_methods_put_the_ids_first(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( true ) )->queue( $this->result( true ) );
		$t    = $this->transport( $http );
		$this->assertTrue( $t->write( 'res.partner', array( 4, 5 ), array( 'phone' => '+971500000000' ) ) );
		$this->assertTrue( $t->callRecords( 'sale.order', 'action_confirm', array( 9 ), array(), array( 'a' => 1 ) ) );

		$write = $this->body( $http, 1 )['params']['args'];
		$this->assertSame( array( array( 4, 5 ), array( 'phone' => '+971500000000' ) ), $write[5] );
		$confirm = $this->body( $http, 2 )['params']['args'];
		$this->assertSame( array( array( 9 ) ), $confirm[5] );
		$this->assertSame( array( 'context' => array( 'a' => 1 ) ), $confirm[6] );
	}

	public function test_read_names_the_fields_and_returns_records(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( array( array( 'id' => 3, 'name' => 'A' ) ) ) );
		$rows = $this->transport( $http )->read( 'res.partner', array( 3 ), array( 'name' ) );
		$this->assertSame( 'A', $rows[0]['name'] );
		$call = $this->body( $http, 1 )['params']['args'];
		$this->assertSame( array( array( 3 ) ), $call[5] );
		$this->assertSame( array( 'fields' => array( 'name' ) ), $call[6] );
	}

	public function test_a_refused_sign_in_is_auth_001_without_the_key_in_the_message(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( false ) );
		try {
			$this->transport( $http )->userId();
			$this->fail( 'Expected a refused sign-in.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Auth001, $exception->error->code );
			$this->assertStringNotContainsString( 'sk-secret-key-12345', $exception->getMessage() . $exception->error->detail . json_encode( $exception->error->context ) );
		}
	}

	public function test_a_missing_login_is_auth_001_before_any_request(): void {
		$http = new FakeHttpClient();
		try {
			$this->transport( $http, '' )->userId();
			$this->fail( 'Expected a refused sign-in.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Auth001, $exception->error->code );
			$this->assertStringContainsString( 'Odoo 17 and 18 need it', $exception->error->detail );
			$this->assertSame( 0, $http->count() );
		}
	}

	public function test_an_odoo_fault_in_a_200_answer_is_mapped(): void {
		$fault = array( 'jsonrpc' => '2.0', 'id' => 2, 'error' => array( 'code' => 200, 'message' => 'Odoo Server Error', 'data' => array( 'name' => 'odoo.exceptions.AccessError', 'message' => 'Not allowed', 'arguments' => array( 'Not allowed' ), 'context' => array(), 'debug' => '...' ) ) );
		$http  = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( FakeHttpClient::json( $fault ) );
		try {
			$this->transport( $http )->create( 'sale.order', array( 'partner_id' => 1 ) );
			$this->fail( 'Expected an access error.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Auth004, $exception->error->code );
			$this->assertSame( 'create sale orders', $exception->error->context['action'] );
		}
	}

	/**
	 * @dataProvider provide_failures
	 *
	 * @param \Estasoft\SyncEngine\Contract\HttpResponse $response Answer.
	 * @param ErrorCode                                  $code     Expected code.
	 */
	public function test_http_failures_are_mapped( \Estasoft\SyncEngine\Contract\HttpResponse $response, ErrorCode $code ): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $response );
		try {
			$this->transport( $http )->searchRead( 'res.partner', array(), array( 'name' ) );
			$this->fail( 'Expected a failure.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( $code, $exception->error->code );
		}
	}

	/**
	 * @return array<string,array{0:\Estasoft\SyncEngine\Contract\HttpResponse,1:ErrorCode}>
	 */
	public static function provide_failures(): array {
		return array(
			'time out'      => array( FakeHttpClient::timeout(), ErrorCode::Net001 ),
			'certificate'   => array( FakeHttpClient::certificateError(), ErrorCode::Net003 ),
			'rate limit'    => array( FakeHttpClient::tooManyRequests( 30 ), ErrorCode::Rate001 ),
			'server error'  => array( FakeHttpClient::status( 500, 'oops' ), ErrorCode::Net002 ),
			'bad gateway'   => array( FakeHttpClient::status( 502, 'bad gateway' ), ErrorCode::Net002 ),
			'html page'     => array( new \Estasoft\SyncEngine\Contract\HttpResponse( 200, '<html>login</html>' ), ErrorCode::Net001 ),
			'broken json'   => array( FakeHttpClient::malformedJson(), ErrorCode::Net001 ),
			'unauthorised'  => array( FakeHttpClient::status( 401 ), ErrorCode::Auth001 ),
		);
	}

	public function test_a_result_that_is_not_a_list_of_records_is_an_error(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) )->queue( $this->result( 'surprise' ) );
		$this->expectException( OdooException::class );
		$this->transport( $http )->searchRead( 'res.partner', array(), array( 'name' ) );
	}

	public function test_a_database_that_does_not_exist_is_named_in_the_error(): void {
		$fault = array( 'jsonrpc' => '2.0', 'id' => 1, 'error' => array( 'code' => 200, 'message' => 'Odoo Server Error', 'data' => array( 'name' => 'psycopg2.OperationalError', 'message' => 'FATAL:  database "testdb" does not exist', 'arguments' => array(), 'context' => array(), 'debug' => '' ) ) );
		$http  = ( new FakeHttpClient() )->queue( FakeHttpClient::json( $fault ) );
		try {
			$this->transport( $http )->userId();
			$this->fail( 'Expected CFG-003.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
			$this->assertSame( 'Odoo has no database called testdb.', $exception->error->message() );
		}
	}

	public function test_a_value_that_is_not_valid_utf8_is_a_val_002_error_not_a_php_error(): void {
		$http = ( new FakeHttpClient() )->queue( $this->result( 2 ) );
		try {
			$this->transport( $http )->create( 'res.partner', array( 'name' => "bad \xB1 byte" ) );
			$this->fail( 'Expected VAL-002.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Val002, $exception->error->code );
		}
	}
}
