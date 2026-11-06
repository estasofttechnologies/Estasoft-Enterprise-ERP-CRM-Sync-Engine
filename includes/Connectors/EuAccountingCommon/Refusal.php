<?php
/**
 * Errors the connectors raise before or after a call, in one place.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Builds the ConnectorError values that do not come from an HTTP answer. The texts that name a vendor value are short and never hold a
 * secret: they are built from the connector's own checks.
 */
final class Refusal {

	/**
	 * SYNC-VAL-002: a value the system cannot take, or a setting that cannot be used.
	 *
	 * @param string $system Vendor label.
	 * @param string $field  Payload path or setting name.
	 * @param string $reason Short reason.
	 * @param string $detail Short log detail.
	 */
	public static function value( string $system, string $field, string $reason, string $detail = 'refused' ): ConnectorError {
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
	 * SYNC-MAP-001: a product the system does not have.
	 *
	 * @param string $system Vendor label.
	 * @param string $sku    SKU (or a line number such as #2).
	 */
	public static function product( string $system, string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'sku'    => $sku,
				'system' => $system,
			),
			null,
			null,
			null,
			'product not found'
		);
	}

	/**
	 * SYNC-MAP-002: a tax code the system does not know, or one that cannot be combined with the others.
	 *
	 * @param string $system  Vendor label.
	 * @param string $country Country or the word "tax".
	 * @param string $rate    Rate or code.
	 */
	public static function tax( string $system, string $country, string $rate ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => $country,
				'rate'    => $rate,
				'system'  => $system,
			),
			null,
			null,
			null,
			'tax code'
		);
	}

	/**
	 * SYNC-MAP-003: the customer email is missing.
	 *
	 * @param string $system Vendor label.
	 * @param string $order  Order number.
	 */
	public static function email( string $system, string $order ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map003,
			array(
				'order'  => $order,
				'system' => $system,
			),
			null,
			null,
			null,
			'email missing'
		);
	}

	/**
	 * A total that differs from the shop's by more than one minor unit.
	 *
	 * @param string $system   Vendor label.
	 * @param string $field    What was compared.
	 * @param string $shop     The shop's total, as text with currency.
	 * @param string $vendor   The system's total, as text with currency.
	 */
	public static function totals( string $system, string $field, string $shop, string $vendor ): ConnectorError {
		return self::value(
			$system,
			$field,
			sprintf(
				/* translators: 1: the shop's total, 2: the total the accounting system worked out. */
				__( 'The totals differ: the shop says %1$s, the system worked out %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$shop,
				$vendor
			),
			'total mismatch'
		);
	}
}
