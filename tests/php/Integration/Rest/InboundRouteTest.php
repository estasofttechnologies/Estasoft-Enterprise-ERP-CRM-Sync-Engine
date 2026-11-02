<?php
/**
 * The signed inbound route: no login, a signature instead.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class InboundRouteTest extends RestIntegrationCase {

	private int $id;

	private string $secret;

	protected function setUp(): void {
		parent::setUp();
		$this->id = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
				'address'   => 'https://erp.example.test',
			),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array()
		);
		$this->actAs( 'administrator' );
		$this->secret = $this->call( 'POST', "/connections/{$this->id}/inbound", array( 'enabled' => true ) )->get_data()['secret'];
		$this->actAs( 'anonymous' );
	}

	/**
	 * Headers for a body, signed with the secret.
	 *
	 * @param string $body   Raw body.
	 * @param string $secret Secret.
	 * @param int    $time   Unix seconds.
	 * @return array<string,string>
	 */
	private function signed( string $body, string $secret, int $time ): array {
		return array(
			'X-Estasoft-Signature' => hash_hmac( 'sha256', $time . '.' . $body, $secret ), // The signature covers the time, so an old message cannot be given a new time.
			'X-Estasoft-Timestamp' => (string) $time,
		);
	}

	public function test_a_signed_message_is_accepted_without_a_login(): void {
		$body     = '{"entity":"stock","id":"CS-LAMP-07"}';
		$response = $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $this->signed( $body, $this->secret, time() ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
	}

	public function test_a_wrong_signature_is_refused_with_sync_inb_001_and_401(): void {
		$body     = '{"entity":"stock"}';
		$response = $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $this->signed( $body, 'not-the-secret', time() ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'SYNC-INB-001', $response->get_data()['code'] );
		$this->assertSame( 401, $response->get_data()['data']['status'] );
		$this->assertSame( 'info', $response->get_data()['data']['class'] );
		$this->assertStringNotContainsString( $this->secret, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_body_changed_after_signing_is_refused(): void {
		$body = '{"entity":"stock"}';
		$this->assertSame( 401, $this->callRaw( 'POST', "/inbound/{$this->id}", $body . ' ', $this->signed( $body, $this->secret, time() ) )->get_status() );
	}

	public function test_the_time_is_part_of_the_signature_so_it_cannot_be_changed_to_make_an_old_message_look_new(): void {
		$body = '{"entity":"stock"}';
		$old  = $this->signed( $body, $this->secret, time() - 3600 );
		$old['X-Estasoft-Timestamp'] = (string) time();
		$this->assertSame( 401, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $old )->get_status() );
	}

	public function test_a_message_older_than_five_minutes_is_refused(): void {
		$body = '{"entity":"stock"}';
		$this->assertSame( 401, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $this->signed( $body, $this->secret, time() - 301 ) )->get_status() );
		$this->assertSame( 200, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $this->signed( $body, $this->secret, time() - 290 ) )->get_status() );
	}

	public function test_a_replayed_message_is_refused(): void {
		$body    = '{"entity":"tracking","id":"WC-1"}';
		$headers = $this->signed( $body, $this->secret, time() );
		$this->assertSame( 200, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $headers )->get_status() );
		$replay = $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $headers );
		$this->assertSame( 409, $replay->get_status() );
		$this->assertSame( 'SYNC-INB-001', $replay->get_data()['code'] );
	}

	public function test_missing_headers_and_an_unknown_or_switched_off_connection_are_refused(): void {
		$body = '{}';
		$this->assertSame( 401, $this->callRaw( 'POST', "/inbound/{$this->id}", $body )->get_status() );
		$this->assertSame( 404, $this->callRaw( 'POST', '/inbound/424242', $body, $this->signed( $body, $this->secret, time() ) )->get_status() );

		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/{$this->id}/inbound", array( 'enabled' => false ) );
		$this->actAs( 'anonymous' );
		$this->assertSame( 404, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $this->signed( $body, $this->secret, time() ) )->get_status() );
	}

	public function test_the_inbound_route_does_not_honour_an_idempotency_key(): void {
		$body    = '{"entity":"stock","id":"A"}';
		$headers = $this->signed( $body, $this->secret, time() ) + array( 'Idempotency-Key' => 'abc' );
		$this->assertSame( 200, $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $headers )->get_status() );
		$second = $this->callRaw( 'POST', "/inbound/{$this->id}", $body, $headers );
		$this->assertSame( 409, $second->get_status(), 'the handler refuses the replay; the cache must not hide it' );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $second->get_headers() );
	}
}
