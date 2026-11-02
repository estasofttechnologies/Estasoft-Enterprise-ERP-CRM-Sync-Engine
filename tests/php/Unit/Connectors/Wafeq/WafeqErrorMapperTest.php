<?php
/**
 * What the connector makes of each kind of Wafeq answer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqErrorMapper;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class WafeqErrorMapperTest extends TestCase {

	private function map( int $status, string $body, array $headers = array() ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		return ( new WafeqErrorMapper() )->map( new ApiResponse( $status, $body, $headers ) );
	}

	public function test_a_field_error_names_the_field_and_the_reason(): void {
		$error = $this->map( 400, '{"invoice_number":["This field is required."]}' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 'invoice_number', $error->context['field'] );
		$this->assertSame( 'This field is required.', $error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $error->errorClass() );
	}

	public function test_a_nested_line_error_names_the_path(): void {
		$error = $this->map( 400, '{"line_items":[{"account":["Invalid pk."]}]}' );
		$this->assertSame( 'line_items.account', $error->context['field'] );
		$this->assertSame( 'Invalid pk.', $error->context['reason'] );
	}

	public function test_a_detail_message_has_no_field(): void {
		$error = $this->map( 400, '{"detail":"The period is closed."}' );
		$this->assertSame( 'The period is closed.', $error->context['reason'] );
		$this->assertNotSame( '', $error->context['field'] );
	}

	public function test_an_answer_that_is_not_json_still_gives_a_reason(): void {
		$error = $this->map( 400, '<html>Bad request</html>' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertNotSame( '', $error->context['reason'] );
	}

	/**
	 * @return array<string,array{0:string,1:ErrorCode}>
	 */
	public static function provide_forbidden(): array {
		return array(
			'api switched off'     => array( '{"detail":"The public API is not enabled for this organization."}', ErrorCode::Auth006 ),
			'plan'                 => array( '{"detail":"Upgrade your plan to use the API."}', ErrorCode::Auth006 ),
			'permission'           => array( '{"detail":"You do not have permission to perform this action."}', ErrorCode::Auth004 ),
			'plan word elsewhere'  => array( '{"detail":"This invoice belongs to a plan of payments and cannot be edited."}', ErrorCode::Auth004 ),
			'not json'             => array( 'Forbidden', ErrorCode::Auth004 ),
		);
	}

	/**
	 * @dataProvider provide_forbidden
	 */
	public function test_a_403_is_a_plan_problem_only_when_the_words_say_so( string $body, ErrorCode $expected ): void {
		$this->assertSame( $expected, $this->map( 403, $body )->code );
	}

	public function test_a_missing_record_is_val_002_and_a_conflict_is_retried(): void {
		$this->assertSame( ErrorCode::Val002, $this->map( 404, '{"detail":"Not found."}' )->code );
		$conflict = $this->map( 409, '{"code":"report_in_progress"}' );
		$this->assertSame( ErrorCode::Net002, $conflict->code );
		$this->assertSame( ErrorClass::Retry, $conflict->errorClass() );
	}

	public function test_the_shared_mapping_keeps_401_429_and_5xx(): void {
		$this->assertNull( $this->map( 401, '{}' ) );
		$this->assertNull( $this->map( 429, '', array( 'retry-after' => '12' ) ) );
		$this->assertNull( $this->map( 503, '' ) );
	}
}
