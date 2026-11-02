<?php
/**
 * A read-only lookup in the connected system failed.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;

defined( 'ABSPATH' ) || exit;

/**
 * Raised when a product or customer lookup returns a connector error. It is not a mapping problem: the queue
 * classifies the carried error (retry, sign-in, access) the same way it classifies a failed send.
 */
final class LookupFailedException extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError $error What the connector reported.
	 */
	public function __construct( public readonly ConnectorError $error ) {
		parent::__construct( $error->code->value );
	}
}
