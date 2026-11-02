<?php
/**
 * Base class of the Jobs integration tests: real WordPress and database, the Core harness, fake shop ports.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once dirname( __DIR__, 2 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Tests\Support\CoreHarness;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;
use Estasoft\SyncEngine\Tests\Support\JobsHarness;

abstract class JobsTestCase extends CoreIntegrationTestCase {

	/**
	 * The Jobs services.
	 */
	protected JobsHarness $j;

	/**
	 * Build the harnesses.
	 */
	protected function setUp(): void {
		parent::setUp();
		// The Core harness default key is shorter than the 32 characters SecretBox now needs.
		$this->h = new CoreHarness( '2026-11-02 12:00:00', 'jobs-test-key-0123456789abcdef-0123' );
		$this->h->reset();
		$this->j = new JobsHarness( $this->h );
		// The running plugin has its own handlers on these hooks (mail, caches); the tests wire theirs by hand.
		foreach ( array( 'estasoft_sync_connection_paused', 'estasoft_sync_connection_resumed', 'estasoft_sync_item_synced', 'estasoft_sync_item_failed', 'estasoft_sync_shadow_processed' ) as $hook ) {
			remove_all_actions( $hook );
		}
		// The two hooks the module attaches to remember a pause caused by a refused sign-in.
		add_action( 'estasoft_sync_connection_paused', array( $this->j->signIns, 'onPaused' ), 5, 2 );
		add_action( 'estasoft_sync_connection_resumed', array( $this->j->signIns, 'onResumed' ), 5 );
	}

	/**
	 * Turn on stock sync for a connection with the given rules.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $rules        Rules over enabled = true.
	 */
	protected function enableStock( int $connectionId, array $rules = array() ): void {
		$this->j->stockService->saveRules( $connectionId, array_merge( array( 'enabled' => true ), $rules ) );
	}
}
