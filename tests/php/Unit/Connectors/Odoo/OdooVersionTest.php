<?php
/**
 * Odoo version parsing.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\OdooVersion;
use PHPUnit\Framework\TestCase;

final class OdooVersionTest extends TestCase {

	public function test_web_version_answer_of_odoo_19_uses_json2(): void {
		$version = OdooVersion::fromAnswer( array( 'version_info' => array( 19, 0, 0, 'final', 0, '' ), 'version' => '19.0' ) );
		$this->assertNotNull( $version );
		$this->assertSame( 19, $version->major );
		$this->assertTrue( $version->usesJson2() );
		$this->assertSame( 'json2', $version->transportId() );
		$this->assertFalse( $version->enterprise );
		$this->assertSame( 'Odoo 19 Community', $version->label() );
	}

	public function test_common_service_answer_of_odoo_17_uses_json_rpc(): void {
		$version = OdooVersion::fromAnswer(
			array(
				'server_version'      => '17.0',
				'server_version_info' => array( 17, 0, 0, 'final', 0, 'e' ),
				'server_serie'        => '17.0',
				'protocol_version'    => 1,
			)
		);
		$this->assertNotNull( $version );
		$this->assertSame( 17, $version->major );
		$this->assertFalse( $version->usesJson2() );
		$this->assertSame( 'jsonrpc', $version->transportId() );
		$this->assertTrue( $version->enterprise );
		$this->assertSame( 'Odoo 17 Enterprise', $version->label() );
		$this->assertTrue( $version->isSupported() );
	}

	public function test_enterprise_is_read_from_the_plus_e_suffix_too(): void {
		$version = OdooVersion::fromAnswer( array( 'server_version' => '18.0+e', 'server_version_info' => array( 18, 0, 0, 'final', 0, '' ) ) );
		$this->assertTrue( $version->enterprise );
	}

	public function test_odoo_online_release_before_19_uses_json_rpc(): void {
		$version = OdooVersion::fromAnswer( array( 'version' => 'saas~18.4', 'version_info' => array( 'saas~18', 4, 0, 'final', 0, '' ) ) );
		$this->assertNotNull( $version );
		$this->assertTrue( $version->saas );
		$this->assertSame( 18, $version->major );
		$this->assertSame( 4, $version->minor );
		$this->assertFalse( $version->usesJson2(), 'JSON-2 is documented from 19.0; saas~18.x keeps JSON-RPC, which exists in every release.' );
		$this->assertSame( 'Odoo saas~18.4 Community', $version->label() );
	}

	public function test_version_info_alone_is_enough(): void {
		$this->assertSame( 19, OdooVersion::fromAnswer( array( 'version_info' => array( 19, 0, 0, 'final', 0, '' ) ) )?->major );
		$online = OdooVersion::fromAnswer( array( 'version_info' => array( 'saas~19', 1, 0, 'final', 0, '' ) ) );
		$this->assertTrue( $online->saas );
		$this->assertTrue( $online->usesJson2() );
	}

	public function test_versions_before_17_are_not_supported(): void {
		$version = OdooVersion::fromAnswer( array( 'server_version' => '16.0', 'server_version_info' => array( 16, 0, 0, 'final', 0 ) ) );
		$this->assertFalse( $version->isSupported() );
	}

	public function test_minor_numbers_show_in_the_label(): void {
		$this->assertSame( 'Odoo 19.1 Community', OdooVersion::fromAnswer( array( 'version' => '19.1' ) )->label() );
	}

	public function test_garbage_gives_null(): void {
		$this->assertNull( OdooVersion::fromAnswer( array() ) );
		$this->assertNull( OdooVersion::fromAnswer( array( 'version' => 'not a version', 'version_info' => 'x' ) ) );
		$this->assertNull( OdooVersion::fromAnswer( array( 'version' => array( 'a' ) ) ) );
	}

	public function test_to_array_names_the_transport(): void {
		$facts = OdooVersion::fromAnswer( array( 'version' => '18.0' ) )->toArray();
		$this->assertSame( 'jsonrpc', $facts['transport'] );
		$this->assertSame( 18, $facts['major'] );
		$this->assertTrue( OdooVersion::fromAnswer( array( 'version' => '18.0' ) )->atLeast( 18 ) );
		$this->assertFalse( OdooVersion::fromAnswer( array( 'version' => '18.0' ) )->atLeast( 19 ) );
	}
}
