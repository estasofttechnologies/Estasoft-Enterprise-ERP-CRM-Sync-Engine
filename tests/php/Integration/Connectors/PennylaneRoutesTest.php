<?php
/**
 * Pennylane on the real plugin: it is not offered yet, so the connection routes must refuse to create or test a connection for it, nothing
 * may be saved, and no request may leave the site (the plugin contains no code that talks to Pennylane).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Connectors;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Pennylane\PennylaneConnector
 */
final class PennylaneRoutesTest extends RestIntegrationCase {

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
	 * Number of saved connections for Pennylane.
	 */
	private function pennylaneConnections(): int {
		$count = 0;
		foreach ( $this->container->get( Services::CONNECTION_REPOSITORY )->allWithoutSecrets() as $connection ) {
			if ( 'pennylane' === $connection->connector ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The Choose a system screen shows Pennylane as a system that cannot be chosen, with no form behind it.
	 */
	public function test_the_catalog_lists_pennylane_as_not_available(): void {
		$rows  = $this->call( 'GET', '/catalog' )->get_data();
		$pennylane = array_values( array_filter( $rows, static fn( array $row ): bool => 'pennylane' === ( $row['connector'] ?? '' ) ) );
		$this->assertCount( 1, $pennylane );
		$this->assertFalse( $pennylane[0]['available'] );
		$this->assertSame( 'Pennylane', $pennylane[0]['name'] );
		$this->assertSame( array(), $pennylane[0]['credential_fields'], 'No form to fill in.' );
		$this->assertFalse( $pennylane[0]['can_sign_in'] );
		$this->assertSame( array(), $pennylane[0]['hosts'], 'No host is contacted.' );
	}

	/**
	 * Creating a connection for Pennylane fails, saves nothing and sends nothing.
	 */
	public function test_creating_a_pennylane_connection_is_refused(): void {
		$before   = $this->pennylaneConnections();
		$response = $this->call( 'POST', '/connections', array( 'connector' => 'pennylane', 'label' => 'Pennylane' ) );
		$this->assertSame( 422, $response->get_status(), 'The route refuses, because the test of the connection fails.' );
		$this->assertSame( $before, $this->pennylaneConnections(), 'Nothing is saved.' );
		$this->assertSame( array(), $this->outgoing, 'Nothing leaves the site.' );
		$this->assertStringContainsString( 'Pennylane', (string) wp_json_encode( $response->get_data() ), 'The answer says why.' );
	}

	/**
	 * Testing Pennylane returns a failed result with the reason, and sends nothing.
	 */
	public function test_testing_pennylane_fails_with_the_reason(): void {
		$response = $this->call( 'POST', '/connections/test', array( 'connector' => 'pennylane' ) );
		$data     = $response->get_data();
		$this->assertSame( array(), $this->outgoing, 'Nothing leaves the site.' );
		$this->assertSame( 0, $this->pennylaneConnections() );
		$this->assertSame( 200, $response->get_status(), 'A test that fails is an answer, not an error.' );
		$this->assertFalse( $data['ok'], 'The test fails.' );
		$this->assertSame( 'SYNC-VAL-002', $data['error']['code'] );
		$this->assertStringContainsString( 'needs a sign-in for production use', $data['error']['message'] );
	}
}
