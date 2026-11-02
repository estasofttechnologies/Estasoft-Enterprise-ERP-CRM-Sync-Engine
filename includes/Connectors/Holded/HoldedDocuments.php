<?php
/**
 * Holded sales documents: search, read, create, delete.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The invoicing API keeps every sales document under `documents/{type}`: `invoice`, `salesorder` and `creditnote` are the three the connector
 * writes [third-party]. There is no search by reference, so a document is found by listing a window of days (`starttmp`, `endtmp`) and looking
 * for the bracketed reference in the notes. When the list carries no notes at all, each document of the window is read on its own, up to a cap.
 */
final class HoldedDocuments {

	/**
	 * Document types the connector may address.
	 */
	public const TYPES = array( 'invoice', 'salesorder', 'creditnote' );

	/**
	 * Most documents read one by one when the list carries no notes.
	 */
	public const READ_CAP = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedPager $pager Reads every page of a list.
	 * @param HoldedIds   $ids   Markers.
	 */
	public function __construct( private readonly HoldedPager $pager, private readonly HoldedIds $ids ) {
	}

	/**
	 * The document that carries a reference, among those dated inside a window.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $type      Document type.
	 * @param string    $reference Reference such as WC-10490.
	 * @param int       $from      First second of the window (Unix time).
	 * @param int       $to        Last second of the window (Unix time).
	 * @return HoldedRecord|null The one with the lowest id when several carry it.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $type, string $reference, int $from, int $to ): ?HoldedRecord {
		$marker  = $this->ids->marker( $reference );
		$rows    = $this->pager->all(
			$api,
			$this->path( $type ),
			array(
				'starttmp' => $from,
				'endtmp'   => $to,
			)
		);
		$carries = false;
		foreach ( $rows as $row ) {
			if ( array_key_exists( 'notes', $row ) || array_key_exists( 'desc', $row ) ) {
				$carries = true;
				break;
			}
		}
		$hits = array();
		if ( $carries || array() === $rows ) {
			foreach ( $rows as $row ) {
				$record = new HoldedRecord( $row );
				if ( $record->hasMarker( $marker ) ) {
					$hits[] = $record;
				}
			}
		} else {
			if ( count( $rows ) > self::READ_CAP ) {
				throw new ApiException( $this->tooMany( $type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			foreach ( $rows as $row ) {
				$id = HoldedPayloadReader::text( $row['id'] ?? '' );
				if ( '' === $id ) {
					continue;
				}
				$record = $this->get( $api, $type, $id );
				if ( $record->hasMarker( $marker ) ) {
					$hits[] = $record;
				}
			}
		}
		if ( array() === $hits ) {
			return null;
		}
		usort( $hits, static fn( HoldedRecord $left, HoldedRecord $right ): int => strcmp( $left->id(), $right->id() ) );
		return $hits[0];
	}

	/**
	 * One document.
	 *
	 * @param ApiClient $api  Client.
	 * @param string    $type Document type.
	 * @param string    $id   Document id.
	 *
	 * @throws ApiException On failure.
	 */
	public function get( ApiClient $api, string $type, string $id ): HoldedRecord {
		$json = $api->get( $this->path( $type ) . '/' . rawurlencode( $id ) )->json();
		$this->pager->guard( $json );
		return new HoldedRecord( ( array_is_list( $json ) ? array() : $json ) + array( 'id' => $id ) );
	}

	/**
	 * One document, or null when Holded does not have it.
	 *
	 * @param ApiClient $api  Client.
	 * @param string    $type Document type.
	 * @param string    $id   Document id.
	 *
	 * @throws ApiException On failure other than a missing document.
	 */
	public function getIfExists( ApiClient $api, string $type, string $id ): ?HoldedRecord {
		$response = $api->get( $this->path( $type ) . '/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		if ( ! $response->isSuccess() ) {
			return null;
		}
		$json = $response->json();
		$this->pager->guard( $json );
		return new HoldedRecord( ( array_is_list( $json ) ? array() : $json ) + array( 'id' => $id ) );
	}

	/**
	 * Create a document.
	 *
	 * @param ApiClient           $api  Client.
	 * @param string              $type Document type.
	 * @param array<string,mixed> $body Document fields and items.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( ApiClient $api, string $type, array $body ): string|ConnectorError {
		$json = $api->post( $this->path( $type ), array(), $body )->json();
		$this->pager->guard( $json );
		$id = HoldedPayloadReader::text( $json['id'] ?? '' );
		if ( '' === $id ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => HoldedConnector::LABEL,
					'field'  => $type,
					'reason' => __( 'Holded did not return the id of the new document.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'document without id'
			);
		}
		return $id;
	}

	/**
	 * Delete a document.
	 *
	 * @param ApiClient $api  Client.
	 * @param string    $type Document type.
	 * @param string    $id   Document id.
	 *
	 * @throws ApiException On failure.
	 */
	public function delete( ApiClient $api, string $type, string $id ): void {
		$json = $api->delete( $this->path( $type ) . '/' . rawurlencode( $id ) )->json();
		$this->pager->guard( $json );
	}

	/**
	 * The path of a document type. A type outside the list is a programming mistake and falls back to the invoice path.
	 *
	 * @param string $type Document type.
	 */
	private function path( string $type ): string {
		return 'invoicing/v1/documents/' . ( in_array( $type, self::TYPES, true ) ? $type : 'invoice' );
	}

	/**
	 * SYNC-VAL-002 for a window with too many documents to read one by one.
	 *
	 * @param string $type Document type.
	 */
	private function tooMany( string $type ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => HoldedConnector::LABEL,
				'field'  => $type,
				'reason' => __( 'Holded lists documents without their notes and too many documents are dated around this one to check each of them.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'window too large'
		);
	}
}
