<?php
/**
 * The setup choices, read from the stored setup answers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Adapters;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Woo\Ports\SendChoices;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `answers.what` of the onboarding option. A value that is missing, or not of the right kind, is the same as the answer the
 * setup screen shows before anyone touches it (DEFAULT_WHAT in src/screens/setup/what.ts): orders and refunds are sent, an order is
 * sent when it is paid or placed with cash on delivery.
 */
final class CoreSendChoices implements SendChoices {

	/**
	 * Values `send_when` can have.
	 */
	public const WHEN = array( 'placed', 'paid_or_cod', 'completed' );

	/**
	 * What was read, once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cached = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OnboardingState $onboarding Setup state.
	 */
	public function __construct( private readonly OnboardingState $onboarding ) {
	}

	/**
	 * Whether orders are sent at all.
	 */
	public function sendOrders(): bool {
		return $this->flag( 'send_orders' );
	}

	/**
	 * When an order is first sent.
	 */
	public function sendWhen(): string {
		$value = $this->what()['send_when'] ?? null;
		return is_string( $value ) && in_array( $value, self::WHEN, true ) ? $value : 'paid_or_cod';
	}

	/**
	 * Whether a refund is sent after its order.
	 */
	public function sendRefunds(): bool {
		return $this->flag( 'send_refunds' );
	}

	/**
	 * A yes/no answer that is yes unless the person turned it off.
	 *
	 * @param string $key Answer.
	 */
	private function flag( string $key ): bool {
		$value = $this->what()[ $key ] ?? null;
		return is_bool( $value ) ? $value : true;
	}

	/**
	 * The stored answers of the "what to sync" step.
	 *
	 * @return array<string,mixed>
	 */
	private function what(): array {
		if ( null === $this->cached ) {
			$answers      = $this->onboarding->get()['answers'];
			$what         = is_array( $answers ) && is_array( $answers['what'] ?? null ) ? $answers['what'] : array();
			$this->cached = $what;
		}
		return $this->cached;
	}
}
