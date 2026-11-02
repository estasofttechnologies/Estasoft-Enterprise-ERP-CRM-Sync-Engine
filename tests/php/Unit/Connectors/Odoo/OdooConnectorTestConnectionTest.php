<?php
/**
 * Test connection and ping.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class OdooConnectorTestConnectionTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public static function provide_versions(): array {
		return array(
			'odoo 17' => array( 17, 'Odoo 17 Enterprise', 'jsonrpc' ),
			'odoo 18' => array( 18, 'Odoo 18 Enterprise', 'jsonrpc' ),
			'odoo 19' => array( 19, 'Odoo 19 Enterprise', 'json2' ),
		);
	}

	/**
	 * @dataProvider provide_versions
	 */
	public function test_a_good_connection_reports_everything_the_screen_needs( int $major, string $label, string $transport ): void {
		$server = $this->server( $major );
		$result = $this->connector( $server )->test( $this->connection( $server ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( $label, $result->version );
		$this->assertSame( 'Cedar & Stone Trading LLC', $result->company );
		$this->assertSame(
			array(
				array( 'id' => '1', 'name' => 'Dubai Main' ),
				array( 'id' => '2', 'name' => 'Riyadh 3PL' ),
			),
			$result->warehouses
		);
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( $transport, $result->detected['transport'] );
		$this->assertSame( $major, $result->detected['major'] );
		$this->assertSame( 'AED', $result->detected['currency'] );
		$this->assertSame( 2, $result->detected['currency_decimals'] );
		$this->assertSame( 'AE', $result->detected['country'] );
		$this->assertSame( 2, $result->detected['product_count'] );
		$this->assertFalse( $result->detected['multi_company'] );
		$this->assertTrue( $result->detected['modules']['sale'] );
		$this->assertTrue( $result->detected['modules']['stock'] );
		$this->assertTrue( $result->detected['modules']['delivery'] );
		$this->assertFalse( $result->detected['modules']['l10n_sa_edi'] );
		$this->assertContains( 'uae_no_einvoicing_module', $result->detected['hints'] );
	}

	public function test_the_permissions_cover_the_models_the_sync_touches_by_name(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertEqualsCanonicalizing(
			array( 'read_products', 'read_customers', 'create_customers', 'create_sale_orders', 'confirm_sale_orders', 'create_credit_notes', 'read_stock', 'read_deliveries' ),
			array_keys( $result->permissions )
		);
	}

	public function test_odoo_17_checks_rights_with_check_access_rights_and_later_versions_with_has_access(): void {
		$old = $this->server( 17 );
		$this->connector( $old )->test( $this->connection( $old ) );
		$this->assertNotEmpty( $old->callsTo( 'sale.order', 'check_access_rights' ) );
		$this->assertEmpty( $old->callsTo( 'sale.order', 'has_access' ) );

		$new = $this->server( 18 );
		$this->connector( $new )->test( $this->connection( $new ) );
		$this->assertNotEmpty( $new->callsTo( 'sale.order', 'has_access' ) );
		$this->assertEmpty( $new->callsTo( 'sale.order', 'check_access_rights' ) );
	}

	public function test_a_missing_right_fails_the_test_with_auth_004_naming_it(): void {
		foreach ( array( 17, 18, 19 ) as $major ) {
			$server = $this->server( $major );
			$server->db->deny( 'sale.order', 'create' );
			$result = $this->connector( $server )->test( $this->connection( $server ) );

			$this->assertFalse( $result->ok, "Odoo {$major}" );
			$this->assertSame( ErrorCode::Auth004, $result->error->code );
			$this->assertSame( 'create sale orders', $result->error->context['action'] );
			$this->assertStringContainsString( 'does not allow this user to create sale orders', $result->error->message() );
			$this->assertSame( array( 'create_sale_orders' ), $result->missingPermissions() );
			$this->assertSame( 'Odoo ' . $major . ' Enterprise', $result->version, 'The version is still reported.' );
		}
	}

	public function test_every_missing_blocking_right_is_listed_but_the_first_is_named(): void {
		$server = $this->server( 18 );
		$server->db->deny( 'res.partner', 'create' );
		$server->db->deny( 'account.move', 'create' );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertEqualsCanonicalizing( array( 'create_customers', 'create_credit_notes' ), $result->missingPermissions() );
		$this->assertStringContainsString( 'create customers', $result->error->message() );
		$this->assertStringContainsString( 'create_credit_notes', $result->error->detail );
	}

	public function test_missing_stock_or_delivery_rights_are_listed_but_do_not_block(): void {
		$server = $this->server( 18 );
		$server->db->deny( 'stock.quant', 'read' );
		$server->db->deny( 'stock.picking', 'read' );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertTrue( $result->ok );
		$this->assertEqualsCanonicalizing( array( 'read_stock', 'read_deliveries' ), $result->missingPermissions() );
	}

	public function test_without_the_inventory_app_the_test_passes_and_says_so(): void {
		$server = $this->server( 18, array( 'stock' => false, 'delivery' => false ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertTrue( $result->ok );
		$this->assertFalse( $result->detected['modules']['stock'] );
		$this->assertFalse( $result->detected['modules']['delivery'] );
		$this->assertSame( array(), $result->warehouses );
		$this->assertContains( 'read_stock', $result->missingPermissions() );
	}

	public function test_without_the_delivery_module_tracking_is_reported_as_missing(): void {
		$server = $this->server( 18, array( 'delivery' => false ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertTrue( $result->detected['modules']['stock'] );
		$this->assertFalse( $result->detected['modules']['delivery'] );
	}

	public function test_a_saudi_company_with_e_invoicing_gets_a_production_hint_only_when_in_production(): void {
		$server = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertTrue( $result->detected['modules']['l10n_sa_edi'] );
		$this->assertSame( 'SAR', $result->detected['currency'] );
		$this->assertContains( 'zatca_not_production', $result->detected['hints'] );

		$server->db->records['res.company'][1]['l10n_sa_edi_is_production'] = true;
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertContains( 'zatca_production', $result->detected['hints'] );
	}

	public function test_before_odoo_19_the_production_hint_comes_from_the_api_mode(): void {
		foreach ( array( 17, 18 ) as $major ) {
			$server = $this->server( $major, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
			$result = $this->connector( $server )->test( $this->connection( $server ) );
			$this->assertContains( 'zatca_not_production', $result->detected['hints'], 'Odoo ' . $major . ' in sandbox mode.' );

			$server->db->records['res.company'][1]['l10n_sa_api_mode'] = 'preprod';
			$result = $this->connector( $server )->test( $this->connection( $server ) );
			$this->assertContains( 'zatca_not_production', $result->detected['hints'], 'Odoo ' . $major . ' in simulation mode.' );
			$this->assertNotContains( 'zatca_production', $result->detected['hints'] );

			$server->db->records['res.company'][1]['l10n_sa_api_mode'] = 'prod';
			$result = $this->connector( $server )->test( $this->connection( $server ) );
			$this->assertContains( 'zatca_production', $result->detected['hints'], 'Odoo ' . $major . ' in production mode.' );
		}
	}

	public function test_a_saudi_company_without_the_e_invoicing_module_is_told_so(): void {
		$server = $this->server( 18, array( 'country' => 'SA', 'currency' => 'SAR' ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertContains( 'zatca_not_installed', $result->detected['hints'] );
	}

	public function test_a_user_who_can_work_in_several_companies_is_flagged(): void {
		$server = $this->server( 18 );
		$server->db->records['res.users'][2]['company_ids'] = array( 1, 2 );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertTrue( $result->detected['multi_company'] );
	}

	public function test_a_named_company_is_used_and_the_context_carries_it(): void {
		$server = $this->server( 18 );
		$server->db->insert( 'res.company', array( 'name' => 'Cedar Riyadh LLC', 'currency_id' => 4, 'country_id' => 2 ) );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'company' => 'cedar riyadh llc' ) ) );
		$this->assertSame( 'Cedar Riyadh LLC', $result->company );
		$this->assertSame( 'SA', $result->detected['country'] );
		$context = $server->callsTo( 'product.product', 'search_count' )[0]['context'];
		$this->assertSame( array( 2 ), $context['allowed_company_ids'] );
	}

	public function test_an_unknown_company_name_is_a_clear_error(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'company' => 'No Such Company' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'No Such Company', $result->error->message() );
	}

	public function test_the_key_end_date_is_reported_when_the_user_has_exactly_one_key(): void {
		$server = $this->server( 19 );
		$server->db->insert( 'res.users.apikeys', array( 'name' => 'woo', 'user_id' => 2, 'scope' => false, 'expiration_date' => '2027-02-01 00:00:00' ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertSame( '2027-02-01T00:00:00Z', $result->detected['credential_expires_at'] );
	}

	public function test_the_key_end_date_is_left_out_when_it_cannot_be_known(): void {
		$server = $this->server( 19 );
		$server->db->insert( 'res.users.apikeys', array( 'name' => 'a', 'user_id' => 2, 'scope' => false, 'expiration_date' => '2027-02-01 00:00:00' ) );
		$server->db->insert( 'res.users.apikeys', array( 'name' => 'b', 'user_id' => 2, 'scope' => false, 'expiration_date' => '2027-05-01 00:00:00' ) );
		$result = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertNull( $result->detected['credential_expires_at'], 'Odoo does not say which key made the call.' );

		$none = $this->server( 19 );
		$this->assertNull( $this->connector( $none )->test( $this->connection( $none ) )->detected['credential_expires_at'] );
	}

	public function test_a_wrong_key_is_auth_001_on_every_version(): void {
		foreach ( array( 17, 18, 19 ) as $major ) {
			$server = $this->server( $major );
			$result = $this->connector( $server )->test( $this->connection( $server, array(), array( 'api_key' => 'wrong-key-value' ) ) );
			$this->assertFalse( $result->ok, "Odoo {$major}" );
			$this->assertSame( ErrorCode::Auth001, $result->error->code, "Odoo {$major}" );
			$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
			$this->assertStringNotContainsString( 'wrong-key-value', $result->error->message() . $result->error->detail );
		}
	}

	public function test_a_wrong_user_is_auth_001_on_odoo_18(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'user' => 'someone@else.example' ) ) );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
	}

	public function test_odoo_17_and_18_need_the_sync_user_but_odoo_19_does_not(): void {
		$old = $this->server( 18 );
		$this->assertSame( ErrorCode::Auth001, $this->connector( $old )->test( $this->connection( $old, array( 'user' => '' ) ) )->error->code );

		$new = $this->server( 19 );
		$this->assertTrue( $this->connector( $new )->test( $this->connection( $new, array( 'user' => '' ) ) )->ok );
	}

	public function test_a_missing_key_is_auth_001_without_any_request(): void {
		$server = $this->server( 19 );
		$result = $this->connector( $server )->test( $this->connection( $server, array(), array( 'api_key' => '' ) ) );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertCount( 0, $server->requests );
	}

	public function test_a_wrong_database_is_cfg_003_with_its_name_on_every_version(): void {
		foreach ( array( 17, 18, 19 ) as $major ) {
			$server = $this->server( $major );
			$result = $this->connector( $server )->test( $this->connection( $server, array( 'database' => 'cedarstone_prod' ) ) );
			$this->assertFalse( $result->ok, "Odoo {$major}" );
			$this->assertSame( ErrorCode::Cfg003, $result->error->code, "Odoo {$major}" );
			$this->assertSame( 'Odoo has no database called cedarstone_prod.', $result->error->message(), "Odoo {$major}" );
		}
	}

	public function test_a_missing_database_is_cfg_003(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'database' => '' ) ) );
		$this->assertSame( ErrorCode::Cfg003, $result->error->code );
	}

	public function test_an_odoo_online_plan_without_outside_access_is_auth_006(): void {
		$server               = new \Estasoft\SyncEngine\Tests\Support\FakeOdooServer( 19, 'cedarstone.odoo.com', 'cedarstone', 'sync@example.com', 'sk-test-0123456789abcdef' );
		$server->planBlocked  = true;
		$result               = $this->connector( $server )->test( $this->connection( $server, array( 'database' => 'cedarstone' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth006, $result->error->code );
		$this->assertStringContainsString( 'plan does not include outside access', $result->error->message() );
	}

	public function test_the_same_403_page_from_a_self_hosted_server_is_not_a_plan_problem(): void {
		$server              = $this->server( 19 );
		$server->planBlocked = true;
		$result              = $this->connector( $server )->test( $this->connection( $server ) );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}

	public function test_a_certificate_problem_is_net_003_and_needs_a_person(): void {
		$http   = ( new FakeHttpClient() )->queue( FakeHttpClient::certificateError() );
		$server = $this->server( 18 );
		$result = $this->connector( $http )->test( $this->connection( $server ) );
		$this->assertSame( ErrorCode::Net003, $result->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
	}

	public function test_a_time_out_is_net_001_and_stops_the_test(): void {
		$http   = ( new FakeHttpClient() )->queue( FakeHttpClient::timeout() );
		$server = $this->server( 18 );
		$result = $this->connector( $http )->test( $this->connection( $server ) );
		$this->assertSame( ErrorCode::Net001, $result->error->code );
		$this->assertSame( 1, $http->count() );
	}

	public function test_odoo_16_is_refused_with_a_clear_message(): void {
		$http   = ( new FakeHttpClient() )
			->queue( FakeHttpClient::status( 404 ) )
			->queue( FakeHttpClient::json( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => array( 'server_version' => '16.0', 'server_version_info' => array( 16, 0, 0, 'final', 0 ) ) ) ) );
		$result = $this->connector( $http )->test( $this->connection( $this->server( 18 ) ) );
		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'Odoo 17 or later', $result->error->message() );
	}

	public function test_an_address_with_a_path_and_slash_is_cleaned_up(): void {
		$server = $this->server( 19 );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'address' => 'https://erp.example.com/odoo/action-12/' ) ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'https://erp.example.com/web/version', $server->requests[0]['url'] );
	}

	public function test_a_private_address_is_refused_before_any_request_unless_the_site_owner_allows_it(): void {
		$server = $this->server( 19 );
		$result = $this->connector( $server )->test( $this->connection( $server, array( 'address' => 'https://192.168.1.10:8069' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $server->requests );

		$allowed = $this->connector( $server, static fn(): bool => true )->test( $this->connection( $server, array( 'address' => 'https://192.168.1.10:8069' ) ) );
		$this->assertGreaterThan( 0, count( $server->requests ), 'With the filter on, the request goes out.' );
		$this->assertNotNull( $allowed );
	}

	public function test_a_test_is_a_burst_the_person_asked_for_so_it_does_not_sleep_between_calls(): void {
		$server    = $this->server( 18 );
		$connector = new \Estasoft\SyncEngine\Connectors\Odoo\OdooConnector( $server, static fn(): bool => false, static fn(): int => 1, function ( int $ms ): void { $this->slept[] = $ms; }, static fn(): float => 0.0 );
		$connection = $this->connection( $server, array( 'options' => array( 'call_interval_ms' => 1000 ) ) );
		$connector->test( $connection );
		$this->assertSame( array(), $this->slept );
	}

	public function test_ping_is_one_cheap_request_on_odoo_19_and_two_on_older_versions(): void {
		$new = $this->server( 19 );
		$this->assertTrue( $this->connector( $new )->ping( $this->connection( $new ) ) );
		$this->assertCount( 1, $new->requests );

		$old = $this->server( 18 );
		$this->assertTrue( $this->connector( $old )->ping( $this->connection( $old ) ) );
		$this->assertCount( 2, $old->requests );
		$this->assertSame( array( 'common.version' ), $old->services, 'Ping never signs in.' );
	}

	public function test_ping_is_false_when_the_server_does_not_answer_or_the_address_is_bad(): void {
		$http   = ( new FakeHttpClient() )->queue( FakeHttpClient::timeout() );
		$server = $this->server( 18 );
		$this->assertFalse( $this->connector( $http )->ping( $this->connection( $server ) ) );
		$this->assertFalse( $this->connector( $server )->ping( $this->connection( $server, array( 'address' => 'ftp://nope' ) ) ) );
	}
}
