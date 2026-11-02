<?php
/**
 * The catalog and customer adapters.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

require_once __DIR__ . '/JobsAdapterTestCase.php';

use Estasoft\SyncEngine\Jobs\Adapters\WooShopCatalog;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopCustomers;
use Estasoft\SyncEngine\Tests\Support\JobsWooRegistry;

final class WooShopCatalogAndCustomersTest extends JobsAdapterTestCase {

	public function test_the_catalog_is_scanned_page_by_page_once(): void {
		JobsWooRegistry::$productPages[1] = (object) array(
			'products'      => array( $this->product( 1, array( 'sku' => 'A-1' ) ), $this->product( 2, array( 'sku' => '' ) ), $this->product( 3, array( 'sku' => 'a-1' ) ) ),
			'max_num_pages' => 2,
		);
		JobsWooRegistry::$productPages[2] = (object) array(
			'products'      => array( $this->product( 4, array( 'sku' => 'B-2', 'name' => '' ) ), $this->product( 5, array( 'sku' => ' ' ) ) ),
			'max_num_pages' => 2,
		);
		$catalog = new WooShopCatalog();

		$this->assertSame( 5, $catalog->productCount() );
		$missing = $catalog->withoutSku( 1 );
		$this->assertSame( 2, $missing['total'] );
		$this->assertCount( 1, $missing['items'] );
		$this->assertSame( 2, $missing['items'][0]['id'] );
		$dupes = $catalog->duplicateSkus( 10 );
		$this->assertSame( 1, $dupes['total'] );
		$this->assertSame( array( 1, 3 ), $dupes['items'][0]['ids'], 'SKUs are compared without regard to case' );
		$this->assertCount( 2, JobsWooRegistry::$productQueries, 'one scan serves every question' );
	}

	public function test_products_by_id(): void {
		$this->product( 1, array( 'name' => 'Cedar lamp' ) );
		$found = ( new WooShopCatalog() )->byId( array( 1, 1, 404 ) );
		$this->assertSame( array( 1 ), array_keys( $found ) );
		$this->assertSame( 'Cedar lamp', $found[1]['name'] );
	}

	public function test_a_state_is_free_text_when_the_country_has_a_list_and_the_state_is_not_on_it(): void {
		$customers = new WooShopCustomers(
			static fn( int $limit ): array => array(
				array( 'id' => 1, 'name' => 'A', 'state' => 'DU', 'country' => 'AE' ),
				array( 'id' => 2, 'name' => 'B', 'state' => 'Dubayy', 'country' => 'AE' ),
				array( 'id' => 3, 'name' => 'C', 'state' => 'Anything', 'country' => 'NZ' ),
				array( 'id' => 4, 'name' => 'D', 'state' => '', 'country' => 'AE' ),
				array( 'id' => 5, 'name' => 'E', 'state' => 'du', 'country' => 'AE' ),
			),
			static fn( string $country ): array => 'AE' === $country ? array( 'DU' => 'Dubai', 'AZ' => 'Abu Dhabi' ) : array()
		);

		$found = $customers->withFreeTextState( 10 );

		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 'Dubayy', $found['items'][0]['state'] );
	}
}
