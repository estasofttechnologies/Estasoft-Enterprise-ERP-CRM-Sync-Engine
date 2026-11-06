<?php
/**
 * Fixed setup choices for tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Woo\Ports\SendChoices;

final class WooFixedChoices implements SendChoices {

	/**
	 * @param bool   $orders  Send orders.
	 * @param string $when    placed, paid_or_cod or completed.
	 * @param bool   $refunds Send refunds.
	 */
	public function __construct( private readonly bool $orders = true, private readonly string $when = 'paid_or_cod', private readonly bool $refunds = true ) {
	}

	public function sendOrders(): bool {
		return $this->orders;
	}

	public function sendWhen(): string {
		return $this->when;
	}

	public function sendRefunds(): bool {
		return $this->refunds;
	}
}
