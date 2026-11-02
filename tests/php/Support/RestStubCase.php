<?php
/**
 * Base class of the unit tests that need global WordPress stand-ins.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The stand-ins are global functions and classes, and other test areas define their own versions, so a test class that extends this
 * one carries `@runTestsInSeparateProcesses` and `@preserveGlobalState disabled` in its own docblock. The stand-ins are loaded here,
 * in the test's own process, and never while PHPUnit loads the test files.
 */
abstract class RestStubCase extends TestCase {

	/**
	 * Load the stand-ins and put the scene back.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/RestWpStubs.php';
		RestRegistry::reset();
	}

	/**
	 * Give the current user these capabilities.
	 *
	 * @param string ...$caps Capabilities.
	 */
	protected function can( string ...$caps ): void {
		RestRegistry::$caps = $caps;
	}
}
