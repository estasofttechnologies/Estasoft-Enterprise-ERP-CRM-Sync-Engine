<?php
/**
 * Lookups for previews, matching and the tax screen.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class OdooConnectorLookupTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array( 'odoo 17' => array( 17 ), 'odoo 18' => array( 18 ), 'odoo 19' => array( 19 ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_customer_is_found_by_email_ignoring_case( int $major ): void {
		$server = $this->server( $major );
		$id     = $server->db->addPartner( 'نور المنصوري', 'Noor@Example.com', array( 'phone' => '+971501234567', 'vat' => '100000000000003' ) );
		$ref    = $this->connector( $server )->lookupCustomer( $this->connection( $server ), '  NOOR@example.COM ' );

		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( (string) $id, $ref->id );
		$this->assertSame( 'نور المنصوري', $ref->ref );
		$this->assertSame( 'Noor@Example.com', $ref->extra['email'] );
		$this->assertSame( '+971501234567', $ref->extra['phone'] );
		$this->assertSame( '100000000000003', $ref->extra['vat'] );
		$this->assertSame( 1, $ref->extra['matches'] );
		$this->assertNotNull( $ref->url );
	}

	public function test_no_customer_gives_null_and_a_blank_email_asks_nothing(): void {
		$server = $this->server( 18 );
		$this->assertNull( $this->connector( $server )->lookupCustomer( $this->connection( $server ), 'nobody@example.com' ) );
		$this->assertCount( 1, $server->callsTo( 'res.partner', 'search_read' ) );

		$blank = $this->server( 18 );
		$this->assertNull( $this->connector( $blank )->lookupCustomer( $this->connection( $blank ), '   ' ) );
		$this->assertSame( array(), $blank->callsTo( 'res.partner', 'search_read' ), 'A blank email is not searched for.' );
	}

	public function test_several_matches_use_the_oldest_and_say_how_many_there_were(): void {
		$server = $this->server( 18 );
		$first  = $server->db->addPartner( 'A', 'dup@example.com' );
		$server->db->addPartner( 'B', 'dup@example.com' );
		$ref = $this->connector( $server )->lookupCustomer( $this->connection( $server ), 'dup@example.com' );
		$this->assertSame( (string) $first, $ref->id );
		$this->assertSame( 2, $ref->extra['matches'] );
	}

	public function test_an_archived_customer_is_not_found(): void {
		$server = $this->server( 18 );
		$server->db->addPartner( 'Old', 'old@example.com', array( 'active' => false ) );
		$this->assertNull( $this->connector( $server )->lookupCustomer( $this->connection( $server ), 'old@example.com' ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_product_is_found_by_its_internal_reference( int $major ): void {
		$server = $this->server( $major );
		$ref    = $this->connector( $server )->lookupProduct( $this->connection( $server ), ' CS-MUG-01 ' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( 'CS-MUG-01', $ref->ref );
		$this->assertSame( 'Ceramic mug, sand', $ref->extra['name'] );
		$this->assertSame( (string) $server->db->idOf( 'product.product', 'default_code', 'CS-MUG-01' ), $ref->id );
	}

	public function test_an_unknown_reference_blank_text_and_other_case_are_not_matches(): void {
		$server = $this->server( 18 );
		$connector = $this->connector( $server );
		$this->assertNull( $connector->lookupProduct( $this->connection( $server ), 'CS-NOPE' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection( $server ), '' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection( $server ), 'cs-mug-01' ), 'SKUs are exact.' );
	}

	public function test_searching_products_finds_by_reference_name_or_barcode(): void {
		$server = $this->server( 18 );
		$bar    = $server->db->addProduct( 'CS-LAMP-07', 'Brass table lamp', 99.0 );
		$server->db->records['product.product'][ $bar ]['barcode'] = '6281234567890';
		$connector = $this->connector( $server );
		$connection = $this->connection( $server );

		$byCode = $connector->searchProducts( $connection, 'lamp-07' );
		$this->assertCount( 1, $byCode );
		$this->assertSame( 'CS-LAMP-07', $byCode[0]->ref );
		$this->assertSame( 'Brass table lamp', $byCode[0]->extra['name'] );
		$this->assertSame( '6281234567890', $byCode[0]->extra['barcode'] );
		$byPrefix = array_map( static fn( ExternalRef $r ): string => $r->ref, $connector->searchProducts( $connection, 'cs-' ) );
		$this->assertSame( array( 'CS-BOWL-02', 'CS-LAMP-07', 'CS-MUG-01' ), $byPrefix, 'Case does not matter, and the results are in reference order.' );
		$this->assertCount( 1, $connector->searchProducts( $connection, 'stoneware' ) );
		$this->assertCount( 1, $connector->searchProducts( $connection, '628123' ) );
		$this->assertSame( array(), $connector->searchProducts( $connection, 'zzz' ) );
		$this->assertSame( array(), $connector->searchProducts( $connection, '  ' ) );
	}

	public function test_a_search_is_limited(): void {
		$server = $this->server( 18 );
		for ( $i = 1; $i <= 30; $i++ ) {
			$server->db->addProduct( sprintf( 'BULK-%02d', $i ), 'Bulk item', 1.0 );
		}
		$this->assertCount( 20, $this->connector( $server )->searchProducts( $this->connection( $server ), 'bulk' ) );
	}

	public function test_the_sale_taxes_are_listed_for_the_tax_screen_with_their_ids_as_codes(): void {
		$server = $this->server( 19 );
		$server->db->addTax( 'Input VAT 5%', 5.0, 'purchase' );
		$taxes = $this->connector( $server )->listTaxes( $this->connection( $server ) );
		$this->assertSame(
			array(
				array( 'code' => '1', 'label' => 'VAT 5% (AE)', 'rate_percent' => 5.0 ),
				array( 'code' => '2', 'label' => 'VAT 0% (AE)', 'rate_percent' => 0.0 ),
			),
			$taxes
		);
	}

	public function test_lookup_failures_come_back_as_errors_and_are_never_thrown(): void {
		$server     = $this->server( 18 );
		$connector  = $this->connector( $server );
		$connection = $this->connection( $server, array(), array( 'api_key' => 'wrong-key-value' ) );
		foreach ( array(
			$connector->lookupCustomer( $connection, 'a@b.c' ),
			$connector->lookupProduct( $connection, 'X' ),
			$connector->searchProducts( $connection, 'x' ),
			$connector->listTaxes( $connection ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Auth001, $result->code );
		}
		$timeout = $this->connector( ( new FakeHttpClient() )->fallback( static fn() => FakeHttpClient::timeout() ) );
		$this->assertSame( ErrorCode::Net001, $timeout->lookupProduct( $this->connection( $server ), 'X' )->code );
	}
}
