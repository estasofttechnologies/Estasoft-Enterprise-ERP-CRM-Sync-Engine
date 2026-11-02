<?php
/**
 * Route: sample orders for the preview step.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingService;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /orders/sample`: a few recent orders to pick from when previewing a mapping.
 */
final class OrdersController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/orders/sample', 'GET', self::MANAGE, $this->sample( ... ) );
	}

	/**
	 * The `SampleOrder[]` of src/api/types.ts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sample(): array {
		$onboarding = $this->serviceAs( JobServices::ONBOARDING, OnboardingService::class );
		return $onboarding->sampleOrders();
	}
}
