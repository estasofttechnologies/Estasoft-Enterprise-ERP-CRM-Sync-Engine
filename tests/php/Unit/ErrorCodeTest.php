<?php
/**
 * Error catalog tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase {

	public function test_catalog_has_thirty_three_codes(): void {
		$this->assertCount( 33, ErrorCode::cases() );
	}

	public function test_every_code_has_a_message_and_a_remedy(): void {
		foreach ( ErrorCode::cases() as $code ) {
			$this->assertNotSame( '', $code->template(), $code->value );
			$this->assertNotSame( '', $code->remedy(), $code->value );
		}
	}

	public function test_network_errors_retry_and_auth_errors_need_a_person(): void {
		$this->assertSame( ErrorClass::Retry, ErrorCode::Net001->errorClass() );
		$this->assertTrue( ErrorCode::Net001->retriesAutomatically() );
		$this->assertSame( ErrorClass::NeedsPerson, ErrorCode::Auth001->errorClass() );
		$this->assertFalse( ErrorCode::Auth001->retriesAutomatically() );
	}

	public function test_pre_send_failures_are_flagged(): void {
		foreach ( array( ErrorCode::Map002, ErrorCode::Map003, ErrorCode::Val001, ErrorCode::Cfg001 ) as $code ) {
			$this->assertTrue( $code->raisedBeforeSending(), $code->value );
		}
		$this->assertFalse( ErrorCode::Net001->raisedBeforeSending() );
	}

	public function test_message_fills_placeholders(): void {
		$error = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'CS-LAMP-07', 'system' => 'Odoo' ) );
		$this->assertSame( 'Product CS-LAMP-07 was not found in Odoo.', $error->message() );
	}

	public function test_connector_can_override_the_class(): void {
		$error = new ConnectorError( ErrorCode::Val002, array(), 422, null, ErrorClass::Retry );
		$this->assertSame( ErrorClass::Retry, $error->errorClass() );
	}
}
