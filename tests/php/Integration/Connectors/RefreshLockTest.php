<?php
/**
 * The database lock that keeps two workers from refreshing one token at once.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Connectors;

use Estasoft\SyncEngine\Connectors\Support\OAuth\WpdbRefreshLock;
use Estasoft\SyncEngine\Tests\Support\CoreFakeClock;
use Estasoft\SyncEngine\Tests\Support\IntegrationTestCase;

final class RefreshLockTest extends IntegrationTestCase {

	private CoreFakeClock $clock;

	private WpdbRefreshLock $lock;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->clock = new CoreFakeClock();
		$this->lock  = new WpdbRefreshLock( $wpdb, $this->clock );
	}

	public function test_exactly_one_caller_gets_the_lock_until_it_is_released(): void {
		$first = $this->lock->acquire( 'oauth:7', 30 );
		$this->assertNotNull( $first );
		$this->assertNull( $this->lock->acquire( 'oauth:7', 30 ), 'The second caller waits.' );
		$this->assertNotNull( $this->lock->acquire( 'oauth:8', 30 ), 'Another key is another lock.' );

		$this->lock->release( 'oauth:7', 'not-the-owner' );
		$this->assertNull( $this->lock->acquire( 'oauth:7', 30 ), 'Only the owner can free it.' );
		$this->lock->release( 'oauth:7', $first );
		$this->assertNotNull( $this->lock->acquire( 'oauth:7', 30 ) );
	}

	public function test_a_lock_whose_owner_died_frees_itself_and_one_taker_wins(): void {
		$this->assertNotNull( $this->lock->acquire( 'oauth:9', 30 ) );
		$this->clock->advance( 31 );
		$taken = $this->lock->acquire( 'oauth:9', 30 );
		$this->assertNotNull( $taken );
		$this->assertNull( $this->lock->acquire( 'oauth:9', 30 ), 'The takeover is atomic: the next caller is refused.' );
	}

	public function test_the_lock_rows_are_not_autoloaded_and_carry_the_prefix_that_uninstall_removes(): void {
		global $wpdb;
		$this->lock->acquire( 'oauth:10', 30 );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( WpdbRefreshLock::PREFIX ) . '%' ), ARRAY_A ); // phpcs:ignore
		$this->assertNotNull( $row );
		$this->assertStringStartsWith( 'estasoft_sync_lock_', $row['option_name'] );
		$this->assertNotSame( 'yes', $row['autoload'] );
		$this->assertLessThan( 100, strlen( $row['option_name'] ) );
	}
}
