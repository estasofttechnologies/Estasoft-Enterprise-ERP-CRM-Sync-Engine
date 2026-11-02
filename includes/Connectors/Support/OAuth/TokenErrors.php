<?php
/**
 * Maps the error answers of a token endpoint to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Redaction;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * RFC 6749 section 5.2. What an `invalid_grant` means depends on the step: when a refresh token is refused it ran out or was revoked
 * (SYNC-AUTH-002, reconnect); when a sign-in code is refused the sign-in was not completed (SYNC-AUTH-003); when a signed assertion or a
 * password is refused the key or user is wrong (SYNC-AUTH-001). `invalid_client` is SYNC-AUTH-001, or SYNC-AUTH-005 when the vendor says the
 * secret expired. `access_denied` is SYNC-AUTH-003. Server errors and rate limits are retryable.
 */
final class TokenErrors {

	public const PHASE_CODE    = 'code';
	public const PHASE_REFRESH = 'refresh';
	public const PHASE_GRANT   = 'grant';

	/**
	 * The error for a failed token request.
	 *
	 * @param HttpResponse $response  Response of the token endpoint.
	 * @param string       $phase     One of the PHASE constants.
	 * @param string       $system    System name.
	 * @param Redaction    $redaction Secret remover.
	 * @param string       $today     Date for SYNC-AUTH-005, YYYY-MM-DD.
	 */
	public static function map( HttpResponse $response, string $phase, string $system, Redaction $redaction, string $today = '' ): ConnectorError {
		if ( 0 === $response->status ) {
			return HttpErrorMapper::fromTransport( $response, $system );
		}
		$api  = ApiResponse::fromHttp( $response );
		$base = array( 'system' => $system );
		if ( 429 === $response->status ) {
			$wait = $api->retryAfterSeconds() ?? HttpErrorMapper::DEFAULT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 429, $wait, null, 'token endpoint rate limited' );
		}
		if ( $response->status >= 500 || 408 === $response->status ) {
			return 408 === $response->status
				? new ConnectorError( ErrorCode::Net001, $base, 408, null, null, 'token endpoint timeout' )
				: new ConnectorError( ErrorCode::Net002, $base + array( 'status' => $response->status ), $response->status, $api->retryAfterSeconds(), null, 'token endpoint server error' );
		}
		$json        = $api->json();
		$error       = self::errorCode( $response );
		$description = is_string( $json['error_description'] ?? null ) ? (string) $json['error_description'] : '';
		// An answer without an OAuth error is often a web page: keep its text, not its markup.
		$text   = '' !== $error ? $error . ( '' !== $description ? ': ' . $description : '' ) : trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/<[^>]*>/', ' ', HttpErrorMapper::message( $api ) ) ) );
		$detail = $redaction->reason( $text, 160 );

		// A page that says "not found" or "not allowed" for the token address means the address is wrong, which retrying does not fix: it is
		// reported as a refusal for a person to look at, but it is not an OAuth answer, so it never holds the connection (see holds()).
		if ( '' === $error && in_array( $response->status, array( 404, 405, 410 ), true ) ) {
			return new ConnectorError( ErrorCode::Auth001, $base, $response->status, null, null, $detail );
		}
		// No OAuth error code (a firewall, Cloudflare or proxy page, an empty body) or a server-side OAuth error: the credentials were not
		// judged, so it is a failure that may pass and the queue retries it.
		if ( '' === $error || in_array( $error, array( 'server_error', 'temporarily_unavailable' ), true ) ) {
			return new ConnectorError( ErrorCode::Net002, $base + array( 'status' => $response->status ), $response->status, $api->retryAfterSeconds(), null, $detail );
		}

		$code = match ( true ) {
			'access_denied' === $error                                                  => ErrorCode::Auth003,
			'invalid_grant' === $error && self::PHASE_REFRESH === $phase                => ErrorCode::Auth002,
			'invalid_grant' === $error && self::PHASE_CODE === $phase                   => ErrorCode::Auth003,
			'invalid_client' === $error && 1 === preg_match( '/expired|no longer valid/i', $description ) => ErrorCode::Auth005,
			'invalid_scope' === $error                                                  => ErrorCode::Auth004,
			default                                                                     => ErrorCode::Auth001,
		};
		$context = $base;
		if ( ErrorCode::Auth005 === $code ) {
			$context['date'] = $today;
		}
		if ( ErrorCode::Auth004 === $code ) {
			$context['action'] = __( 'use the access this connection asks for', 'estasoft-erp-crm-sync-engine' );
		}
		return new ConnectorError( $code, $context, $response->status, null, null, $detail );
	}

	/**
	 * Whether the answer is the vendor's own refusal of the credentials: a JSON body whose OAuth `error` is invalid_grant, invalid_client
	 * or unauthorized_client (and access_denied when a sign-in code is traded). Only that may hold the connection for a person.
	 *
	 * @param HttpResponse $response Response of the token endpoint.
	 * @param string       $phase    One of the PHASE constants.
	 */
	public static function holds( HttpResponse $response, string $phase ): bool {
		if ( $response->status < 400 || $response->status >= 500 ) {
			return false;
		}
		$error = self::errorCode( $response );
		return in_array( $error, array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) || ( 'access_denied' === $error && self::PHASE_CODE === $phase );
	}

	/**
	 * The OAuth `error` of a JSON answer, lower case, or an empty text when the answer has none (an HTML page, an empty body).
	 *
	 * @param HttpResponse $response Response.
	 */
	private static function errorCode( HttpResponse $response ): string {
		$json = ApiResponse::fromHttp( $response )->json();
		return is_array( $json ) && is_string( $json['error'] ?? null ) ? strtolower( trim( (string) $json['error'] ) ) : '';
	}
}
