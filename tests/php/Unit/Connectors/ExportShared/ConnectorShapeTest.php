<?php
/**
 * What the four connectors promise, checked against what they do: honest capability flags, settings fields that match their
 * settings classes, no secret anywhere, nothing that contacts a server.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Datev\DatevSettings;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Settings;
use Estasoft\SyncEngine\Connectors\Support\Export\AbstractExportConnector;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Connectors\Tally\TallySettings;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallyConnector
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevConnector
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector
 */
final class ConnectorShapeTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Fresh folder.
	 */
	protected function setUp(): void {
		$this->dir = FakeExportKit::folder( 'shape' );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * The three ledger connectors with their id, label, the keys of their settings class, and the file extension.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:array<int,string>,4:string}>
	 */
	public static function connectors(): array {
		return array(
			'tally' => array( TallyConnector::class, 'tallyprime-export', 'TallyPrime', TallySettings::KEYS, 'xml' ),
			'datev' => array( DatevConnector::class, 'datev-export', 'DATEV', DatevSettings::KEYS, 'csv' ),
			'sage'  => array( Sage50Connector::class, 'sage50-export', 'Sage 50', Sage50Settings::KEYS, 'csv' ),
		);
	}

	/**
	 * Who they are, what they can do (orders and refunds only), and that they are export connectors.
	 *
	 * @dataProvider connectors
	 *
	 * @param class-string<AbstractExportConnector> $class Class.
	 * @param string                                $id    Id.
	 * @param string                                $label Label.
	 * @param array<int,string>                     $keys  Keys of the settings class.
	 * @param string                                $ext   Extension.
	 */
	public function test_identity_and_honest_capabilities( string $class, string $id, string $label, array $keys, string $ext ): void {
		unset( $keys );
		$connector = new $class( FakeExportKit::deps( $this->dir ) );
		$this->assertInstanceOf( AbstractExportConnector::class, $connector );
		$this->assertSame( $id, $connector->id() );
		$this->assertSame( $label, $connector->label() );
		$this->assertSame( ConnectorType::Accounting, $connector->type() );
		$this->assertSame( ConnectorInterface::CONTRACT_VERSION, $connector->contractVersion() );

		$flags = $connector->capabilities();
		$this->assertSame( count( Capability::cases() ), count( $flags ) );
		foreach ( $flags as $flag => $on ) {
			$this->assertSame( in_array( $flag, array( 'orders', 'refunds' ), true ), $on, $flag );
		}
		$this->assertEquals( array( EntityType::Order, EntityType::Refund ), $connector->supportedEntities() );
		$this->assertSame( $ext, $connector->exportFormat()->safeExtension() );
		$this->assertSame( '{connection}-{date}-{random}.' . $ext, $connector->filenamePattern() );
		$this->assertNotSame( '', $connector->exportFormat()->label );
		$this->assertArrayHasKey( 'requests_per_second', $connector->rateLimitHints() );
	}

	/**
	 * Every setting has a field on the settings screen and the other way round; nothing is secret; selects have their default among
	 * their options; groups and types are the ones the screen draws.
	 *
	 * @dataProvider connectors
	 *
	 * @param class-string<AbstractExportConnector> $class Class.
	 * @param string                                $id    Id.
	 * @param string                                $label Label.
	 * @param array<int,string>                     $keys  Keys of the settings class.
	 * @param string                                $ext   Extension.
	 */
	public function test_settings_fields_match_the_settings_class( string $class, string $id, string $label, array $keys, string $ext ): void {
		unset( $id, $label, $ext );
		$connector = new $class( FakeExportKit::deps( $this->dir ) );
		$names     = array();
		foreach ( $connector->credentialFields() as $field ) {
			$names[] = $field->name;
			$this->assertFalse( $field->secret, $field->name . ' keeps nothing secret: an export file needs no credential.' );
			$this->assertNotSame( '', $field->label );
			$this->assertContains( $field->type, array( 'text', 'select', 'textarea', 'number' ), $field->name );
			$this->assertContains( $field->group, array( 'connection', 'advanced' ), $field->name );
			if ( 'select' === $field->type ) {
				$this->assertNotEmpty( $field->options, $field->name );
				$this->assertArrayHasKey( $field->default, $field->options, $field->name . ': the default is one of the options.' );
			}
		}
		$this->assertSame( array(), array_values( array_diff( $names, $keys ) ), 'A field no setting reads.' );
		$this->assertSame( array(), array_values( array_diff( $keys, $names ) ), 'A setting without a field on the screen.' );
		$this->assertSame( $names, array_values( array_unique( $names ) ), 'No field twice.' );
	}

	/**
	 * Select defaults that the settings class would otherwise choose by itself are the same words (so the screen shows the truth).
	 */
	public function test_select_defaults_equal_the_real_defaults(): void {
		$tally = TallySettings::fromConnection( FakeExportKit::connection( 'tallyprime-export', array() ) );
		$this->assertTrue( $tally->taxAsLedger() );
		$this->assertFalse( $tally->perCustomer() );
		$this->assertSame( 'utf-16', $tally->charset()->value );
		$this->assertTrue( $tally->useInventory() );
		$this->assertTrue( $tally->stockBySku() );
		$this->assertFalse( $tally->ledgerOnlyWhenMissing() );
		$this->assertTrue( $tally->stableId() );
		$this->assertFalse( $tally->recordPayments() );
		$this->assertSame( 5, $tally->roundOffMax() );

		$datev = DatevSettings::fromConnection( FakeExportKit::connection( 'datev-export', array() ) );
		$this->assertSame( '03', $datev->chart() );
		$this->assertFalse( $datev->debtorPerCustomer() );
		$this->assertSame( 'windows-1252', $datev->charset()->value );
		$this->assertTrue( $datev->textWithName() );
		$this->assertFalse( $datev->lock() );
		$this->assertFalse( $datev->recordPayments() );
		$this->assertSame( '10000', $datev->debtorAccount() );
		$this->assertSame( 'RE', $datev->origin() );
		$this->assertSame( '01-01', $datev->fiscalYearStart() );
		$this->assertSame( 4, $datev->accountLength() );

		$sage = Sage50Settings::fromConnection( FakeExportKit::connection( 'sage50-export', array() ) );
		$this->assertTrue( $sage->isUk() );
		$this->assertSame( 'windows-1252', $sage->charset()->value );
		$this->assertTrue( $sage->headerRow() );
		$this->assertFalse( $sage->useItems() );
		$this->assertFalse( $sage->recordPayments() );
		$this->assertSame( 'T0', $sage->untaxedCode() );
		$this->assertSame( 'dd/mm/yyyy', $sage->dateFormat() );
	}

	/**
	 * Settings never carry a secret into the batch rows: what a row keeps from the settings is a short list of plain values.
	 */
	public function test_row_snapshot_holds_only_plain_values(): void {
		foreach ( array(
			TallySettings::fromConnection( FakeExportKit::connection( 'tallyprime-export', array( 'company' => 'X', 'currency' => 'AED' ) ) ),
			DatevSettings::fromConnection( FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 2000, 'client_number' => 1 ) ) ),
			Sage50Settings::fromConnection( FakeExportKit::connection( 'sage50-export', array( 'currency' => 'GBP' ) ) ),
		) as $settings ) {
			foreach ( $settings->snapshot() as $key => $value ) {
				$this->assertTrue( is_scalar( $value ), $key );
			}
		}
	}

	/**
	 * Each provider builds its connector under the id the catalog uses.
	 */
	public function test_providers(): void {
		$deps = FakeExportKit::deps( $this->dir );
		foreach ( array(
			array( new \Estasoft\SyncEngine\Connectors\Tally\TallyProvider(), 'tallyprime-export', TallyConnector::class ),
			array( new \Estasoft\SyncEngine\Connectors\Datev\DatevProvider(), 'datev-export', DatevConnector::class ),
			array( new \Estasoft\SyncEngine\Connectors\Sage50\Sage50Provider(), 'sage50-export', Sage50Connector::class ),
		) as $row ) {
			$this->assertSame( $row[1], $row[0]->id() );
			$connector = $row[0]->create( $deps );
			$this->assertInstanceOf( $row[2], $connector );
			$this->assertSame( $row[1], $connector->id() );
		}
	}

	/**
	 * The generated provider index lists the four providers.
	 */
	public function test_provider_index_lists_the_four_providers(): void {
		$index = require dirname( __DIR__, 5 ) . '/includes/Connectors/providers.php';
		$this->assertSame( \Estasoft\SyncEngine\Connectors\Tally\TallyProvider::class, $index['tallyprime-export'] );
		$this->assertSame( \Estasoft\SyncEngine\Connectors\Datev\DatevProvider::class, $index['datev-export'] );
		$this->assertSame( \Estasoft\SyncEngine\Connectors\Sage50\Sage50Provider::class, $index['sage50-export'] );
		$this->assertSame( \Estasoft\SyncEngine\Connectors\Smacc\SmaccProvider::class, $index['smacc'] );
	}
}
