<?php
/**
 * Finds or creates Odoo customers (res.partner).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A customer is found by email, ignoring case, and never duplicated for a repeat buyer. What happens when nobody matches or
 * several match is set by the options customer_policy and duplicate_customer. An existing customer is not changed by an order;
 * only the customer sync (send of a customer) updates it.
 */
final class OdooPartners {

	/**
	 * Text fields copied to a partner as they are.
	 *
	 * @var array<int,string>
	 */
	private const TEXT_FIELDS = array( 'name', 'phone', 'street', 'street2', 'city', 'zip', 'vat', 'company_name' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooCatalog $catalog Lookups.
	 * @param OdooOptions $options Connection options.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooCatalog $catalog, private readonly OdooOptions $options ) {
	}

	/**
	 * Customers with an email, oldest first. The comparison ignores case; Odoo's pattern match is only a first filter.
	 *
	 * @param string $email Email address.
	 * @return array<int,array<string,mixed>> Partner records (id, name, email, parent_id, phone, vat).
	 *
	 * @throws OdooException On failure.
	 */
	public function findByEmail( string $email ): array {
		$email = $this->normalizeEmail( $email );
		if ( '' === $email ) {
			return array();
		}
		$records = $this->client->searchRead(
			'res.partner',
			array_merge( array( array( 'email', '=ilike', $email ) ), $this->client->companyDomain() ),
			array( 'name', 'email', 'parent_id', 'phone', 'vat' ),
			array(
				'order' => 'id asc',
				'limit' => 20,
			)
		);
		return array_values(
			array_filter(
				$records,
				fn( array $record ): bool => $this->normalizeEmail( OdooRecord::text( $record['email'] ?? '' ) ) === $email
			)
		);
	}

	/**
	 * The customer and delivery contact ids for an order, creating what the options allow.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields; may be empty.
	 * @return array{partner:int,delivery:int,created:bool} Delivery is 0 when the order has no separate delivery address.
	 *
	 * @throws OdooException When the customer cannot be matched or created.
	 */
	public function resolveForOrder( array $partner, array $delivery ): array {
		$policy  = $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only', 'always_create' ), 'match_or_create' );
		$matches = 'always_create' === $policy ? array() : $this->findByEmail( $partner['email'] ?? '' );
		$created = false;

		if ( count( $matches ) > 1 && 'fail' === $this->options->choice( 'duplicate_customer', array( 'oldest', 'fail' ), 'oldest' ) ) {
			$exception = $this->refused(
				'partner.email',
				sprintf(
					/* translators: %s: customer email address. */
					__( 'More than one customer in Odoo has the email %s. Merge them in Odoo or choose to use the oldest.', 'estasoft-erp-crm-sync-engine' ),
					$partner['email'] ?? ''
				)
			);
			throw $exception;
		}

		if ( array() !== $matches ) {
			$partnerId = OdooRecord::id( $matches[0]['id'] ?? 0 );
		} elseif ( 'match_only' === $policy ) {
			$exception = $this->refused(
				'partner.email',
				sprintf(
					/* translators: %s: customer email address. */
					__( 'Odoo has no customer with the email %s, and creating customers is switched off.', 'estasoft-erp-crm-sync-engine' ),
					$partner['email'] ?? ''
				)
			);
			throw $exception;
		} else {
			$partnerId = $this->client->create( 'res.partner', $this->values( $partner ) + array( 'email' => $partner['email'] ?? '' ) );
			$created   = true;
		}

		return array(
			'partner'  => $partnerId,
			'delivery' => $this->deliveryContact( $partnerId, $partner, $delivery ),
			'created'  => $created,
		);
	}

	/**
	 * Create or update a customer for a customer sync (the customer entity).
	 *
	 * @param array<string,string> $partner Customer fields (email is required).
	 * @return array{id:int,created:bool}
	 *
	 * @throws OdooException On failure.
	 */
	public function upsert( array $partner ): array {
		$matches = $this->findByEmail( $partner['email'] ?? '' );
		$values  = $this->values( $partner );
		if ( array() === $matches ) {
			return array(
				'id'      => $this->client->create( 'res.partner', $values + array( 'email' => $partner['email'] ?? '' ) ),
				'created' => true,
			);
		}
		$id      = OdooRecord::id( $matches[0]['id'] ?? 0 );
		$current = $matches[0];
		$changed = array();
		unset( $values['is_company'], $values['type'] );
		foreach ( $values as $field => $value ) {
			$before = array_key_exists( $field, $current ) ? $current[ $field ] : null;
			if ( in_array( $field, array( 'name', 'phone', 'vat' ), true ) && OdooRecord::text( $before ) !== $value ) {
				$changed[ $field ] = $value;
			} elseif ( ! array_key_exists( $field, $current ) ) {
				$changed[ $field ] = $value;
			}
		}
		if ( array() !== $changed ) {
			$this->client->write( 'res.partner', array( $id ), $changed );
		}
		return array(
			'id'      => $id,
			'created' => false,
		);
	}

