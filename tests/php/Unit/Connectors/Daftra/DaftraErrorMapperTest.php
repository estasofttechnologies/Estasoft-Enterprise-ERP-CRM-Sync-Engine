<?php
/**
 * What the connector makes of each kind of Daftra answer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Daftra;

use Estasoft\SyncEngine\Connectors\Daftra\DaftraErrorMapper;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class DaftraErrorMapperTest extends TestCase {

	private function map( int $status, string $body, array $headers = array() ): ?ConnectorError {
		return ( new DaftraErrorMapper() )->map( new ApiResponse( $status, $body, $headers ) );
	}

	public function test_a_validation_map_names_the_field_and_the_reason(): void {
		$error = $this->map( 400, '{"result":"failed","code":400,"validation":{"client_id":["Client does not exist"]}}' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 'client_id', $error->context['field'] );
		$this->assertSame( 'Client does not exist', $error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $error->errorClass() );
	}

	public function test_a_nested_line_error_keeps_its_path(): void {
		$error = $this->map( 400, '{"validation":{"InvoiceItem.0.tax1":["Tax does not exist"]}}' );
		$this->assertSame( 'InvoiceItem.0.tax1', $error->context['field'] );
	}

	public function test_a_plain_message_is_the_reason(): void {
		$error = $this->map( 400, '{"result":"failed","message":"The period is closed."}' );
		$this->assertSame( 'The period is closed.', $error->context['reason'] );
		$this->assertNotSame( '', $error->context['field'] );
	}

	public function test_an_error_given_as_a_text_is_the_reason(): void {
		$this->assertSame( 'Bad things', $this->map( 422, '{"error":"Bad things"}' )->context['reason'] );
	}

	public function test_an_answer_that_is_not_json_still_gives_a_reason(): void {
		$error = $this->map( 400, '<html>Bad request</html>' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertNotSame( '', $error->context['reason'] );
	}

	public function test_a_402_is_the_plan_error_and_a_403_a_permission_error(): void {
		$plan = $this->map( 402, '{"message":"Payment required"}' );
		$this->assertSame( ErrorCode::Auth006, $plan->code );
		$this->assertSame( ErrorCode::Auth004, $this->map( 403, '{"message":"You may not use this plan"}' )->code, 'A word in a 403 is not enough to call it a plan problem.' );
		$this->assertSame( ErrorCode::Auth004, $this->map( 403, 'Forbidden' )->code );
	}

	public function test_a_missing_record_is_val_002(): void {
		$error = $this->map( 404, '{"result":"failed","code":404}' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 404, $error->httpStatus );
	}

	public function test_the_shared_mapping_keeps_401_429_and_5xx(): void {
		$this->assertNull( $this->map( 401, '{}' ) );
		$this->assertNull( $this->map( 429, '', array( 'retry-after' => '12' ) ) );
		$this->assertNull( $this->map( 503, '' ) );
		$this->assertNull( $this->map( 500, '' ) );
	}
}
