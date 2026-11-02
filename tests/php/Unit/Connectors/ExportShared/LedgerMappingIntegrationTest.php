<?php
/**
 * The version 1 rules of the ledger exports, run by the real mapping engine on the sample order and refund, then written to a file.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerMappings
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerFields
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\DocumentReader
 */
final class LedgerMappingIntegrationTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Services of the connector under test.
	 *
	 * @var \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies
	 */
	private \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies $deps;

	/**
	 * Fresh folder.
	 */
	protected function setUp(): void {
		$this->dir  = FakeExportKit::folder( 'mapping' );
		$this->deps = FakeExportKit::deps( $this->dir );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * The four layouts with a connection each, the currency of the books and the tax code the tax mapping would give.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public static function systems(): array {
		return array(
			'tally'    => array( 'tallyprime-export', array( 'company' => 'Cedar & Stone Trading LLC', 'currency' => 'AED' ), 'AED', 'Output VAT 5%' ),
			'datev'    => array( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ), 'EUR', '8400' ),
			'sage uk'  => array( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP' ), 'GBP', 'T1' ),
			'sage us'  => array( 'sage50-export', array( 'edition' => 'us', 'currency' => 'USD', 'ar_account' => '11000', 'sales_account' => '40000', 'tax_gl_account' => '23000' ), 'USD', 'STATE' ),
		);
	}

	/**
	 * The connector for an id.
	 *
	 * @param string $id Connector id.
	 */
	private function connector( string $id ): AbstractApiConnector {
		return match ( $id ) {
			'tallyprime-export' => new TallyConnector( $this->deps ),
			'datev-export'      => new DatevConnector( $this->deps ),
			default             => new Sage50Connector( $this->deps ),
		};
	}

	/**
	 * The mapping engine's environment with a tax mapping that knows one code.
	 *
	 * @param string $code Tax code.
	 */
	private function environment( string $code ): MappingEnvironment {
		$taxes = new class( $code ) implements TaxResolver {
			public function __construct( private string $code ) {
			}

			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return '5' === $rateKey ? $this->code : null;
			}
		};
		return new MappingEnvironment( 7, 'Export', $taxes );
	}

	/**
	 * The sample order and refund in the books' currency.
	 *
	 * @param string $currency Currency.
	 * @param bool   $refund   Whether to add the refund section.
	 * @return array<string,mixed>
	 */
	private function canonical( string $currency, bool $refund ): array {
		$order = Orders::dubai( array( 'order' => array( 'currency' => $currency ) ) );
		if ( $refund ) {
			$order['refund'] = array(
				'id'              => 55,
				'order_id'        => 10490,
				'amount'          => 21900 + 1095 + 2625,
				'reason'          => 'Bowl cracked',
				'created_at'      => '2026-11-03T08:00:00Z',
				'lines'           => array(
					array(
						'sku'      => ' CS-BOWL-02 ',
						'quantity' => 1,
						'amount'   => 21900,
						'tax'      => 1095,
					),
				),
				'shipping_amount' => 2625,
				'excluded_amount' => 0,
				'other_amount'    => 0,
			);
		}
		return $order;
	}

	/**
	 * The rules are valid for the connector's own fields, every required field has a rule, and no rule writes to a field that does not exist.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_default_rules_are_valid_for_the_fields( string $id, array $options, string $currency, string $code ): void {
		unset( $options, $currency, $code );
		$connector = $this->connector( $id );
		$validator = new RuleValidator( TransformRegistry::standard() );
		foreach ( array( 'orders', 'refunds' ) as $type ) {
			$fields = $connector->describeFields( $type );
			$rules  = $connector->defaultMapping( $type );
			$this->assertNotEmpty( $fields );
			$this->assertSame( array(), array_map( static fn( $e ): string => $e->code . ' ' . $e->message, $validator->validate( $rules, $fields ) ), $type );

			$targets = array_unique( array_map( static fn( array $rule ): string => RuleValidator::normalise( (string) $rule['target'] ), $rules ) );
			foreach ( $fields as $field ) {
				if ( $field['required'] ) {
					$this->assertContains( RuleValidator::normalise( $field['path'] ), $targets, $type . ': required field ' . $field['path'] . ' has no rule.' );
				}
			}
		}
		foreach ( array( 'customers', 'products', 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $connector->describeFields( $type ), 'A file writes names; it has no ' . $type . ' mapping.' );
			$this->assertSame( array(), $connector->defaultMapping( $type ) );
		}
	}

	/**
	 * The rules turn the sample order into a payload the connector writes: the total of the file equals the total of the order.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_sample_order_end_to_end( string $id, array $options, string $currency, string $code ): void {
		$connector = $this->connector( $id );
		$engine    = new RuleEngine( TransformRegistry::standard() );
		$output    = $engine->apply( $connector->defaultMapping( 'orders' ), new CanonicalOrder( $this->canonical( $currency, false ) ), $this->environment( $code ) );
		$this->assertTrue( $output->isValid(), (string) wp_json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );

		$payload = $output->payload;
		$this->assertSame( '10490', $payload['document']['number'] );
		$this->assertSame( 'WC-10490', $payload['document']['reference'] );
		$this->assertSame( '2026-11-02', $payload['document']['date'] );
		$this->assertSame( 48615, $payload['document']['total'] );
		$this->assertSame( 'نور المنصوري', $payload['customer']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( 43800, $payload['line'][0]['net'] );
		$this->assertSame( 2190, $payload['line'][0]['tax'] );
		$this->assertSame( $code, $payload['line'][0]['tax_code'] );
		$this->assertSame( $code, $payload['shipping'][0]['tax_code'] );
		$this->assertSame( 2500, $payload['shipping'][0]['net'] );

		$connection = FakeExportKit::connection( $id, $options );
		$result     = $connector->send( $connection, FakeExportKit::item( 1, '10490' ), $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 48615, $this->fileTotal( $id, $options, $connector ), 'The file carries the total of the order.' );
	}

	/**
	 * A refund of one bowl and its shipping goes through the same path and is accepted.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_sample_refund_end_to_end( string $id, array $options, string $currency, string $code ): void {
		$connector = $this->connector( $id );
		$engine    = new RuleEngine( TransformRegistry::standard() );
		$output    = $engine->apply( $connector->defaultMapping( 'refunds' ), new CanonicalOrder( $this->canonical( $currency, true ) ), $this->environment( $code ) );
		$this->assertTrue( $output->isValid(), (string) wp_json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );

		$payload = $output->payload;
		$this->assertSame( '10490', $payload['document']['number'] );
		$this->assertSame( 55, $payload['document']['refund_id'] );
		$this->assertSame( '2026-11-03', $payload['document']['date'] );
		$this->assertSame( 25620, $payload['document']['total'] );
		$this->assertSame( 21900, $payload['refund_line'][0]['net'] );
		$this->assertSame( 2625, $payload['refund_shipping']['gross'] );
		$this->assertSame( $code, $payload['original_line'][0]['tax_code'] );
		$this->assertSame( $code, $payload['original_shipping'][0]['tax_code'] );

		$result = $connector->send( FakeExportKit::connection( $id, $options ), FakeExportKit::item( 2, '10490', EntityType::Refund ), $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 25620, $this->fileTotal( $id, $options, $connector ), 'The file carries the total of the refund.' );
	}

	/**
	 * Build the file and read the document total back with a parser of the test's own: nothing is left out, and the total of the
	 * document in the file, in minor units, is returned.
	 *
	 * @param string                $id        Connector id.
	 * @param array<string,mixed>   $options   Settings.
	 * @param AbstractApiConnector  $connector Connector.
	 */
	private function fileTotal( string $id, array $options, AbstractApiConnector $connector ): int {
		$batch = $this->deps->exports->openBatch( 7 );
		$file  = $connector->finish( $batch );
		$this->assertSame( array(), $batch->leftOut(), 'Nothing is left out.' );
		$this->assertSame( 1, $batch->count() );
		$bytes = FakeExportKit::bytes( $file );

		if ( 'tallyprime-export' === $id ) {
			$dom = new \DOMDocument();
			$this->assertTrue( $dom->loadXML( $bytes ) );
			$xp    = new \DOMXPath( $dom );
			$party = $xp->query( '//VOUCHER/LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT' );
			$this->assertSame( 1, $party->length );
			return abs( (int) str_replace( '.', '', trim( $party->item( 0 )->textContent ) ) );
		}
		if ( 'datev-export' === $id ) {
			$lines = explode( "\r\n", rtrim( mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' ), "\r\n" ) );
			$sum   = 0;
			foreach ( array_slice( $lines, 2 ) as $line ) {
				$sum += (int) str_replace( ',', '', str_getcsv( $line, ';', '"', '' )[0] );
			}
			return $sum;
		}
		$text = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
		$rows = array_map( static fn( string $line ): array => str_getcsv( $line, ',', '"', '' ), explode( "\r\n", rtrim( $text, "\r\n" ) ) );
		$sum  = 0;
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$sum += 'uk' === $options['edition'] ? (int) str_replace( '.', '', $row[7] ) + (int) str_replace( '.', '', $row[9] ) : abs( (int) str_replace( '.', '', $row[16] ) );
		}
		return $sum;
	}
}
