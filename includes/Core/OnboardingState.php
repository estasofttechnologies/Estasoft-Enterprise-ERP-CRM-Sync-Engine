<?php
/**
 * Setup wizard state (the `Onboarding` shape in src/api/types.ts).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Keeps the wizard answers and the shadow window in the option `estasoft_sync_onboarding`. The queue reads
 * it to decide whether a new item is live or shadow.
 *
 * Shadow mode never ends by itself. Once it has started (`shadow_started_at`), it stays on until an explicit end is
 * stored: `endShadow()` (which saves `shadow_ended_at`, `shadow_end_reason` and `mode` = live), a `mode` of `live`, or the
 * older marker `answers.shadow_ended_by` that ShadowService writes when a person ends it. The date in `shadow_ends_at`
 * is information (when the window was planned to end, or when it did end); a date in the past does not end shadow
 * mode, and a date that cannot be read does not either. The reason is that a shadow item is never sent, so ending shadow
 * mode by the clock alone would silently start sending (or silently keep holding) real orders.
 */
final class OnboardingState {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_onboarding';

	/**
	 * @param OptionStore $options Options.
	 */
	public function __construct( private readonly OptionStore $options ) {
	}

	/**
	 * The starting state of a new install.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'step'              => 1,
			'completed'         => false,
			'connector'         => null,
			'answers'           => array(),
			'shadow_started_at' => null,
			'shadow_ends_at'    => null,
			'shadow_ended_at'   => null,
			'shadow_end_reason' => null,
			'mode'              => null,
		);
	}

	/**
	 * Current state.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$stored = $this->options->get( self::OPTION, array() );
		$state  = $this->defaults();
		if ( is_array( $stored ) ) {
			foreach ( $state as $key => $default ) {
				if ( array_key_exists( $key, $stored ) ) {
					$state[ $key ] = $stored[ $key ];
				}
			}
		}
		$state['step']      = max( 1, min( 5, (int) $state['step'] ) );
		$state['completed'] = (bool) $state['completed'];
		$state['answers']   = is_array( $state['answers'] ) ? $state['answers'] : array();
		return $state;
	}

	/**
	 * Merge changes and save.
	 *
	 * @param array<string,mixed> $changes Fields to change.
	 * @return array<string,mixed> State after saving.
	 */
	public function save( array $changes ): array {
		$state = $this->get();
		$began = ! empty( $changes['shadow_started_at'] ) && (string) $changes['shadow_started_at'] !== (string) ( $state['shadow_started_at'] ?? '' );
		foreach ( $changes as $key => $value ) {
			if ( array_key_exists( $key, $state ) ) {
				$state[ $key ] = $value;
			}
		}
		if ( $began ) {
			// A new shadow window starts open: whatever ended the previous one does not carry over.
			foreach ( array( 'shadow_ended_at', 'shadow_end_reason' ) as $key ) {
				if ( ! array_key_exists( $key, $changes ) ) {
					$state[ $key ] = null;
				}
			}
			if ( ! array_key_exists( 'mode', $changes ) ) {
				$state['mode'] = 'shadow';
			}
			if ( is_array( $state['answers'] ) ) {
				unset( $state['answers']['shadow_ended_by'] );
			}
		}
		$this->options->set( self::OPTION, $state );
		return $this->get();
	}

	/**
	 * Whether shadow mode is on at a moment: it has started and nothing has explicitly ended it. The planned end date
	 * does not matter, and neither does a date that cannot be read.
	 *
	 * @param \DateTimeImmutable $now The moment to test.
	 */
	public function isShadowActive( \DateTimeImmutable $now ): bool {
		$state = $this->get();
		if ( empty( $state['shadow_started_at'] ) ) {
			return false;
		}
		if ( $this->hasEnded( $state ) ) {
			return false;
		}
		$started = date_create_immutable( (string) $state['shadow_started_at'] );
		// A start that cannot be read counts as started: when in doubt, nothing is sent.
		return false === $started || $started <= $now;
	}

	/**
	 * End shadow mode now. From this moment new items are live. Does nothing when shadow mode is not on, so the first
	 * end is the one that is kept.
	 *
	 * @param \DateTimeImmutable $at     When it ended.
	 * @param string             $reason Why: `person`, `go_live`, or another short word.
	 * @return array<string,mixed> State after the call.
	 */
	public function endShadow( \DateTimeImmutable $at, string $reason ): array {
		$state = $this->get();
		if ( empty( $state['shadow_started_at'] ) || $this->hasEnded( $state ) ) {
			return $state;
		}
		$moment = $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Time::ISO );
		return $this->save(
			array(
				'shadow_ended_at'   => $moment,
				'shadow_end_reason' => mb_substr( $reason, 0, 40 ),
				'shadow_ends_at'    => $moment,
				'mode'              => 'live',
			)
		);
	}

	/**
	 * Whether something explicit ended the shadow window.
	 *
	 * @param array<string,mixed> $state State from get().
	 */
	private function hasEnded( array $state ): bool {
		if ( ! empty( $state['shadow_ended_at'] ) || 'live' === $state['mode'] ) {
			return true;
		}
		$answers = is_array( $state['answers'] ) ? $state['answers'] : array();
		return isset( $answers['shadow_ended_by'] );
	}
}
