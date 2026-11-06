<?php
/**
 * Maps Odoo faults and HTTP failures to catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One place that decides which error code a failure gets (spec section 11 and the connector brief):
 * transport problems NET-001 and NET-003, 401 AUTH-001, access errors AUTH-004, unknown database CFG-003,
 * a plan without outside access AUTH-006, 429 RATE-001 with Retry-After, server errors NET-002, rejected data VAL-002.
 * Secrets given to the constructor are removed from every text this class produces.
 */
final class OdooErrorMapper {

	/**
	 * Name used in messages.
	 */
	public const SYSTEM = 'Odoo';

	/**
	 * Longest piece of Odoo's own message kept in an error.
	 */
	private const MAX_REASON = 240;

	/**
	 * Names that Odoo gives to faults that may pass by themselves.
	 *
	 * @var array<int,string>
	 */
	public const RETRYABLE_FAULTS = array( 'LockError', 'ConcurrencyError', 'SerializationFailure', 'DeadlockDetected', 'LockNotAvailable', 'QueryCanceled', 'TooManyRequests' );

	/**
	 * Names of database connection and server errors. Their text is never kept.
	 *
	 * @var array<int,string>
	 */
	private const DATABASE_FAULTS = array( 'OperationalError', 'InterfaceError', 'DatabaseError', 'InternalError', 'AdminShutdown', 'CannotConnectNow', 'ConnectionException' );

	/**
	 * Names that mean the data was refused, not that the server is in trouble.
	 *
	 * @var array<int,string>
	 */
	private const REFUSED_FAULTS = array( 'UserError', 'ValidationError', 'RedirectWarning', 'UnprocessableEntity', 'BadRequest', 'IntegrityError', 'NotNullViolation', 'UniqueViolation', 'CheckViolation', 'ForeignKeyViolation', 'DataError', 'InvalidTextRepresentation', 'StringDataRightTruncation', 'NumericValueOutOfRange', 'TypeError', 'ValueError', 'KeyError', 'AttributeError', 'IndexError' );

	/**
	 * Words of models for rights messages.
	 *
	 * @var array<string,string>
	 */
	private const MODEL_WORDS = array(
		'sale.order'               => 'sale orders',
		'sale.order.line'          => 'sale order lines',
		'res.partner'              => 'customers',
		'product.product'          => 'products',
		'product.template'         => 'products',
		'account.move'             => 'invoices and credit notes',
		'account.tax'              => 'taxes',
		'stock.quant'              => 'stock',
		'stock.warehouse'          => 'warehouses',
		'stock.picking'            => 'deliveries',
		'res.company'              => 'the company',
		'res.users'                => 'the user',
		'account.payment.term'     => 'payment terms',
		'account.payment.register' => 'payments',
		'sale.advance.payment.inv' => 'invoices',
	);

