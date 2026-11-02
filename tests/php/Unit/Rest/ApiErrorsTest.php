<?php
/**
 * ApiException and ErrorMapper: which failure becomes which answer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Rest;

use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\Inbound\InboundRefused;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\RuleError;
use Estasoft\SyncEngine\Mapping\RuleValidationException;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\ErrorMapper;
use PHPUnit\Framework\TestCase;

final class ApiErrorsTest extends TestCase {

	/**
	 * What the mapper reported.
	 *
	 * @var array<int,string>
	 */
	private array $reported = array();

	private function mapper(): ErrorMapper {
		return new ErrorMapper(
			function ( string $description ): void {
				$this->reported[] = $description;
			}
		);
	}

	public function test_the_body_has_the_documented_shape(): void {
		$body = ( new ApiException( 'SYNC-MAP-001', 'Product A was not found.', 422, 'needs_person', array( 'sku' => 'A' ) ) )->toBody();
		$this->assertSame(
			array(
				'code'    => 'SYNC-MAP-001',
				'message' => 'Product A was not found.',
				'data'    => array(
					'status'  => 422,
					'class'   => 'needs_person',
					'context' => array( 'sku' => 'A' ),
				),
			),
			$body
		);
	}

	public function test_empty_context_fields_and_details_are_left_out_and_an_unknown_class_becomes_needs_person(): void {
		$body = ( new ApiException( 'x', 'y', 400, 'weird' ) )->toBody();
		$this->assertSame( array( 'status' => 400, 'class' => 'needs_person' ), $body['data'] );
	}

	public function test_a_catalog_error_fills_the_placeholders_and_keeps_the_class_of_the_code(): void {
		$error = ApiException::fromCatalog( ErrorCode::Map001, array( 'sku' => 'CS-LAMP-07', 'system' => 'Odoo' ), 422 );
		$this->assertSame( 'SYNC-MAP-001', $error->apiCode );
		$this->assertSame( 'Product CS-LAMP-07 was not found in Odoo.', $error->getMessage() );
		$this->assertSame( 'needs_person', $error->errorClass );
		$this->assertSame( array( 'sku' => 'CS-LAMP-07', 'system' => 'Odoo' ), $error->context );
		$this->assertSame( 'retry', ApiException::fromCatalog( ErrorCode::Net001, array( 'system' => 'Odoo' ) )->errorClass );
		$this->assertSame( 'info', ApiException::fromCatalog( ErrorCode::Inb001 )->errorClass );
	}

	public function test_the_helpers_use_the_wordpress_codes_where_wordpress_has_one(): void {
		$this->assertSame( array( 'rest_not_found', 404 ), array( ApiException::notFound( 'x' )->apiCode, ApiException::notFound( 'x' )->status ) );
		$invalid = ApiException::invalid( 'x', array( 'a' => 'b' ) );
		$this->assertSame( array( 'rest_invalid_param', 422, array( 'a' => 'b' ) ), array( $invalid->apiCode, $invalid->status, $invalid->fields ) );
		$this->assertSame( 409, ApiException::conflict( 'x' )->status );
		$generic = ApiException::generic();
		$this->assertSame( array( 'SYNC-REST-001', 500, 'info' ), array( $generic->apiCode, $generic->status, $generic->errorClass ) );
	}

	public function test_an_api_exception_passes_through_unchanged(): void {
		$error = ApiException::conflict( 'x' );
		$this->assertSame( $error, $this->mapper()->map( $error ) );
		$this->assertSame( array(), $this->reported );
	}

	public function test_field_errors_become_a_422_with_one_message_per_field(): void {
		$mapped = $this->mapper()->map( new ValidationException( array( 'safety_buffer' => 'Enter a number from 0 to 999.' ) ) );
		$this->assertSame( array( 'rest_invalid_param', 422, array( 'safety_buffer' => 'Enter a number from 0 to 999.' ) ), array( $mapped->apiCode, $mapped->status, $mapped->fields ) );
	}

	public function test_rule_problems_are_listed_by_rule_and_part_with_the_full_list_in_the_details(): void {
		$mapped = $this->mapper()->map(
			new RuleValidationException(
				array(
					new RuleError( 'r1', 'target', 'target_unknown', 'This field does not exist.' ),
					new RuleError( 'r1', 'transforms[0]', 'transform_unknown', 'Unknown transform.' ),
					new RuleError( '', 'rules', 'empty', 'Add at least one rule.' ),
				)
			)
		);
		$this->assertSame( 422, $mapped->status );
		$this->assertSame( array( 'rules.r1.target', 'rules.r1.transforms[0]', 'rules.rules' ), array_keys( $mapped->fields ) );
		$this->assertCount( 3, $mapped->details['errors'] );
		$this->assertSame( 'target_unknown', $mapped->details['errors'][0]['code'] );
	}

	public function test_a_mapping_failure_keeps_its_catalog_code(): void {
		$mapped = $this->mapper()->map( new MappingException( ErrorCode::Val001, array( 'country' => 'Saudi Arabia' ) ) );
		$this->assertSame( array( 'SYNC-VAL-001', 422 ), array( $mapped->apiCode, $mapped->status ) );
	}

	public function test_a_missing_encryption_key_is_a_409_sync_cfg_002_and_a_changed_key_is_a_409_without_a_catalog_code(): void {
		$missing = $this->mapper()->map( CryptoException::keyMissing() );
		$this->assertSame( array( 'SYNC-CFG-002', 409, 'needs_person' ), array( $missing->apiCode, $missing->status, $missing->errorClass ) );
		$changed = $this->mapper()->map( CryptoException::keyChanged() );
		$this->assertSame( array( 'estasoft_sync_credentials_unreadable', 409 ), array( $changed->apiCode, $changed->status ) );
	}

	public function test_a_jobs_refusal_keeps_its_status_message_code_and_named_facts(): void {
		$with = $this->mapper()->map( new JobException( 'Refused.', 422, ErrorCode::Auth001, array( 'test' => array( 'ok' => false ), 'key_line' => 'define()', 'system' => 'Odoo', 'noise' => array( 'nested' ) ) ) );
		$this->assertSame( array( 'SYNC-AUTH-001', 422, 'Refused.' ), array( $with->apiCode, $with->status, $with->getMessage() ) );
		$this->assertSame( array( 'test' => array( 'ok' => false ), 'key_line' => 'define()' ), $with->details );
		$this->assertSame( array( 'system' => 'Odoo' ), $with->context, 'only scalar values go in the context' );
	}

	/**
	 * @dataProvider statuses
	 *
	 * @param int    $status HTTP status of the Jobs refusal.
	 * @param string $code   Code the answer should carry.
	 * @param string $class  Class of the answer.
	 */
	public function test_a_jobs_refusal_without_a_catalog_code_gets_a_code_from_its_status( int $status, string $code, string $class ): void {
		$mapped = $this->mapper()->map( new JobException( 'Because.', $status ) );
		$this->assertSame( array( $code, $status, $class ), array( $mapped->apiCode, $mapped->status, $mapped->errorClass ) );
	}

	/**
	 * Statuses.
	 *
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public static function statuses(): array {
		return array(
			'not found' => array( 404, 'rest_not_found', 'needs_person' ),
			'conflict'  => array( 409, 'estasoft_sync_conflict', 'needs_person' ),
			'invalid'   => array( 422, 'rest_invalid_param', 'needs_person' ),
			'bad'       => array( 400, 'rest_invalid_param', 'needs_person' ),
			'too many'  => array( 429, 'estasoft_sync_refused', 'needs_person' ),
			'down'      => array( 503, 'estasoft_sync_failed', 'retry' ),
		);
	}

	public function test_an_inbound_refusal_is_a_jobs_refusal_with_sync_inb_001(): void {
		$mapped = $this->mapper()->map( new InboundRefused( 'The signature or the time is wrong.', 401 ) );
		$this->assertSame( array( 'SYNC-INB-001', 401, 'info' ), array( $mapped->apiCode, $mapped->status, $mapped->errorClass ) );
	}

	/**
	 * @dataProvider plainExceptions
	 *
	 * @param \Throwable $error A plain exception any library could throw.
	 */
	public function test_a_plain_invalid_argument_or_out_of_bounds_exception_is_not_a_404_or_a_422_and_never_shows_its_message( \Throwable $error ): void {
		$mapped = $this->mapper()->map( $error );
		$this->assertSame( array( 'SYNC-REST-001', 500 ), array( $mapped->apiCode, $mapped->status ) );
		$this->assertStringNotContainsString( 'hunter2', $mapped->getMessage() );
		$this->assertSame( array( get_class( $error ) ), $this->reported, 'logged, with the class and nothing else' );
		$this->assertStringNotContainsString( 'hunter2', implode( '', $this->reported ) );
	}

	/**
	 * Exceptions that used to be turned into 404 and 422 answers.
	 *
	 * @return array<string,array{0:\Throwable}>
	 */
	public static function plainExceptions(): array {
		return array(
			'out of bounds'    => array( new \OutOfBoundsException( 'Version 99 password=hunter2 does not exist.' ) ),
			'invalid argument' => array( new \InvalidArgumentException( 'Bad row password=hunter2.' ) ),
			'logic'            => array( new \LogicException( 'Service password=hunter2 is not registered.' ) ),
			'type error'       => array( new \TypeError( 'password=hunter2' ) ),
		);
	}

	public function test_anything_else_is_a_generic_500_that_reports_only_the_class(): void {
		$mapped = $this->mapper()->map( new \RuntimeException( 'password=hunter2 at /var/www/wp-config.php', 42 ) );
		$this->assertSame( array( 'SYNC-REST-001', 500 ), array( $mapped->apiCode, $mapped->status ) );
		$this->assertStringNotContainsString( 'hunter2', $mapped->getMessage() );
		$this->assertSame( array( 'RuntimeException' ), $this->reported, 'no message, no code, no file' );
	}

	public function test_what_a_connector_detected_never_reaches_the_details_of_a_failed_test(): void {
		$mapped = $this->mapper()->map( new JobException( 'Refused.', 422, ErrorCode::Auth001, array( 'test' => array( 'ok' => false, 'detected' => array( 'token' => 'x' ) ) ) ) );
		$this->assertSame( array( 'test' => array( 'ok' => false ) ), $mapped->details );
	}

	public function test_the_default_reporter_ignores_everything(): void {
		$mapped = ( new ErrorMapper() )->map( new \LogicException( 'x' ) );
		$this->assertSame( 500, $mapped->status );
	}
}
