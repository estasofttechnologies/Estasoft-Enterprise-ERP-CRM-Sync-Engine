<?php
/**
 * The setup wizard's saved state.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Onboarding;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;

/**
 * ONB-4: the wizard is resumable. The person's answers and the step are kept; nothing here sends anything to any system.
 * The shadow window and the completed flag are not set from here (the shadow and go-live services own them).
 */
final class OnboardingService {

	/**
	 * Largest saved answers, in bytes of JSON.
	 */
	private const MAX_ANSWERS_BYTES = 20000;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OnboardingState $state  Core wizard state.
	 * @param ShopOrders      $orders WooCommerce orders (the preview step).
	 */
	public function __construct( private readonly OnboardingState $state, private readonly ShopOrders $orders ) {
	}

	/**
	 * The state (the `Onboarding` of src/api/types.ts).
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		return OnboardingView::present( $this->state->get() );
	}

	/**
	 * Save the step, the chosen system and the answers.
	 *
	 * @param array<string,mixed> $state Fields: step (1 to 5), connector (id or null), answers (merged into the saved ones).
	 * @return array<string,mixed> The state after saving.
	 *
	 * @throws ValidationException With one message per bad field.
	 */
	public function save( array $state ): array {
		$errors  = array();
		$changes = array();
		if ( array_key_exists( 'step', $state ) ) {
			$step = Ranges::read( $state['step'], array( 1, 5, 1 ) );
			if ( null === $step ) {
				$errors['step'] = Ranges::message( array( 1, 5, 1 ) );
			} else {
				$changes['step'] = $step;
			}
		}
		if ( array_key_exists( 'connector', $state ) ) {
			$connector = $state['connector'];
			if ( null === $connector || '' === $connector ) {
				$changes['connector'] = null;
			} elseif ( is_string( $connector ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}$/', $connector ) ) {
				$changes['connector'] = $connector;
			} else {
				$errors['connector'] = __( 'Choose a system from the list.', 'estasoft-erp-crm-sync-engine' );
			}
		}
		if ( array_key_exists( 'answers', $state ) ) {
			if ( ! is_array( $state['answers'] ) ) {
				$errors['answers'] = __( 'The answers are not valid.', 'estasoft-erp-crm-sync-engine' );
			} else {
				$clean = $this->clean( $state['answers'], 0 );
				if ( strlen( Json::encode( $clean ) ) > self::MAX_ANSWERS_BYTES ) {
					$errors['answers'] = __( 'The answers are too long.', 'estasoft-erp-crm-sync-engine' );
				} else {
					$current            = $this->state->get()['answers'];
					$changes['answers'] = array_replace( $current, array_diff_key( $clean, array_flip( OnboardingView::RESERVED ) ) );
				}
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
		$this->state->save( $changes );
		return $this->get();
	}

	/**
	 * Recent orders to preview (`SampleOrder[]` of src/api/types.ts).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sampleOrders(): array {
		$rows = array();
		foreach ( $this->orders->recent( 10 ) as $order ) {
			$rows[] = array(
				'id'         => (int) $order['id'],
				'label'      => '#' . (string) $order['label'],
				'customer'   => (string) $order['customer'],
				'total'      => array(
					'minor'    => (int) $order['total_minor'],
					'currency' => (string) $order['currency'],
				),
				'created_at' => '' === (string) $order['created_at'] ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $order['created_at'] . ' UTC' ) ),
			);
		}
		return $rows;
	}

	/**
	 * Keep scalars and shallow lists of scalars; drop everything else.
	 *
	 * @param array<mixed> $values Raw answers.
	 * @param int          $depth  Depth so far.
	 * @return array<string,mixed>
	 */
	private function clean( array $values, int $depth ): array {
		$clean = array();
		foreach ( $values as $key => $value ) {
			$key = is_string( $key ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $key ), 0, 60 ) : (string) $key;
			if ( '' === $key ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = mb_substr( sanitize_text_field( $value ), 0, 500 );
			} elseif ( is_array( $value ) && $depth < 2 ) {
				$clean[ $key ] = $this->clean( $value, $depth + 1 );
			}
		}
		return $clean;
	}
}
