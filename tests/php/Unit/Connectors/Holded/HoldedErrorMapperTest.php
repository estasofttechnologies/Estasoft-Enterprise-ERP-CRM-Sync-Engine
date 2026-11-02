<?php
/**
 * What the connector makes of each kind of Holded answer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Connectors\Holded\HoldedErrorMapper;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class HoldedErrorMapperTest extends TestCase {

	private function map( int $status, string $body, array $headers = array() ): ?ConnectorError {
		return ( new HoldedErrorMapper() )->map( new ApiResponse( $status, $body, $headers ) );
	}

	public function test_a_validation_answer_gives_the_reason(): void {
		$error = $this->map( 400, '{"status":0,"info":"items.0.sku: unknown product"}' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 'items.0.sku: unknown product', $error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $error->errorClass() );
	}

	public function test_a_message_or_an_error_text_is_the_reason_too(): void {
		$this->assertSame( 'Too long', $this->map( 422, '{"message":"Too long"}' )->context['reason'] );
		$this->assertSame( 'Bad things', $this->map( 400, '{"error":"Bad things"}' )->context['reason'] );
		$this->assertSame( 'First', $this->map( 400, '{"errors":[["First"],["Second"]]}' )->context['reason'] );
	}

	public function test_an_answer_that_is_not_json_still_gives_a_reason(): void {
		$error = $this->map( 400, '<html>Bad request</html>' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertNotSame( '', $error->context['reason'] );
	}

	public function test_a_long_reason_is_cut(): void {
		$error = $this->map( 400, json_encode( array( 'info' => str_repeat( 'x', 2000 ) ) ) );
		$this->assertLessThanOrEqual( 300, mb_strlen( $error->context['reason'] ) );
	}

	/**
	 * @return array<string,array{0:string,1:ErrorCode}>
	 */
	public static function provide_forbidden(): array {
		return array(
			'plan and api'        => array( '{"info":"Your plan does not include API access. Please upgrade your plan."}', ErrorCode::Auth006 ),
			'subscription'        => array( '{"message":"The API is not part of your subscription"}', ErrorCode::Auth006 ),
			'permission'          => array( '{"info":"You do not have permission to use this module"}', ErrorCode::Auth004 ),
			'plan without api'    => array( '{"info":"This document belongs to a plan of payments"}', ErrorCode::Auth004 ),
			'api without plan'    => array( '{"info":"The API key has no access to contacts"}', ErrorCode::Auth004 ),
			'not json'            => array( 'Forbidden', ErrorCode::Auth004 ),
		);
	}

	/**
	 * @dataProvider provide_forbidden
	 */
	public function test_a_403_is_a_plan_problem_only_when_the_words_say_so( string $body, ErrorCode $expected ): void {
		$this->assertSame( $expected, $this->map( 403, $body )->code );
	}

	public function test_a_402_is_the_plan_error_and_a_404_is_val_002(): void {
		$this->assertSame( ErrorCode::Auth006, $this->map( 402, '' )->code );
		$missing = $this->map( 404, '{"info":"Document not found"}' );
		$this->assertSame( ErrorCode::Val002, $missing->code );
		$this->assertSame( 404, $missing->httpStatus );
	}

	public function test_the_shared_mapping_keeps_401_429_and_5xx(): void {
		$this->assertNull( $this->map( 401, '{}' ) );
		$this->assertNull( $this->map( 429, '', array( 'retry-after' => '12' ) ) );
		$this->assertNull( $this->map( 503, '' ) );
		$this->assertNull( $this->map( 500, '' ) );
	}

	public function test_a_200_answer_with_status_zero_is_an_error_and_a_list_or_a_success_is_not(): void {
		$mapper = new HoldedErrorMapper();
		$this->assertSame( ErrorCode::Val002, $mapper->answer( array( 'status' => 0, 'info' => 'Contact not found' ) )->code );
		$this->assertSame( 'Contact not found', $mapper->answer( array( 'status' => 0, 'info' => 'Contact not found' ) )->context['reason'] );
		$this->assertSame( ErrorCode::Val002, $mapper->answer( array( 'status' => '0' ) )->code );
		$this->assertNull( $mapper->answer( array( 'status' => 1, 'info' => 'Created', 'id' => 'x' ) ) );
		$this->assertNull( $mapper->answer( array( array( 'status' => 0 ) ) ), 'A list whose first record has a status is a list.' );
		$this->assertNull( $mapper->answer( array( 'id' => 'x' ) ) );
	}
}
