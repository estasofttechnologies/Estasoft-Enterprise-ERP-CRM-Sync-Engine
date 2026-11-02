<?php
/**
 * The error shapes the three Microsoft services share.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class MicrosoftErrorsTest extends TestCase {

	private function response( int $status, mixed $body, array $headers = array() ): ApiResponse {
		return new ApiResponse( $status, is_string( $body ) ? $body : (string) wp_json_encode( $body ), $headers );
	}

	public function test_the_message_and_code_are_read_from_the_odata_error(): void {
		$odata = $this->response( 400, array( 'error' => array( 'code' => '0x80040265', 'message' => 'A value is missing.' ) ) );
		$this->assertSame( 'A value is missing.', MicrosoftErrors::message( $odata ) );
		$this->assertSame( '0x80040265', MicrosoftErrors::code( $odata ) );
	}

	public function test_an_error_that_is_plain_text_or_missing_gives_empty_values(): void {
		$this->assertSame( 'plain', MicrosoftErrors::message( $this->response( 400, array( 'error' => 'plain' ) ) ) );
		$this->assertSame( '', MicrosoftErrors::code( $this->response( 400, array( 'error' => 'plain' ) ) ) );
		$this->assertSame( '', MicrosoftErrors::message( $this->response( 500, 'not json at all' ) ) );
		$this->assertSame( '', MicrosoftErrors::message( $this->response( 500, array( 'other' => 1 ) ) ) );
		$this->assertSame( '', MicrosoftErrors::code( $this->response( 500, array( 'error' => array( 'code' => array( 'x' ) ) ) ) ) );
	}

	public function test_a_401_after_a_good_token_means_the_app_is_not_known_to_the_service(): void {
		$error = MicrosoftErrors::common( $this->response( 401, array( 'error' => array( 'message' => 'Unauthorized' ) ) ), 'Test system' );
		$this->assertSame( ErrorCode::Auth007, $error->code );
		$this->assertSame( 401, $error->httpStatus );
	}

	public function test_an_edit_conflict_is_retried_and_other_statuses_are_left_to_the_engine(): void {
		$conflict = MicrosoftErrors::common( $this->response( 409, array( 'error' => array( 'message' => 'Edit conflict' ) ), array( 'Retry-After' => '7' ) ), 'Test system' );
		$this->assertSame( ErrorCode::Net002, $conflict->code );
		$this->assertSame( 7, $conflict->retryAfter );

		$stale = MicrosoftErrors::common( $this->response( 412, array( 'error' => array( 'message' => 'The ETag does not match: concurrency violation.' ) ) ), 'Test system' );
		$this->assertSame( ErrorCode::Net002, $stale->code );

		$this->assertNull( MicrosoftErrors::common( $this->response( 412, array( 'error' => array( 'message' => 'A required condition failed.' ) ) ), 'Test system' ) );
		foreach ( array( 400, 403, 404, 429, 500, 503 ) as $status ) {
			$this->assertNull( MicrosoftErrors::common( $this->response( $status, '' ), 'Test system' ), (string) $status );
		}
	}
}
