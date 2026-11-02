<?php
/**
 * Which hosts a connector may contact.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\HostPins;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class HostPolicyTest extends TestCase {

	public function test_fixed_hosts_allow_those_hosts_and_nothing_else(): void {
		$policy = new HostPolicy( array( 'api.xero.com', '*.example.com' ) );
		$this->assertNull( $policy->violation( 'https://api.xero.com/x' ) );
		$this->assertNull( $policy->violation( 'https://eu.example.com/y' ) );
		$this->assertNotNull( $policy->violation( 'https://example.com/y' ), 'The wildcard needs a subdomain.' );
		$this->assertNotNull( $policy->violation( 'https://evil.com/api.xero.com' ) );
		$this->assertNotNull( $policy->violation( 'https://api.xero.com.evil.com/' ) );
		$this->assertNotNull( $policy->violation( 'https://xapi.example.com.evil.test/' ) );
	}

	public function test_it_is_https_only_and_refuses_credentials_in_the_address(): void {
		$policy = new HostPolicy( array( 'api.example.com' ) );
		$this->assertNotNull( $policy->violation( 'http://api.example.com/' ) );
		$this->assertNotNull( $policy->violation( 'ftp://api.example.com/' ) );
		$this->assertNotNull( $policy->violation( 'https://user:pass@api.example.com/' ) );
		$this->assertNotNull( $policy->violation( 'not a url' ) );
		$this->assertNotNull( $policy->violation( '/relative' ) );
	}

	public function test_a_merchant_host_must_be_public(): void {
		$policy = new HostPolicy( array(), true );
		$this->assertNull( $policy->violation( 'https://erp.acme-trading.test/api' ) );
		foreach ( array( 'https://localhost/', 'https://127.0.0.1/', 'https://10.0.0.5/', 'https://192.168.1.2/', 'https://172.16.4.4/', 'https://169.254.169.254/latest', 'https://[::1]/', 'https://intranet/', 'https://erp.internal/', 'https://printer.local/' ) as $url ) {
			$this->assertNotNull( $policy->violation( $url ), $url );
		}
	}

	public function test_the_site_owner_can_allow_private_hosts_and_then_http_is_accepted_for_them(): void {
		$allow = new HostPolicy( array(), true, static fn(): bool => true );
		$this->assertNull( $allow->violation( 'https://10.0.0.5/' ) );
		$this->assertNull( $allow->violation( 'http://10.0.0.5:8069/' ) );
		$this->assertNotNull( $allow->violation( 'http://erp.acme-trading.test/' ), 'http stays refused for public hosts.' );
	}

	public function test_private_hosts_are_not_allowed_for_a_connector_with_fixed_hosts(): void {
		$policy = new HostPolicy( array( 'api.example.com' ), false, static fn(): bool => true );
		$this->assertNotNull( $policy->violation( 'https://127.0.0.1/' ) );
	}

	public function test_assert_throws_val_002_with_the_address_field(): void {
		$policy = HostPolicy::fromMeta( new ConnectorMeta( id: 'x', label: 'X', type: ConnectorType::Crm, auth: AuthStyle::Key, hosts: array( 'api.example.com' ) ) );
		$policy->assert( 'https://api.example.com/', 'X' );
		try {
			$policy->assert( 'https://evil.test/', 'X' );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ErrorCode::Val002, $exception->error()->code );
			$this->assertSame( 'address', $exception->error()->context['field'] );
			$this->assertStringNotContainsString( 'evil.test', $exception->getMessage() );
		}
	}

	public function test_a_policy_with_no_host_and_no_merchant_host_allows_nothing(): void {
		$closed = new HostPolicy();
		$this->assertNotNull( $closed->violation( 'https://api.example.com/' ) );
		$this->assertNotNull( $closed->violation( 'https://8.8.8.8/' ) );
		$this->assertNotNull( ( new HostPolicy( array(), false, static fn(): bool => true ) )->violation( 'https://api.example.com/' ), 'Even a site that allows private hosts does not open it.' );
		$this->assertNotNull( HostPolicy::fromMeta( new ConnectorMeta( id: 'x', label: 'X', type: ConnectorType::Crm, auth: AuthStyle::Key ) )->violation( 'https://api.example.com/' ) );
		$this->assertNull( ( new HostPolicy( array(), true ) )->violation( 'https://api.example.com/' ) );
	}

	public function test_every_address_a_merchant_name_resolves_to_must_be_public(): void {
		$answers = array(
			'good.example.com'  => array( '93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946' ),
			'mixed.example.com' => array( '93.184.216.34', '10.0.0.7' ),
			'six.example.com'   => array( '93.184.216.34', 'fd00::5' ),
			'meta.example.com'  => array( '169.254.169.254' ),
			'gone.example.com'  => array(),
		);
		$policy  = new HostPolicy( array(), true, null, static fn( string $host ): array => $answers[ $host ] ?? array() );
		$this->assertNull( $policy->violation( 'https://good.example.com/api' ) );
		$this->assertSame( 'The address points to a private network.', $policy->violation( 'https://mixed.example.com/api' ), 'One private address among public ones is enough to refuse.' );
		$this->assertSame( 'The address points to a private network.', $policy->violation( 'https://six.example.com/' ) );
		$this->assertSame( 'The address points to a private network.', $policy->violation( 'https://meta.example.com/' ) );
		$this->assertStringContainsString( 'could not be found', (string) $policy->violation( 'https://gone.example.com/' ) );
		$this->assertNull( ( new HostPolicy( array(), true, static fn(): bool => true, static fn(): array => array( '10.0.0.7' ) ) )->violation( 'https://mixed.example.com/' ), 'The site owner may allow private hosts.' );
	}

	public function test_private_and_reserved_ranges_in_both_families(): void {
		$private = array( '127.0.0.1', '127.255.255.254', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.0.1', '169.254.1.1', '100.64.0.1', '100.127.255.255', '0.0.0.0', '0.1.2.3', '198.18.0.1', '224.0.0.1', '255.255.255.255', '::', '::1', 'fc00::1', 'fdff::1', 'fe80::1', 'febf::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:7f00:1', '64:ff9b::7f00:1', '2002:7f00:1::1', 'not an address' );
		foreach ( $private as $ip ) {
			$this->assertTrue( HostPolicy::isPrivateAddress( $ip ), $ip );
		}
		$public = array( '8.8.8.8', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '11.0.0.1', '2606:2800:220:1:248:1893:25c8:1946', '2001:4860:4860::8888', '::ffff:8.8.8.8' );
		foreach ( $public as $ip ) {
			$this->assertFalse( HostPolicy::isPrivateAddress( $ip ), $ip );
		}
	}

	public function test_numeric_host_tricks_trailing_dots_and_odd_characters_are_refused(): void {
		$policy = new HostPolicy( array(), true, static fn(): bool => true ); // Even where private hosts are allowed, these forms are not accepted.
		$bad    = array(
			'https://2130706433/',          // 127.0.0.1 as one decimal number.
			'https://0x7f000001/',          // Hexadecimal.
			'https://0x7f.0.0.1/',
			'https://0177.0.0.1/',          // Octal.
			'https://017700000001/',
			'https://127.1/',               // Short form.
			'https://127.0.1/',
			'https://1.1.1/',
			'https://example.com./',        // Trailing dot.
			'https://localhost./',
			'https://[fe80::1%25eth0]/',    // Zone id.
			'https://user@example.com/',
			'https://user:pw@example.com/',
		);
		foreach ( $bad as $url ) {
			$this->assertNotNull( $policy->violation( $url ), $url );
		}
		$this->assertNull( ( new HostPolicy( array(), true, null, static fn(): array => array( '93.184.216.34' ) ) )->violation( 'https://erp.example.com:8443/x' ) );
		$this->assertNull( ( new HostPolicy( array(), true ) )->violation( 'https://93.184.216.34/' ), 'A plain public IP address is fine.' );
	}

	public function test_a_name_that_passed_is_pinned_to_the_addresses_that_were_checked(): void {
		HostPins::reset();
		$policy = new HostPolicy( array(), true, null, static fn(): array => array( '93.184.216.34', '2606:2800:220:1::1' ) );
		$this->assertSame( array(), HostPins::entriesFor( 'https://erp.example.com/api' ), 'Nothing is pinned before a check.' );
		$this->assertNull( $policy->violation( 'https://erp.example.com:8443/api' ) );
		$this->assertSame( array( 'erp.example.com:8443:93.184.216.34,[2606:2800:220:1::1]' ), HostPins::entriesFor( 'https://erp.example.com:8443/api?x=1' ) );
		$this->assertSame( array(), HostPins::entriesFor( 'https://erp.example.com/api' ), 'Another port is another pin.' );
		$this->assertSame( array(), HostPins::entriesFor( 'https://other.example.com:8443/' ) );

		( new HostPolicy( array(), true, null, static fn(): array => array( '10.0.0.1' ) ) )->violation( 'https://bad.example.com/' );
		$this->assertSame( array(), HostPins::entriesFor( 'https://bad.example.com/' ), 'A refused name is never pinned.' );
		( new HostPolicy( array( 'api.vendor.test' ) ) )->violation( 'https://api.vendor.test/' );
		$this->assertSame( array(), HostPins::entriesFor( 'https://api.vendor.test/' ), 'A vendor host is not pinned: its name is trusted.' );
		HostPins::reset();
	}
}
