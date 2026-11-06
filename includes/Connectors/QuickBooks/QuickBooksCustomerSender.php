<?php
/**
 * Sends a customer to QuickBooks.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A customer item is a QuickBooks customer found by its email; QuickBooks has no field the shop's reference could be searched by, so the
 * customer item is matched by email only. A customer that already has the email is linked and never changed.
 */
final class QuickBooksCustomerSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksCustomers $customers Customers.
	 */
	public function __construct( private readonly QuickBooksCustomers $customers ) {
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
		$partner = QuickBooksPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( QuickBooksErrors::noEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( QuickBooksErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		$existing = $this->customers->findByEmail( $partner['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->reference( $existing, array() ) );
		}
		$warnings = array();
		$customer = $this->customers->resolve( $partner, QuickBooksPayloadReader::address( $payload['delivery'] ?? null ), $warnings );
		return SendResult::created( $this->reference( $customer, $warnings ) );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $customer Customer.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $customer, array $warnings ): ExternalRef {
		return new ExternalRef(
			QuickBooksPayloadReader::text( $customer['Id'] ?? '' ),
			QuickBooksPayloadReader::text( $customer['DisplayName'] ?? '' ),
			null,
			array() === $warnings ? array() : array( 'warnings' => $warnings )
		);
	}
}
