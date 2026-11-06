<?php
/**
 * An error the REST layer turns into the plugin's JSON error body.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\PlainText;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Carries what the body `{ code, message, data: { status, class } }` needs. Services and controllers throw it; the
 * ErrorMapper turns every other exception into one. The message is shown to a person, so it must never contain a secret,
 * a stack trace or a path.
 *
 * Catalog failures use a `SYNC-...` code from Domain\ErrorCode. Three codes are used by the REST layer itself and are not in
 * the catalog yet: GENERIC for an unexpected failure, NO_SIGN_IN for a sign-in route on a connector that does not use sign-in, and
 * MAIL_FAILED for a test email that WordPress did not accept.
 * Failures that WordPress already has a code for (not found, invalid parameter, forbidden) keep the WordPress code.
 *
 * The message and the field messages are kept escaped with `esc_html()`, the way they are written at the `throw` (WordPress.org
 * plugin review asks for it). `toBody()` turns them back into plain text, because the answer is JSON and not HTML.
 */
final class ApiException extends \RuntimeException {

	/**
	 * Unexpected failure; the real cause is in the log, never in the answer.
	 */
	public const GENERIC = 'SYNC-REST-001';

	/**
	 * A sign-in route was called for a connector that connects with a key.
	 */
	public const NO_SIGN_IN = 'SYNC-CFG-004';

	/**
	 * The test email was not accepted by wp_mail().
	 */
	public const MAIL_FAILED = 'SYNC-REST-002';

	/**
	 * WordPress' own code for a parameter that fails validation.
	 */
	public const INVALID_PARAM = 'rest_invalid_param';

	/**
	 * WordPress' own code for a missing record.
	 */
	public const NOT_FOUND = 'rest_not_found';

	/**
	 * Machine code: `SYNC-...` or a WordPress REST code.
	 *
	 * @var string
	 */
	public readonly string $apiCode;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Class from the error catalog: retry, needs_person, handled or info.
	 *
	 * @var string
	 */
	public readonly string $errorClass;

	/**
	 * Values for the {placeholders} of the catalog message, as text.
	 *
	 * @var array<string,string>
	 */
	public readonly array $context;

	/**
	 * Field name to message (escaped), for validation failures.
	 *
	 * @var array<string,string>
	 */
	public readonly array $fields;

	/**
	 * Extra structured detail for the screen (for example every rule error of a mapping).
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $details;

	/**
	 * Create the error with everything the answer body needs.
	 *
	 * @param string               $apiCode    `SYNC-...` or a WordPress REST code.
	 * @param string               $message    Sentence for the person, escaped with `esc_html()`.
	 * @param int                  $status     HTTP status.
	 * @param string               $errorClass One of the values of Domain\ErrorClass.
	 * @param array<string,string> $context    Placeholder values.
	 * @param array<string,string> $fields     Messages by field name, escaped with `esc_html()`.
	 * @param array<string,mixed>  $details    Extra detail.
	 */
	public function __construct(
		string $apiCode,
		string $message,
		int $status = 400,
		string $errorClass = 'needs_person',
		array $context = array(),
		array $fields = array(),
		array $details = array()
	) {
		parent::__construct( $message );
		$this->apiCode    = $apiCode;
		$this->status     = $status;
		$this->errorClass = null === ErrorClass::tryFrom( $errorClass ) ? ErrorClass::NeedsPerson->value : $errorClass;
		$this->context    = $context;
		$this->fields     = $fields;
		$this->details    = $details;
	}

	/**
	 * An error from the catalog, with its message filled in.
	 *
	 * @param ErrorCode           $code    Catalog code.
	 * @param array<string,mixed> $context Values for the placeholders.
	 * @param int                 $status  HTTP status.
	 */
	public static function fromCatalog( ErrorCode $code, array $context = array(), int $status = 422 ): self {
		$strings = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$strings[ (string) $key ] = (string) $value;
			}
		}
		return new self(
			$code->value,
			esc_html( ( new ConnectorError( $code, $context ) )->message() ),
			$status,
			$code->errorClass()->value,
			$strings
		);
	}

	/**
	 * A parameter or field is wrong.
	 *
	 * @param string               $message Sentence for the person, escaped with `esc_html()`.
	 * @param array<string,string> $fields  Messages by field name, escaped with `esc_html()`.
	 * @param array<string,mixed>  $details Extra detail.
	 */
	public static function invalid( string $message, array $fields = array(), array $details = array() ): self {
		return new self( self::INVALID_PARAM, $message, 422, ErrorClass::NeedsPerson->value, array(), $fields, $details );
	}

	/**
	 * The record does not exist.
	 *
	 * @param string $message Sentence for the person, escaped with `esc_html()`.
	 */
	public static function notFound( string $message ): self {
		return new self( self::NOT_FOUND, $message, 404, ErrorClass::NeedsPerson->value );
	}

	/**
	 * The request is fine but the record is not in a state that allows it.
	 *
	 * @param string $message Sentence for the person, escaped with `esc_html()`.
	 * @param string $code    Machine code.
	 */
	public static function conflict( string $message, string $code = 'estasoft_sync_conflict' ): self {
		return new self( $code, $message, 409, ErrorClass::NeedsPerson->value );
	}

	/**
	 * Something failed that the person cannot fix. The cause is in the plugin's log.
	 */
	public static function generic(): self {
		return new self(
			self::GENERIC,
			esc_html__( 'Something went wrong on the server. Try again in a moment. If it keeps happening, copy the support report from Settings and send it to support.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			500,
			ErrorClass::Info->value
		);
	}

	/**
	 * The body of the error answer: `{ code, message, data: { status, class, ... } }`, with the messages as plain text.
	 *
	 * @return array{code:string,message:string,data:array<string,mixed>}
	 */
	public function toBody(): array {
		$data = array(
			'status' => $this->status,
			'class'  => $this->errorClass,
		);
		if ( array() !== $this->context ) {
			$data['context'] = $this->context;
		}
		if ( array() !== $this->fields ) {
			$data['fields'] = array_map( PlainText::fromEscaped( ... ), $this->fields );
		}
		if ( array() !== $this->details ) {
			$data['details'] = $this->details;
		}
		return array(
			'code'    => $this->apiCode,
			'message' => PlainText::fromEscaped( $this->getMessage() ),
			'data'    => $data,
		);
	}
}
