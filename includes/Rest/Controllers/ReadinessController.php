<?php
/**
 * Routes: readiness checks.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /readiness` (the last result) and `POST /readiness/run` (the ten checks again).
 */
final class ReadinessController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/readiness', 'GET', self::MANAGE, $this->last( ... ) );
		$this->add( '/readiness/run', 'POST', self::MANAGE, $this->run( ... ) );
	}

	/**
	 * `GET /readiness`.
	 *
	 * @return array<string,mixed>
	 */
	public function last(): array {
		/** @var ReadinessService $readiness */
		$readiness = $this->service( JobServices::READINESS );
		return $readiness->last();
	}

	/**
	 * `POST /readiness/run`.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		/** @var ReadinessService $readiness */
		$readiness = $this->service( JobServices::READINESS );
		return $readiness->run();
	}
}
