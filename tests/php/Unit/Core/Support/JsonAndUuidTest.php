<?php
/**
 * JSON helpers and UUIDs.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Support;

use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class JsonAndUuidTest extends TestCase {

	public function test_canonical_form_ignores_key_order_but_keeps_list_order(): void {
		$a = array( 'b' => 1, 'a' => array( 'y' => 2, 'x' => 1 ), 'lines' => array( 'two', 'one' ) );
		$b = array( 'lines' => array( 'two', 'one' ), 'a' => array( 'x' => 1, 'y' => 2 ), 'b' => 1 );
		$c = array( 'b' => 1, 'a' => array( 'x' => 1, 'y' => 2 ), 'lines' => array( 'one', 'two' ) );
		$this->assertSame( Json::canonical( $a ), Json::canonical( $b ) );
		$this->assertNotSame( Json::canonical( $a ), Json::canonical( $c ) );
	}

	public function test_arabic_text_stays_readable(): void {
		$this->assertStringContainsString( 'نور المنصوري', Json::encode( array( 'name' => 'نور المنصوري' ) ) );
	}

	public function test_decode_never_throws(): void {
		$this->assertSame( array(), Json::decode( null ) );
		$this->assertSame( array(), Json::decode( '' ) );
		$this->assertSame( array(), Json::decode( '{broken' ) );
		$this->assertSame( array(), Json::decode( '"a string"' ) );
		$this->assertSame( array( 'a' => 1 ), Json::decode( '{"a":1}' ) );
	}

	public function test_invalid_utf8_is_substituted_not_fatal(): void {
		$this->assertNotSame( '', Json::encode( array( 'x' => "bad \xB1 byte" ) ) );
	}

	public function test_uuid_v4_format_and_uniqueness(): void {
		$ids = array();
		for ( $i = 0; $i < 50; ++$i ) {
			$id = Uuid::v4();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
			$ids[ $id ] = true;
		}
		$this->assertCount( 50, $ids );
	}
}
