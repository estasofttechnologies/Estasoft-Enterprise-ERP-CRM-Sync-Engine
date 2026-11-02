<?php
/**
 * Routes: the setup wizard state.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingService;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * `GET /onboarding` and `PUT /onboarding`. The wizard resumes where the person left it; nothing is sent to any system before
 * the last step.
 */
final class OnboardingController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/onboarding', 'GET', self::MANAGE, $this->show( ... ) );
		$this->add(
			'/onboarding',
			'PUT',
			self::CONFIGURE,
			$this->save( ... ),
			array(
				'step'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 5,
				),
				'connector' => array(
					'type'              => array( 'string', 'null' ),
					'sanitize_callback' => static fn( $value ): ?string => null === $value || '' === $value ? null : Sanitize::name( $value ),
				),
				'answers'   => self::structureArg( 'object' ),
			)
		);
	}

	/**
	 * `GET /onboarding`.
	 *
	 * @return array<string,mixed>
	 */
	public function show(): array {
		$onboarding = $this->serviceAs( JobServices::ONBOARDING, OnboardingService::class );
		return $onboarding->get();
	}

	/**
	 * `PUT /onboarding`: save the fields that were sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function save( \WP_REST_Request $request ): array {
		$onboarding = $this->serviceAs( JobServices::ONBOARDING, OnboardingService::class );
		$state      = array();
		foreach ( array( 'step', 'connector', 'answers' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$state[ $field ] = $request->get_param( $field );
			}
		}
		return $onboarding->save( $state );
	}
}
