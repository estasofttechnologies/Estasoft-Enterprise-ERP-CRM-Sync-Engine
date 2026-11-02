<?php
/**
 * Compatibility declaration, order meta and product meta.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Woo\Compatibility;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\ProductMeta;

final class MetaAndCompatibilityTest extends WooTestCase {

	protected function setUp(): void {
		parent::setUp();
		FeaturesUtil::$declared = array();
	}

	public function test_it_declares_high_performance_order_storage_and_block_checkout(): void {
		( new Compatibility( '/plugins/estasoft/estasoft.php' ) )->declareCompatibility();
		$this->assertSame(
			array(
				array( 'custom_order_tables', '/plugins/estasoft/estasoft.php', true ),
				array( 'cart_checkout_blocks', '/plugins/estasoft/estasoft.php', true ),
			),
			FeaturesUtil::$declared
		);
	}

	public function test_order_meta_keys_are_exactly_the_ones_core_removes_on_uninstall(): void {
		$this->assertEqualsCanonicalizing( MetaKeys::order(), OrderMeta::KEYS );
	}

	public function test_product_meta_keys_core_removes_cover_the_ones_written_here(): void {
		$written = array( ProductMeta::EXCLUDE, ProductMeta::EXTERNAL_ID, ProductMeta::EXTERNAL_REF, ProductMeta::LAST_STOCK, ProductMeta::LAST_STOCK_AT );
		$this->assertEqualsCanonicalizing( MetaKeys::product(), $written );
		$this->assertSame( array( ProductMeta::MATCH ), ProductMeta::EXTRA_KEYS, 'The one extra key is added to the uninstall list through a filter.' );
	}

	public function test_order_meta_is_written_through_the_order_object_without_saving_the_order(): void {
		$order = $this->order( 1 );
		( new OrderMeta() )->write(
			$order,
			array(
				OrderMeta::STATE        => 'synced',
				OrderMeta::EXTERNAL_REF => 'S00490',
				OrderMeta::SKIP         => array( 'reason' => 'Test order', 'by' => 'Layla Haddad', 'at' => '2026-11-02 10:05:00' ),
				'_not_ours'             => 'x',
			)
		);
		$this->assertSame( 'synced', $order->meta[ OrderMeta::STATE ] );
		$this->assertSame( 'S00490', $order->meta[ OrderMeta::EXTERNAL_REF ] );
		$this->assertArrayNotHasKey( '_not_ours', $order->meta, 'Keys that are not the plugin\'s are ignored.' );
		$this->assertSame( 1, $order->metaSaves );
		$this->assertSame( 0, $order->saves, 'save() would fire WooCommerce\'s update hooks and look like an order change.' );
	}

	public function test_empty_values_remove_the_key(): void {
		$order = $this->order( 1, array(), array(), array( OrderMeta::EXTERNAL_REF => 'S1', OrderMeta::SKIP => '{"reason":"x"}' ) );
		( new OrderMeta() )->write( $order, array( OrderMeta::EXTERNAL_REF => '', OrderMeta::SKIP => null ) );
		$this->assertSame( array(), $order->meta );
	}

	public function test_writing_nothing_saves_nothing(): void {
		$order = $this->order( 1 );
		( new OrderMeta() )->write( $order, array( '_other' => 'x' ) );
		$this->assertSame( 0, $order->metaSaves );
	}

	public function test_order_meta_reads_back_as_a_typed_snapshot(): void {
		$order = $this->order(
			1,
			array(),
			array(),
			array(
				OrderMeta::STATE           => 'synced',
				OrderMeta::EXTERNAL_ID     => '490',
				OrderMeta::EXTERNAL_REF    => 'S00490',
				OrderMeta::EXTERNAL_URL    => 'https://odoo.example/web#id=490',
				OrderMeta::LAST_SYNC       => '2026-11-02 07:39:00',
				OrderMeta::MAPPING_VERSION => '7',
				OrderMeta::INVOICE_NUMBER  => 'INV/2026/1201',
				OrderMeta::SKIP            => '{"reason":"Test order","by":"Layla","at":"2026-11-02 10:05:00"}',
			)
		);
		$info = ( new OrderMeta() )->read( $order );
		$this->assertSame( 'synced', $info->state );
		$this->assertSame( 'S00490', $info->externalRef );
		$this->assertSame( 7, $info->mappingVersion );
		$this->assertSame( 'INV/2026/1201', $info->invoiceNumber );
		$this->assertSame( array( 'reason' => 'Test order', 'by' => 'Layla', 'at' => '2026-11-02 10:05:00' ), $info->skip );
	}

	public function test_an_order_with_no_meta_reads_as_empty(): void {
		$info = ( new OrderMeta() )->read( $this->order( 1 ) );
		$this->assertSame( '', $info->state );
		$this->assertNull( $info->mappingVersion );
		$this->assertNull( $info->skip );
	}

	public function test_an_unknown_state_or_broken_skip_record_is_ignored(): void {
		$order = $this->order( 1, array(), array(), array( OrderMeta::STATE => 'exploded', OrderMeta::SKIP => 'not json' ) );
		$meta  = new OrderMeta();
		$this->assertSame( '', $meta->state( $order ) );
		$this->assertNull( $meta->read( $order )->skip );
	}

	public function test_clear_removes_every_key(): void {
		$order = $this->order( 1, array(), array(), array_fill_keys( OrderMeta::KEYS, 'x' ) + array( '_other' => 'kept' ) );
		( new OrderMeta() )->clear( $order );
		$this->assertSame( array( '_other' => 'kept' ), $order->meta );
	}

	public function test_a_product_is_excluded_when_it_or_its_parent_is_marked(): void {
		$meta   = new ProductMeta();
		$parent = $this->product( 10, array(), array( ProductMeta::EXCLUDE => 'yes' ) );
		$child  = $this->product( 11, array( 'parent_id' => 10 ) );
		$plain  = $this->product( 12 );
		$this->assertTrue( $meta->isExcluded( $parent ) );
		$this->assertTrue( $meta->isExcluded( $child ) );
		$this->assertFalse( $meta->isExcluded( $plain ) );
		$this->assertTrue( $meta->isExcluded( $this->product( 13, array(), array( ProductMeta::EXCLUDE => '1' ) ) ) );
		$this->assertFalse( $meta->isExcluded( $this->product( 14, array(), array( ProductMeta::EXCLUDE => 'no' ) ) ) );
	}

	public function test_excluding_and_including_a_product(): void {
		$meta    = new ProductMeta();
		$product = $this->product( 20 );
		$meta->setExcluded( $product, true );
		$this->assertSame( 'yes', $product->meta[ ProductMeta::EXCLUDE ] );
		$meta->setExcluded( $product, false );
		$this->assertArrayNotHasKey( ProductMeta::EXCLUDE, $product->meta );
		$this->assertSame( 2, $product->metaSaves );
		$this->assertSame( 0, $product->saves );
	}

	public function test_match_verdict_and_stock_are_recorded_without_extra_writes(): void {
		$meta    = new ProductMeta();
		$product = $this->product( 21 );
		$meta->recordMatch( $product, false );
		$meta->recordMatch( $product, false );
		$this->assertSame( 1, $product->metaSaves, 'The same verdict is not written twice.' );
		$meta->recordMatch( $product, true );
		$meta->recordStock( $product, 14, '2026-11-02 11:35:00' );
		$read = $meta->read( $product );
		$this->assertSame( ProductMeta::MATCHED, $read['match'] );
		$this->assertSame( 14, $read['last_stock'] );
		$this->assertSame( '2026-11-02 11:35:00', $read['last_stock_at'] );
	}
}
