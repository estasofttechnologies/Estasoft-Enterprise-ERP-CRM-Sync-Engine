<?php
/**
 * Finds sale orders and credit notes by the WooCommerce client reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The client reference WC-{id} is the connector's idempotency key. A sale order carries it in client_order_ref; a credit note
 * carries it at the start of ref. Odoo does not enforce that the value is unique, so the oldest record wins when there are several.
 */
final class OdooOrderFinder {

	/**
	 * Fields read from a sale order.
	 *
	 * @var array<int,string>
	 */
	public const ORDER_FIELDS = array( 'name', 'state', 'amount_total', 'currency_id', 'partner_id', 'client_order_ref', 'invoice_ids', 'date_order' );

	/**
	 * Fields read from a credit note.
	 *
	 * @var array<int,string>
	 */
	public const MOVE_FIELDS = array( 'name', 'state', 'amount_total', 'currency_id', 'partner_id', 'ref', 'move_type', 'reversed_entry_id' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooAmounts $amounts ) {
	}

	/**
	 * The sale order for a client reference.
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array<string,mixed>|null The order record, or null.
	 *
	 * @throws OdooException On failure.
	 */
	public function order( string $clientRef ): ?array {
		$domain  = array_merge( array( array( 'client_order_ref', '=', $clientRef ) ), $this->client->companyDomain() );
		$records = $this->client->searchRead(
			'sale.order',
			$domain,
			self::ORDER_FIELDS,
			array(
				'order' => 'id asc',
				'limit' => 5,
			)
		);
		return $records[0] ?? null;
	}

	/**
	 * The credit note for a client reference. The reference may be followed by a space and the refund reason.
	 *
	 * @param string $clientRef WC-{refund id}.
	 * @return array<string,mixed>|null The credit note record, or null.
	 *
	 * @throws OdooException On failure.
	 */
	public function creditNote( string $clientRef ): ?array {
		$domain  = array_merge(
			array(
				array( 'move_type', '=', 'out_refund' ),
				'|',
				array( 'ref', '=', $clientRef ),
				array( 'ref', '=like', $this->escapeLike( $clientRef ) . ' %' ),
			),
			$this->client->companyDomain()
		);
		$records = $this->client->searchRead(
			'account.move',
			$domain,
			self::MOVE_FIELDS,
			array(
				'order' => 'id asc',
				'limit' => 5,
			)
		);
		return $records[0] ?? null;
	}

	/**
	 * A reference to a sale order record.
	 *
	 * @param array<string,mixed> $record Sale order record read with ORDER_FIELDS.
	 */
	public function orderRef( array $record ): ExternalRef {
		$id       = OdooRecord::id( $record['id'] ?? 0 );
		$currency = strtoupper( OdooRecord::name( $record['currency_id'] ?? false ) );
		return new ExternalRef(
			(string) $id,
			OdooRecord::text( $record['name'] ?? '' ),
			$this->client->recordUrl( 'sale.order', $id ),
			array(
				'total_minor' => $this->amounts->toMinor( $record['amount_total'] ?? 0, $this->amounts->exponent( $currency ) ),
				'currency'    => $currency,
				'state'       => OdooRecord::text( $record['state'] ?? '' ),
				'partner_id'  => OdooRecord::id( $record['partner_id'] ?? 0 ),
				'invoice_ids' => OdooRecord::ids( $record['invoice_ids'] ?? array() ),
			)
		);
	}

	/**
	 * A reference to a credit note record.
	 *
	 * @param array<string,mixed> $record Account move record read with MOVE_FIELDS.
	 */
	public function creditNoteRef( array $record ): ExternalRef {
		$id       = OdooRecord::id( $record['id'] ?? 0 );
		$currency = strtoupper( OdooRecord::name( $record['currency_id'] ?? false ) );
		$name     = OdooRecord::text( $record['name'] ?? '' );
		return new ExternalRef(
			(string) $id,
			'/' === $name ? OdooRecord::text( $record['ref'] ?? '' ) : $name,
			$this->client->recordUrl( 'account.move', $id ),
			array(
				'total_minor' => $this->amounts->toMinor( $record['amount_total'] ?? 0, $this->amounts->exponent( $currency ) ),
				'currency'    => $currency,
				'state'       => OdooRecord::text( $record['state'] ?? '' ),
				'document'    => 'credit_note',
			)
		);
	}

	/**
	 * Escape the characters that LIKE treats as wildcards.
	 *
	 * @param string $value Value.
	 */
	private function escapeLike( string $value ): string {
		return str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $value );
	}
}
