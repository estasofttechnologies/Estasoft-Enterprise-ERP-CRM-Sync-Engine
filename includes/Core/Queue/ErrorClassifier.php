<?php
/**
 * Decides what a failure means: retry, fail, or pause the connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Order of precedence: the catalog class of the code, then the connector's own classification (QUE-7), then the
 * filter `estasoft_sync_classify_error`. A refused sign-in (SYNC-AUTH-001) pauses the connection and leaves the
 * item Queued; an expired or unfinished sign-in (AUTH-002, AUTH-003, AUTH-005) marks the connection as needing
 * reconnection and the item waits. Rate limiting (429) retries but never counts toward the circuit breaker.
 */
final class ErrorClassifier {

	/**
	 * Classify a failure.
	 *
	 * @param ConnectorError          $error     The failure.
	 * @param ConnectorInterface|null $connector The connector that raised it, for its override.
	 * @param string                  $system    Name of the system, filled into {system}.
	 */
	public function classify( ConnectorError $error, ?ConnectorInterface $connector = null, string $system = '' ): Classification {
		$error = $this->withSystem( $error, $system );
		$class = $error->errorClass();

		if ( null === $error->classOverride && null !== $connector ) {
			try {
				$override = $connector->classifyError( $error );
			} catch ( \Throwable $e ) {
				unset( $e );
				$override = null;
			}
			if ( $override instanceof ErrorClass ) {
				$class = $override;
			}
		}

		/**
		 * Filter the class of a failure.
		 *
		 * @param ErrorClass     $class     Class decided so far.
		 * @param ConnectorError $error     The failure.
		 * @param string         $connector Connector id, or an empty string.
		 */
		$filtered = apply_filters( 'estasoft_sync_classify_error', $class, $error, null === $connector ? '' : $connector->id() );
		if ( $filtered instanceof ErrorClass ) {
			$class = $filtered;
		}

		$action = $this->action( $error->code, $class );
		return new Classification(
			$error,
			$class,
			$action,
			FailureAction::Retry === $action && ErrorCode::Rate001 !== $error->code && 429 !== $error->httpStatus,
			$error->retryAfter
		);
	}

	/**
	 * The default mapping from an HTTP answer to a catalog error (spec section 11), for connectors that have nothing
	 * more specific to say. Returns null for a successful answer.
	 *
	 * 401 sign-in refused; 403 not allowed; 408, 425 and transport errors network; 429 slow down; 5xx server error;
	 * other 4xx the system rejected the request. A certificate problem is SYNC-NET-003.
	 *
	 * @param HttpResponse $response Answer from HttpClientInterface.
	 * @param string       $system   Name of the system.
	 * @return ConnectorError|null
	 */
	public function fromResponse( HttpResponse $response, string $system ): ?ConnectorError {
		$context = array( 'system' => $system );
		$status  = $response->status;

		if ( 0 === $status ) {
			if ( str_starts_with( $response->error, 'certificate' ) ) {
				return new ConnectorError( ErrorCode::Net003, $context, null, null, null, $this->shortDetail( $response->error ) );
			}
			return new ConnectorError( ErrorCode::Net001, $context, null, null, null, $this->shortDetail( $response->error ) );
		}
		if ( $status >= 200 && $status < 400 ) {
			return null;
		}
		if ( 401 === $status ) {
			return new ConnectorError( ErrorCode::Auth001, $context, $status );
		}
		if ( 403 === $status ) {
			return new ConnectorError( ErrorCode::Auth004, $context + array( 'action' => 'complete this request' ), $status );
		}
		if ( 429 === $status ) {
			$wait = $response->retryAfter();
			return new ConnectorError( ErrorCode::Rate001, $context + array( 'n' => $wait ?? 60 ), $status, $wait );
		}
		if ( 408 === $status || 425 === $status ) {
			return new ConnectorError( ErrorCode::Net001, $context, $status );
		}
		if ( $status >= 500 ) {
			return new ConnectorError( ErrorCode::Net002, $context + array( 'status' => $status ), $status, $response->retryAfter() );
		}
		return new ConnectorError(
			ErrorCode::Val002,
			$context + array(
				'field'  => 'request',
				/* translators: %d: HTTP status code. */
				'reason' => sprintf( __( 'The answer was HTTP %d.', 'estasoft-erp-crm-sync-engine' ), $status ),
			),
			$status
		);
	}

	/**
	 * A short cause for grouping failures on Needs attention, for example "SKU CS-LAMP-07".
	 *
	 * @param ConnectorError $error The failure.
	 */
	public function cause( ConnectorError $error ): string {
		$context = $error->context;
		foreach ( array( 'sku' => 'SKU', 'field' => 'Field', 'type' => 'Mapping', 'action' => 'Access', 'name' => 'Database' ) as $key => $label ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) && '' !== (string) $context[ $key ] ) {
				return $label . ' ' . mb_substr( (string) $context[ $key ], 0, 120 );
			}
		}
		if ( isset( $context['country'], $context['rate'] ) ) {
			return 'Tax ' . (string) $context['country'] . ' ' . (string) $context['rate'];
		}
		return '';
	}

	/**
	 * A copy of the error with {system} filled in, when it was not already.
	 *
	 * @param ConnectorError $error  The failure.
	 * @param string         $system Name of the system.
	 */
	public function withSystem( ConnectorError $error, string $system ): ConnectorError {
		if ( '' === $system || isset( $error->context['system'] ) ) {
			return $error;
		}
		return new ConnectorError(
			$error->code,
			$error->context + array( 'system' => $system ),
			$error->httpStatus,
			$error->retryAfter,
			$error->classOverride,
			$error->detail
		);
	}

	/**
	 * What the queue does for a code and class.
	 *
	 * @param ErrorCode  $code  Catalog code.
	 * @param ErrorClass $class Final class.
	 */
	private function action( ErrorCode $code, ErrorClass $class ): FailureAction {
		if ( ErrorClass::NeedsPerson === $class ) {
			if ( ErrorCode::Auth001 === $code ) {
				return FailureAction::PauseSignIn;
			}
			if ( in_array( $code, array( ErrorCode::Auth002, ErrorCode::Auth003, ErrorCode::Auth005 ), true ) ) {
				return FailureAction::NeedsReconnect;
			}
			return FailureAction::Fail;
		}
		if ( ErrorClass::Retry === $class || ( ErrorClass::Handled === $class && $code->retriesAutomatically() ) ) {
			return FailureAction::Retry;
		}
		return FailureAction::Fail;
	}

	/**
	 * Keep only the first line of a transport error, without any URL.
	 *
	 * @param string $text Transport error text.
	 */
	private function shortDetail( string $text ): string {
		$line = trim( strtok( $text, "\n" ) ?: '' );
		return mb_substr( (string) preg_replace( '#https?://\S+#i', '[url]', $line ), 0, 200 );
	}
}
