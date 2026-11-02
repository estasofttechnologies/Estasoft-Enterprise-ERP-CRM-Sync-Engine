<?php
/**
 * Turns exceptions into the ConnectorError the engine understands.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * An ApiException already carries its ConnectorError. Anything else is a defect in the connector or in the data: it becomes a
 * SYNC-VAL-002 that names only the kind of exception, never its message (a message could hold a secret or a body).
 */
final class ErrorBridge {

	/**
	 * The ConnectorError for a throwable.
	 *
	 * @param \Throwable $throwable Whatever was thrown.
	 * @param string     $system    System name for the message.
	 */
	public static function toError( \Throwable $throwable, string $system ): ConnectorError {
		if ( $throwable instanceof ApiException ) {
			return $throwable->withSystem( $system )->error();
		}
		if ( $throwable instanceof CryptoException && null !== $throwable->errorCode() ) {
			return new ConnectorError( $throwable->errorCode(), array( 'system' => $system ), null, null, null, 'encryption' );
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $system,
				'field'  => 'connector',
				'reason' => sprintf(
					/* translators: %s: PHP error class name. */
					__( 'The connector stopped unexpectedly (%s).', 'estasoft-erp-crm-sync-engine' ),
					( new \ReflectionClass( $throwable ) )->getShortName()
				),
			),
			null,
			null,
			null,
			'connector exception'
		);
	}
}
