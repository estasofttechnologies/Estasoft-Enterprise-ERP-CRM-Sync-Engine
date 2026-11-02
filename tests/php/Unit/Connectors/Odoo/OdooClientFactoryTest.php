<?php
/**
 * Building and remembering signed-in clients.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\OdooAddressNormalizer;
use Estasoft\SyncEngine\Connectors\Odoo\OdooClient;
use Estasoft\SyncEngine\Connectors\Odoo\OdooClientFactory;
use Estasoft\SyncEngine\Connectors\Odoo\OdooException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooClientFactoryTest extends OdooTestCase {

	/**
	 * Current time in seconds, moved by the tests.
	 *
	 * @var int
	 */
	private int $now = 1_800_000_000;

	private function factory( FakeOdooServer $server ): OdooClientFactory {
		return new OdooClientFactory(
			$server,
			new OdooAddressNormalizer( static fn(): bool => false ),
			fn(): int => $this->now,
			function ( int $milliseconds ): void {
				$this->slept[] = $milliseconds;
			},
			static fn(): float => 0.0
		);
	}

	public function test_the_client_has_the_transport_of_the_detected_version(): void {
		foreach ( array( 17 => 'jsonrpc', 18 => 'jsonrpc', 19 => 'json2' ) as $major => $transport ) {
			$server = $this->server( $major );
			$client = $this->factory( $server )->forConnection( $this->connection( $server ) );
			$this->assertInstanceOf( OdooClient::class, $client );
			$this->assertSame( $transport, $client->transportId() );
			$this->assertSame( $major, $client->version()->major );
		}
	}

	public function test_a_client_is_reused_for_five_minutes_and_then_made_again(): void {
		$server  = $this->server( 18 );
		$factory = $this->factory( $server );
		$first   = $factory->forConnection( $this->connection( $server ) );
		$first->userId();
		$this->now += 299;
		$this->assertSame( $first, $factory->forConnection( $this->connection( $server ) ) );
		$this->assertCount( 3, $server->requests, 'The version check (two requests on Odoo 18) and one sign-in, once.' );

		$this->now += 2;
		$again = $factory->forConnection( $this->connection( $server ) );
		$this->assertNotSame( $first, $again );
	}

	public function test_fresh_ignores_what_is_remembered(): void {
		$server  = $this->server( 19 );
		$factory = $this->factory( $server );
		$first   = $factory->forConnection( $this->connection( $server ) );
		$this->assertNotSame( $first, $factory->forConnection( $this->connection( $server ), true, true ) );
	}

	public function test_forget_drops_every_remembered_client(): void {
		$server  = $this->server( 19 );
		$factory = $this->factory( $server );
		$first   = $factory->forConnection( $this->connection( $server ) );
		$factory->forget();
		$this->assertNotSame( $first, $factory->forConnection( $this->connection( $server ) ) );
	}

	public function test_a_changed_key_user_database_company_or_address_gets_its_own_client(): void {
		$server  = $this->server( 18 );
		$factory = $this->factory( $server );
		$base    = $factory->forConnection( $this->connection( $server ) );
		$this->assertNotSame( $base, $factory->forConnection( $this->connection( $server, array(), array( 'api_key' => 'sk-test-0123456789abcdef' . 'x' ) ) ) );
		$this->assertNotSame( $base, $factory->forConnection( $this->connection( $server, array( 'user' => 'other@example.com' ) ) ) );
		$this->assertNotSame( $base, $factory->forConnection( $this->connection( $server, array( 'company' => 'Cedar' ) ) ) );
	}

	public function test_a_missing_key_is_auth_001_and_a_missing_database_is_cfg_003_before_any_request(): void {
		$server = $this->server( 18 );
		try {
			$this->factory( $server )->forConnection( $this->connection( $server, array(), array( 'api_key' => '' ) ) );
			$this->fail( 'Expected a refused sign-in.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Auth001, $exception->error->code );
		}
		try {
			$this->factory( $server )->forConnection( $this->connection( $server, array( 'database' => ' ' ) ) );
			$this->fail( 'Expected CFG-003.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
		}
		$this->assertCount( 0, $server->requests );
	}

	public function test_a_bad_address_is_val_002(): void {
		$server = $this->server( 18 );
		$this->expectException( OdooException::class );
		$this->factory( $server )->forConnection( $this->connection( $server, array( 'address' => 'ftp://nope' ) ) );
	}

	public function test_the_pace_carries_over_from_one_client_to_the_next_for_the_same_server(): void {
		$server  = $this->server( 19 );
		$factory = $this->factory( $server );
		$config  = array( 'options' => array() );
		$factory->forConnection( $this->connection( $server, $config ) );
		$this->assertSame( array(), $this->slept, 'The first request goes at once.' );
		$factory->forConnection( $this->connection( $server, $config + array( 'company' => '' ) ), true, true );
		$this->assertSame( array( 1000 ), $this->slept, 'The next one waits.' );
	}

	public function test_an_unpaced_client_never_waits(): void {
		$server  = $this->server( 18 );
		$factory = $this->factory( $server );
		$factory->forConnection( $this->connection( $server, array( 'options' => array() ) ), false )->userId();
		$this->assertSame( array(), $this->slept );
	}

	public function test_the_interval_option_is_kept_within_sane_limits(): void {
		$server  = $this->server( 18 );
		$factory = $this->factory( $server );
		$factory->forConnection( $this->connection( $server, array( 'options' => array( 'call_interval_ms' => 999999 ) ) ) )->userId();
		$this->assertSame( array( 5000 ), array_values( array_unique( $this->slept ) ), 'Capped at ten seconds in the option, five in a single wait.' );
	}

	public function test_responds_does_not_sign_in(): void {
		$server = $this->server( 18 );
		$this->assertTrue( $this->factory( $server )->responds( $this->connection( $server ) ) );
		$this->assertSame( array( 'common.version' ), $server->services );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_unsafe_database_names(): array {
		return array(
			'line break'      => array( "db\r\nX-Injected: yes" ),
			'space'           => array( 'my db' ),
			'slash'           => array( 'db/../x' ),
			'leading dot'     => array( '.hidden' ),
			'quote'           => array( 'db"x' ),
			'too long'        => array( str_repeat( 'a', 64 ) ),
		);
	}

	/**
	 * @dataProvider provide_unsafe_database_names
	 */
	public function test_a_database_name_that_could_break_a_header_is_refused_before_any_request( string $name ): void {
		$server = $this->server( 19 );
		try {
			$this->factory( $server )->forConnection( $this->connection( $server, array( 'database' => $name ) ) );
			$this->fail( 'Expected CFG-003.' );
		} catch ( OdooException $exception ) {
			$this->assertSame( ErrorCode::Cfg003, $exception->error->code );
		}
		$this->assertCount( 0, $server->requests );
	}

	public function test_ordinary_database_names_pass(): void {
		$server = $this->server( 19 );
		foreach ( array( 'testdb', 'cedarstone', 'cedar-stone_prod.2026', 'A1' ) as $name ) {
			$this->assertTrue( 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,62}$/', $name ), $name );
		}
		$this->assertInstanceOf( OdooClient::class, $this->factory( $server )->forConnection( $this->connection( $server ) ) );
	}

	public function test_a_key_with_a_line_break_or_a_space_is_refused_before_any_request(): void {
		$server = $this->server( 19 );
		foreach ( array( "abc\r\nX-Evil: 1", 'abc def', "abc\tdef" ) as $key ) {
			try {
				$this->factory( $server )->forConnection( $this->connection( $server, array(), array( 'api_key' => $key ) ) );
				$this->fail( 'Expected a refused sign-in.' );
			} catch ( OdooException $exception ) {
				$this->assertSame( ErrorCode::Auth001, $exception->error->code );
				$this->assertStringNotContainsString( 'Evil', $exception->getMessage() . $exception->error->detail );
			}
		}
		$this->assertCount( 0, $server->requests );
	}
}
