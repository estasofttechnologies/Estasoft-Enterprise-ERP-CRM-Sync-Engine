<?php
/**
 * Tax mapping and product matching routes, with a fake WooCommerce behind the ports.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Repositories\LogQuery;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Rest\Products\ProductMatchService;
use Estasoft\SyncEngine\Rest\Products\ProductSource;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Tax\ConnectorTaxCodes;
use Estasoft\SyncEngine\Rest\Tax\TaxMapAssembler;
use Estasoft\SyncEngine\Rest\Tax\TaxSource;
use Estasoft\SyncEngine\Tests\Support\RestFakeConnector;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class TaxAndProductRoutesTest extends RestIntegrationCase {

	private int $conn;

	private RestFakeConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->connector = new RestFakeConnector( 'restfake' );
		$this->replace( Services::CONNECTOR_REGISTRY, new ConnectorRegistry( array( $this->connector ) ) );
		$this->conn = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'restfake',
				'type'      => 'erp',
				'label'     => 'Rest Fake',
			),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array()
		);
		$this->actAs( 'administrator' );
	}

	/**
	 * Install a tax assembler that reads rates and use counts from arrays.
	 */
	private function taxAssembler(): void {
		$source = new class() implements TaxSource {
			public function shopRates(): array {
				return array(
					array( 'country' => 'AE', 'tax_class' => '', 'tax_name' => 'VAT', 'rate_percent' => 5 ),
					array( 'country' => 'SA', 'tax_class' => 'reduced', 'tax_name' => 'VAT reduced', 'rate_percent' => 15 ),
					array( 'country' => 'KW', 'tax_class' => '', 'tax_name' => 'Fee', 'rate_percent' => 2.5 ),
				);
			}

			public function ordersLast30Days(): array {
				return array( 'AE|5' => 41, 'SA|15' => 3 );
			}
		};
		$this->replace(
			RestModule::TAX_MAP,
			new TaxMapAssembler(
				$this->container->get( 'mapping.tax_map' ),
				$source,
				new ConnectorTaxCodes( $this->container->get( Services::CONNECTION_REPOSITORY ), $this->container->get( Services::CONNECTOR_REGISTRY ) )
			)
		);
		$this->connector->taxes = array(
			array( 'code' => '12', 'label' => 'VAT 5%', 'rate_percent' => 5.0 ),
			array( 'code' => '14', 'label' => 'VAT 15%', 'rate_percent' => 15.0 ),
		);
	}

	public function test_the_tax_map_lists_the_shops_rates_unmapped_with_recent_use_and_the_systems_codes(): void {
		$this->taxAssembler();
		$map = $this->call( 'GET', '/tax-map' )->get_data();
		$this->assertCount( 3, $map['rows'] );
		$ae = array_values( array_filter( $map['rows'], static fn( array $row ): bool => 'AE' === $row['country'] ) )[0];
		$this->assertSame( array( 'country' => 'AE', 'tax_name' => 'VAT', 'rate_percent' => 5, 'system_code' => null, 'orders_30d' => 41 ), $ae );
		$this->assertSame( array( '12', '14' ), array_column( $map['system_codes'], 'code' ) );
		$this->assertSame( 'SA', $map['buyer_tax_rules'][0]['country'], 'the default Saudi rule (AS-16)' );
		$this->assertSame( 15, $map['buyer_tax_rules'][0]['digits'] );
	}

	public function test_saving_a_code_keeps_the_tax_class_that_the_screen_does_not_send(): void {
		$this->taxAssembler();
		$saved = $this->call(
			'PUT',
			'/tax-map',
			array(
				'rows' => array(
					array( 'country' => 'ae', 'tax_name' => 'VAT', 'rate_percent' => 5, 'system_code' => '12' ),
					array( 'country' => 'SA', 'tax_name' => 'VAT reduced', 'rate_percent' => 15, 'system_code' => '14' ),
				),
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$rows = array_column( $saved->get_data()['rows'], null, 'country' );
		$this->assertSame( '12', $rows['AE']['system_code'] );
		$this->assertSame( '14', $rows['SA']['system_code'] );
		$this->assertSame( 41, $rows['AE']['orders_30d'] );
		$this->assertSame( 'reduced', $this->container->get( 'mapping.tax_map' )->resolve( $this->conn, 'SA', 'reduced', '15' ) === '14' ? 'reduced' : 'lost', 'the code resolves for the class it was saved under' );
		$this->assertSame( '12', $this->container->get( 'mapping.tax_map' )->resolve( $this->conn, 'AE', '', '5' ) );
	}

	public function test_a_row_with_a_bad_country_or_rate_is_refused_and_nothing_is_saved(): void {
		$this->taxAssembler();
		$this->assertSame( 400, $this->call( 'PUT', '/tax-map', array( 'rows' => array( array( 'country' => 'UAE', 'rate_percent' => 5 ) ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/tax-map', array( 'rows' => array( array( 'country' => 'AE', 'rate_percent' => 500 ) ) ) )->get_status() );
		$this->assertNull( $this->call( 'GET', '/tax-map' )->get_data()['rows'][0]['system_code'] );
	}

	public function test_a_buyer_rule_without_a_country_is_a_422_with_a_message_for_that_rule_and_nothing_is_saved(): void {
		$this->taxAssembler();
		$response = $this->call(
			'PUT',
			'/tax-map',
			array(
				'rows'            => array( array( 'country' => 'AE', 'tax_name' => 'VAT', 'rate_percent' => 5, 'system_code' => '12' ) ),
				'buyer_tax_rules' => array( array( 'country' => 'SA', 'digits' => 15 ), array( 'country' => 'SAU' ), array( 'digits' => 9 ) ),
			)
		);
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( array( 'buyer_tax_rules.1.country', 'buyer_tax_rules.2.country' ), array_keys( $response->get_data()['data']['fields'] ) );
		$this->assertSame( 'Enter a two-letter country code, for example SA.', $response->get_data()['data']['fields']['buyer_tax_rules.1.country'] );
		$this->assertNull( $this->call( 'GET', '/tax-map' )->get_data()['rows'][0]['system_code'], 'the valid row was not saved either' );
		$this->assertSame( 0, $this->container->get( Services::LOG_REPOSITORY )->query( new LogQuery( 'error' ) )->toArray()['total'], 'a mistake of the person is not an unexpected failure' );
	}

	public function test_the_assembler_names_each_wrong_row_before_anything_reaches_the_tax_store(): void {
		$this->taxAssembler();
		$assembler = $this->container->get( RestModule::TAX_MAP );
		try {
			$assembler->save(
				$this->conn,
				array(
					array( 'country' => 'AE', 'rate_percent' => 5 ),
					array( 'country' => 'UAE', 'rate_percent' => 5 ),
					array( 'country' => 'SA', 'rate_percent' => 'abc' ),
					array( 'country' => 'KW', 'rate_percent' => 100.5 ),
					array( 'rate_percent' => 5 ),
				),
				null,
				'Test'
			);
			$this->fail( 'expected a ValidationException' );
		} catch ( ValidationException $error ) {
			$this->assertSame(
				array( 'rows.1.country', 'rows.2.rate_percent', 'rows.3.rate_percent', 'rows.4.country' ),
				array_keys( $error->errors() )
			);
		}
		$this->assertNull( $assembler->build( $this->conn )['rows'][0]['system_code'] );
	}

	public function test_a_connector_that_breaks_while_listing_codes_leaves_the_screen_its_rows_and_the_log_the_class_only(): void {
		$this->container->instance(
			RestModule::TAX_MAP,
			new TaxMapAssembler(
				$this->container->get( 'mapping.tax_map' ),
				new class() implements TaxSource {
					public function shopRates(): array {
						return array( array( 'country' => 'AE', 'tax_class' => '', 'tax_name' => 'VAT', 'rate_percent' => 5 ) );
					}

					public function ordersLast30Days(): array {
						return array();
					}
				},
				new ConnectorTaxCodes(
					$this->container->get( Services::CONNECTION_REPOSITORY ),
					$this->container->get( Services::CONNECTOR_REGISTRY ),
					function ( string $class ): void {
						$this->container->get( Services::LOGGER )->warning( 'The tax codes of the connected system could not be read: ' . $class );
					}
				)
			)
		);
		$this->connector->taxesThrow = new \RuntimeException( 'api_key=hunter2 was refused' );
		$map                         = $this->call( 'GET', '/tax-map' )->get_data();
		$this->assertCount( 1, $map['rows'] );
		$this->assertSame( array(), $map['system_codes'] );
		$logged = (string) wp_json_encode( $this->container->get( Services::LOG_REPOSITORY )->query( new LogQuery( 'warning' ) )->toArray() );
		$this->assertStringContainsString( 'could not be read: RuntimeException', $logged );
		$this->assertStringNotContainsString( 'hunter2', $logged );
	}

	public function test_a_system_that_cannot_be_reached_still_gives_the_tax_screen_its_rows(): void {
		$this->taxAssembler();
		$this->connector->taxes = new ConnectorError( ErrorCode::Net001 );
		$map = $this->call( 'GET', '/tax-map' )->get_data();
		$this->assertCount( 3, $map['rows'] );
		$this->assertSame( array(), $map['system_codes'] );
	}

	/**
	 * Install a product matching service over a fake shop.
	 *
	 * @param array<int,array{id:int,sku:string,name:string}> $products Products that need matching.
	 */
	private function matching( array $products ): void {
		$source = new class( $products ) implements ProductSource {
			public function __construct( private readonly array $products ) {
			}

			public function unmatched( int $page, int $perPage ): array {
				return array( 'items' => array_slice( $this->products, ( $page - 1 ) * $perPage, $perPage ), 'total' => count( $this->products ) );
			}

			public function find( int $id ): ?array {
				foreach ( $this->products as $product ) {
					if ( $product['id'] === $id ) {
						return $product;
					}
				}
				return null;
			}
		};
		$this->replace(
			RestModule::PRODUCT_MATCHING,
			new ProductMatchService(
				$source,
				$this->container->get( 'mapping.sku_matcher' ),
				$this->container->get( Services::ID_MAP_REPOSITORY ),
				$this->container->get( Services::QUEUE_REPOSITORY ),
				$this->container->get( Services::CONNECTION_REPOSITORY ),
				$this->container->get( Services::CONNECTOR_REGISTRY )
			)
		);
	}

	public function test_unmatched_products_show_their_status_and_how_many_failed_orders_wait_for_them(): void {
		$this->matching(
			array(
				array( 'id' => 11, 'sku' => 'CS-LAMP-07', 'name' => 'Lamp' ),
				array( 'id' => 12, 'sku' => '', 'name' => 'Mystery box' ),
			)
		);
		foreach ( array( '1', '2' ) as $order ) {
			$item = $this->h->item( $this->conn, $order );
			$this->h->queue->markFailed( $item, 'SYNC-MAP-001', 'Product CS-LAMP-07 was not found.', 'SKU CS-LAMP-07' );
		}
		$response = $this->call( 'GET', '/products/unmatched' );
		$page     = $response->get_data();
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 25, $page['per_page'] );
		$this->assertSame( 'unmatched', $page['items'][0]['status'] );
		$this->assertSame( 2, $page['items'][0]['orders_blocked'] );
		$this->assertSame( 'no_sku', $page['items'][1]['status'] );
		$this->assertSame( 0, $page['items'][1]['orders_blocked'] );
		$this->assertNull( $page['items'][0]['external_id'] );
	}

	public function test_choosing_a_system_product_by_hand_is_stored_as_an_override_and_can_be_cleared(): void {
		$this->matching( array( array( 'id' => 11, 'sku' => 'CS-LAMP-07', 'name' => 'Lamp' ) ) );
		$mapped = $this->call( 'POST', '/products/map', array( 'product_id' => 11, 'external_id' => '9001', 'external_ref' => 'LAMP-7' ) );
		$this->assertSame( 200, $mapped->get_status() );
		$this->assertSame( 'override', $mapped->get_data()['status'] );
		$this->assertSame( '9001', $mapped->get_data()['external_id'] );
		$this->assertSame( 'LAMP-7', $mapped->get_data()['external_ref'] );
		$this->assertTrue( $this->container->get( Services::ID_MAP_REPOSITORY )->get( $this->conn, SkuMatcher::ENTITY, '11' )['is_override'] );

		$cleared = $this->call( 'POST', '/products/map', array( 'product_id' => 11, 'external_id' => null ) );
		$this->assertSame( 'unmatched', $cleared->get_data()['status'] );
		$this->assertNull( $cleared->get_data()['external_id'] );
		$this->assertSame( 404, $this->call( 'POST', '/products/map', array( 'product_id' => 999, 'external_id' => '1' ) )->get_status() );
	}

	public function test_searching_the_system_returns_id_ref_and_name_and_failures_use_the_catalog_code(): void {
		$this->matching( array() );
		$this->connector->search = array( new ExternalRef( '9001', 'LAMP-7', null, array( 'name' => 'Cedar lamp' ) ) );
		$hits = $this->call( 'GET', '/products/search', array( 'q' => 'lamp' ) );
		$this->assertSame( array( array( 'id' => '9001', 'ref' => 'LAMP-7', 'name' => 'Cedar lamp' ) ), $hits->get_data() );
		$this->assertSame( array( 'lamp' ), $this->connector->searched );

		$this->connector->search = new ConnectorError( ErrorCode::Net001, array( 'system' => 'Rest Fake' ) );
		$failed = $this->call( 'GET', '/products/search', array( 'q' => 'lamp' ) );
		$this->assertSame( 502, $failed->get_status() );
		$this->assertSame( 'SYNC-NET-001', $failed->get_data()['code'] );
		$this->assertSame( 'retry', $failed->get_data()['data']['class'] );
		$this->assertSame( 400, $this->call( 'GET', '/products/search' )->get_status(), 'q is required' );
	}

	public function test_a_shop_manager_can_see_unmatched_products_but_not_search_or_map(): void {
		$this->matching( array() );
		$this->actAs( 'shop_manager' );
		$this->assertSame( 200, $this->call( 'GET', '/products/unmatched' )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/products/search', array( 'q' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/products/map', array( 'product_id' => 1, 'external_id' => '2' ) )->get_status() );
	}
}
