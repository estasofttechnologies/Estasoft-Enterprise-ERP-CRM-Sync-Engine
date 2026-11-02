<?php
/**
 * Classification of failures.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Queue\ErrorClassifier;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\Queue\FailureAction;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore;
use Estasoft\SyncEngine\Tests\Support\CoreFakeConnector;
use PHPUnit\Framework\TestCase;

final class ErrorClassifierTest extends TestCase {

	private ErrorClassifier $classifier;

	protected function setUp(): void {
		$this->classifier = new ErrorClassifier();
	}

	/**
	 * @dataProvider httpAnswers
	 *
	 * @param array<string,string> $headers Headers.
	 */
	public function test_http_answers_map_to_catalog_codes( int $status, string $transport, array $headers, ?ErrorCode $expected ): void {
		$error = $this->classifier->fromResponse( new HttpResponse( $status, '', $headers, $transport ), 'Odoo' );
		if ( null === $expected ) {
			$this->assertNull( $error );
			return;
		}
		$this->assertNotNull( $error );
		$this->assertSame( $expected, $error->code );
		$this->assertSame( 'Odoo', $error->context['system'] );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:array<string,string>,3:?ErrorCode}>
	 */
	public static function httpAnswers(): array {
		return array(
			'ok'                    => array( 200, '', array(), null ),
			'created'               => array( 201, '', array(), null ),
			'redirect'              => array( 302, '', array(), ErrorCode::Val002 ),
			'moved permanently'     => array( 301, '', array(), ErrorCode::Val002 ),
			'temporary redirect'    => array( 307, '', array(), ErrorCode::Val002 ),
			'multiple choices'      => array( 399, '', array(), ErrorCode::Val002 ),
			'sign-in refused'       => array( 401, '', array(), ErrorCode::Auth001 ),
			'not allowed'           => array( 403, '', array(), ErrorCode::Auth004 ),
			'slow down'             => array( 429, '', array( 'retry-after' => '30' ), ErrorCode::Rate001 ),
			'request timeout'       => array( 408, '', array(), ErrorCode::Net001 ),
			'server error'          => array( 500, '', array(), ErrorCode::Net002 ),
			'unavailable'           => array( 503, '', array(), ErrorCode::Net002 ),
			'rejected field'        => array( 422, '', array(), ErrorCode::Val002 ),
			'bad request'           => array( 400, '', array(), ErrorCode::Val002 ),
			'no answer'             => array( 0, 'connection: the system could not be reached', array(), ErrorCode::Net001 ),
			'timed out'             => array( 0, 'timeout: the system did not answer in time', array(), ErrorCode::Net001 ),
			'certificate'           => array( 0, 'certificate: the security certificate could not be checked', array(), ErrorCode::Net003 ),
		);
	}

	public function test_retry_after_is_carried_and_filled_into_the_message(): void {
		$error = $this->classifier->fromResponse( new HttpResponse( 429, '', array( 'retry-after' => '45' ) ), 'Odoo' );
		$this->assertSame( 45, $error->retryAfter );
		$this->assertSame( 'Odoo asked us to slow down. Waiting 45 seconds.', $error->message() );
	}

	public function test_transport_errors_never_carry_a_url_in_the_detail(): void {
		$error = $this->classifier->fromResponse( new HttpResponse( 0, '', array(), "connection: could not resolve https://secret.example.test/path?token=abc\nsecond line" ), 'Odoo' );
		$this->assertStringNotContainsString( 'secret.example.test', $error->detail );
		$this->assertStringNotContainsString( 'token=abc', $error->detail );
		$this->assertStringNotContainsString( 'second line', $error->detail );
	}

	public function test_network_errors_retry_and_count_toward_the_breaker(): void {
		$decision = $this->classifier->classify( new ConnectorError( ErrorCode::Net001 ), null, 'Odoo' );
		$this->assertSame( FailureAction::Retry, $decision->action );
		$this->assertSame( ErrorClass::Retry, $decision->class );
		$this->assertTrue( $decision->countsForBreaker );
		$this->assertSame( 'Couldn’t reach Odoo, or it didn’t answer within 30 seconds.', $decision->error->message() );
	}

	public function test_rate_limiting_retries_but_never_counts_toward_the_breaker(): void {
		$decision = $this->classifier->classify( new ConnectorError( ErrorCode::Rate001, array( 'n' => 30 ), 429, 30 ), null, 'Odoo' );
		$this->assertSame( FailureAction::Retry, $decision->action );
		$this->assertFalse( $decision->countsForBreaker );
		$this->assertSame( 30, $decision->retryAfter );

		$by_status = $this->classifier->classify( new ConnectorError( ErrorCode::Net002, array(), 429 ), null, 'Odoo' );
		$this->assertFalse( $by_status->countsForBreaker, 'a 429 never counts, whatever code the connector chose' );
	}

	public function test_errors_a_person_must_fix_fail_without_retry(): void {
		foreach ( array( ErrorCode::Map001, ErrorCode::Map002, ErrorCode::Map003, ErrorCode::Val001, ErrorCode::Val002, ErrorCode::Cfg001, ErrorCode::Auth004, ErrorCode::Auth006, ErrorCode::Net003 ) as $code ) {
			$decision = $this->classifier->classify( new ConnectorError( $code ), null, 'Odoo' );
			$this->assertSame( FailureAction::Fail, $decision->action, $code->value );
			$this->assertFalse( $decision->countsForBreaker, $code->value );
		}
	}

	public function test_a_refused_sign_in_pauses_the_connection(): void {
		$decision = $this->classifier->classify( new ConnectorError( ErrorCode::Auth001 ), null, 'Odoo' );
		$this->assertSame( FailureAction::PauseSignIn, $decision->action );
	}

	public function test_expired_or_unfinished_sign_ins_need_a_reconnect(): void {
		foreach ( array( ErrorCode::Auth002, ErrorCode::Auth003, ErrorCode::Auth005 ) as $code ) {
			$this->assertSame( FailureAction::NeedsReconnect, $this->classifier->classify( new ConnectorError( $code ), null, 'Odoo' )->action, $code->value );
		}
	}

	public function test_the_lock_error_retries(): void {
		$this->assertSame( FailureAction::Retry, $this->classifier->classify( new ConnectorError( ErrorCode::Lock001 ) )->action );
	}

	public function test_the_connector_can_override_the_class(): void {
		$connector                = new CoreFakeConnector();
		$connector->classOverride = ErrorClass::Retry;
		$retry                    = $this->classifier->classify( new ConnectorError( ErrorCode::Val002 ), $connector, 'Odoo' );
		$this->assertSame( FailureAction::Retry, $retry->action );

		$connector->classOverride = ErrorClass::NeedsPerson;
		$fail                     = $this->classifier->classify( new ConnectorError( ErrorCode::Net002 ), $connector, 'Odoo' );
		$this->assertSame( FailureAction::Fail, $fail->action );
	}

	public function test_a_class_set_on_the_error_itself_wins_over_the_connector_method(): void {
		$connector                = new CoreFakeConnector();
		$connector->classOverride = ErrorClass::NeedsPerson;
		$decision                 = $this->classifier->classify( new ConnectorError( ErrorCode::Val002, array(), 422, null, ErrorClass::Retry ), $connector, 'Odoo' );
		$this->assertSame( FailureAction::Retry, $decision->action );
	}

	public function test_causes_for_grouping(): void {
		$this->assertSame( 'SKU CS-LAMP-07', $this->classifier->cause( new ConnectorError( ErrorCode::Map001, array( 'sku' => 'CS-LAMP-07' ) ) ) );
		$this->assertSame( 'Field partner.vat', $this->classifier->cause( new ConnectorError( ErrorCode::Val002, array( 'field' => 'partner.vat' ) ) ) );
		$this->assertSame( 'Tax AE 5%', $this->classifier->cause( new ConnectorError( ErrorCode::Map002, array( 'country' => 'AE', 'rate' => '5%' ) ) ) );
		$this->assertSame( '', $this->classifier->cause( new ConnectorError( ErrorCode::Net001 ) ) );
	}

	public function test_the_system_name_is_filled_in_only_when_missing(): void {
		$bare = $this->classifier->withSystem( new ConnectorError( ErrorCode::Map001, array( 'sku' => 'A' ) ), 'Odoo' );
		$this->assertSame( 'Product A was not found in Odoo.', $bare->message() );
		$kept = $this->classifier->withSystem( new ConnectorError( ErrorCode::Map001, array( 'sku' => 'A', 'system' => 'Odoo 18' ) ), 'Other' );
		$this->assertSame( 'Product A was not found in Odoo 18.', $kept->message() );
	}

	public function test_a_redirect_needs_a_person_and_says_which_address_to_use(): void {
		foreach ( array( 301, 302, 307, 308 ) as $status ) {
			$error = $this->classifier->fromResponse( new HttpResponse( $status, '', array( 'location' => 'https://elsewhere.example/api' ), '' ), 'Odoo' );
			$this->assertNotNull( $error, (string) $status );
			$this->assertSame( ErrorCode::Val002, $error->code );
			$this->assertSame( $status, $error->httpStatus );
			$this->assertStringContainsString( 'redirects', $error->message() );
			$this->assertStringContainsString( 'final address', $error->message() );
			$this->assertStringNotContainsString( 'elsewhere.example', $error->message(), 'the address it points to is not copied into a message' );

			$decision = $this->classifier->classify( $error, null, 'Odoo' );
			$this->assertSame( FailureAction::Fail, $decision->action, 'no automatic retry' );
			$this->assertSame( ErrorClass::NeedsPerson, $decision->class );
			$this->assertSame( 'Field address', $this->classifier->cause( $decision->error ) );
		}
	}

	public function test_only_2xx_answers_are_successes(): void {
		foreach ( array( 200, 201, 204, 299 ) as $status ) {
			$this->assertNull( $this->classifier->fromResponse( new HttpResponse( $status, '', array(), '' ), 'Odoo' ), (string) $status );
		}
		foreach ( array( 300, 304, 399 ) as $status ) {
			$this->assertNotNull( $this->classifier->fromResponse( new HttpResponse( $status, '', array(), '' ), 'Odoo' ), (string) $status );
		}
	}

	public function test_text_from_the_other_system_is_redacted_before_it_fills_a_message(): void {
		$error   = new ConnectorError(
			ErrorCode::Val002,
			array(
				'field'  => 'partner.email',
				'reason' => 'noor@example.com is not valid for phone 971501234567; Authorization: Bearer abcdef1234567890abcdef; api_key=sk_live_123456789abcdef',
			),
			422,
			null,
			null,
			'detail with https://bob:hunter2@erp.example.com/x'
		);
		$decision = $this->classifier->classify( $error, null, 'Odoo' );
		$text     = $decision->error->message() . ' | ' . $decision->error->detail;
		foreach ( array( 'noor@example.com', '971501234567', 'abcdef1234567890abcdef', 'sk_live_123456789abcdef', 'hunter2' ) as $private ) {
			$this->assertStringNotContainsString( $private, $text, $private );
		}
		$this->assertStringContainsString( 'partner.email', $decision->error->message(), 'field names stay readable' );
		$this->assertSame( ErrorCode::Val002, $decision->error->code );
		$this->assertSame( 422, $decision->error->httpStatus );
	}

	public function test_the_text_is_cut_to_a_sensible_length(): void {
		$decision = $this->classifier->classify( new ConnectorError( ErrorCode::Val002, array( 'field' => 'x', 'reason' => str_repeat( 'word ', 400 ) ) ), null, 'Odoo' );
		$this->assertLessThan( 450, mb_strlen( $decision->error->message() ) );
	}

	public function test_an_error_with_nothing_to_hide_is_not_copied(): void {
		$error    = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'CS-LAMP-07', 'system' => 'Odoo' ) );
		$decision = $this->classifier->classify( $error, null, 'Odoo' );
		$this->assertSame( $error, $decision->error );
	}

	public function test_personal_data_stays_when_the_setting_is_off_but_secrets_never_do(): void {
		$settings = new Settings( new CoreArrayOptionStore(), 'owner@example.test' );
		$settings->update( array( 'redaction' => false ) );
		$classifier = new ErrorClassifier( new Redactor(), $settings );
		$decision   = $classifier->classify( new ConnectorError( ErrorCode::Val002, array( 'field' => 'email', 'reason' => 'noor@example.com rejected, token=abcdef123456' ) ), null, 'Odoo' );
		$this->assertStringContainsString( 'noor@example.com', $decision->error->message() );
		$this->assertStringNotContainsString( 'abcdef123456', $decision->error->message() );
	}
}
