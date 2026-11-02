<?php
/**
 * Base class for integration tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Wraps each test in a database transaction that is rolled back, so tests stay independent.
 * Note: DDL (dbDelta) causes an implicit commit in MySQL; tests that create tables must clean up.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * Start a transaction.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Roll back.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		parent::tearDown();
	}
}
