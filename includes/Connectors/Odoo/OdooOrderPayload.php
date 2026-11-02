<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the rules of the "orders" mapping (target paths such as
 * partner.email and order_line[].price_unit, see docs/connectors/odoo.md) into typed values. Money arrives as integer minor
 * units. The client reference always comes from the queue item, never from the payload, so a mapping mistake cannot break
 * the idempotency key.
 */
final class OdooOrderPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $clientRef        WC-{id}.
	 * @param string                         $currency         ISO currency code, or empty.
	 * @param array<string,string>           $partner          Billing customer fields.
	 * @param array<string,string>           $delivery         Delivery address fields.
	 * @param array<int,array<string,mixed>> $lines            Product lines: sku, product_id, name, quantity, price_minor, discount, tax.
	 * @param array<int,array<string,mixed>> $extras           Shipping, fee and discount lines: kind, name, price_minor, tax.
	 * @param string                         $dateOrder        Order date in Odoo format, or empty.
	 * @param string                         $note             Order note as text.
	 * @param string                         $paymentTerm      Payment term name, or empty.
	 * @param string                         $warehouse        Warehouse code, name or id, or empty.
	 * @param bool                           $confirm          Confirm the sale order.
	 * @param string                         $invoice          none, draft or post.
	 * @param bool                           $registerPayment  Register the payment on the invoice.
	 * @param string                         $paymentReference Payment reference (transaction id).
	 * @param bool                           $taxesMapped      True when the mapping supplies tax codes, so an empty code means no tax.
	 * @param int|null                       $totalMinor       Total the shop expects Odoo to show (the order total less the part of products left out), or null when the mapping gives none.
	 * @param bool                           $pricesIncludeTax True when the mapped prices include tax; false (the default) when they do not.
	 */
	private function __construct(
		public readonly string $clientRef,
		public readonly string $currency,
		public readonly array $partner,
		public readonly array $delivery,
		public readonly array $lines,
		public readonly array $extras,
		public readonly string $dateOrder,
		public readonly string $note,
		public readonly string $paymentTerm,
		public readonly string $warehouse,
		public readonly bool $confirm,
		public readonly string $invoice,
		public readonly bool $registerPayment,
		public readonly string $paymentReference,
		public readonly bool $taxesMapped,
		public readonly ?int $totalMinor = null,
		public readonly bool $pricesIncludeTax = false,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item.
	 * @param OdooOptions         $options Connection options (the fallback for the document flow).
	 * @param OdooAmounts         $amounts Conversion helper.
	 * @return self|ConnectorError The payload, or MAP-003 (email missing) or VAL-002 (anything else wrong).
	 */
	public static function parse( array $payload, Item $item, OdooOptions $options, OdooAmounts $amounts ): self|ConnectorError {
		$partner = OdooPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return new ConnectorError(
				ErrorCode::Map003,
				array(
					'order'  => $item->entityId,
					'system' => OdooErrorMapper::SYSTEM,
				),
				null,
				null,
				null,
				'email missing'
			);
		}
		if ( '' === ( $partner['name'] ?? '' ) ) {
			return self::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$taxesMapped = false;
		$lines       = array();
		foreach ( OdooPayloadReader::rows( $payload['order_line'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1 );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$taxesMapped = $taxesMapped || array_key_exists( 'tax', $raw );
			$lines[]     = $line;
		}
		if ( array() === $lines ) {
			return self::refused( 'order_line', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$flow = self::plan( $options, isset( $payload['flow'] ) && is_array( $payload['flow'] ) ? $payload['flow'] : array() );

		$total = OdooPayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) OdooPayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}

		$paymentBlock = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();

		return new self(
			$item->clientReference(),
			strtoupper( OdooPayloadReader::text( $payload['currency'] ?? '' ) ),
			$partner,
			OdooPayloadReader::address( $payload['delivery'] ?? null ),
			$lines,
			self::extras( $payload ),
			$amounts->toOdooDatetime( OdooPayloadReader::text( $payload['date_order'] ?? '' ) ),
			OdooPayloadReader::text( $payload['note'] ?? '' ),
			OdooPayloadReader::text( $payload['payment_term'] ?? '' ),
			OdooPayloadReader::text( $payload['warehouse'] ?? '' ),
			$flow['confirm'],
			$flow['invoice'],
			$flow['register_payment'],
			OdooPayloadReader::text( $paymentBlock['reference'] ?? '' ),
			$taxesMapped,
			$total,
			OdooPayloadReader::flag( $payload['prices_include_tax'] ?? null, false )
		);
	}

	/**
	 * The document steps an order goes through: what the connection's options ask for, changed by the payload's "flow" block.
	 * Without a payload (a lookup that has only the options) pass an empty block.
	 *
	 * @param OdooOptions         $options Connection options.
	 * @param array<string,mixed> $flow    The payload's flow block.
	 * @return array{confirm:bool,invoice:string,register_payment:bool} invoice is none, draft or post.
	 */
	public static function plan( OdooOptions $options, array $flow ): array {
		$documents = $options->choice( 'documents', array( 'order', 'order_invoice', 'order_invoice_payment' ), 'order' );
		$fallback  = 'order' === $documents ? 'none' : $options->choice( 'invoice_state', array( 'draft', 'post' ), 'post' );
		$invoice   = OdooPayloadReader::choice( $flow['invoice'] ?? null, array( 'none', 'draft', 'post' ), $fallback );
		$payment   = OdooPayloadReader::flag( $flow['register_payment'] ?? null, 'order_invoice_payment' === $documents );
		if ( $payment ) {
			$invoice = 'post';
		}
		return array(
			'confirm'          => OdooPayloadReader::flag( $flow['confirm'] ?? null, true ) || 'none' !== $invoice,
			'invoice'          => $invoice,
			'register_payment' => $payment,
		);
	}

	/**
	 * One product line.
	 *
	 * @param array<string,mixed> $raw    Line from the payload.
	 * @param int                 $number Position, for error texts.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function productLine( array $raw, int $number ): array|ConnectorError {
		$sku       = OdooPayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
		$productId = isset( $raw['product_id'] ) && is_numeric( $raw['product_id'] ) ? (int) $raw['product_id'] : 0;
		if ( '' === $sku && $productId < 1 ) {
			return new ConnectorError(
				ErrorCode::Map001,
				array(
					'sku'    => '#' . $number,
					'system' => OdooErrorMapper::SYSTEM,
				),
				null,
				null,
				null,
				'line has no product'
			);
		}
		$quantity = isset( $raw['quantity'] ) && is_numeric( $raw['quantity'] ) ? (float) $raw['quantity'] : 0.0;
		if ( $quantity <= 0.0 ) {
			return self::refused(
				'order_line[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		$price = OdooPayloadReader::minor( $raw['price_unit'] ?? null );
		if ( null === $price ) {
			return self::refused(
				'order_line[].price_unit',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no price in minor units (a whole number such as 21900).', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		return array(
			'sku'         => $sku,
			'product_id'  => $productId,
			'name'        => OdooPayloadReader::text( $raw['name'] ?? '' ),
			'quantity'    => $quantity,
			'price_minor' => $price,
			'discount'    => isset( $raw['discount'] ) && is_numeric( $raw['discount'] ) ? max( 0.0, min( 100.0, (float) $raw['discount'] ) ) : 0.0,
			'tax'         => array_key_exists( 'tax', $raw ) ? OdooPayloadReader::taxCodes( $raw['tax'] ) : null,
		);
	}

	/**
	 * Shipping, fee and discount lines. A zero or unreadable amount adds no line.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extras( array $payload ): array {
		$extras = array();

		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$extra    = self::extra( 'shipping', $shipping, 'total', __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) );
		if ( null !== $extra ) {
			$extras[] = $extra;
		}

		foreach ( OdooPayloadReader::rows( $payload['fee'] ?? null ) as $fee ) {
			$extra = self::extra( 'fee', $fee, 'total', __( 'Fee', 'estasoft-erp-crm-sync-engine' ) );
			if ( null !== $extra ) {
				$extras[] = $extra;
			}
		}

		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$extra    = self::extra( 'discount', $discount, 'total', __( 'Discount', 'estasoft-erp-crm-sync-engine' ) );
		if ( null !== $extra ) {
			$extra['price_minor'] = -abs( (int) $extra['price_minor'] );
			$extras[]             = $extra;
		}
		return $extras;
	}

	/**
	 * One extra line, or null when it has no amount.
	 *
	 * @param string              $kind    shipping, fee or discount.
	 * @param array<string,mixed> $raw     Block from the payload.
	 * @param string              $key     Key of the amount.
	 * @param string              $fallback Name when the block has none.
	 * @return array<string,mixed>|null
	 */
	private static function extra( string $kind, array $raw, string $key, string $fallback ): ?array {
		$amount = OdooPayloadReader::minor( $raw[ $key ] ?? null );
		if ( null === $amount || 0 === $amount ) {
			return null;
		}
		$name = OdooPayloadReader::text( $raw['name'] ?? '' );
		return array(
			'kind'        => $kind,
			'name'        => '' !== $name ? $name : $fallback,
			'price_minor' => $amount,
			'tax'         => array_key_exists( 'tax', $raw ) ? OdooPayloadReader::taxCodes( $raw['tax'] ) : null,
		);
	}

	/**
	 * A rejection as SYNC-VAL-002.
	 *
	 * @param string $field  Field path.
	 * @param string $reason Why.
	 */
	private static function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'payload rejected'
		);
	}
}
