<?php
/**
 * Address checking.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\OdooAddress;
use Estasoft\SyncEngine\Connectors\Odoo\OdooAddressNormalizer;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class OdooAddressNormalizerTest extends TestCase {

	private function strict(): OdooAddressNormalizer {
		return new OdooAddressNormalizer( static fn(): bool => false );
	}

	private function permissive(): OdooAddressNormalizer {
		return new OdooAddressNormalizer( static fn(): bool => true );
	}

	/**
	 * @dataProvider provide_good_addresses
	 */
	public function test_good_addresses_are_normalised( string $input, string $expected ): void {
		$address = $this->strict()->normalize( $input );
		$this->assertInstanceOf( OdooAddress::class, $address, $input );
		$this->assertSame( $expected, $address->baseUrl );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_good_addresses(): array {
		return array(
			'plain'                  => array( 'https://erp.example.com', 'https://erp.example.com' ),
			'trailing slash'         => array( 'https://erp.example.com/', 'https://erp.example.com' ),
			'path is dropped'        => array( 'https://erp.example.com/web/login?db=x#top', 'https://erp.example.com' ),
			'no scheme gets https'   => array( 'erp.example.com', 'https://erp.example.com' ),
			'upper case host'        => array( 'HTTPS://ERP.Example.COM', 'https://erp.example.com' ),
			'surrounding spaces'     => array( "  https://erp.example.com \n", 'https://erp.example.com' ),
			'odoo online'            => array( 'https://cedarstone.odoo.com/odoo/action-123', 'https://cedarstone.odoo.com' ),
			'custom port kept'       => array( 'https://erp.example.com:8443/', 'https://erp.example.com:8443' ),
			'default port dropped'   => array( 'https://erp.example.com:443', 'https://erp.example.com' ),
			'trailing dot in host'   => array( 'https://erp.example.com./', 'https://erp.example.com' ),
		);
	}

	/**
	 * @dataProvider provide_bad_addresses
	 */
	public function test_bad_addresses_are_refused_with_val_002( string $input ): void {
		$result = $this->strict()->normalize( $input );
		$this->assertInstanceOf( ConnectorError::class, $result, $input );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertNotSame( '', $result->context['reason'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_bad_addresses(): array {
		return array(
			'empty'                    => array( '' ),
			'ftp'                      => array( 'ftp://erp.example.com' ),
			'file'                     => array( 'file:///etc/passwd' ),
			'javascript'               => array( 'javascript:alert(1)' ),
			'credentials'              => array( 'https://admin:secret@erp.example.com' ),
			'user only'                => array( 'https://admin@erp.example.com' ),
			'space inside'             => array( 'https://erp example.com' ),
			'plain http public'        => array( 'http://erp.example.com' ),
			'bad port'                 => array( 'https://erp.example.com:99999' ),
			'underscore host'          => array( 'https://erp_example.com' ),
			'localhost'                => array( 'https://localhost' ),
			'localhost with port'      => array( 'http://localhost:8069' ),
			'loopback'                 => array( 'https://127.0.0.1' ),
			'loopback range'           => array( 'https://127.1.2.3' ),
			'ten network'              => array( 'https://10.0.0.5' ),
			'private 172'              => array( 'https://172.16.4.4' ),
			'private 192'              => array( 'https://192.168.1.10:8069' ),
			'link local metadata'      => array( 'https://169.254.169.254/latest' ),
			'carrier grade nat'        => array( 'https://100.64.1.1' ),
			'unspecified'              => array( 'https://0.0.0.0' ),
			'ipv6 loopback'            => array( 'https://[::1]/' ),
			'ipv6 unique local'        => array( 'https://[fd00::1]' ),
			'ipv6 link local'          => array( 'https://[fe80::1]' ),
			'ipv4 mapped loopback'     => array( 'https://[::ffff:127.0.0.1]' ),
			'ipv4 mapped private'      => array( 'https://[::ffff:10.1.1.1]' ),
			'decimal ip'               => array( 'https://2130706433' ),
			'hex ip'                   => array( 'https://0x7f.0.0.1' ),
			'short ip'                 => array( 'https://127.1' ),
			'dot local'                => array( 'https://erp.local' ),
			'dot internal'             => array( 'https://odoo.corp.internal' ),
			'dot localhost'            => array( 'https://odoo.localhost' ),
		);
	}

	public function test_private_hosts_are_allowed_when_the_site_owner_says_so(): void {
		$permissive = $this->permissive();
		foreach ( array( 'http://localhost:8069' => 'http://localhost:8069', 'https://192.168.1.10:8069' => 'https://192.168.1.10:8069', 'http://10.0.0.5' => 'http://10.0.0.5', 'http://[::1]:8069' => 'http://[::1]:8069' ) as $input => $expected ) {
			$address = $permissive->normalize( $input );
			$this->assertInstanceOf( OdooAddress::class, $address, $input );
			$this->assertSame( $expected, $address->baseUrl );
			$this->assertTrue( $address->isPrivate );
		}
	}

	public function test_plain_http_is_still_refused_for_a_public_host_even_when_private_hosts_are_allowed(): void {
		$this->assertInstanceOf( ConnectorError::class, $this->permissive()->normalize( 'http://erp.example.com' ) );
	}

	public function test_the_filter_receives_the_normalised_address(): void {
		$seen       = array();
		$normalizer = new OdooAddressNormalizer(
			static function ( string $url ) use ( &$seen ): bool {
				$seen[] = $url;
				return true;
			}
		);
		$normalizer->normalize( 'http://LOCALHOST:8069/web' );
		$this->assertSame( array( 'http://localhost:8069' ), $seen );
	}

	public function test_public_addresses_never_ask_the_filter(): void {
		$asked      = 0;
		$normalizer = new OdooAddressNormalizer(
			static function () use ( &$asked ): bool {
				++$asked;
				return false;
			}
		);
		$normalizer->normalize( 'https://erp.example.com' );
		$this->assertSame( 0, $asked );
	}

	public function test_odoo_online_hosts_are_recognised(): void {
		$this->assertTrue( $this->strict()->normalize( 'https://cedarstone.odoo.com' )->isOdooOnline() );
		$this->assertFalse( $this->strict()->normalize( 'https://erp.example.com' )->isOdooOnline() );
		$this->assertFalse( $this->strict()->normalize( 'https://notodoo.com' )->isOdooOnline() );
	}

	public function test_urls_are_built_below_the_root(): void {
		$this->assertSame( 'https://erp.example.com/jsonrpc', $this->strict()->normalize( 'erp.example.com/web' )->url( '/jsonrpc' ) );
	}
}
