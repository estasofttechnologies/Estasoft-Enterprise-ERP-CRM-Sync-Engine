<?php
/**
 * The errors the Microsoft connectors raise themselves.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One place for the wording of the connector's own refusals, so the three connectors say the same thing the same way.
 */
final class Failures {

	/**
	 * A field or a setting the system cannot use (SYNC-VAL-002).
	 *
	 * @param string $system System name.
	 * @param string $field  Field, option or step.
	 * @param string $reason Short plain reason (already translated).
	 * @param string $detail Short note for the log.
	 */
	public static function invalid( string $system, string $field, string $reason, string $detail = 'invalid' ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $system,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			$detail
		);
	}

	/**
	 * A SKU the system does not know (SYNC-MAP-001).
	 *
	 * @param string $system System name.
	 * @param string $sku    SKU.
	 */
	public static function unknownSku( string $system, string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'system' => $system,
				'sku'    => $sku,
			),
			null,
			null,
			null,
			'unknown sku'
		);
	}

	/**
	 * An order without a customer email (SYNC-MAP-003).
	 *
	 * @param string $orderId WooCommerce order id.
	 */
	public static function missingEmail( string $orderId ): ConnectorError {
		return new ConnectorError( ErrorCode::Map003, array( 'order' => $orderId ), null, null, null, 'email missing' );
	}

	/**
	 * The kind of item this connector does not send (SYNC-VAL-002).
	 *
	 * @param string $system System name.
	 * @param string $kind   Entity type word.
	 */
	public static function unsupported( string $system, string $kind ): ConnectorError {
		return self::invalid( $system, $kind, __( 'This connector does not send this kind of item.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' );
	}

	/**
	 * Two totals that differ by more than rounding (SYNC-VAL-002).
	 *
	 * @param string $system   System name.
	 * @param string $expected The shop's total, as text with its currency.
	 * @param string $found    The system's total.
	 * @param string $hint     What to check.
	 */
	public static function totalDiffers( string $system, string $expected, string $found, string $hint ): ConnectorError {
		return self::invalid(
			$system,
			'total',
			sprintf(
				/* translators: 1: the shop's total, 2: the other system's total, 3: what to check. */
				__( 'The shop total is %1$s but the total in the system is %2$s. %3$s', 'estasoft-erp-crm-sync-engine' ),
				$expected,
				$found,
				$hint
			),
			'total differs'
		);
	}

	/**
	 * A currency that the system took differently (SYNC-VAL-002).
	 *
	 * @param string $system   System name.
	 * @param string $expected Currency code of the order.
	 * @param string $found    Currency code the document came back with.
	 */
	public static function currencyDiffers( string $system, string $expected, string $found ): ConnectorError {
		return self::invalid(
			$system,
			'currency',
			sprintf(
				/* translators: 1: currency code of the order, 2: currency code the system used. */
				__( 'The order is in %1$s but the document came back in %2$s.', 'estasoft-erp-crm-sync-engine' ),
				$expected,
				'' !== $found ? $found : '?'
			),
			'currency differs'
		);
	}
}
