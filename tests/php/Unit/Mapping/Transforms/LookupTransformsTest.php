<?php
/**
 * tax_lookup and sku_match.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\SkuMatch;
use Estasoft\SyncEngine\Mapping\Transforms\TaxLookup;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class LookupTransformsTest extends TransformTestCase {

	/**
	 * A tax resolver that answers from a table and remembers what it was asked.
	 *
	 * @param array<string,string> $table "country|class|rate" => code.
	 */
	private function taxes( array $table ): TaxResolver {
		return new class( $table ) implements TaxResolver {
			/** @var array<int,string> */
			public array $asked = array();
			public function __construct( private array $table ) {
			}
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				$this->asked[] = "$connectionId|$country|$taxClass|$rateKey";
				return $this->table[ "$country|$taxClass|$rateKey" ] ?? null;
			}
		};
	}

	public function test_tax_lookup_finds_the_code_for_the_orders_country_and_rate(): void {
		$taxes   = $this->taxes( array( 'AE||5' => 'VAT 5% (AE)' ) );
		$context = $this->context( Orders::dubai(), 'lines[].tax_rate', 0, $taxes );
		$this->assertSame( 'VAT 5% (AE)', ( new TaxLookup() )->apply( 5, array(), $context ) );
		$this->assertSame( array( '1|AE||5' ), $taxes->asked );
	}

	public function test_tax_lookup_passes_the_line_tax_class(): void {
		$taxes = $this->taxes( array( 'AE|reduced-rate|5' => 'VAT 5% reduced' ) );
		$order = Orders::dubai();
		$order['lines'][0]['tax_class'] = 'reduced-rate';
		$this->assertSame( 'VAT 5% reduced', ( new TaxLookup() )->apply( '5.00', array(), $this->context( $order, 'lines[].tax_rate', 0, $taxes ) ) );
	}

	public function test_tax_lookup_prefers_the_shipping_country(): void {
		$taxes = $this->taxes( array( 'SA||15' => 'VAT 15% (SA)' ) );
		$order = Orders::dubai( array( 'shipping' => array( 'country' => 'SA' ) ) );
		$this->assertSame( 'VAT 15% (SA)', ( new TaxLookup() )->apply( 15, array(), $this->context( $order, 'lines[].tax_rate', 0, $taxes ) ) );
	}

	public function test_tax_lookup_country_argument(): void {
		$taxes = $this->taxes( array( 'AE||5' => 'VAT 5% (AE)' ) );
		$this->assertSame( 'VAT 5% (AE)', ( new TaxLookup() )->apply( 5, array( 'country' => 'billing.country' ), $this->context( Orders::dubai( array( 'shipping' => array( 'country' => 'SA' ) ) ), 'order.tax_total', null, $taxes ) ) );
		$this->assertSame( 'VAT 5% (AE)', ( new TaxLookup() )->apply( 5, array( 'country' => 'ae' ), $this->context( array(), '', null, $taxes ) ) );
	}

	public function test_unmapped_rate_raises_map_002_with_country_and_rate(): void {
		$context = $this->context( Orders::dubai( array( 'shipping' => array( 'country' => 'OM' ), 'billing' => array( 'country' => 'OM' ) ) ), 'lines[].tax_rate', 0, $this->taxes( array() ) );
		try {
			( new TaxLookup() )->apply( 5, array(), $context );
			$this->fail( 'An unmapped rate must stop the order.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map002, $e->errorCode );
			$this->assertSame( 'Tax code not mapped for Oman 5%.', $e->toConnectorError()->message() );
		}
	}

	public function test_an_untaxed_line_needs_no_code(): void {
		$taxes = $this->taxes( array() );
		$this->assertNull( ( new TaxLookup() )->apply( null, array(), $this->context( Orders::dubai(), '', null, $taxes ) ) );
		$this->assertNull( ( new TaxLookup() )->apply( '', array(), $this->context( Orders::dubai(), '', null, $taxes ) ) );
		$this->assertSame( array(), $taxes->asked );
	}

	public function test_a_zero_rate_still_needs_a_code(): void {
		$taxes = $this->taxes( array( 'AE||0' => 'VAT 0% (AE export)' ) );
		$this->assertSame( 'VAT 0% (AE export)', ( new TaxLookup() )->apply( 0, array(), $this->context( Orders::dubai(), 'lines[].tax_rate', 0, $taxes ) ) );
		$this->expectException( MappingException::class );
		( new TaxLookup() )->apply( 0, array(), $this->context( Orders::dubai(), 'lines[].tax_rate', 0, $this->taxes( array() ) ) );
	}

	public function test_tax_lookup_without_a_tax_map_warns_and_gives_no_code(): void {
		$context = $this->context( Orders::dubai() );
		$this->assertNull( ( new TaxLookup() )->apply( 5, array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_tax_lookup_check(): void {
		$tax = new TaxLookup();
		$this->assertSame( array(), $tax->check( array() ) );
		$this->assertSame( array(), $tax->check( array( 'country' => 'AE', 'class' => 'lines[].tax_class' ) ) );
		$this->assertCount( 1, $tax->check( array( 'country' => 'no good!' ) ) );
		$this->assertCount( 1, $tax->check( array( 'class' => 'no good!' ) ) );
	}

	/**
	 * A product resolver that answers from a table.
	 *
	 * @param array<string,ExternalRef> $table SKU => reference.
	 */
	private function products( array $table ): ProductResolver {
		return new class( $table ) implements ProductResolver {
			/** @var array<int,string> */
			public array $asked = array();
			public function __construct( private array $table ) {
			}
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				$this->asked[] = "$connectionId|$sku|$productId|$variationId";
				return $this->table[ $sku ] ?? null;
			}
		};
	}

	public function test_sku_match_returns_the_system_product_id_with_the_line_ids(): void {
		$products = $this->products( array( 'CS-BOWL-02' => new ExternalRef( '77', 'CS-BOWL-02' ) ) );
		$context  = $this->context( Orders::dubai(), 'lines[].sku', 0, null, $products );
		$this->assertSame( '77', ( new SkuMatch() )->apply( ' CS-BOWL-02 ', array(), $context ) );
		$this->assertSame( array( '1|CS-BOWL-02|501|0' ), $products->asked );
	}

	public function test_sku_match_can_return_the_systems_reference(): void {
		$products = $this->products( array( 'CS-BOWL-04' => new ExternalRef( '78', 'CS-BOWL-04L' ) ) );
		$context  = $this->context( Orders::dubai(), 'lines[].sku', 0, null, $products );
		$this->assertSame( 'CS-BOWL-04L', ( new SkuMatch() )->apply( 'CS-BOWL-04', array( 'output' => 'ref' ), $context ) );
	}

	public function test_unmatched_sku_raises_map_001(): void {
		try {
			( new SkuMatch() )->apply( 'CS-LAMP-07', array(), $this->context( Orders::dubai(), 'lines[].sku', 0, null, $this->products( array() ) ) );
			$this->fail( 'An unknown SKU must stop the order.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map001, $e->errorCode );
			$this->assertSame( 'Product CS-LAMP-07 was not found in Odoo.', $e->toConnectorError()->message() );
		}
	}

	public function test_a_line_without_a_sku_raises_map_001_naming_the_product(): void {
		try {
			( new SkuMatch() )->apply( '', array(), $this->context( Orders::dubai(), 'lines[].sku', 0, null, $this->products( array() ) ) );
			$this->fail( 'A product with no SKU cannot be matched.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map001, $e->errorCode );
			$this->assertStringContainsString( 'Stoneware bowl set (no SKU)', $e->toConnectorError()->message() );
		}
	}

	public function test_sku_match_without_a_resolver_keeps_the_sku_and_warns(): void {
		$context = $this->context( Orders::dubai(), 'lines[].sku', 0 );
		$this->assertSame( 'CS-BOWL-02', ( new SkuMatch() )->apply( 'CS-BOWL-02', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_sku_match_check(): void {
		$this->assertSame( array(), ( new SkuMatch() )->check( array() ) );
		$this->assertSame( array(), ( new SkuMatch() )->check( array( 'output' => 'ref' ) ) );
		$this->assertCount( 1, ( new SkuMatch() )->check( array( 'output' => 'name' ) ) );
	}
}
