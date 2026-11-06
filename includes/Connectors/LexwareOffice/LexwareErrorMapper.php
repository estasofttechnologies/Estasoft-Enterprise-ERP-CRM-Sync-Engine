<?php
/**
 * Lexware Office error answers, mapped to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Lexware answers a rejected document with HTTP 406 and a body that lists the problems (an `IssueList` with stable `i18nKey` values, or the
 * regular error body with `message` and `details`) [third-party, docs/connectors/lexware-office.md]. 402 is the one status in the documented
 * error list that fits a licence block. 409 is a `version` conflict and is tried again after a fresh read. Everything else keeps the default
 * mapping of the base (401, 403, 429 without Retry-After, 5xx). Only short sanitised reasons are kept: keys and field names, never the body.
 */
final class LexwareErrorMapper {

	/**
	 * Map an answer, or return null to let the base map it.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 402 === $status ) {
			return new ConnectorError( ErrorCode::Auth006, array( 'system' => LexwareOfficeConnector::LABEL ), 402, null, null, 'payment required' );
		}
		if ( 409 === $status ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => LexwareOfficeConnector::LABEL,
					'status' => 409,
				),
				409,
				null,
				null,
				'version conflict'
			);
		}
		if ( 406 === $status ) {
			$issues = $this->issues( $response );
			$joined = implode( ', ', $issues );
			if ( 1 === preg_match( '/taxRatePercentage/i', $joined ) ) {
				return new ConnectorError(
					ErrorCode::Map002,
					array(
						'country' => 'tax',
						'rate'    => '',
						'system'  => LexwareOfficeConnector::LABEL,
					),
					406,
					null,
					null,
					'tax rate refused'
				);
			}
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => LexwareOfficeConnector::LABEL,
					'field'  => __( 'the document', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => '' !== $joined ? $joined : __( 'Lexware Office did not accept the document.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				406,
				null,
				null,
				'validation failed'
			);
		}
		return null;
	}

	/**
	 * Short keys of the problems in an answer: the `i18nKey` of each `IssueList` entry, or the field and message of each `details` entry.
	 *
	 * @param ApiResponse $response Response.
	 * @return array<int,string>
	 */
	public function issues( ApiResponse $response ): array {
		$json = $response->json();
		$out  = array();
		foreach ( (array) ( $json['IssueList'] ?? array() ) as $issue ) {
			if ( is_array( $issue ) ) {
				$key   = (string) ( $issue['i18nKey'] ?? $issue['type'] ?? '' );
				$where = (string) ( $issue['source'] ?? '' );
				$out[] = trim( $key . ( '' !== $where ? ' (' . $where . ')' : '' ) );
			}
		}
		foreach ( (array) ( $json['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) ) {
				$field = (string) ( $detail['field'] ?? '' );
				$text  = (string) ( $detail['message'] ?? $detail['violation'] ?? '' );
				$out[] = trim( $field . ( '' !== $text ? ': ' . $text : '' ) );
			}
		}
		if ( array() === $out && isset( $json['message'] ) && is_string( $json['message'] ) ) {
			$out[] = $json['message'];
		}
		$out = array_values( array_filter( array_map( static fn( string $text ): string => mb_substr( $text, 0, 80 ), $out ) ) );
		return array_slice( $out, 0, 4 );
	}
}
