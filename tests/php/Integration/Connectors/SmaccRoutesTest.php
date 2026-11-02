<?php
/**
 * SMACC on the real plugin: it publishes no API, so the connection routes must refuse to create or test a connection for it, nothing
 * may be saved, and no request may leave the site.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Connectors;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Smacc\SmaccConnector
 */
final class SmaccRoutesTest extends RestIntegrationCase {

	/**
	 * Requests that tried to leave the site during a test.
	 *
	 * @var array<int,string>
	 */
	private array $outgoing = array();

	/**
	 * Watch the outgoing requests and sign in as an administrator.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->outgoing = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $args );
				$this->outgoing[] = (string) $url;
				return new \WP_Error( 'blocked_in_test', 'No request may be made.' );
			},
			10,
			3
		);
		$this->actAs( 'administrator' );
	}

	/**
	 * Stop watching.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Number of saved connections for SMACC.
	 */
	private function smaccConnections(): int {
		$count = 0;
		foreach ( $this->container->get( Services::CONNECTION_REPOSITORY )->allWithoutSecrets() as $connection ) {
			if ( 'smacc' === $connection->connector ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The Choose a system screen shows SMACC as a system that cannot be chosen, with no form behind it.
	 */
	public function test_the_catalog_lists_smacc_as_not_available(): void {
		$rows  = $this->call( 'GET', '/catalog' )->get_data();
		$smacc = array_values( array_filter( $rows, static fn( array $row ): bool => 'smacc' === ( $row['connector'] ?? '' ) ) );
		$this->assertCount( 1, $smacc );
		$this->assertFalse( $smacc[0]['available'] );
		$this->assertSame( 'SMACC', $smacc[0]['name'] );
		$this->assertSame( array(), $smacc[0]['credential_fields'], 'No form to fill in.' );
		$this->assertFalse( $smacc[0]['can_sign_in'] );
		$this->assertSame( array(), $smacc[0]['hosts'], 'No host is contacted.' );
	}

	/**
	 * Creating a connection for SMACC fails, saves nothing and sends nothing.
	 */
	public function test_creating_a_smacc_connection_is_refused(): void {
		$before   = $this->smaccConnections();
		$response = $this->call( 'POST', '/connections', array( 'connector' => 'smacc', 'label' => 'SMACC' ) );
		$this->assertSame( 422, $response->get_status(), 'The route refuses, because the test of the connection fails.' );
		$this->assertSame( $before, $this->smaccConnections(), 'Nothing is saved.' );
		$this->assertSame( array(), $this->outgoing, 'Nothing leaves the site.' );
		$this->assertStringContainsString( 'SMACC', (string) wp_json_encode( $response->get_data() ), 'The answer says why.' );
	}

	/**
	 * Testing SMACC returns a failed result with the reason, and sends nothing.
	 */
	public function test_testing_smacc_fails_with_the_reason(): void {
		$response = $this->call( 'POST', '/connections/test', array( 'connector' => 'smacc' ) );
		$data     = $response->get_data();
		$this->assertSame( array(), $this->outgoing, 'Nothing leaves the site.' );
		$this->assertSame( 0, $this->smaccConnections() );
		$this->assertSame( 200, $response->get_status(), 'A test that fails is an answer, not an error.' );
		$this->assertFalse( $data['ok'], 'The test fails.' );
		$this->assertSame( 'SYNC-VAL-002', $data['error']['code'] );
		$this->assertStringContainsString( 'publishes no public API', $data['error']['message'] );
	}
}
