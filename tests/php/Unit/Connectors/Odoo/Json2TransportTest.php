<?php
/**
 * The JSON-2 transport (Odoo 19 and later).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\Json2Transport;
use Estasoft\SyncEngine\Connectors\Odoo\OdooAddress;
use Estasoft\SyncEngine\Connectors\Odoo\OdooErrorMapper;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class Json2TransportTest extends TestCase {

	private function transport( FakeHttpClient $http, string $database = 'testdb' ): Json2Transport {
		return new Json2Transport( $http, new OdooErrorMapper( array( 'sk-secret-key-12345' ) ), new OdooAddress( 'https://erp.example.com', 'erp.example.com' ), $database, 'sk-secret-key-12345' );
	}

	/**
	 * Decoded body of a recorded request.
	 *
	 * @param FakeHttpClient $http  Client.
	 * @param int            $index Request index.
	 * @return array<string,mixed>
	 */
	private function body( FakeHttpClient $http, int $index = 0 ): array {
		return json_decode( (string) $http->requests[ $index ]['body'], true );
	}

	public function test_it_posts_to_the_model_and_method_with_a_bearer_key_and_the_database_header(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array() ) );
		$this->transport( $http )->searchRead( 'res.partner', array( array( 'email', '=ilike', 'a@b.c' ) ), array( 'name' ), array( 'limit' => 1, 'offset' => 2, 'order' => 'id asc' ), array( 'lang' => 'ar_001' ) );

		$request = $http->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://erp.example.com/json/2/res.partner/search_read', $request['url'] );
		$this->assertSame( 'bearer sk-secret-key-12345', $request['headers']['Authorization'] );
		$this->assertSame( 'testdb', $request['headers']['X-Odoo-Database'] );
		$this->assertStringStartsWith( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( 30, $request['timeout'] );
		$this->assertSame(
			array(
				'domain'  => array( array( 'email', '=ilike', 'a@b.c' ) ),
				'fields'  => array( 'name' ),
				'limit'   => 1,
				'offset'  => 2,
				'order'   => 'id asc',
				'context' => array( 'lang' => 'ar_001' ),
			),
			$this->body( $http )
		);
	}

	public function test_no_database_header_when_the_database_is_empty(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array() ) );
		$this->transport( $http, '' )->searchRead( 'res.partner', array(), array( 'name' ) );
		$this->assertArrayNotHasKey( 'X-Odoo-Database', $http->requests[0]['headers'] );
	}

	public function test_model_level_calls_never_send_ids(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 3 ) );
		$this->assertSame( 3, $this->transport( $http )->callModel( 'res.partner', 'search_count', array( 'domain' => array() ) ) );
		$this->assertArrayNotHasKey( 'ids', $this->body( $http ) );
	}

	public function test_record_methods_send_the_ids_as_a_list(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( true ) );
		$this->transport( $http )->callRecords( 'sale.order', 'action_confirm', array( 9 ), array(), array( 'k' => 'v' ) );
		$this->assertSame( 'https://erp.example.com/json/2/sale.order/action_confirm', $http->requests[0]['url'] );
		$this->assertEquals( array( 'ids' => array( 9 ), 'context' => array( 'k' => 'v' ) ), $this->body( $http ) );
	}

	public function test_a_record_method_on_no_records_sends_no_ids(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( true ) );
		$this->transport( $http )->callRecords( 'res.partner', 'has_access', array(), array( 'operation' => 'create' ) );
		$this->assertSame( array( 'operation' => 'create' ), $this->body( $http ) );
	}

	public function test_create_uses_vals_list_and_returns_the_new_id(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array( 31 ) ) );
		$id   = $this->transport( $http )->create( 'res.partner', array( 'name' => 'نور المنصوري' ) );
		$this->assertSame( 31, $id );
		$this->assertSame( array( 'vals_list' => array( array( 'name' => 'نور المنصوري' ) ) ), $this->body( $http ) );
		$this->assertStringContainsString( 'نور المنصوري', (string) $http->requests[0]['body'] );
	}

	public function test_write_sends_ids_and_vals(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( true ) );
		$this->assertTrue( $this->transport( $http )->write( 'res.partner', array( 4 ), array( 'phone' => '1' ) ) );
		$this->assertEquals( array( 'ids' => array( 4 ), 'vals' => array( 'phone' => '1' ) ), $this->body( $http ) );
	}

	public function test_read_sends_ids_and_fields(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array( array( 'id' => 4, 'name' => 'A' ) ) ) );
		$rows = $this->transport( $http )->read( 'res.partner', array( 4 ), array( 'name' ) );
		$this->assertSame( 'A', $rows[0]['name'] );
		$this->assertEquals( array( 'ids' => array( 4 ), 'fields' => array( 'name' ) ), $this->body( $http ) );
	}

	public function test_the_user_id_comes_from_context_get_once(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array( 'lang' => 'en_US', 'tz' => 'Asia/Dubai', 'uid' => 7 ) ) );
		$t    = $this->transport( $http );
		$this->assertSame( 7, $t->userId() );
		$this->assertSame( 7, $t->userId() );
		$this->assertSame( 1, $http->count() );
		$this->assertSame( 'https://erp.example.com/json/2/res.users/context_get', $http->requests[0]['url'] );
		$this->assertSame( '{}', $http->requests[0]['body'], 'An empty body is an empty object.' );
		$this->assertSame( 'json2', $t->id() );
	}

	/**
	 * @dataProvider provide_failures
	 *
	 * @param HttpResponse $response Answer.
	 * @param ErrorCode    $code     Expected code.
	 */
	public function test_failures_are_mapped( HttpResponse $response, ErrorCode $code ): void {
		$http = ( new FakeHttpClient() )->queue( $response );
		try {
			$this->transport( $http )->callModel( 'res.partner', 'search_count', array( 'domain' => array() ) );
			$this->fail( 'Expected a failure.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( $code, $exception->error->code );
		}
	}

	/**
	 * @return array<string,array{0:HttpResponse,1:ErrorCode}>
	 */
	public static function provide_failures(): array {
		$error = static fn( string $name, string $message ): array => array( 'name' => $name, 'message' => $message, 'arguments' => array( $message ), 'context' => array(), 'debug' => '...' );
		return array(
			'invalid key'        => array( FakeHttpClient::json( $error( 'werkzeug.exceptions.Unauthorized', 'Invalid apikey' ), 401 ), ErrorCode::Auth001 ),
			'access error'       => array( FakeHttpClient::json( $error( 'odoo.exceptions.AccessError', 'Not allowed' ), 403 ), ErrorCode::Auth004 ),
			'no database'        => array( new HttpResponse( 404, '<p>No database is selected and the requested URL was not found in the server-wide controllers.</p>' ), ErrorCode::Cfg003 ),
			'missing model'      => array( FakeHttpClient::json( $error( 'werkzeug.exceptions.NotFound', "the model 'x.y' does not exist" ), 404 ), ErrorCode::Val002 ),
			'validation error'   => array( FakeHttpClient::json( $error( 'odoo.exceptions.ValidationError', 'Bad value' ), 422 ), ErrorCode::Val002 ),
			'bad arguments'      => array( FakeHttpClient::json( $error( 'werkzeug.exceptions.UnprocessableEntity', "missing a required argument: 'vals_list'" ), 422 ), ErrorCode::Val002 ),
			'rate limit'         => array( FakeHttpClient::tooManyRequests( 12 ), ErrorCode::Rate001 ),
			'server error'       => array( FakeHttpClient::json( $error( 'psycopg2.OperationalError', 'server closed the connection' ), 500 ), ErrorCode::Net002 ),
			'bad gateway page'   => array( FakeHttpClient::status( 502, '<html>Bad gateway</html>' ), ErrorCode::Net002 ),
			'gateway timeout'    => array( FakeHttpClient::status( 504 ), ErrorCode::Net001 ),
			'time out'           => array( FakeHttpClient::timeout(), ErrorCode::Net001 ),
			'certificate'        => array( FakeHttpClient::certificateError(), ErrorCode::Net003 ),
			'html with 200'      => array( new HttpResponse( 200, '<html>login</html>' ), ErrorCode::Net001 ),
			'broken json'        => array( FakeHttpClient::malformedJson(), ErrorCode::Net001 ),
			'conflict'           => array( FakeHttpClient::json( $error( 'odoo.exceptions.LockError', 'could not serialize access' ), 409 ), ErrorCode::Net002 ),
		);
	}

	public function test_a_404_page_names_the_database_in_the_error(): void {
		$http = ( new FakeHttpClient() )->queue( new HttpResponse( 404, 'No database is selected' ) );
		try {
			$this->transport( $http, 'cedarstone_prod' )->userId();
			$this->fail( 'Expected CFG-003.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( 'Odoo has no database called cedarstone_prod.', $exception->error->message() );
		}
	}

	public function test_the_key_never_appears_in_an_error(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array( 'name' => 'odoo.exceptions.UserError', 'message' => 'Key sk-secret-key-12345 was rejected', 'arguments' => array(), 'context' => array(), 'debug' => '' ), 422 ) );
		try {
			$this->transport( $http )->callModel( 'res.partner', 'search_count', array( 'domain' => array() ) );
			$this->fail( 'Expected a failure.' );
		} catch ( OdooException $exception ) {
			$this->assertStringNotContainsString( 'sk-secret-key-12345', $exception->getMessage() . $exception->error->detail . json_encode( $exception->error->context ) );
		}
	}

	public function test_a_key_that_gives_no_user_is_a_refused_sign_in(): void {
		$http = ( new FakeHttpClient() )->queue( FakeHttpClient::json( array( 'lang' => 'en_US' ) ) );
		$this->expectException( OdooException::class );
		$this->transport( $http )->userId();
	}
}
