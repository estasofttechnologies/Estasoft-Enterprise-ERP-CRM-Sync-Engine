<?php
/**
 * A failed call to a vendor API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Thrown by ApiClient for every non-2xx answer (unless allowed), transport failure or refused address. It carries the ConnectorError
 * the failure maps to. The message is the catalog text with the system name filled in: it never holds a secret, a host or a body.
 * The base turns it into a ConnectorError at the boundary (GuardedConnector); connector code can also read error() directly.
 */
class ApiException extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorError   $error    What the failure means.
	 * @param ApiResponse|null $response The answer, when there was one (its body may hold the vendor's own error shape).
	 */
	final public function __construct( private readonly ConnectorError $error, private readonly ?ApiResponse $response = null ) {
		parent::__construct( $error->message(), $error->httpStatus ?? 0 );
	}

	/**
	 * The ConnectorError for this failure.
	 */
	public function error(): ConnectorError {
		return $this->error;
	}

	/**
	 * The vendor's answer, if any.
	 */
	public function response(): ?ApiResponse {
		return $this->response;
	}

	/**
	 * HTTP status, or null when the request never completed.
	 */
	public function status(): ?int {
		return $this->error->httpStatus;
	}

	/**
	 * A copy that names the system in its message (used when a lower layer did not know the connector's name).
	 *
	 * @param string $system System name.
	 */
	public function withSystem( string $system ): static {
		if ( isset( $this->error->context['system'] ) && '' !== (string) $this->error->context['system'] ) {
			return $this;
		}
		$error = new ConnectorError(
			$this->error->code,
			array( 'system' => $system ) + $this->error->context,
			$this->error->httpStatus,
			$this->error->retryAfter,
			$this->error->classOverride,
			$this->error->detail
		);
		return new static( $error, $this->response );
	}
}
