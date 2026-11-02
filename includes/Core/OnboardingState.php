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

/**
 * Keeps the wizard answers and the shadow window in the option `estasoft_sync_onboarding`. The queue reads
 * it to decide whether a new item is live or shadow.
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
		foreach ( $changes as $key => $value ) {
			if ( array_key_exists( $key, $state ) ) {
				$state[ $key ] = $value;
			}
		}
		$this->options->set( self::OPTION, $state );
		return $this->get();
	}

	/**
	 * Whether a shadow window is open at a moment: it has started and has not ended.
	 *
	 * @param \DateTimeImmutable $now The moment to test.
	 */
	public function isShadowActive( \DateTimeImmutable $now ): bool {
		$state = $this->get();
		if ( empty( $state['shadow_started_at'] ) ) {
			return false;
		}
		$ends = empty( $state['shadow_ends_at'] ) ? null : date_create_immutable( (string) $state['shadow_ends_at'] );
		return false === $ends || null === $ends ? true : $ends > $now;
	}
}
