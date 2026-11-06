<?php
/**
 * Sends a customer to Sage as a customer contact.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A customer item is a Sage contact found by its email (or its reference, which the connector sets to the email); Sage has no field the shop's reference could be searched
 * by, so the item is matched by email only. A contact that already has the email is linked and never changed.
 */
final class SageCustomerSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageContacts $contacts     Contacts.
	 * @param int          $connectionId Connection id, for idempotency ids.
	 */
	public function __construct( private readonly SageContacts $contacts, private readonly int $connectionId ) {
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
		$partner = SagePayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( SageErrors::noEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( SageErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		$existing = $this->contacts->findByEmail( $partner['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->reference( $existing, array() ) );
		}
		$warnings = array();
		$contact  = $this->contacts->resolve( $partner, SagePayloadReader::address( $payload['delivery'] ?? null ), md5( 'customer|' . $this->connectionId . '|' . $partner['email'] ), $warnings );
		return SendResult::created( $this->reference( $contact, $warnings ) );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $contact  Contact.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $contact, array $warnings ): ExternalRef {
		$name = SagePayloadReader::text( $contact['name'] ?? ( $contact['displayed_as'] ?? '' ) );
		return new ExternalRef(
			SagePayloadReader::text( $contact['id'] ?? '' ),
			'' !== SagePayloadReader::text( $contact['reference'] ?? '' ) ? SagePayloadReader::text( $contact['reference'] ) : $name,
			null,
			array() === $warnings ? array( 'name' => $name ) : array(
				'name'     => $name,
				'warnings' => $warnings,
			)
		);
	}
}
