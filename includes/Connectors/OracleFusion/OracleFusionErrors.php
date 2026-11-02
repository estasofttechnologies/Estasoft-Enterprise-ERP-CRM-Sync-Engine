<?php
/**
 * The Oracle Fusion REST error shapes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The base maps 401, 403 and 5xx. This class adds the wait of a 429 (10 seconds when the answer gives none) and turns a refused value
 * (400, 422 and other client errors) into SYNC-VAL-002 with the field and the system's own words. The body shape (`title`, `detail`,
 * `o:errorDetails[].detail`, `o:errorDetails[].o:errorPath`) is the Oracle REST framework's and was not read on a page; see the
 * documentation file under "Not verified".
 */
final class OracleFusionErrors {

	/**
	 * Wait in seconds when a throttled answer carries no Retry-After.
	 */
	public const DEFAULT_WAIT = 10;

	/**
	 * Longest reason shown.
	 */
	public const REASON_LENGTH = 200;

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $system System name for messages.
	 */
	public function __construct( private readonly string $system ) {
	}

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @return ConnectorError|null Null for the base's default mapping.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$base   = array( 'system' => $this->system );
		if ( 429 === $status ) {
			$wait = $response->retryAfterSeconds() ?? self::DEFAULT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( $status < 400 || $status >= 500 || in_array( $status, array( 401, 403, 404 ), true ) ) {
			return null;
		}
		$found = $this->detail( $response );
		if ( '' === $found['reason'] ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			$base + array(
				'field'  => '' === $found['field'] ? __( 'the request', 'estasoft-erp-crm-sync-engine' ) : $found['field'],
				'reason' => $found['reason'],
			),
			$status,
			null,
			null,
			'status ' . $status
		);
	}

	/**
	 * The first useful detail of an error answer.
	 *
	 * @param ApiResponse $response Response.
	 * @return array{field:string,reason:string}
	 */
	public function detail( ApiResponse $response ): array {
		$json = $response->json();
		foreach ( (array) ( $json['o:errorDetails'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && '' !== trim( (string) ( $detail['detail'] ?? '' ) ) ) {
				return array(
					'field'  => $this->clean( (string) ( $detail['o:errorPath'] ?? '' ), 80 ),
					'reason' => $this->clean( (string) $detail['detail'], self::REASON_LENGTH ),
				);
			}
		}
		$text = trim( (string) ( $json['detail'] ?? '' ) );
		if ( '' === $text ) {
			$text = trim( (string) ( $json['title'] ?? '' ) );
		}
		return array(
			'field'  => '',
			'reason' => $this->clean( $text, self::REASON_LENGTH ),
		);
	}

	/**
	 * One line of plain text of at most a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Most characters.
	 */
	private function clean( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/<[^>]*>/', ' ', $text ) ) );
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . "\u{2026}" : $text;
	}
}
