<?php
/**
 * Builds the connector errors the ERP connectors share.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One place for the wording of the errors that do not come from an HTTP answer: a refused payload, an unknown SKU, a total that differs.
 */
final class Problem {

	/**
	 * SYNC-VAL-002: a field or a rule of the system was not met.
	 *
	 * @param string $system System name.
	 * @param string $field  Field path or name.
	 * @param string $reason Short reason for the person.
	 */
	public static function validation( string $system, string $field, string $reason ): ConnectorError {
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
			'refused by the connector'
		);
	}

	/**
	 * SYNC-MAP-003: the order has no customer email.
	 *
	 * @param string $system System name.
	 * @param string $order  Order id.
	 */
	public static function missingEmail( string $system, string $order ): ConnectorError {
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
	 * SYNC-MAP-001: the SKU is not in the system.
	 *
	 * @param string $system System name.
	 * @param string $sku    SKU.
	 */
	public static function unknownSku( string $system, string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'sku'    => $sku,
				'system' => $system,
			),
			null,
			null,
			null,
			'unknown sku'
		);
	}

	/**
	 * The same error as an exception, for code that sits below a method that returns a result.
	 *
	 * @param ConnectorError $error Error.
	 */
	public static function raise( ConnectorError $error ): ApiException {
		return new ApiException( $error );
	}
}
