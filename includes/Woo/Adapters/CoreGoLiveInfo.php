<?php
/**
 * Go-live time from the core onboarding state.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Adapters;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Woo\Ports\GoLiveInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads answers.went_live_at once per request. The value is an ISO 8601 UTC time written when the person finished setup. When setup is
 * marked complete but no time was recorded, nothing is guessed: the answer is null and no order counts as placed after go-live.
 */
final class CoreGoLiveInfo implements GoLiveInfo {

	/**
	 * The time read, or false before the first read.
	 *
	 * @var \DateTimeImmutable|null|false
	 */
	private \DateTimeImmutable|null|false $cached = false;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OnboardingState $onboarding Onboarding state.
	 */
	public function __construct( private readonly OnboardingState $onboarding ) {
	}

	/**
	 * The moment syncing went live, or null.
	 */
	public function wentLiveAt(): ?\DateTimeImmutable {
		if ( false === $this->cached ) {
			$state   = $this->onboarding->get();
			$answers = is_array( $state['answers'] ?? null ) ? $state['answers'] : array();
			$text    = ! empty( $state['completed'] ) && isset( $answers['went_live_at'] ) && is_string( $answers['went_live_at'] ) ? $answers['went_live_at'] : '';
			$parsed  = '' === $text ? false : date_create_immutable( $text, new \DateTimeZone( 'UTC' ) );

			$this->cached = false === $parsed ? null : $parsed;
		}
		return $this->cached;
	}
}
