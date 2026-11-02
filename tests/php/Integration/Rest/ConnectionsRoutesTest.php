<?php
/**
 * Connection routes: no secret in any answer, parameters cleaned, failures shaped.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestFake;
use Estasoft\SyncEngine\Tests\Support\RestFakeConnector;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class ConnectionsRoutesTest extends RestIntegrationCase {

	private const SECRET = 'sk-live-TOPSECRET-1234567890abcdef';

	/**
	 * Create an Odoo connection with a secret, through the container's own repository (the running key).
	 */
	private function odooConnection(): int {
		return $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
				'address'   => 'https://erp.example.test',
				'database'  => 'main',
				'user'      => 'sync@example.test',
			),
			array( 'api_key' => self::SECRET ),
			array()
		);
	}

	public function test_no_connection_route_returns_a_secret_or_the_stored_ciphertext(): void {
		$id = $this->odooConnection();
		$this->actAs( 'administrator' );

		$bodies = array(
			$this->call( 'GET', '/connections' ),
			$this->call( 'GET', "/connections/$id" ),
			$this->call( 'POST', "/connections/$id", array( 'label' => 'Odoo main', 'settings' => array( 'requests_per_second' => 3 ) ) ),
			$this->call( 'GET', '/status' ),
			$this->call( 'GET', '/catalog' ),
			$this->call( 'GET', '/support-report' ),
		);
		$ciphertext = (string) $this->h->connectionRow( $id )['secrets_enc'];
		$this->assertNotSame( '', $ciphertext );
		foreach ( $bodies as $index => $response ) {
			$json = (string) wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( self::SECRET, $json, "answer $index" );
			$this->assertStringNotContainsString( $ciphertext, $json, "answer $index" );
			$this->assertStringNotContainsString( 'TOPSECRET', $json, "answer $index" );
		}
		$this->assertSame( $id, $this->call( 'GET', "/connections/$id" )->get_data()['id'] );
	}

	public function test_the_screen_is_told_that_a_key_is_saved_but_never_what_it_is(): void {
		$id = $this->odooConnection();
		$this->actAs( 'administrator' );
		$one = $this->call( 'GET', "/connections/$id" )->get_data();
		$key = array_values( array_filter( $one['credentials'], static fn( array $field ): bool => 'api_key' === $field['name'] ) );
		$this->assertNull( $key[0]['value'], 'the value is never returned' );
		$this->assertTrue( $key[0]['has_value'], 'the screen is told a key is saved' );
	}

	public function test_a_shop_manager_can_read_connections_without_secrets_but_not_change_them(): void {
		$id = $this->odooConnection();
		$this->actAs( 'shop_manager' );
		$this->assertSame( 200, $this->call( 'GET', "/connections/$id" )->get_status() );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $this->call( 'GET', "/connections/$id" )->get_data() ) );
		$this->assertSame( 403, $this->call( 'POST', "/connections/$id", array( 'label' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', "/connections/$id" )->get_status() );
	}

	public function test_a_missing_connection_is_a_404_with_the_wordpress_code(): void {
		$this->actAs( 'administrator' );
		$response = $this->call( 'GET', '/connections/424242' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_not_found', $response->get_data()['code'] );
	}

	public function test_create_cleans_the_fields_but_keeps_a_secret_exactly_as_typed(): void {
		$service = new RestFake( array( 'create' => array( 'id' => 5 ) ) );
		$this->replace( JobServices::CONNECTIONS, $service );
		$this->actAs( 'administrator' );

		$secret   = ' p<a>ss"wo\'rd&<script> ';
		$response = $this->call(
			'POST',
			'/connections',
			array(
				'connector' => 'odoo',
				'label'     => '  Main <b>Odoo</b>  ',
				'config'    => array(
					'address'  => " https://erp.example.test/<script>x</script> ",
					'Database' => 'main',
					'bad key!' => 'value',
				),
				'secrets'   => array( 'api_key' => $secret ),
				'settings'  => array( 'requests_per_second' => 3 ),
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'id' => 5 ), $response->get_data() );

		$input = $service->called( 'create' )[0][0];
		$this->assertSame( 'odoo', $input['connector'] );
		$this->assertSame( 'Main Odoo', $input['label'] );
		$this->assertSame( 'https://erp.example.test/', $input['config']['address'], 'tags and what is inside a script tag are removed, spaces trimmed' );
		$this->assertSame( 'main', $input['config']['database'], 'keys are lower-cased' );
		$this->assertSame( 'value', $input['config']['badkey'], 'odd characters leave the key' );
		$this->assertSame( $secret, $input['secrets']['api_key'], 'secrets are not touched' );
		$this->assertSame( array( 'requests_per_second' => 3 ), $input['settings'] );
	}

	public function test_create_without_a_connector_or_with_an_odd_connector_id_is_refused(): void {
		$this->replace( JobServices::CONNECTIONS, new RestFake() );
		$this->actAs( 'administrator' );
		$this->assertSame( 400, $this->call( 'POST', '/connections', array() )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/connections', array( 'connector' => '../etc' ) )->get_status() );
	}

	public function test_a_failed_connection_test_is_a_422_with_the_catalog_code_and_the_test_result(): void {
		$result = array(
			'ok'    => false,
			'error' => array( 'code' => 'SYNC-AUTH-001' ),
		);
		$this->replace(
			JobServices::CONNECTIONS,
			new RestFake( array(), array( 'create' => new JobException( 'Odoo refused the sign-in. Check the user and the API key.', 422, ErrorCode::Auth001, array( 'test' => $result ) ) ) )
		);
		$this->actAs( 'administrator' );
		$response = $this->call( 'POST', '/connections', array( 'connector' => 'odoo' ) );
		$data     = $response->get_data();
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'SYNC-AUTH-001', $data['code'] );
		$this->assertSame( 'needs_person', $data['data']['class'] );
		$this->assertSame( 422, $data['data']['status'] );
		$this->assertSame( $result, $data['data']['details']['test'] );
	}

	public function test_a_missing_encryption_key_is_a_409_with_sync_cfg_002_and_the_line_to_add(): void {
		$line = "define( 'ESTASOFT_SYNC_KEY', 'abc' );";
		$this->replace(
			JobServices::CONNECTIONS,
			new RestFake( array(), array( 'create' => new JobException( ErrorCode::Cfg002->template(), 409, ErrorCode::Cfg002, array( 'key_line' => $line ) ) ) )
		);
		$this->actAs( 'administrator' );
		$response = $this->call( 'POST', '/connections', array( 'connector' => 'odoo' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'SYNC-CFG-002', $response->get_data()['code'] );
		$this->assertSame( $line, $response->get_data()['data']['details']['key_line'] );

		$this->replace( JobServices::CONNECTIONS, new RestFake( array(), array( 'create' => CryptoException::keyMissing() ) ) );
		$this->assertSame( 'SYNC-CFG-002', $this->call( 'POST', '/connections', array( 'connector' => 'odoo' ) )->get_data()['code'] );
	}

	public function test_field_errors_are_a_422_with_one_message_per_field(): void {
		$this->replace( JobServices::CONNECTIONS, new RestFake( array(), array( 'update' => new ValidationException( array( 'settings.requests_per_second' => 'Enter a number from 1 to 50.' ) ) ) ) );
		$this->actAs( 'administrator' );
		$response = $this->call( 'POST', '/connections/3', array( 'settings' => array( 'requests_per_second' => 500 ) ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'Enter a number from 1 to 50.', $response->get_data()['data']['fields']['settings.requests_per_second'] );
	}

	public function test_an_unexpected_failure_is_a_generic_500_that_leaks_nothing_and_is_logged_without_its_message(): void {
		$this->replace( JobServices::CONNECTIONS, new RestFake( array(), array( 'summaries' => new \RuntimeException( 'SQL error near sk-live-LEAKY-TOKEN in /var/www/secret/path.php' ) ) ) );
		$this->actAs( 'administrator' );
		$response = $this->call( 'GET', '/connections' );
		$json     = (string) wp_json_encode( $response->get_data() );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'SYNC-REST-001', $response->get_data()['code'] );
		$this->assertSame( 'info', $response->get_data()['data']['class'] );
		$this->assertStringNotContainsString( 'LEAKY', $json );
		$this->assertStringNotContainsString( '/var/www', $json );

		$logged = (string) wp_json_encode( $this->container->get( Services::LOG_REPOSITORY )->latest( 5 ) );
		$this->assertStringContainsString( 'RuntimeException', $logged );
		$this->assertStringNotContainsString( 'LEAKY', $logged );
	}

	public function test_test_before_saving_passes_the_saved_connection_so_that_empty_secrets_keep_their_value(): void {
		$service = new RestFake( array( 'test' => array( 'ok' => true ) ) );
		$this->replace( JobServices::CONNECTIONS, $service );
		$this->actAs( 'administrator' );

		$this->call( 'POST', '/connections/test', array( 'connector' => 'odoo', 'connection_id' => 4, 'config' => array( 'address' => 'https://x.test' ), 'secrets' => array( 'api_key' => '' ) ) );
		$this->call( 'POST', '/connections/9/test' );
		$calls = $service->called( 'test' );
		$this->assertSame( 4, $calls[0][1] );
		$this->assertSame( '', $calls[0][0]['secrets']['api_key'] );
		$this->assertSame( array(), $calls[1][0] );
		$this->assertSame( 9, $calls[1][1] );
	}

	/**
	 * The keys of `ConnectionTestResult` in src/api/types.ts.
	 */
	private const TEST_RESULT_KEYS = array( 'ok', 'version', 'company', 'warehouses', 'product_count', 'permissions', 'missing_permissions', 'error' );

	public function test_the_answers_of_a_connection_test_have_exactly_the_keys_of_the_type_and_never_what_the_connector_detected(): void {
		$connector             = new RestFakeConnector( 'restfake' );
		$connector->testResult = new TestResult(
			true,
			'Fake 1.0',
			'Cedar & Stone',
			array( array( 'id' => '3', 'name' => 'Main' ) ),
			array( 'create_sale_orders' => true ),
			null,
			array( 'product_count' => 120, 'transport' => 'jsonrpc', 'token' => 'DETECTED-SECRET', 'nested' => array( 'a' => new \stdClass() ) )
		);
		$this->replace( Services::CONNECTOR_REGISTRY, new ConnectorRegistry( array( $connector ) ) );
		$id = $this->container->get( Services::CONNECTION_REPOSITORY )->create( array( 'connector' => 'restfake', 'type' => 'erp', 'label' => 'Fake' ), array( 'api_key' => self::SECRET ), array() );
		$this->actAs( 'administrator' );

		foreach ( array( $this->call( 'POST', "/connections/$id/test" ), $this->call( 'POST', '/connections/test', array( 'connector' => 'restfake', 'connection_id' => $id, 'config' => array(), 'secrets' => array() ) ) ) as $response ) {
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( self::TEST_RESULT_KEYS, array_keys( $response->get_data() ) );
			$this->assertSame( 120, $response->get_data()['product_count'], 'the count the screen shows still comes through' );
			$this->assertStringNotContainsString( 'DETECTED-SECRET', (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_a_failed_test_does_not_carry_what_the_connector_detected_in_its_details(): void {
		$this->replace(
			JobServices::CONNECTIONS,
			new RestFake( array(), array( 'create' => new JobException( 'Refused.', 422, ErrorCode::Auth001, array( 'test' => array( 'ok' => false, 'detected' => array( 'token' => 'DETECTED-SECRET' ) ) ) ) ) )
		);
		$this->actAs( 'administrator' );
		$response = $this->call( 'POST', '/connections', array( 'connector' => 'odoo', 'config' => array( 'address' => 'https://x.test' ), 'secrets' => array( 'api_key' => 'k' ) ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertStringNotContainsString( 'DETECTED-SECRET', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_disconnect_answers_with_the_documented_shape(): void {
		$service = new RestFake();
		$this->replace( JobServices::CONNECTIONS, $service );
		$this->actAs( 'administrator' );
		$response = $this->call( 'DELETE', '/connections/3' );
		$this->assertSame( array( 'disconnected' => true ), $response->get_data() );
		$this->assertSame( array( array( 3 ) ), $service->called( 'disconnect' ) );
	}

	public function test_inbound_is_switched_on_once_with_the_secret_shown_once_and_never_kept_for_replay(): void {
		$id = $this->odooConnection();
		$this->actAs( 'administrator' );
		$headers = array( 'Idempotency-Key' => 'inbound-on' );

		$on = $this->call( 'POST', "/connections/$id/inbound", array( 'enabled' => true ), $headers );
		$this->assertSame( 200, $on->get_status() );
		$secret = $on->get_data()['secret'];
		$this->assertGreaterThanOrEqual( 32, strlen( $secret ) );
		$this->assertStringEndsWith( "/estasoft-sync/v1/inbound/$id", $on->get_data()['url'] );
		$this->assertTrue( $this->call( 'GET', "/connections/$id" )->get_data()['inbound_enabled'] );
		$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $this->call( 'GET', "/connections/$id" )->get_data() ), 'the secret is shown once' );

		global $wpdb;
		$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $secret ) . '%' ) );
		$this->assertSame( 0, $stored, 'the answer is not kept in a transient for the Idempotency-Key' );

		$off = $this->call( 'POST', "/connections/$id/inbound", array( 'enabled' => false ) );
		$this->assertSame( array( 'enabled' => false ), $off->get_data() );
		$this->assertFalse( $this->call( 'GET', "/connections/$id" )->get_data()['inbound_enabled'] );
	}
}
