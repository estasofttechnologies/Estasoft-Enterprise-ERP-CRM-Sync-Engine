<?php
/**
 * A failed call to Odoo, carrying the classified connector error.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Used inside the connector only. Public connector methods catch it and return the ConnectorError.
 * The message is the catalog message with placeholders filled in; it never holds a raw response or a secret.
 */
final class OdooException extends \RuntimeException {

	/**
	 * Python exception name Odoo reported, for example odoo.exceptions.AccessError. Empty for transport failures.
	 *
	 * @var string
	 */
	public readonly string $faultName;

	/**
	 * Model the failed call was made on, when known.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Method the failed call used, when known.
	 *
	 * @var string
	 */
	public readonly string $method;

	/**
	 * Whether the failure says a model or method does not exist (an Odoo app that is not installed).
	 *
	 * @var bool
	 */
	public readonly bool $modelMissing;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError $error        The classified failure.
	 * @param string         $faultName    Python exception name, when Odoo sent one.
	 * @param string         $model        Model of the call.
	 * @param string         $method       Method of the call.
	 * @param bool           $modelMissing True when the model or method does not exist on the server.
	 */
	public function __construct( public readonly ConnectorError $error, string $faultName = '', string $model = '', string $method = '', bool $modelMissing = false ) {
		parent::__construct( $error->message() );
		$this->faultName    = $faultName;
		$this->model        = $model;
		$this->method       = $method;
		$this->modelMissing = $modelMissing;
	}

	/**
	 * Whether the failure is a rights problem of the sync user.
	 */
	public function isAccessError(): bool {
		return \Estasoft\SyncEngine\Domain\ErrorCode::Auth004 === $this->error->code;
	}

	/**
	 * Whether Odoo says a record no longer exists.
	 */
	public function isMissingRecord(): bool {
		return str_ends_with( $this->faultName, 'MissingError' );
	}
}
