<?php
/**
 * Turns exceptions into the plugin's error answers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\RuleValidationException;

/**
 * The only place that decides which exception becomes which answer. Failures that a person can act on keep their message;
 * everything else becomes the generic answer SYNC-REST-001 and is reported once to the log, with the exception class and nothing
 * else (no message, code, file or trace), so a secret that an exception might carry never reaches the answer or the log.
 */
final class ErrorMapper {

	/**
	 * Called with a short description of an unexpected failure.
	 *
	 * @var \Closure(string):void
	 */
	private \Closure $report;

	/**
	 * @param \Closure(string):void|null $report Receives a description of each unexpected failure; ignores it by default.
	 */
	public function __construct( ?\Closure $report = null ) {
		$this->report = $report ?? static function ( string $description ): void {
			unset( $description );
		};
	}

	/**
	 * The answer for a failure.
	 *
	 * @param \Throwable $error The failure.
	 */
	public function map( \Throwable $error ): ApiException {
		if ( $error instanceof ApiException ) {
			return $error;
		}
		if ( $error instanceof ValidationException ) {
			return ApiException::invalid( __( 'Some fields need attention.', 'estasoft-erp-crm-sync-engine' ), $error->errors() );
		}
		if ( $error instanceof RuleValidationException ) {
			return $this->ruleProblems( $error );
		}
		if ( $error instanceof MappingException ) {
			return ApiException::fromCatalog( $error->errorCode, $error->context );
		}
		if ( $error instanceof CryptoException ) {
			return $this->crypto( $error );
		}
		if ( $error instanceof JobException ) {
			return $this->job( $error );
		}
		// Anything else is a fault in the code or the server, however harmless its class sounds (a plain InvalidArgumentException or
		// OutOfBoundsException could come from any library), so it never becomes a 404 or a 422 and its message never leaves the server.
		// The cases a person causes are checked before the service is called and raise ApiException or ValidationException.
		( $this->report )( get_class( $error ) );
		return ApiException::generic();
	}

	/**
	 * Every problem of a rule set, by rule and part of the rule.
	 *
	 * @param RuleValidationException $error The failure.
	 */
	private function ruleProblems( RuleValidationException $error ): ApiException {
		$fields = array();
		$list   = array();
		foreach ( $error->errors as $problem ) {
			$key = 'rules' . ( '' === $problem->ruleId ? '' : '.' . $problem->ruleId ) . ( '' === $problem->field ? '' : '.' . $problem->field );
			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = $problem->message;
			}
			$list[] = $problem->toArray();
		}
		return ApiException::invalid( $error->getMessage(), $fields, array( 'errors' => $list ) );
	}

	/**
	 * A refusal from a Jobs service: its status, its catalog code when it has one, and the extra facts it names (the failed
	 * connection test, the line to add to wp-config.php).
	 *
	 * @param JobException $error The failure.
	 */
	private function job( JobException $error ): ApiException {
		$status  = max( 400, min( 599, $error->httpStatus() ) );
		$code    = $error->errorCode();
		$context = $error->context();
		$details = array();
		foreach ( array( 'test', 'key_line' ) as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$details[ $key ] = $context[ $key ];
				unset( $context[ $key ] );
			}
		}
		if ( is_array( $details['test'] ?? null ) ) {
			unset( $details['test']['detected'] ); // What a connector found out is its own business and is not part of the contract.
		}
		$strings = array();
		foreach ( $context as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$strings[ (string) $name ] = (string) $value;
			}
		}
		if ( null !== $code ) {
			return new ApiException( $code->value, $error->getMessage(), $status, $code->errorClass()->value, $strings, array(), $details );
		}
		return match ( true ) {
			404 === $status                  => new ApiException( ApiException::NOT_FOUND, $error->getMessage(), 404, ErrorClass::NeedsPerson->value, $strings, array(), $details ),
			409 === $status                  => new ApiException( 'estasoft_sync_conflict', $error->getMessage(), 409, ErrorClass::NeedsPerson->value, $strings, array(), $details ),
			in_array( $status, array( 400, 422 ), true ) => new ApiException( ApiException::INVALID_PARAM, $error->getMessage(), $status, ErrorClass::NeedsPerson->value, $strings, array(), $details ),
			$status < 500                    => new ApiException( 'estasoft_sync_refused', $error->getMessage(), $status, ErrorClass::NeedsPerson->value, $strings, array(), $details ),
			default                          => new ApiException( 'estasoft_sync_failed', $error->getMessage(), $status, ErrorClass::Retry->value, $strings, array(), $details ),
		};
	}

	/**
	 * A key problem: the catalog code when there is one, otherwise a conflict that says the credentials cannot be read.
	 *
	 * @param CryptoException $error The failure.
	 */
	private function crypto( CryptoException $error ): ApiException {
		$code = $error->errorCode();
		if ( null !== $code ) {
			return new ApiException( $code->value, $error->getMessage(), 409, $code->errorClass()->value );
		}
		return new ApiException( 'estasoft_sync_credentials_unreadable', $error->getMessage(), 409, ErrorClass::NeedsPerson->value );
	}
}
