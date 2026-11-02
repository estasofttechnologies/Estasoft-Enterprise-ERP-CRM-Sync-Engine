<?php
/**
 * Idempotency keys.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use Estasoft\SyncEngine\Core\Queue\IdempotencyKey;
use Estasoft\SyncEngine\Domain\EntityType;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase {

	public function test_key_follows_the_documented_formula(): void {
		$this->assertSame(
			hash( 'sha256', 'v1:3:order:10490:created:0' ),
			IdempotencyKey::make( 3, EntityType::Order, '10490', 'created' )
		);
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', IdempotencyKey::make( 3, EntityType::Order, '10490', 'created' ) );
	}

	public function test_same_event_always_gives_the_same_key(): void {
		for ( $i = 0; $i < 20; ++$i ) {
			$this->assertSame(
				IdempotencyKey::make( 1, EntityType::Order, '55', 'paid' ),
				IdempotencyKey::make( 1, EntityType::Order, '55', 'paid', 0 )
			);
		}
	}

	public function test_every_part_changes_the_key(): void {
		$base = IdempotencyKey::make( 1, EntityType::Order, '55', 'paid', 0 );
		$all  = array(
			IdempotencyKey::make( 2, EntityType::Order, '55', 'paid', 0 ),
			IdempotencyKey::make( 1, EntityType::Refund, '55', 'paid', 0 ),
			IdempotencyKey::make( 1, EntityType::Order, '56', 'paid', 0 ),
			IdempotencyKey::make( 1, EntityType::Order, '55', 'created', 0 ),
			IdempotencyKey::make( 1, EntityType::Order, '55', 'paid', 1 ),
		);
		foreach ( $all as $other ) {
			$this->assertNotSame( $base, $other );
		}
		$this->assertCount( 5, array_unique( $all ) );
	}

	public function test_only_updates_are_repeatable(): void {
		$this->assertTrue( IdempotencyKey::isRepeatable( 'updated' ) );
		foreach ( array( 'created', 'paid', 'refunded', 'cancelled', 'delivered' ) as $event ) {
			$this->assertFalse( IdempotencyKey::isRepeatable( $event ), $event );
		}
	}
}
