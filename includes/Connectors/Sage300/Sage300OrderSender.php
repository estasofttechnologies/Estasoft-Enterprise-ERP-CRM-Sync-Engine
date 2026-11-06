<?php
/**
 * Sends a WooCommerce order to Sage 300 as an Order Entry order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\Text;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Every order goes on one customer account (or on the customer found by the buyer's email, when the policy says so). The order is found
 * by its PO number before it is created; an order that exists is read back and its total checked again.
 */
final class Sage300OrderSender {

	/**
	 * Longest PO number the property is assumed to take.
	 */
	private const REF_MAX = 22;

	/**
	 * Names tried for the total of an order in the answer, after the option `total_property`.
	 *
	 * @var array<int,string>
	 */
	private const TOTALS = array( 'OrderTotal', 'TotalOrderAmount', 'OrderTotalAmount' );

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage300Client   $client   Client.
	 * @param Sage300Metadata $metadata Property names of the server.
	 * @param Sage300Lookups  $lookups  Setup lookups.
	 * @param Options         $options  Connection options.
	 * @param Amounts         $amounts  Money helper.
	 * @param string          $system   System name.
	 */
	public function __construct(
		private readonly Sage300Client $client,
		private readonly Sage300Metadata $metadata,
		private readonly Sage300Lookups $lookups,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly string $system
	) {
	}