	/**
	 * Verbs for rights messages.
	 *
	 * @var array<string,string>
	 */
	private const METHOD_VERBS = array(
		'create'       => 'create',
		'write'        => 'change',
		'unlink'       => 'delete',
		'read'         => 'read',
		'search_read'  => 'read',
		'search'       => 'read',
		'search_count' => 'read',
		'name_search'  => 'read',
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<int,string> $secrets Values that must never appear in an error text (API key, user name).
	 * @param bool              $odooOnline True when the host is an Odoo Online address.
	 * @param string            $database Database name of the connection. It is taken out of Odoo's own texts where it stands in
	 *                                    quotes or after dbname=, as in PostgreSQL's database "name" does not exist; the CFG-003
	 *                                    message still names it because the person typed it.
	 */
	public function __construct( private readonly array $secrets = array(), private readonly bool $odooOnline = false, private readonly string $database = '' ) {
	}

	/**
	 * Remove secrets from a text and cut it to a safe length.
	 *
	 * @param string $text Text from Odoo or from a transport.
	 */
	public function scrub( string $text ): string {
		foreach ( $this->secrets as $secret ) {
			if ( strlen( $secret ) >= 4 ) {
				$text = str_ireplace( $secret, '***', $text );
			}
		}
		if ( strlen( $this->database ) >= 2 ) {
			$name = preg_quote( $this->database, '/' );
			$text = (string) preg_replace( '/(["\'`])' . $name . '\1/i', '$1***$1', $text );
			$text = (string) preg_replace( '/\b(dbname|database|db)(\s*[=:]\s*)' . $name . '\b/i', '$1$2***', $text );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/<[^>]*>/', ' ', $text ) ) );
		return mb_strlen( $text ) > self::MAX_REASON ? mb_substr( $text, 0, self::MAX_REASON ) . '...' : $text;
	}

	/**
	 * A request that never completed (status 0).
	 *
	 * @param HttpResponse $response Response with status 0.
	 * @param string       $model    Model of the call.
	 * @param string       $method   Method of the call.
	 */
	public function transportFailure( HttpResponse $response, string $model = '', string $method = '' ): OdooException {
		$text = strtolower( $response->error );
		if ( str_starts_with( $text, 'unsafe_url' ) ) {
			return $this->exception(
				ErrorCode::Val002,
				array(
					'field'  => __( 'Odoo address', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => __( 'The address points to a private network or to this computer, which is not allowed.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				null,
				null,
				'unsafe address',
				'',
				$model,
				$method
			);
		}
		// A time-out or a connection that was reset is a network problem that may pass, whatever the words around it say about
		// SSL ("SSL connection timeout", "SSL_connect: Connection reset"). Only a certificate that fails verification is NET-003.
		if ( $response->timedOut || str_starts_with( $text, 'timeout' ) || 1 === preg_match( '/timed? ?out|timeout|connection reset|reset by peer|connection refused|could not resolve|couldn\'t resolve|name or service not known|no route to host|network is unreachable|broken pipe|empty reply|curl error (6|7|28|52|55|56)\b/', $text ) ) {
			return $this->exception( ErrorCode::Net001, array(), null, null, $response->timedOut || str_contains( $text, 'time' ) ? 'timeout' : 'connection failed', '', $model, $method );
		}
		if ( str_starts_with( $text, 'certificate' ) || 1 === preg_match( '/certificate (problem|verify|has expired|is not|not valid|verification)|self.signed|unable to get (local )?issuer|unable to verify|verify failed|no alternative certificate|hostname .*(mismatch|does not match)|curl error (51|58|60|77|83|90|91)\b/', $text ) ) {
			return $this->exception( ErrorCode::Net003, array(), null, null, 'certificate not trusted', '', $model, $method );
		}
		return $this->exception( ErrorCode::Net001, array(), null, null, 'connection failed', '', $model, $method );
	}

	/**
	 * A failure reported through the HTTP status (JSON-2, or a proxy in front of any transport).
	 *
	 * @param HttpResponse             $response Response with a status other than 200.
	 * @param array<string,mixed>|null $body     Decoded JSON body, or null when it is not JSON.
	 * @param string                   $model    Model of the call.
	 * @param string                   $method   Method of the call.
	 */
	public function httpFailure( HttpResponse $response, ?array $body, string $model = '', string $method = '' ): OdooException {
		$status  = $response->status;
		$name    = is_array( $body ) && isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '';
		$message = is_array( $body ) && isset( $body['message'] ) && is_scalar( $body['message'] ) ? (string) $body['message'] : '';

		if ( 429 === $status ) {
			return $this->rateLimited( $response, $model, $method );
		}
		if ( 401 === $status ) {
			return $this->exception( ErrorCode::Auth001, array(), 401, null, 'unauthorized', $name, $model, $method );
		}
		if ( '' !== $name ) {
			return $this->fault( $name, $message, $status, $model, $method );
		}

		// No JSON body: the answer came from a proxy, a web server or an Odoo page.
		if ( 404 === $status && str_contains( $response->body, 'No database is selected' ) ) {
			return $this->databaseMissing( '', $model, $method, 404 );
		}
		if ( 403 === $status ) {
			if ( $this->odooOnline ) {
				return $this->exception( ErrorCode::Auth006, array(), 403, null, 'outside access refused', '', $model, $method );
			}
			return $this->exception( ErrorCode::Auth004, array( 'action' => __( 'use the external API', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 403, null, 'forbidden', '', $model, $method );
		}
		if ( in_array( $status, array( 408, 504, 524 ), true ) ) {
			return $this->exception( ErrorCode::Net001, array(), $status, null, 'gateway timeout', '', $model, $method );
		}
		if ( $status >= 500 ) {
			return $this->exception( ErrorCode::Net002, array( 'status' => $status ), $status, null, 'server error', '', $model, $method );
		}
		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			return $this->exception(
				ErrorCode::Val002,
				array(
					'field'  => __( 'Odoo address', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => __( 'The address sends visitors on to another address. Enter the address you end up on when you open Odoo.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				'redirect',
				'',
				$model,
				$method
			);
		}
		if ( 404 === $status ) {
			return $this->exception( ErrorCode::Net001, array(), 404, null, 'not an Odoo address', '', $model, $method );
		}
		return $this->exception(
			ErrorCode::Val002,
			array(
				'field'  => $model,
				'reason' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Odoo answered with status %d.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$status
				),
			),
			$status,
			null,
			'unexpected status',
			'',
			$model,
			$method
		);
	}

	/**
	 * A JSON-RPC error object (HTTP 200 with an "error" member).
	 *
	 * @param array<string,mixed> $error    The "error" member.
	 * @param HttpResponse        $response Response, for Retry-After.
	 * @param string              $model    Model of the call.
	 * @param string              $method   Method of the call.
	 */
	public function rpcFault( array $error, HttpResponse $response, string $model = '', string $method = '' ): OdooException {
		$data    = isset( $error['data'] ) && is_array( $error['data'] ) ? $error['data'] : array();
		$name    = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		$message = isset( $data['message'] ) && is_scalar( $data['message'] ) ? (string) $data['message'] : ( isset( $error['message'] ) && is_scalar( $error['message'] ) ? (string) $error['message'] : '' );
		$code    = isset( $error['code'] ) && is_int( $error['code'] ) ? $error['code'] : 0;

		if ( 100 === $code ) {
			return $this->exception( ErrorCode::Auth002, array(), null, null, 'session expired', $name, $model, $method );
		}
		if ( 404 === $code && '' === $name ) {
			return $this->exception( ErrorCode::Net001, array(), 404, null, 'JSON-RPC route not found', '', $model, $method );
		}
		return $this->fault( $name, $message, 200, $model, $method );
	}

	/**
	 * A 429 answer.
	 *
	 * @param HttpResponse $response Response.
	 * @param string       $model    Model of the call.
	 * @param string       $method   Method of the call.
	 */
	public function rateLimited( HttpResponse $response, string $model = '', string $method = '' ): OdooException {
		$wait = $response->retryAfter();
		return $this->exception( ErrorCode::Rate001, array( 'n' => $wait ?? 60 ), 429, $wait, 'rate limited', '', $model, $method );
	}

	/**
	 * A 200 answer that is not the JSON the call expects.
	 *
	 * @param HttpResponse $response Response.
	 * @param string       $model    Model of the call.
	 * @param string       $method   Method of the call.
	 */
	public function unreadable( HttpResponse $response, string $model = '', string $method = '' ): OdooException {
		return $this->exception( ErrorCode::Net001, array(), $response->status, null, 'the answer is not valid JSON', '', $model, $method );
	}

	/**
	 * The sign-in was refused.
	 *
	 * @param string $detail Short reason for the log.
	 */
	public function signInRefused( string $detail = 'sign-in refused' ): OdooException {
		return $this->exception( ErrorCode::Auth001, array(), null, null, $detail );
	}

	/**
	 * The server runs an Odoo version this connector does not support.
	 *
	 * @param OdooVersion $version The detected version.
	 */
	public function unsupportedVersion( OdooVersion $version ): OdooException {
		return $this->exception(
			ErrorCode::Val002,
			array(
				'field'  => __( 'Odoo version', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'reason' => sprintf(
					/* translators: %s: Odoo version label such as "Odoo 16 Community". */
					__( '%s is older than the versions this plugin supports. Use Odoo 17 or later.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$version->label()
				),
			),
			null,
			null,
			'unsupported version ' . $version->raw
		);
	}

	/**
	 * The database named on the connection does not exist or is not served at this address.
	 *
	 * @param string $name   Database name.
	 * @param string $model  Model of the call.
	 * @param string $method Method of the call.
	 * @param int    $status HTTP status, when there was one.
	 */
	public function databaseMissing( string $name, string $model = '', string $method = '', int $status = 0 ): OdooException {
		return $this->exception( ErrorCode::Cfg003, array( 'name' => $name ), $status > 0 ? $status : null, null, 'database not found', '', $model, $method );
	}

	/**
	 * Fill in the database name on a CFG-003 error that was raised without one.
	 *
	 * @param OdooException $exception Exception from a call.
	 * @param string        $database  Database name from the connection.
	 */
	public function withDatabase( OdooException $exception, string $database ): OdooException {
		if ( ErrorCode::Cfg003 !== $exception->error->code ) {
			return $exception;
		}
		return $this->databaseMissing( $this->scrub( $database ), $exception->model, $exception->method, (int) $exception->error->httpStatus );
	}

	/**
	 * Map a named Python exception.
	 *
	 * @param string $name    Exception name such as odoo.exceptions.AccessError, or empty.
	 * @param string $message Odoo's message.
	 * @param int    $status  HTTP status of the answer.
	 * @param string $model   Model of the call.
	 * @param string $method  Method of the call.
	 */
	private function fault( string $name, string $message, int $status, string $model, string $method ): OdooException {
		$short  = $this->shortName( $name );
		$reason = $this->scrub( $message );

		// Odoo Online blocks outside access on plans without it. The wording of that refusal is not documented, so it is read this
		// way only on an odoo.com address, only when it comes as an access refusal (never as a UserError or ValidationError about
		// the data), and only when the message talks about the external API itself.
		if ( $this->odooOnline && $this->looksLikePlanBlock( $short, $message ) ) {
			return $this->exception( ErrorCode::Auth006, array(), $status > 0 ? $status : null, null, 'outside access not in the plan', $name, $model, $method );
		}
		if ( 'AccessDenied' === $short || 'Unauthorized' === $short ) {
			return $this->exception( ErrorCode::Auth001, array(), $status > 0 ? $status : null, null, 'access denied', $name, $model, $method );
		}
		if ( 'AccessError' === $short || 'Forbidden' === $short ) {
			return $this->exception( ErrorCode::Auth004, array( 'action' => $this->action( $model, $method ) ), $status > 0 ? $status : null, null, $this->detail( $name, $reason ), $name, $model, $method );
		}
		if ( in_array( $short, self::DATABASE_FAULTS, true ) ) {
			if ( 1 === preg_match( '/database "([^"]*)" does not exist/i', $message, $parts ) ) {
				return $this->databaseMissing( $this->scrub( $parts[1] ), $model, $method, $status );
			}
			// The text of a database error can hold the PostgreSQL host, port or user, so only the kind of error is kept.
			return $this->exception( ErrorCode::Net002, array( 'status' => 503 ), 503, null, 'database error ' . $short, $name, $model, $method );
		}
		if ( 'NotFound' === $short || ( 'UserError' === $short && 1 === preg_match( "/Object \S+ doesn't exist/", $message ) ) ) {
			return $this->missingModel( $name, $reason, $status, $model, $method );
		}
		if ( 'MissingError' === $short ) {
			return $this->exception(
				ErrorCode::Val002,
				array(
					'field'  => $model,
					'reason' => $reason,
				),
				$status > 0 ? $status : null,
				null,
				$this->detail( $name, $reason ),
				$name,
				$model,
				$method,
				ErrorClass::Retry
			);
		}
		if ( in_array( $short, self::RETRYABLE_FAULTS, true ) ) {
			return $this->exception( ErrorCode::Net002, array( 'status' => 409 ), 409, null, $this->detail( $name, $reason ), $name, $model, $method );
		}
		if ( in_array( $short, self::REFUSED_FAULTS, true ) ) {
			return $this->exception(
				ErrorCode::Val002,
				array(
					'field'  => $this->guessField( $message, $model ),
					'reason' => $reason,
				),
				$status > 0 ? $status : null,
				null,
				$this->detail( $name, $reason ),
				$name,
				$model,
				$method
			);
		}
		// Anything else is a server problem we cannot read; it is tried again a few times and then shown to the person.
		return $this->exception( ErrorCode::Net002, array( 'status' => $status >= 500 ? $status : 500 ), $status >= 500 ? $status : 500, null, $this->detail( $name, $reason ), $name, $model, $method );
	}

	/**
	 * Whether a fault looks like the refusal Odoo Online gives to a plan without external API access.
	 *
	 * @param string $short   Short exception name.
	 * @param string $message Odoo's message.
	 */
	private function looksLikePlanBlock( string $short, string $message ): bool {
		if ( ! in_array( $short, array( 'AccessDenied', 'AccessError', 'Forbidden', '' ), true ) ) {
			return false;
		}
		return 1 === preg_match( '/\b(external api|xml-?rpc|json-?rpc|json-?2|api access|outside access)\b/i', $message )
			|| 1 === preg_match( '/\b(plan|subscription)\b.*\b(api|rpc)\b|\b(api|rpc)\b.*\b(plan|subscription)\b/i', $message );
	}

	/**
	 * A model or method that does not exist: an Odoo app that is not installed.
	 *
	 * @param string $name   Exception name.
	 * @param string $reason Scrubbed message.
	 * @param int    $status HTTP status.
	 * @param string $model  Model of the call.
	 * @param string $method Method of the call.
	 */
	private function missingModel( string $name, string $reason, int $status, string $model, string $method ): OdooException {
		return $this->exception(
			ErrorCode::Val002,
			array(
				'field'  => $model,
				'reason' => __( 'This Odoo app is not installed or this version does not have it.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			$status > 0 ? $status : null,
			null,
			$this->detail( $name, $reason ),
			$name,
			$model,
			$method,
			null,
			true
		);
	}

	/**
	 * The part of an exception name after the last dot.
	 *
	 * @param string $name Exception name.
	 */
	private function shortName( string $name ): string {
		$position = strrpos( $name, '.' );
		return false === $position ? $name : substr( $name, $position + 1 );
	}

	/**
	 * Name of the field Odoo complains about, when its message says it.
	 *
	 * @param string $message Odoo's message.
	 * @param string $model   Fallback.
	 */
	private function guessField( string $message, string $model ): string {
		$patterns = array(
			"/Invalid field '([\w.]+)'/",
			"/Missing required value for the field '([^']+)'/",
			'/Wrong value for ([\w.]+)/',
			"/for field '([\w.]+)'/",
		);
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $message, $parts ) ) {
				return $this->scrub( $parts[1] );
			}
		}
		return '' !== $model ? $model : self::SYSTEM;
	}

	/**
	 * Words for what a rights error was about: "create sale orders".
	 *
	 * @param string $model  Model.
	 * @param string $method Method.
	 */
	public function action( string $model, string $method ): string {
		$verb  = self::METHOD_VERBS[ $method ] ?? 'use';
		$thing = self::MODEL_WORDS[ $model ] ?? ( '' !== $model ? $model : __( 'this data', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		return $verb . ' ' . $thing;
	}

	/**
	 * Short log text: exception name and scrubbed message.
	 *
	 * @param string $name   Exception name.
	 * @param string $reason Scrubbed message.
	 */
	private function detail( string $name, string $reason ): string {
		return trim( $name . ': ' . $reason, ': ' );
	}

	/**
	 * Build the exception with filled context.
	 *
	 * @param ErrorCode           $code         Catalog code.
	 * @param array<string,mixed> $context      Placeholders.
	 * @param int|null            $status       HTTP status.
	 * @param int|null            $retryAfter   Seconds to wait.
	 * @param string              $detail       Short detail for the log.
	 * @param string              $faultName    Odoo exception name.
	 * @param string              $model        Model.
	 * @param string              $method       Method.
	 * @param ErrorClass|null     $classOverride Class override.
	 * @param bool                $modelMissing Whether a model or method is missing.
	 */
	private function exception( ErrorCode $code, array $context, ?int $status, ?int $retryAfter, string $detail, string $faultName = '', string $model = '', string $method = '', ?ErrorClass $classOverride = null, bool $modelMissing = false ): OdooException {
		$context += array( 'system' => self::SYSTEM );
		$error    = new ConnectorError( $code, $context, $status, $retryAfter, $classOverride, $this->scrub( $detail ) );
		return new OdooException( $error, $faultName, $model, $method, $modelMissing );
	}
}
