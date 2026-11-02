<?php
/**
 * Reads and writes sales documents.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Find a document by the client reference, list its lines, add the lines that are missing, and run the posting actions. Every step starts
 * from a read, so a pass that was cut off can be repeated: lines already there are checked and kept, never added twice.
 */
final class BcDocuments {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient $bc     Client pinned to the company.
	 * @param string   $system System name for messages.
	 */
	public function __construct( private readonly BcClient $bc, private readonly string $system ) {
	}

	/**
	 * The document whose external document number is the reference. The server filters; the rows are compared again.
	 *
	 * @param BcDocType $type Kind of document.
	 * @param string    $ref  WC-123.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( BcDocType $type, string $ref ): ?array {
		$rows = $this->bc->rows( $this->bc->path( $type->collection() ), array( '$filter' => OData::eq( 'externalDocumentNumber', $ref ) ) );
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['externalDocumentNumber'] ?? '' ) === $ref ) );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['number'] ?? '' ), (string) ( $b['number'] ?? '' ) ) );
		return $rows[0] ?? null;
	}

	/**
	 * A posted document (an invoice or a credit memo whose status says posted) with the reference.
	 *
	 * @param BcDocType $type Invoice or credit memo.
	 * @param string    $ref  WC-123.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findPosted( BcDocType $type, string $ref ): ?array {
		$row = $this->find( $type, $ref );
		return null !== $row && $type->isPosted( (string) ( $row['status'] ?? '' ) ) ? $row : null;
	}

	/**
	 * The lines of a document in the order they were written.
	 *
	 * @param BcDocType $type Kind of document.
	 * @param string    $id   Document GUID.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function lines( BcDocType $type, string $id ): array {
		$rows = $this->bc->rows( $this->bc->path( $type->collection(), $id, $type->lines() ) );
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['sequence'] ?? 0 ) <=> (int) ( $b['sequence'] ?? 0 ) );
		return $rows;
	}

	/**
	 * A document by id, or null.
	 *
	 * @param BcDocType $type Kind of document.
	 * @param string    $id   Document GUID.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function header( BcDocType $type, string $id ): ?array {
		return $this->bc->one( $this->bc->path( $type->collection(), $id ) );
	}

	/**
	 * Create the header of a document.
	 *
	 * @param BcDocType           $type Kind of document.
	 * @param array<string,mixed> $body Fields.
	 * @return array<string,mixed> The new header.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( BcDocType $type, array $body ): array {
		return $this->bc->create( $this->bc->path( $type->collection() ), $body );
	}

	/**
	 * Whether the lines of a document end with the completion marker of the reference, and how many lines the marker counts.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines in order.
	 * @param string                         $ref   WC-123.
	 * @return int|null The count when the last line is the marker and it counts the lines before it; otherwise null.
	 */
	public function markerCount( array $lines, string $ref ): ?int {
		if ( array() === $lines ) {
			return null;
		}
		$last  = $lines[ count( $lines ) - 1 ];
		$count = 'Comment' === (string) ( $last['lineType'] ?? '' ) ? BcPlan::markerCount( (string) ( $last['description'] ?? '' ), $ref ) : null;
		return null !== $count && count( $lines ) - 1 === $count ? $count : null;
	}

	/**
	 * Make the document hold the planned lines. Lines already present are checked against the plan and kept, never added twice. The
	 * completion marker is not written here: it is the statement that the document is right, so it is written (see ensureMarker) only after
	 * the total of the document was checked.
	 *
	 * @param BcDocType         $type     Kind of document.
	 * @param string            $id       Document GUID.
	 * @param array<int,BcLine> $plan     Lines to write.
	 * @param string            $ref      WC-123.
	 * @param string            $currency Currency of the document.
	 * @return array{written:int,error:ConnectorError|null}
	 *
	 * @throws ApiException On failure.
	 */
	public function ensureLines( BcDocType $type, string $id, array $plan, string $ref, string $currency ): array {
		$existing = $this->lines( $type, $id );
		$written  = 0;
		foreach ( $plan as $index => $line ) {
			if ( isset( $existing[ $index ] ) ) {
				if ( ! $line->matches( $existing[ $index ], $currency ) ) {
					return array(
						'written' => $written,
						'error'   => $this->changed(),
					);
				}
				continue;
			}
			$this->bc->create( $this->bc->path( $type->collection(), $id, $type->lines() ), $line->body( $currency ) );
			++$written;
		}
		$count = count( $plan );
		if ( isset( $existing[ $count ] ) && ! BcLine::comment( BcPlan::marker( $ref, $count ) )->matches( $existing[ $count ], $currency ) ) {
			return array(
				'written' => $written,
				'error'   => $this->changed(),
			);
		}
		return array(
			'written' => $written,
			'error'   => null,
		);
	}

	/**
	 * Write the completion marker after the last planned line, when it is not there yet. Call it only once the total was checked: a document
	 * with the marker is reported as finished by findExisting() and is never looked at again.
	 *
	 * @param BcDocType $type     Kind of document.
	 * @param string    $id       Document GUID.
	 * @param string    $ref      WC-123.
	 * @param int       $count    Number of planned lines before the marker.
	 * @param string    $currency Currency of the document.
	 * @return bool True when the marker was written now.
	 *
	 * @throws ApiException On failure.
	 */
	public function ensureMarker( BcDocType $type, string $id, string $ref, int $count, string $currency ): bool {
		if ( null !== $this->markerCount( $this->lines( $type, $id ), $ref ) ) {
			return false;
		}
		$this->bc->create( $this->bc->path( $type->collection(), $id, $type->lines() ), BcLine::comment( BcPlan::marker( $ref, $count ) )->body( $currency ) );
		return true;
	}

	/**
	 * Post a draft invoice or credit memo.
	 *
	 * @param BcDocType $type Invoice or credit memo.
	 * @param string    $id   Document GUID.
	 * @return bool False when the document was gone (it may have been posted already).
	 *
	 * @throws ApiException On failure.
	 */
	public function post( BcDocType $type, string $id ): bool {
		return $this->bc->action( $this->bc->path( $type->collection(), $id ), 'post', true );
	}

	/**
	 * Ship and invoice a sales order.
	 *
	 * @param string $id Order GUID.
	 * @return bool False when the order was gone (it may have been invoiced already).
	 *
	 * @throws ApiException On failure.
	 */
	public function shipAndInvoice( string $id ): bool {
		return $this->bc->action( $this->bc->path( BcDocType::Order->collection(), $id ), 'shipAndInvoice', true );
	}

	/**
	 * The refusal for a document whose lines are not what the shop sent.
	 */
	private function changed(): ConnectorError {
		return Failures::invalid(
			$this->system,
			'document',
			__( 'The document in Business Central was changed after it was created: its lines are not the ones the shop sent. Delete the draft in Business Central and try again.', 'estasoft-erp-crm-sync-engine' ),
			'lines differ'
		);
	}
}