	/**
	 * The property that holds the shop's order reference: the option, else PONumber, else Reference.
	 *
	 * @return string|ConnectorError The property name, or SYNC-VAL-002 when the server's order has none of them.
	 *
	 * @throws ApiException On failure.
	 */
	public function referenceProperty(): string|ConnectorError {
		$option = $this->options->text( 'reference_property' );
		if ( '' !== $option ) {
			return $option;
		}
		if ( null === $this->metadata->entity( 'OE', 'OEOrders' ) ) {
			return 'PONumber';
		}
		$found = $this->metadata->firstOf( 'OE', 'OEOrders', array( 'PONumber', 'Reference' ) );
		return '' !== $found ? $found : Problems::refused( $this->system, 'reference_property', __( 'The Order Entry order of this Sage 300 has no PO number or Reference property. Enter the property that can hold the shop order number in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * The record of an order that exists; null otherwise.
	 *
	 * @param Item $item The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$order = $this->find( $item->clientReference() );
		return null === $order ? null : $this->reference( $order );
	}

	/**
	 * The order of a client reference (the first).
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		$property = $this->referenceProperty();
		if ( $property instanceof ConnectorError ) {
			return null;
		}
		foreach ( $this->client->rows( 'OE/OEOrders', $property . ' eq ' . Sage300Client::literal( $clientRef ), 10 ) as $row ) {
			if ( strtolower( trim( (string) ( $row[ $property ] ?? '' ) ) ) === strtolower( $clientRef ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Send an order.
	 *
	 * @param Item         $item    The queue item.
	 * @param OrderPayload $payload The order.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, OrderPayload $payload ): SendResult {
		unset( $item );
		$existing = $this->find( $payload->clientRef );
		$warnings = array();
		$currency = $payload->currency;
		if ( null === $existing ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$order = $created;
		} else {
			$order = $existing;
		}
		$problem = $this->verify( $order, $payload, $warnings );
		if ( $problem instanceof ConnectorError ) {
			return SendResult::failure( $problem );
		}
		$ref = $this->reference( $order, $currency, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The record for an order.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param string              $currency ISO code of the order, or empty when unknown.
	 * @param array<int,string>   $warnings Warnings to carry.
	 */
	public function reference( array $order, string $currency = '', array $warnings = array() ): ExternalRef {
		$number = isset( $order['OrderNumber'] ) && is_scalar( $order['OrderNumber'] ) ? trim( (string) $order['OrderNumber'] ) : '';
		$id     = isset( $order['OrderUniquifier'] ) && is_scalar( $order['OrderUniquifier'] ) && '' !== (string) $order['OrderUniquifier'] ? (string) $order['OrderUniquifier'] : $number;
		$extra  = array( 'currency' => $currency );
		$total  = $this->totalOf( $order );
		if ( null !== $total ) {
			$extra['total_minor'] = $this->amounts->toMinor( $total, $this->amounts->exponent( $currency ) );
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $id, '' !== $number ? $number : $id, null, $extra );
	}

	/**
	 * The total of an order record, or null when the server's answer has none of the known properties.
	 *
	 * @param array<string,mixed> $order Order record.
	 */
	public function totalOf( array $order ): ?float {
		$names = '' !== $this->options->text( 'total_property' ) ? array_merge( array( $this->options->text( 'total_property' ) ), self::TOTALS ) : self::TOTALS;
		foreach ( $names as $name ) {
			if ( isset( $order[ $name ] ) && is_numeric( $order[ $name ] ) ) {
				return (float) $order[ $name ];
			}
		}
		return null;
	}

	/**
	 * Check, then create the order.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( OrderPayload $payload ): array|ConnectorError {
		$property = $this->referenceProperty();
		if ( $property instanceof ConnectorError ) {
			return $property;
		}
		$problem = $this->precheck( $payload );
		if ( $problem instanceof ConnectorError ) {
			return $problem;
		}
		$customer = $this->customer( $payload );
		if ( $customer instanceof ConnectorError ) {
			return $customer;
		}
		if ( '' !== $customer['currency'] && $customer['currency'] !== $payload->currency ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: currency of the shop order, 2: customer number, 3: currency of that customer. */
					__( 'The order is in %1$s but customer %2$s in Sage 300 is in %3$s, and Sage 300 orders follow the customer\'s currency.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$payload->currency,
					$customer['id'],
					$customer['currency']
				)
			);
		}
		$codes = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		$items = $this->lookups->items( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $items[ strtolower( $code ) ] ) ) {
				return Problems::missingProduct( $this->system, $code );
			}
		}
		$group = $this->taxGroup( $payload );
		if ( $group instanceof ConnectorError ) {
			return $group;
		}
		if ( ! $this->hasUnitPrice() ) {
			return Problems::refused( $this->system, 'order_line[].price_unit', __( 'The Order Entry order lines of this Sage 300 have no UnitPrice property, so the shop price cannot be sent.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$exponent = $this->amounts->exponent( $payload->currency );
		$location = $this->options->text( 'location' );
		$details  = array();
		foreach ( $payload->lines as $line ) {
			$price = $this->discounted( (int) $line['price_minor'], (float) $line['discount'] );
			$row   = array(
				'LineType'        => 'Item',
				'Item'            => $items[ strtolower( (string) $line['sku'] ) ]['id'],
				'QuantityOrdered' => (float) $line['quantity'],
				'UnitPrice'       => $this->amounts->toDecimal( $price, $exponent ),
			);
			if ( '' !== $location ) {
				$row = array_slice( $row, 0, 2, true ) + array( 'Location' => $location ) + array_slice( $row, 2, null, true );
			}
			$details[] = $row;
		}
		foreach ( $payload->extras as $extra ) {
			$sign      = 'discount' === $extra['kind'] ? -1 : 1;
			$details[] = array(
				'LineType'                 => 'Miscellaneous',
				'MiscellaneousChargesCode' => $this->options->text( $this->chargeOption( $extra ) ),
				'ExtendedAmount'           => $sign * $this->amounts->toDecimal( (int) $extra['amount_minor'], $exponent ),
			);
		}

		$body = array(
			'CustomerNumber' => $customer['id'],
			'OrderDate'      => '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ),
			$property        => $payload->clientRef,
		);
		if ( '' !== $payload->note ) {
			$body['OrderDescription'] = Text::fit( $payload->note, 60 );
		}
		if ( '' !== $group ) {
			$body['TaxGroup'] = $group;
		}
		$body['OrderDetails'] = $details;

		$stored = $this->client->create( 'OE/OEOrders', $body );
		if ( ! isset( $stored[ $property ] ) && ! isset( $stored['OrderNumber'] ) && ! isset( $stored['OrderUniquifier'] ) ) {
			$found = $this->find( $payload->clientRef );
			if ( null === $found ) {
				return Problems::refused( $this->system, 'OEOrders', __( 'Sage 300 did not return the new order.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$stored = $found;
		}
		return $stored;
	}

	/**
	 * Everything that can be refused without a request.
	 *
	 * @param OrderPayload $payload The order.
	 */
	private function precheck( OrderPayload $payload ): ?ConnectorError {
		if ( '' === $payload->currency ) {
			return Problems::refused( $this->system, 'currency', __( 'The order has no currency.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( Text::exceeds( $payload->clientRef, self::REF_MAX ) ) {
			return Problems::refused( $this->system, 'client_ref', __( 'The order reference is longer than the PO number of Sage 300 takes.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( $payload->pricesIncludeTax ) {
			return Problems::refused(
				$this->system,
				'prices_include_tax',
				__( 'Sage 300 works out tax itself from the tax group and this connector sends prices without tax. Set the shop to show prices without tax in the mapping.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		foreach ( $payload->extras as $extra ) {
			if ( '' === $this->options->text( $this->chargeOption( $extra ) ) ) {
				$option = $this->chargeOption( $extra );
				return Problems::refused(
					$this->system,
					$option,
					sprintf(
						/* translators: %s: option name. */
						__( 'The order has a shipping, fee or discount amount, so the connection settings need the miscellaneous charge code in "%s".', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$option
					)
				);
			}
		}
		return null;
	}

	/**
	 * The option that holds the charge code of an extra row.
	 *
	 * @param array<string,mixed> $extra Extra row.
	 */
	private function chargeOption( array $extra ): string {
		return match ( $extra['kind'] ) {
			'shipping' => 'shipping_charge_code',
			'discount' => 'discount_charge_code',
			default    => 'fee_charge_code',
		};
	}

	/**
	 * Whether the order lines have a UnitPrice property (true when the server does not describe itself).
	 *
	 * @throws ApiException On failure.
	 */
	private function hasUnitPrice(): bool {
		return null === $this->metadata->entity( 'OE', 'OEOrders' ) || $this->metadata->has( 'OE', 'OEOrders', 'UnitPrice', 'OrderDetails' );
	}

	/**
	 * The customer the order goes on.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array{id:string,name:string,currency:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function customer( OrderPayload $payload ): array|ConnectorError {
		if ( 'match_only' === $this->options->choice( 'customer_policy', array( 'fixed', 'match_only' ), 'fixed' ) ) {
			return $this->byEmail( $payload->partner['email'] ?? '' );
		}
		$number = $this->options->text( 'customer_number' );
		if ( '' === $number ) {
			return Problems::refused( $this->system, 'customer_number', __( 'Enter the customer number that should receive the web orders in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$customer = $this->lookups->customer( $number );
		return $customer ?? Problems::refused( $this->system, 'customer_number', __( 'Sage 300 has no customer with the number given in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * The customer whose email property holds the buyer's email.
	 *
	 * @param string $email Email address.
	 * @return array{id:string,name:string,currency:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function byEmail( string $email ): array|ConnectorError {
		$property = $this->metadata->containing( 'AR', 'ARCustomers', 'Email' );
		if ( '' === $property ) {
			return Problems::refused( $this->system, 'customer_policy', __( 'The customers of this Sage 300 have no email property, so buyers cannot be matched. Use the fixed customer setting.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$row = '' === trim( $email ) ? null : $this->client->first( 'AR/ARCustomers', $property . ' eq ' . Sage300Client::literal( trim( $email ) ) );
		if ( null === $row || ! isset( $row['CustomerNumber'] ) || ! is_scalar( $row['CustomerNumber'] ) ) {
			return Problems::refused(
				$this->system,
				'partner.email',
				__( 'No customer in Sage 300 has this email address and the customer setting is "match only". Create the customer there or use the fixed customer setting.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		return array(
			'id'       => trim( (string) $row['CustomerNumber'] ),
			'name'     => isset( $row['CustomerName'] ) && is_scalar( $row['CustomerName'] ) ? trim( (string) $row['CustomerName'] ) : '',
			'currency' => isset( $row['CurrencyCode'] ) && is_scalar( $row['CurrencyCode'] ) ? strtoupper( trim( (string) $row['CurrencyCode'] ) ) : '',
		);
	}

	/**
	 * The one tax group of the order, or an empty string when the order names none.
	 *
	 * @param OrderPayload $payload The order.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function taxGroup( OrderPayload $payload ): string|ConnectorError {
		$codes = array();
		$none  = false;
		foreach ( array_merge( $payload->lines, $payload->extras ) as $row ) {
			if ( 'discount' === ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$list = $row['tax'] ?? null;
			if ( ! is_array( $list ) || array() === $list ) {
				$none = true;
				continue;
			}
			foreach ( $list as $code ) {
				$codes[ (string) $code ] = true;
			}
		}
		if ( count( $codes ) > 1 || ( 1 === count( $codes ) && $none && $payload->taxesMapped ) ) {
			return Problems::refused( $this->system, 'order_line[].tax', __( 'Sage 300 applies one tax group to a whole order. This order has lines with different tax groups, or with and without one.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( array() === $codes ) {
			return '';
		}
		$code   = (string) array_key_first( $codes );
		$groups = $this->lookups->taxGroups();
		if ( ! isset( $groups[ strtolower( $code ) ] ) ) {
			return Problems::missingTax( $this->system, $code );
		}
		if ( null !== $this->metadata->entity( 'OE', 'OEOrders' ) && ! $this->metadata->has( 'OE', 'OEOrders', 'TaxGroup' ) ) {
			return Problems::refused( $this->system, 'order_line[].tax', __( 'The Order Entry order of this Sage 300 has no TaxGroup property, so a tax group cannot be sent. Leave the tax mapping empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $groups[ strtolower( $code ) ]['id'];
	}

	/**
	 * Check the total of a stored order.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param OrderPayload        $payload  The order.
	 * @param array<int,string>   $warnings Receives warnings.
	 */
	private function verify( array $order, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		$total = $this->totalOf( $order );
		if ( null === $total ) {
			$number = trim( (string) ( $order['OrderNumber'] ?? $order['OrderUniquifier'] ?? '' ) );
			if ( ( new TotalCheck( $this->options, $this->amounts, $this->system ) )->isStrict() ) {
				return Problems::refused(
					$this->system,
					'total_property',
					sprintf(
						/* translators: %s: order number. */
						__( 'Order %s was made but its answer has no total (OrderTotal, TotalOrderAmount or OrderTotalAmount), so it could not be compared with the shop; price lists in Sage 300 can change the prices unnoticed. The order stays in Sage 300. Enter the property that holds the total in "total_property", or choose "Continue with a warning".', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'' !== $number ? $number : $payload->clientRef
					),
					'total missing'
				);
			}
			$warnings[] = __( 'Sage 300 did not return an order total, so the total was not verified.', 'estasoft-enterprise-erpcrm-sync-engine' );
			return null;
		}
		$number = trim( (string) ( $order['OrderNumber'] ?? $order['OrderUniquifier'] ?? '' ) );
		$check  = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff   = $check->difference( $payload->totalMinor, $total, $payload->currency, $number );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The order stays in Sage 300. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}

	/**
	 * A unit price reduced by a line discount in percent, in whole numbers.
	 *
	 * @param int   $minor    Price in minor units.
	 * @param float $discount Percent (0 to 100).
	 */
	private function discounted( int $minor, float $discount ): int {
		if ( $discount <= 0.0 ) {
			return $minor;
		}
		$per = (int) round( $discount * 100 );
		$abs = abs( $minor );
		$net = intdiv( $abs * ( 10000 - $per ) + 5000, 10000 );
		return $minor < 0 ? -$net : $net;
	}
}
