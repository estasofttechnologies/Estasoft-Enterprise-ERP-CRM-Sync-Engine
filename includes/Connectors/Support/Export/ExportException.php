<?php
/**
 * An export could not be made.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * SYNC-EXP-001 (the folder cannot be written), SYNC-EXP-002 (orders left out) or SYNC-VAL-002 (nothing to export, wrong connection).
 */
final class ExportException extends ApiException {

	/**
	 * The uploads folder cannot be written.
	 */
	public static function notWritable(): self {
		return new self( new ConnectorError( ErrorCode::Exp001, array(), null, null, null, 'export folder not writable' ) );
	}

	/**
	 * Orders were left out of the export.
	 *
	 * @param int    $count  Number of orders.
	 * @param string $reason Reason shown to the person.
	 */
	public static function leftOut( int $count, string $reason ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Exp002,
				array(
					'n'      => $count,
					'reason' => $reason,
				),
				null,
				null,
				null,
				'orders left out'
			)
		);
	}

	/**
	 * Something the person asked for cannot be done.
	 *
	 * @param string $reason Reason shown to the person.
	 */
	public static function refused( string $reason ): self {
		return new self(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => '',
					'field'  => 'export',
					'reason' => $reason,
				),
				null,
				null,
				null,
				'export refused'
			)
		);
	}
}
