<?php
/**
 * The `Onboarding` shape of src/api/types.ts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Onboarding;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Time;

/**
 * The stored state has no planned end while a shadow window is open (so the window never closes by itself: at day 7 the owner
 * decides). The screens still want an end date to count down to, so it is worked out here from the start and the length chosen.
 * Reserved answers (the ones the plugin writes itself) are not shown.
 */
final class OnboardingView {

	/**
	 * Answers only the plugin writes.
	 */
	public const RESERVED = array( 'went_live_at', 'shadow_days', 'shadow_connection', 'shadow_ended_by', 'live_mode' );

	/**
	 * Shape a stored state for the screens.
	 *
	 * @param array<string,mixed> $state State from Core\OnboardingState::get().
	 * @return array{step:int,completed:bool,connector:?string,answers:array<string,mixed>,shadow_started_at:?string,shadow_ends_at:?string}
	 */
	public static function present( array $state ): array {
		$started = self::time( $state['shadow_started_at'] ?? null );
		$ends    = self::time( $state['shadow_ends_at'] ?? null );
		$answers = is_array( $state['answers'] ?? null ) ? $state['answers'] : array();
		if ( null !== $started && null === $ends ) {
			$days = max( 1, (int) ( $answers['shadow_days'] ?? 7 ) );
			$ends = gmdate( Time::ISO, (int) strtotime( $started ) + ( $days * 86400 ) );
		}
		return array(
			'step'              => max( 1, min( 5, (int) ( $state['step'] ?? 1 ) ) ),
			'completed'         => (bool) ( $state['completed'] ?? false ),
			'connector'         => isset( $state['connector'] ) && '' !== (string) $state['connector'] ? (string) $state['connector'] : null,
			'answers'           => array_diff_key( $answers, array_flip( self::RESERVED ) ),
			'shadow_started_at' => $started,
			'shadow_ends_at'    => $ends,
		);
	}

	/**
	 * A stored time as ISO text.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function time( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$parsed = date_create_immutable( $value, new \DateTimeZone( 'UTC' ) );
		return false === $parsed ? null : $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Time::ISO );
	}
}
