<?php
/**
 * Route: the capacity test.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Capacity\CapacityTest;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `POST /capacity-test`: measure how many items a minute this server can move. The test never writes to the real queue and never
 * contacts a connection (Jobs\Capacity\CapacityTest).
 */
final class CapacityController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/capacity-test', 'POST', self::CONFIGURE, $this->run( ... ), array(), false );
	}

	/**
	 * The `CapacityResult`.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		$capacity = $this->serviceAs( JobServices::CAPACITY, CapacityTest::class );
		return $capacity->run();
	}
}
