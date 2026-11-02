<?php
/**
 * Lists::isList() gives the same answer as PHP's array_is_list().
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Support;

use Estasoft\SyncEngine\Core\Support\Lists;
use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase {

	/**
	 * @return array<string,array{0:array<mixed>}>
	 */
	public static function arrays(): array {
		return array(
			'empty'                       => array( array() ),
			'one item'                    => array( array( 'a' ) ),
			'three items'                 => array( array( 'a', 'b', 'c' ) ),
			'nested lists'                => array( array( array( 1 ), array( 2, 3 ) ) ),
			'null and false items'        => array( array( null, false, 0, '' ) ),
			'a map'                       => array( array( 'id' => 1, 'name' => 'x' ) ),
			'a list with a text key'      => array( array( 0 => 'a', 'k' => 'b' ) ),
			'keys start at one'           => array( array( 1 => 'a', 2 => 'b' ) ),
			'keys out of order'           => array( array( 1 => 'a', 0 => 'b' ) ),
			'a gap in the keys'           => array( array( 0 => 'a', 2 => 'b' ) ),
			'after unset'                 => array( array_values( array( 'a', 'b' ) ) + array( 5 => 'c' ) ),
			'numeric strings are ints'    => array( array( '0' => 'a', '1' => 'b' ) ),
			'negative key'                => array( array( -1 => 'a', 0 => 'b' ) ),
		);
	}

	/**
	 * @dataProvider arrays
	 *
	 * @param array<mixed> $value The array.
	 */
	public function test_it_agrees_with_array_is_list( array $value ): void {
		$this->assertSame( array_is_list( $value ), Lists::isList( $value ) );
	}
}
