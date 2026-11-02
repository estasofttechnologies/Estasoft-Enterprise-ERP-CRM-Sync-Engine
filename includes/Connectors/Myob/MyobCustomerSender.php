<?php
/**
 * Sends a customer to MYOB as a customer card.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A customer item is a MYOB customer card found by the email of one of its addresses; MYOB has no field the shop's reference could be searched by,
 * so the item is matched by email only. A card that already has the email is linked and never changed.
 */
final class MyobCustomerSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobCustomers $customers Customers.
	 */
	public function __construct( private readonly MyobCustomers $customers ) {
	}

	/**
	 * Send the customer.
	 *
	 * @param Item                $item    The queue item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$partner = MyobPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( MyobErrors::noEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( MyobErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$existing = $this->customers->findByEmail( $partner['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->reference( $existing, array() ) );
		}
		$warnings = array();
		$customer = $this->customers->resolve( $partner, MyobPayloadReader::address( $payload['delivery'] ?? null ), $warnings );
		return SendResult::created( $this->reference( $customer, $warnings ) );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $customer Customer card.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $customer, array $warnings ): ExternalRef {
		$name = MyobPayloadReader::text( $customer['CompanyName'] ?? '' );
		if ( '' === $name ) {
			$name = trim( MyobPayloadReader::text( $customer['FirstName'] ?? '' ) . ' ' . MyobPayloadReader::text( $customer['LastName'] ?? '' ) );
		}
		return new ExternalRef(
			MyobPayloadReader::text( $customer['UID'] ?? '' ),
			'' !== MyobPayloadReader::text( $customer['DisplayID'] ?? '' ) ? MyobPayloadReader::text( $customer['DisplayID'] ) : $name,
			null,
			array() === $warnings ? array( 'name' => $name ) : array(
				'name'     => $name,
				'warnings' => $warnings,
			)
		);
	}
}
