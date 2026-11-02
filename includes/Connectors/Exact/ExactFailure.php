<?php
/**
 * A failure that stops the current step with a ConnectorError.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thrown inside the connector for a problem the person can read (an unknown item, a total that differs) and caught in send() and
 * findExisting(), which turn it into the result the engine expects. Its message is never shown.
 */
final class ExactFailure extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError $error The error.
	 */
	public function __construct( public readonly ConnectorError $error ) {
		parent::__construct( 'exact failure' );
	}

	/**
	 * Stop with a SYNC-VAL-002 failure.
	 *
	 * @param string $field  Field the person can fix.
	 * @param string $reason Short reason.
	 *
	 * @throws ExactFailure Always.
	 */
	public static function refuse( string $field, string $reason ): never {
		$failure = self::invalid( $field, $reason );
		throw $failure;
	}

	/**
	 * A SYNC-VAL-002 failure.
	 *
	 * @param string $field  Field the person can fix.
	 * @param string $reason Short reason.
	 */
	public static function invalid( string $field, string $reason ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => ExactConnector::LABEL,
					'field'  => $field,
					'reason' => $reason,
				),
				null,
				null,
				null,
				'invalid ' . $field
			)
		);
	}

	/**
	 * A SYNC-MAP-001 failure.
	 *
	 * @param string $sku SKU or item code.
	 */
	public static function unknownItem( string $sku ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Map001,
				array(
					'system' => ExactConnector::LABEL,
					'sku'    => $sku,
				),
				null,
				null,
				null,
				'unknown item'
			)
		);
	}

	/**
	 * A SYNC-MAP-002 failure.
	 *
	 * @param string $code VAT code that Exact does not know.
	 */
	public static function unknownVat( string $code ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Map002,
				array(
					'system'  => ExactConnector::LABEL,
					'country' => '',
					'rate'    => $code,
				),
				null,
				null,
				null,
				'unknown vat code'
			)
		);
	}

	/**
	 * A SYNC-AUTH-004 failure.
	 *
	 * @param string $action What the sign-in may not do.
	 */
	public static function noRight( string $action ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => ExactConnector::LABEL,
					'action' => $action,
				),
				403,
				null,
				null,
				'no right'
			)
		);
	}

	/**
	 * Stop with a SYNC-MAP-001 failure.
	 *
	 * @param string $sku SKU or item code.
	 *
	 * @throws ExactFailure Always.
	 */
	public static function refuseItem( string $sku ): never {
		$failure = self::unknownItem( $sku );
		throw $failure;
	}

	/**
	 * Stop with a SYNC-MAP-002 failure.
	 *
	 * @param string $code VAT code that Exact does not know.
	 *
	 * @throws ExactFailure Always.
	 */
	public static function refuseVat( string $code ): never {
		$failure = self::unknownVat( $code );
		throw $failure;
	}
}