	/**
	 * Values for res.partner from the mapped fields. Empty fields are left out, so Odoo keeps its defaults.
	 *
	 * @param array<string,string> $partner Mapped fields.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException On failure while looking up the country or state.
	 */
	private function values( array $partner ): array {
		$values = array(
			'is_company' => false,
			'type'       => 'contact',
		);
		foreach ( self::TEXT_FIELDS as $field ) {
			$value = trim( $partner[ $field ] ?? '' );
			if ( '' !== $value ) {
				$values[ $field ] = $value;
			}
		}
		$country = $this->catalog->countryId( $partner['country_id'] ?? '' );
		if ( $country > 0 ) {
			$values['country_id'] = $country;
			$state                = $this->catalog->stateId( $partner['state_id'] ?? '' );
			if ( $state > 0 ) {
				$values['state_id'] = $state;
			}
		}
		return $values;
	}

	/**
	 * The delivery contact for an order whose delivery address differs from the billing address. Reuses a contact that
	 * already has the same address.
	 *
	 * @param int                  $partnerId Customer id.
	 * @param array<string,string> $partner   Billing fields.
	 * @param array<string,string> $delivery  Delivery fields.
	 * @return int Contact id, or 0 when no separate contact is needed.
	 *
	 * @throws OdooException On failure.
	 */
	private function deliveryContact( int $partnerId, array $partner, array $delivery ): int {
		$street = trim( $delivery['street'] ?? '' );
		$city   = trim( $delivery['city'] ?? '' );
		if ( '' === $street && '' === $city ) {
			return 0;
		}
		$same = $this->same( $street, $partner['street'] ?? '' ) && $this->same( $city, $partner['city'] ?? '' ) && $this->same( $delivery['zip'] ?? '', $partner['zip'] ?? '' );
		if ( $same ) {
			return 0;
		}
		$existing = $this->client->searchRead(
			'res.partner',
			array_merge(
				array(
					array( 'parent_id', '=', $partnerId ),
					array( 'type', '=', 'delivery' ),
					array( 'street', '=', $street ),
					array( 'city', '=', $city ),
				),
				$this->client->companyDomain()
			),
			array( 'zip' ),
			array( 'order' => 'id asc' )
		);
		foreach ( $existing as $record ) {
			if ( $this->same( OdooRecord::text( $record['zip'] ?? '' ), $delivery['zip'] ?? '' ) ) {
				return OdooRecord::id( $record['id'] ?? 0 );
			}
		}
		$values              = $this->values( $delivery );
		$values['parent_id'] = $partnerId;
		$values['type']      = 'delivery';
		$values['name']      = '' !== trim( $delivery['name'] ?? '' ) ? trim( $delivery['name'] ) : ( $partner['name'] ?? '' );
		unset( $values['vat'], $values['company_name'] );
		return $this->client->create( 'res.partner', $values );
	}

	/**
	 * Whether two address parts are the same, ignoring case and spaces at the ends.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private function same( string $a, string $b ): bool {
		return mb_strtolower( trim( $a ) ) === mb_strtolower( trim( $b ) );
	}

	/**
	 * Lower-case, trimmed email.
	 *
	 * @param string $email Email.
	 */
	private function normalizeEmail( string $email ): string {
		return mb_strtolower( trim( $email ) );
	}

	/**
	 * A rejection as SYNC-VAL-002.
	 *
	 * @param string $field  Field path.
	 * @param string $reason Why.
	 */
	private function refused( string $field, string $reason ): OdooException {
		return new OdooException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => OdooErrorMapper::SYSTEM,
					'field'  => $field,
					'reason' => $reason,
				),
				null,
				null,
				null,
				'customer not usable'
			),
			'',
			'res.partner',
			'search_read'
		);
	}
}
