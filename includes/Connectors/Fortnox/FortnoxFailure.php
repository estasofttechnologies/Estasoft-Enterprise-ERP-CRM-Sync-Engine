<?php
/**
 * A failure that stops the current step with a ConnectorError.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thrown inside the connector for a problem the person can read (an unknown article, a total that differs) and caught in send() and
 * findExisting(), which turn it into the result the engine expects. Its message is never shown.
 */
final class FortnoxFailure extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError $error The error.
	 */
	public function __construct( public readonly ConnectorError $error ) {
		parent::__construct( 'fortnox failure' );
	}

	/**
	 * Stop with a SYNC-VAL-002 failure.
	 *
	 * @param string $field  Field the person can fix.
	 * @param string $reason Short reason.
	 *
	 * @throws FortnoxFailure Always.
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
					'system' => FortnoxConnector::LABEL,
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
	 * @param string $sku Article number.
	 */
	public static function unknownArticle( string $sku ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Map001,
				array(
					'system' => FortnoxConnector::LABEL,
					'sku'    => $sku,
				),
				null,
				null,
				null,
				'unknown article'
			)
		);
	}

	/**
	 * A SYNC-MAP-002 failure.
	 *
	 * @param string $rate VAT rate that is not mapped.
	 */
	public static function unmappedVat( string $rate ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Map002,
				array(
					'system'  => FortnoxConnector::LABEL,
					'country' => '',
					'rate'    => $rate,
				),
				null,
				null,
				null,
				'unmapped vat'
			)
		);
	}

	/**
	 * Stop with a SYNC-MAP-001 failure.
	 *
	 * @param string $sku Article number.
	 *
	 * @throws FortnoxFailure Always.
	 */
	public static function refuseArticle( string $sku ): never {
		$failure = self::unknownArticle( $sku );
		throw $failure;
	}

	/**
	 * Stop with a SYNC-MAP-002 failure.
	 *
	 * @param string $rate VAT rate that is not mapped.
	 *
	 * @throws FortnoxFailure Always.
	 */
	public static function refuseVat( string $rate ): never {
		$failure = self::unmappedVat( $rate );
		throw $failure;
	}
}
