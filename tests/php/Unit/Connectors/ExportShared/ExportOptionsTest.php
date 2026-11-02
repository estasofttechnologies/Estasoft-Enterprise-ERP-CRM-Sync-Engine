<?php
/**
 * The typed settings of an export connection.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\ExportShared\ExportOptions;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\ExportOptions
 */
final class ExportOptionsTest extends TestCase {

	/**
	 * Text is trimmed, cleaned and falls back when empty.
	 */
	public function test_text(): void {
		$options = new ExportOptions( array( 'a' => "  Cedar \t& Stone ", 'b' => '   ', 'c' => array( 'x' ), 'd' => 42 ) );
		$this->assertSame( 'Cedar & Stone', $options->text( 'a' ) );
		$this->assertSame( 'fallback', $options->text( 'b', 'fallback' ) );
		$this->assertSame( '', $options->text( 'missing' ) );
		$this->assertSame( 'fallback', $options->text( 'c', 'fallback' ), 'A list is not text.' );
		$this->assertSame( '42', $options->text( 'd' ) );
	}

	/**
	 * Choices ignore case and fall back for unknown words.
	 */
	public function test_choice(): void {
		$options = new ExportOptions( array( 'a' => ' UTF-8 ', 'b' => 'other' ) );
		$this->assertSame( 'utf-8', $options->choice( 'a', array( 'utf-16', 'utf-8' ), 'utf-16' ) );
		$this->assertSame( 'utf-16', $options->choice( 'b', array( 'utf-16', 'utf-8' ), 'utf-16' ) );
		$this->assertSame( 'utf-16', $options->choice( 'missing', array( 'utf-16', 'utf-8' ), 'utf-16' ) );
	}

	/**
	 * Yes and no in the words the settings screen and a hand-edited config use.
	 */
	public function test_flag(): void {
		foreach ( array( true, 'yes', 'YES', '1', 'true', 'on', 1 ) as $yes ) {
			$this->assertTrue( ( new ExportOptions( array( 'f' => $yes ) ) )->flag( 'f', false ), var_export( $yes, true ) );
		}
		foreach ( array( false, 'no', '0', 'false', 'off', '', 0, array() ) as $no ) {
			$this->assertFalse( ( new ExportOptions( array( 'f' => $no ) ) )->flag( 'f', true ), var_export( $no, true ) );
		}
		$this->assertTrue( ( new ExportOptions() )->flag( 'f', true ) );
		$this->assertFalse( ( new ExportOptions() )->flag( 'f', false ) );
	}

	/**
	 * Whole numbers stay within their limits.
	 */
	public function test_integer(): void {
		$options = new ExportOptions( array( 'a' => '5', 'b' => 500, 'c' => -3, 'd' => 'x' ) );
		$this->assertSame( 5, $options->integer( 'a', 1, 0, 100 ) );
		$this->assertSame( 100, $options->integer( 'b', 1, 0, 100 ) );
		$this->assertSame( 0, $options->integer( 'c', 1, 0, 100 ) );
		$this->assertSame( 1, $options->integer( 'd', 1, 0, 100 ) );
		$this->assertSame( 1, $options->integer( 'missing', 1, 0, 100 ) );
	}

	/**
	 * A table is an array, or lines typed as "key = value".
	 */
	public function test_table(): void {
		$array = new ExportOptions( array( 't' => array( 'stripe' => ' Stripe account ', 'empty' => '', '' => 'x' ) ) );
		$this->assertSame( array( 'stripe' => 'Stripe account' ), $array->table( 't' ) );
		$lines = new ExportOptions( array( 't' => "stripe = Stripe account\r\npaypal=PayPal=main\n\nbad line\n = x" ) );
		$this->assertSame( array( 'stripe' => 'Stripe account', 'paypal' => 'PayPal=main' ), $lines->table( 't' ) );
		$this->assertSame( array(), ( new ExportOptions() )->table( 't' ) );
		$this->assertSame( array(), ( new ExportOptions( array( 't' => 5 ) ) )->table( 't' ) );
	}

	/**
	 * Settings come from `options` first, then from keys of the config; unknown keys are ignored.
	 */
	public function test_from_connection(): void {
		$connection = new Connection(
			3,
			'x',
			ConnectorType::Accounting,
			'X',
			ConnectionStatus::Active,
			array(
				'company' => 'Direct',
				'other'   => 'ignored',
				'options' => array( 'company' => 'From options', 'currency' => 'AED', 'unknown' => 'ignored' ),
			)
		);
		$options = ExportOptions::fromConnection( $connection, array( 'company', 'currency', 'missing' ) );
		$this->assertSame( 'From options', $options->text( 'company' ) );
		$this->assertSame( 'AED', $options->text( 'currency' ) );
		$this->assertSame( '', $options->text( 'other' ) );
		$this->assertSame( '', $options->text( 'unknown' ) );
		$this->assertSame( '', $options->text( 'missing' ) );
	}
}
