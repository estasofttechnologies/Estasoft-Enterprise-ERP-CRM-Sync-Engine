<?php
/**
 * Sends a customer to Xero as a contact.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A customer item is a contact with the shop's reference (WC-{customer id}) as its contact number. A contact that already has the email is
 * linked and never changed.
 */
final class XeroCustomerSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param XeroContacts $contacts Contacts.
	 */
	public function __construct( private readonly XeroContacts $contacts ) {
	}

	/**
	 * The contact of a customer, found by its contact number.
	 *
	 * @param Item $item The queue item.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ?ExternalRef {
		$contact = $this->contacts->findByNumber( $item->clientReference() );
		return null === $contact ? null : $this->reference( $contact );
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
		$partner = XeroPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( XeroErrors::noEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( XeroErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		$existing = $this->contacts->findByNumber( $item->clientReference() ) ?? $this->contacts->findByEmail( $partner['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->reference( $existing ) );
		}
		$warnings = array();
		$contact  = $this->contacts->create( $partner, XeroPayloadReader::address( $payload['delivery'] ?? null ), $item->clientReference(), $warnings );
		$ref      = $this->reference( $contact );
		return SendResult::created( array() === $warnings ? $ref : new ExternalRef( $ref->id, $ref->ref, null, array( 'warnings' => $warnings ) ) );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $contact Contact.
	 */
	private function reference( array $contact ): ExternalRef {
		return new ExternalRef( XeroPayloadReader::text( $contact['ContactID'] ?? '' ), XeroPayloadReader::text( $contact['Name'] ?? '' ) );
	}
}
