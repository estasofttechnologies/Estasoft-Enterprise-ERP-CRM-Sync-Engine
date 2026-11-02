<?php
/**
 * A failure that stops the current step with a ConnectorError.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thrown inside the connector for a problem the person can read and turned into a result by the connector. Its message is never shown.
 */
final class SuiteCrmFailure extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError $error The error.
	 */
	public function __construct( public readonly ConnectorError $error ) {
		parent::__construct( 'suitecrm failure' );
	}

	/**
	 * Stop with a SYNC-VAL-002 failure.
	 *
	 * @param string $field  Field the person can fix.
	 * @param string $reason Short reason.
	 *
	 * @throws SuiteCrmFailure Always.
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
					'system' => SuiteCrmConnector::LABEL,
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
	 * The answer was not the JSON:API: the server returned a page, so URL rewriting or the address is wrong.
	 */
	public static function notApi(): self {
		return self::invalid( 'address', __( 'The server did not answer like the SuiteCRM API. Check the address, the version and that URL rewriting is switched on.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * A SYNC-MAP-001 failure.
	 *
	 * @param string $sku SKU.
	 */
	public static function unknownSku( string $sku ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Map001,
				array(
					'system' => SuiteCrmConnector::LABEL,
					'sku'    => $sku,
				),
				null,
				null,
				null,
				'unknown sku'
			)
		);
	}

	/**
	 * Stop because the answer was not the JSON:API.
	 *
	 * @throws SuiteCrmFailure Always.
	 */
	public static function refuseNotApi(): never {
		$failure = self::notApi();
		throw $failure;
	}

	/**
	 * Stop with a SYNC-MAP-001 failure.
	 *
	 * @param string $sku SKU.
	 *
	 * @throws SuiteCrmFailure Always.
	 */
	public static function refuseSku( string $sku ): never {
		$failure = self::unknownSku( $sku );
		throw $failure;
	}
}
