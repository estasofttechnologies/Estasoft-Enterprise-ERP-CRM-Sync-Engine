<?php
/**
 * Mapping of Odoo failures to catalog errors.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\OdooErrorMapper;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Connectors\Odoo\OdooVersion;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OdooErrorMapperTest extends TestCase {

	private function mapper( bool $online = false ): OdooErrorMapper {
		return new OdooErrorMapper( array( 'sk-secret-key-12345', 'sync@example.com' ), $online );
	}

	/**
	 * A JSON-RPC error object.
	 *
	 * @param string $name    Exception name.
	 * @param string $message Message.
	 * @param int    $code    JSON-RPC code.
	 * @return array<string,mixed>
	 */
	private function rpcError( string $name, string $message, int $code = 200 ): array {
		return array(
			'code'    => $code,
			'message' => 'Odoo Server Error',
			'data'    => array(
				'name'      => $name,
				'message'   => $message,
				'arguments' => array( $message ),
				'context'   => array(),
				'debug'     => 'Traceback ...',
			),
		);
	}

	public function test_a_time_out_is_net_001_and_retries(): void {
		$exception = $this->mapper()->transportFailure( FakeHttpClient::timeout(), 'sale.order', 'create' );
		$this->assertSame( ErrorCode::Net001, $exception->error->code );
		$this->assertSame( ErrorClass::Retry, $exception->error->errorClass() );
		$this->assertSame( 'sale.order', $exception->model );
	}

	public function test_an_unreachable_server_is_net_001(): void {
		$this->assertSame( ErrorCode::Net001, $this->mapper()->transportFailure( FakeHttpClient::unreachable() )->error->code );
	}

	public function test_an_untrusted_certificate_is_net_003_and_needs_a_person(): void {
		$exception = $this->mapper()->transportFailure( FakeHttpClient::certificateError() );
		$this->assertSame( ErrorCode::Net003, $exception->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $exception->error->errorClass() );
	}

	public function test_certificate_wording_from_other_clients_is_recognised(): void {
		foreach ( array( 'SSL certificate problem: self signed certificate', 'cURL error 60: unable to get local issuer certificate' ) as $text ) {
			$this->assertSame( ErrorCode::Net003, $this->mapper()->transportFailure( new HttpResponse( 0, '', array(), $text ) )->error->code, $text );
		}
	}

	public function test_an_address_the_http_client_refuses_is_val_002_about_the_address(): void {
		$exception = $this->mapper()->transportFailure( new HttpResponse( 0, '', array(), 'unsafe_url: the address is not allowed' ) );
		$this->assertSame( ErrorCode::Val002, $exception->error->code );
		$this->assertSame( 'Odoo address', $exception->error->context['field'] );
	}

	public function test_401_is_auth_001(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 401, '{"name":"werkzeug.exceptions.Unauthorized","message":"Invalid apikey"}' ), array( 'name' => 'werkzeug.exceptions.Unauthorized', 'message' => 'Invalid apikey' ) );
		$this->assertSame( ErrorCode::Auth001, $exception->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $exception->error->errorClass() );
		$this->assertSame( 401, $exception->error->httpStatus );
	}

	public function test_an_access_error_is_auth_004_and_names_the_action(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 403 ), array( 'name' => 'odoo.exceptions.AccessError', 'message' => 'You are not allowed to create documents of type sale.order' ), 'sale.order', 'create' );
		$this->assertSame( ErrorCode::Auth004, $exception->error->code );
		$this->assertSame( 'create sale orders', $exception->error->context['action'] );
		$this->assertStringContainsString( 'does not allow this user to create sale orders', $exception->error->message() );
		$this->assertTrue( $exception->isAccessError() );
	}

	public function test_access_denied_is_a_refused_sign_in(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.AccessDenied', 'Access Denied' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Auth001, $exception->error->code );
	}

	public function test_a_403_page_from_odoo_online_is_the_plan_error(): void {
		$exception = $this->mapper( true )->httpFailure( new HttpResponse( 403, '<html>Forbidden</html>' ), null, 'res.partner', 'search_read' );
		$this->assertSame( ErrorCode::Auth006, $exception->error->code );
		$this->assertStringContainsString( 'plan does not include outside access', $exception->error->message() );
	}

	public function test_a_403_page_from_another_host_is_a_rights_error(): void {
		$exception = $this->mapper( false )->httpFailure( new HttpResponse( 403, '<html>Forbidden</html>' ), null );
		$this->assertSame( ErrorCode::Auth004, $exception->error->code );
	}

	public function test_a_plan_message_on_odoo_online_is_the_plan_error(): void {
		$exception = $this->mapper( true )->rpcFault( $this->rpcError( 'odoo.exceptions.AccessDenied', 'External API access is not available on your Odoo plan' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Auth006, $exception->error->code );
		$other = $this->mapper( false )->rpcFault( $this->rpcError( 'odoo.exceptions.AccessDenied', 'External API access is not available on your Odoo plan' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Auth001, $other->error->code, 'Only an odoo.com address is read as a plan problem.' );
	}

	public function test_the_no_database_page_is_cfg_003(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 404, '<p>No database is selected and the requested URL was not found in the server-wide controllers.</p>' ), null );
		$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
		$named = $this->mapper()->withDatabase( $exception, 'cedarstone_prod' );
		$this->assertSame( 'Odoo has no database called cedarstone_prod.', $named->error->message() );
	}

	public function test_a_database_error_from_json_rpc_is_cfg_003_with_the_name(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'psycopg2.OperationalError', 'connection failed: FATAL:  database "cedarstone_prod" does not exist' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
		$this->assertSame( 'cedarstone_prod', $exception->error->context['name'] );
	}

	public function test_another_database_error_is_a_server_error(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'psycopg2.OperationalError', 'server closed the connection unexpectedly' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Net002, $exception->error->code );
	}

	public function test_a_missing_model_is_flagged_for_both_transports(): void {
		$rpc = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.UserError', "Object stock.quant doesn't exist" ), new HttpResponse( 200 ), 'stock.quant', 'search_read' );
		$this->assertTrue( $rpc->modelMissing );
		$json2 = $this->mapper()->httpFailure( new HttpResponse( 404 ), array( 'name' => 'werkzeug.exceptions.NotFound', 'message' => "the model 'stock.quant' does not exist" ), 'stock.quant', 'search_read' );
		$this->assertTrue( $json2->modelMissing );
		$this->assertSame( ErrorCode::Val002, $json2->error->code );
	}

	public function test_422_and_user_errors_are_val_002_with_the_reason(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 422 ), array( 'name' => 'odoo.exceptions.ValidationError', 'message' => "Wrong value for sale.order.line.tax_id: 'x'" ), 'sale.order', 'create' );
		$this->assertSame( ErrorCode::Val002, $exception->error->code );
		$this->assertSame( 'sale.order.line.tax_id', $exception->error->context['field'] );
		$this->assertStringContainsString( 'Wrong value', $exception->error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $exception->error->errorClass() );
	}

	public function test_the_field_is_read_from_the_message_when_it_names_one(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'builtins.ValueError', "Invalid field 'tax_ids' on model 'sale.order.line'" ), new HttpResponse( 200 ), 'sale.order', 'create' );
		$this->assertSame( 'tax_ids', $exception->error->context['field'] );
		$missing = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.ValidationError', "Missing required value for the field 'partner_id' (partner_id)." ), new HttpResponse( 200 ) );
		$this->assertSame( 'partner_id', $missing->error->context['field'] );
	}

	public function test_a_record_deleted_in_between_may_be_tried_again(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.' ), new HttpResponse( 200 ), 'product.product', 'read' );
		$this->assertSame( ErrorClass::Retry, $exception->error->errorClass() );
		$this->assertTrue( $exception->isMissingRecord() );
	}

	public function test_lock_and_serialisation_errors_are_net_002_and_retry(): void {
		foreach ( array( 'odoo.exceptions.LockError', 'psycopg2.errors.SerializationFailure', 'psycopg2.errors.DeadlockDetected', 'odoo.exceptions.ConcurrencyError' ) as $name ) {
			$exception = $this->mapper()->rpcFault( $this->rpcError( $name, 'could not serialize access due to concurrent update' ), new HttpResponse( 200 ) );
			$this->assertSame( ErrorCode::Net002, $exception->error->code, $name );
			$this->assertSame( ErrorClass::Retry, $exception->error->errorClass(), $name );
			$this->assertSame( 409, $exception->error->httpStatus );
		}
	}

	public function test_database_constraint_errors_are_val_002_and_do_not_retry(): void {
		foreach ( array( 'psycopg2.errors.CheckViolation', 'psycopg2.errors.NotNullViolation', 'psycopg2.errors.UniqueViolation' ) as $name ) {
			$exception = $this->mapper()->rpcFault( $this->rpcError( $name, 'violates constraint' ), new HttpResponse( 200 ), 'sale.order.line', 'create' );
			$this->assertSame( ErrorCode::Val002, $exception->error->code, $name );
			$this->assertSame( ErrorClass::NeedsPerson, $exception->error->errorClass(), $name );
		}
	}

	public function test_429_is_rate_001_with_the_retry_after_seconds(): void {
		$exception = $this->mapper()->httpFailure( FakeHttpClient::tooManyRequests( 45 ), null );
		$this->assertSame( ErrorCode::Rate001, $exception->error->code );
		$this->assertSame( 45, $exception->error->retryAfter );
		$this->assertSame( 45, $exception->error->context['n'] );
		$this->assertSame( 429, $exception->error->httpStatus );
		$this->assertSame( ErrorClass::Retry, $exception->error->errorClass() );
	}

	public function test_429_without_a_header_waits_a_minute(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 429 ), null );
		$this->assertNull( $exception->error->retryAfter );
		$this->assertSame( 60, $exception->error->context['n'] );
	}

	public function test_server_errors_are_net_002_and_gateway_time_outs_are_net_001(): void {
		$this->assertSame( ErrorCode::Net002, $this->mapper()->httpFailure( new HttpResponse( 500, 'oops' ), null )->error->code );
		$this->assertSame( ErrorCode::Net002, $this->mapper()->httpFailure( new HttpResponse( 502, 'bad gateway' ), null )->error->code );
		$this->assertSame( ErrorCode::Net002, $this->mapper()->httpFailure( new HttpResponse( 503, 'busy' ), null )->error->code );
		$this->assertSame( ErrorCode::Net001, $this->mapper()->httpFailure( new HttpResponse( 504, 'gateway timeout' ), null )->error->code );
		$this->assertSame( ErrorCode::Net001, $this->mapper()->httpFailure( new HttpResponse( 408 ), null )->error->code );
		$this->assertSame( 502, $this->mapper()->httpFailure( new HttpResponse( 502 ), null )->error->httpStatus );
	}

	public function test_a_redirect_asks_for_the_final_address(): void {
		foreach ( array( 301, 302, 307, 308 ) as $status ) {
			$exception = $this->mapper()->httpFailure( new HttpResponse( $status, '', array( 'location' => 'https://www.example.com/' ) ), null );
			$this->assertSame( ErrorCode::Val002, $exception->error->code, (string) $status );
			$this->assertSame( 'Odoo address', $exception->error->context['field'] );
			$this->assertStringContainsString( 'another address', $exception->error->context['reason'] );
		}
	}

	public function test_a_plain_404_is_not_an_odoo_address(): void {
		$exception = $this->mapper()->httpFailure( new HttpResponse( 404, '<h1>Not Found</h1>' ), null );
		$this->assertSame( ErrorCode::Net001, $exception->error->code );
	}

	public function test_an_unknown_fault_is_net_002_so_it_is_tried_again_then_shown(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'builtins.RuntimeError', 'boom' ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Net002, $exception->error->code );
		$this->assertSame( ErrorClass::Retry, $exception->error->errorClass() );
	}

	public function test_an_expired_session_is_auth_002(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.http.SessionExpiredException', 'Session expired', 100 ), new HttpResponse( 200 ) );
		$this->assertSame( ErrorCode::Auth002, $exception->error->code );
	}

	public function test_secrets_are_removed_from_every_text(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.UserError', 'Bad key sk-secret-key-12345 for user sync@example.com' ), new HttpResponse( 200 ), 'sale.order', 'create' );
		$text      = $exception->getMessage() . json_encode( $exception->error->context ) . $exception->error->detail;
		$this->assertStringNotContainsString( 'sk-secret-key-12345', $text );
		$this->assertStringNotContainsString( 'sync@example.com', $text );
		$this->assertStringContainsString( '***', $text );
	}

	public function test_long_messages_are_cut_and_tags_removed(): void {
		$long      = '<p>' . str_repeat( 'x', 1000 ) . '</p>';
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.UserError', $long ), new HttpResponse( 200 ) );
		$this->assertLessThan( 300, mb_strlen( $exception->error->context['reason'] ) );
		$this->assertStringNotContainsString( '<p>', $exception->error->context['reason'] );
	}

	public function test_arabic_text_in_a_message_survives(): void {
		$exception = $this->mapper()->rpcFault( $this->rpcError( 'odoo.exceptions.ValidationError', 'العنوان مفقود' ), new HttpResponse( 200 ) );
		$this->assertSame( 'العنوان مفقود', $exception->error->context['reason'] );
	}

	public function test_an_unsupported_version_is_val_002_with_the_version_in_the_reason(): void {
		$exception = $this->mapper()->unsupportedVersion( OdooVersion::fromAnswer( array( 'version' => '16.0' ) ) );
		$this->assertSame( ErrorCode::Val002, $exception->error->code );
		$this->assertStringContainsString( 'Odoo 16 Community', $exception->error->context['reason'] );
		$this->assertStringContainsString( 'Odoo 17 or later', $exception->error->context['reason'] );
	}

	public function test_an_unreadable_answer_is_net_001(): void {
		$this->assertSame( ErrorCode::Net001, $this->mapper()->unreadable( FakeHttpClient::malformedJson() )->error->code );
	}

	public function test_the_exception_message_is_the_catalog_message(): void {
		$exception = $this->mapper()->transportFailure( FakeHttpClient::timeout() );
		$this->assertInstanceOf( OdooException::class, $exception );
		$this->assertSame( 'Couldn’t reach Odoo, or it didn’t answer within 30 seconds.', $exception->getMessage() );
	}
}
