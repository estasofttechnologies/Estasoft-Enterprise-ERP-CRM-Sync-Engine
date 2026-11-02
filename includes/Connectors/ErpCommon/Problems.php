<?php
/**
 * The errors an ERP connector raises about a payload or a setting.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Builds ConnectorError values with the context the catalog templates need. No vendor text and no secret goes in here: the reasons are
 * written by the connector.
 */
final class Problems {

	/**
	 * A rejection as SYNC-VAL-002.
	 *
	 * @param string $system System name.
	 * @param string $field  Field path or option name.
	 * @param string $reason Why, in a plain sentence.
	 * @param string $detail Short note for the log.
	 */
	public static function refused( string $system, string $field, string $reason, string $detail = 'payload rejected' ): ConnectorError {
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
	 * A product that the system does not know (SYNC-MAP-001).
	 *
	 * @param string $system System name.
	 * @param string $sku    SKU, or a line marker such as #2.
	 */
	public static function missingProduct( string $system, string $sku ): ConnectorError {
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
	 * A tax code the system does not know (SYNC-MAP-002).
	 *
	 * @param string $system System name.
	 * @param string $code   The code that was not found.
	 */
	public static function missingTax( string $system, string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => $system,
				'rate'    => $code,
			),
			null,
			null,
			null,
			'tax code not found'
		);
	}

	/**
	 * A buyer without an email (SYNC-MAP-003).
	 *
	 * @param string $order  Order id.
	 * @param string $detail Short note for the log.
	 */
	public static function missingEmail( string $order, string $detail = 'email missing' ): ConnectorError {
		return new ConnectorError( ErrorCode::Map003, array( 'order' => $order ), null, null, null, $detail );
	}

	/**
	 * A record that a later pass found while the first one was still working (SYNC-DUP-001).
	 *
	 * @param string $system System name.
	 * @param string $ref    Reference of the existing record.
	 */
	public static function duplicate( string $system, string $ref ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Dup001,
			array(
				'system' => $system,
				'ref'    => $ref,
			),
			null,
			null,
			null,
			'duplicate'
		);
	}
}
