<?php
/**
 * What the connector makes of each kind of Qoyod answer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Connectors\Qoyod\QoyodAmounts;
use Estasoft\SyncEngine\Connectors\Qoyod\QoyodErrorMapper;
use Estasoft\SyncEngine\Connectors\Qoyod\QoyodLines;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class QoyodErrorMapperTest extends TestCase {

	private function map( int $status, string $body ): ?ConnectorError {
		return ( new QoyodErrorMapper() )->map( new ApiResponse( $status, $body ) );
	}

	public function test_a_field_error_names_the_field_and_the_reason(): void {
		$error = $this->map( 422, '{"errors":{"issue_date":["can\'t be blank"]}}' );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 'issue_date', $error->context['field'] );
		$this->assertSame( "can't be blank", $error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $error->errorClass() );
	}

	public function test_the_other_shapes_a_rails_api_uses_are_read(): void {
		$this->assertSame( 'Something is wrong', $this->map( 400, '{"errors":["Something is wrong"]}' )->context['reason'] );
		$this->assertSame( 'No good', $this->map( 400, '{"error":"No good"}' )->context['reason'] );
		$this->assertSame( 'Bad', $this->map( 400, '{"message":"Bad"}' )->context['reason'] );
		$this->assertNotSame( '', $this->map( 400, '<html>Bad request</html>' )->context['reason'] );
	}

	public function test_a_taken_reference_is_a_duplicate_that_the_senders_resolve(): void {
		$error = $this->map( 422, '{"errors":{"reference":["has already been taken"]}}' );
		$this->assertSame( ErrorCode::Dup001, $error->code );
		$this->assertSame( ErrorClass::Handled, $error->errorClass() );
		$this->assertSame( ErrorCode::Val002, $this->map( 422, '{"errors":{"name":["has already been taken"]}}' )->code );
	}

	public function test_a_403_is_a_plan_problem_only_when_the_words_say_so(): void {
		$this->assertSame( ErrorCode::Auth006, $this->map( 403, '{"error":"The API is not included in your plan, upgrade to Pro"}' )->code );
		$this->assertSame( ErrorCode::Auth004, $this->map( 403, '{"error":"You are not allowed to see invoices"}' )->code );
		$this->assertSame( ErrorCode::Auth004, $this->map( 403, '{"error":"Your plan of payments is closed"}' )->code );
		$this->assertSame( ErrorCode::Val002, $this->map( 404, '{}' )->code );
	}

	public function test_the_shared_mapping_keeps_401_429_and_5xx(): void {
		$this->assertNull( $this->map( 401, '{}' ) );
		$this->assertNull( $this->map( 429, '' ) );
		$this->assertNull( $this->map( 500, '' ) );
	}

	public function test_a_line_without_a_tax_is_refused_unless_the_merchant_chose_a_code(): void {
		$lines = new QoyodLines( new QoyodAmounts() );
		$row   = array( 'item' => '3', 'name' => 'Fee', 'quantity' => 1.0, 'total_minor' => 500, 'tax_code' => '', 'tax_amount' => null );
		$taxes = array( '2' => array( 'name' => 'VAT 0%', 'rate_bp' => 0 ), '3' => array( 'name' => 'Exempt', 'rate_bp' => 0 ) );

		$refused = $lines->build( array( $row ), 'SAR', false, 'tax_id', '1', $taxes );
		$this->assertInstanceOf( ConnectorError::class, $refused );
		$this->assertSame( ErrorCode::Val002, $refused->code );
		$this->assertSame( 'lines[].tax_code', $refused->context['field'] );
		$this->assertStringContainsString( 'Fee', $refused->context['reason'] );

		$zero = $lines->build( array( $row ), 'SAR', false, 'tax_id', '1', $taxes, QoyodLines::ZERO_RATED );
		$this->assertSame( '2', $zero['lines'][0]['tax_id'] );
		$exempt = $lines->build( array( $row ), 'SAR', false, 'tax_id', '1', $taxes, QoyodLines::EXEMPT );
		$this->assertSame( '3', $exempt['lines'][0]['tax_id'] );
		$percent = $lines->build( array( $row ), 'SAR', false, 'tax_percent', '1', $taxes, QoyodLines::ZERO_RATED );
		$this->assertSame( '0.00', $percent['lines'][0]['tax_percent'] );
	}

	public function test_a_custom_tax_code_cannot_be_sent_as_a_percentage(): void {
		$lines = new QoyodLines( new QoyodAmounts() );
		$built = $lines->build( array( array( 'item' => '3', 'name' => 'Fee', 'quantity' => 1.0, 'total_minor' => 500, 'tax_code' => '9', 'tax_amount' => null ) ), 'SAR', false, 'tax_percent', '1', array() );
		$this->assertInstanceOf( ConnectorError::class, $built );
		$this->assertSame( ErrorCode::Map002, $built->code );
	}
}
