<?php
/**
 * Contacts, accounts, currencies, opportunities, notes and products of the SuiteCRM server.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;

/**
 * Every search names its conditions for SuiteCRM and reads all pages, then compares the value in code. SuiteCRM keeps no external id on
 * these modules, so the reference `WC-{id}` lives at the start of a record's name and is found with an exact or a "reference + space"
 * match, which cannot hit `WC-12` when looking for `WC-1`.
 */
final class SuiteCrmRecords {

	/**
	 * Longest opportunity name SuiteCRM keeps, in characters.
	 */
	public const NAME_MAX = 50;

	/**
	 * Currencies found, by ISO code.
	 *
	 * @var array<string,string>
	 */
	private array $currencies = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param SuiteCrmApi $api     API.
	 * @param CrmOptions  $options Options.
	 */
	public function __construct( private readonly SuiteCrmApi $api, private readonly CrmOptions $options ) {
	}

	/**
	 * The oldest contact with an email address (any letter case).
	 *
	 * @param string $email Email address.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function contactByEmail( string $email ): ?array {
		foreach ( $this->api->find( 'Contacts', array( array( 'email1', 'eq', $email ) ), 'and', 'date_entered' ) as $record ) {
			$found = (string) ( $record['attributes']['email1'] ?? '' );
			if ( '' === $found || Text::same( $found, $email ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string,string> $contact Buyer: email, first_name, last_name, company, phone, street, city, state, postcode, country.
	 * @return array{id:string,attributes:array<string,mixed>}
	 */
	public function createContact( array $contact ): array {
		$last  = $contact['last_name'] ?? '';
		$first = $contact['first_name'] ?? '';
		if ( '' === $last ) {
			$last  = $first;
			$first = '';
		}
		if ( '' === $last ) {
			$last = '' !== ( $contact['company'] ?? '' ) ? $contact['company'] : (string) strstr( $contact['email'], '@', true );
		}
		$attributes = array(
			'last_name'   => Text::limit( $last, 100 )[0],
			'email1'      => $contact['email'],
			'lead_source' => $this->options->text( 'lead_source', 'Web Site' ),
		);
		if ( '' !== $first ) {
			$attributes['first_name'] = Text::limit( $first, 100 )[0];
		}
		foreach ( array(
			'phone_work'                 => 'phone',
			'primary_address_street'     => 'street',
			'primary_address_city'       => 'city',
			'primary_address_state'      => 'state',
			'primary_address_postalcode' => 'postcode',
			'primary_address_country'    => 'country',
		) as $field => $key ) {
			if ( '' !== ( $contact[ $key ] ?? '' ) ) {
				$attributes[ $field ] = $contact[ $key ];
			}
		}
		return $this->api->create( 'Contacts', $attributes );
	}

	/**
	 * The oldest account with exactly this name.
	 *
	 * @param string $name Account name.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function accountByName( string $name ): ?array {
		foreach ( $this->api->find( 'Accounts', array( array( 'name', 'eq', $name ) ), 'and', 'date_entered' ) as $record ) {
			if ( Text::same( (string) ( $record['attributes']['name'] ?? '' ), $name ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Create an account for a company.
	 *
	 * @param array<string,string> $contact Buyer.
	 * @return array{id:string,attributes:array<string,mixed>}
	 */
	public function createAccount( array $contact ): array {
		$attributes = array(
			'name'   => Text::limit( $contact['company'], 150 )[0],
			'email1' => $contact['email'],
		);
		foreach ( array(
			'phone_office'               => 'phone',
			'billing_address_street'     => 'street',
			'billing_address_city'       => 'city',
			'billing_address_state'      => 'state',
			'billing_address_postalcode' => 'postcode',
			'billing_address_country'    => 'country',
		) as $field => $key ) {
			if ( '' !== ( $contact[ $key ] ?? '' ) ) {
				$attributes[ $field ] = $contact[ $key ];
			}
		}
		return $this->api->create( 'Accounts', $attributes );
	}

	/**
	 * The id SuiteCRM uses for a currency. The default currency of the installation is `-99`; any other is looked up by its ISO code.
	 *
	 * @param string $iso ISO code of the order.
	 * @throws SuiteCrmFailure When SuiteCRM has no such currency.
	 */
	public function currencyId( string $iso ): string {
		$base = strtoupper( $this->options->text( 'base_currency' ) );
		if ( '' !== $base && $base === $iso ) {
			return '-99';
		}
		if ( isset( $this->currencies[ $iso ] ) ) {
			return $this->currencies[ $iso ];
		}
		$id = '';
		foreach ( $this->api->find( 'Currencies', array( array( 'iso4217', 'eq', $iso ) ) ) as $record ) {
			if ( strtoupper( (string) ( $record['attributes']['iso4217'] ?? '' ) ) === $iso && 'Inactive' !== ( $record['attributes']['status'] ?? '' ) ) {
				$id = $record['id'];
				break;
			}
		}
		if ( '' === $id ) {
			/* translators: %s: currency code. */
			SuiteCrmFailure::refuse( 'currency', sprintf( __( 'Currency %s is not defined in SuiteCRM (Admin, Currencies), and it is not the default currency set in the connection options.', 'estasoft-erp-crm-sync-engine' ), $iso ) );
		}
		$this->currencies[ $iso ] = $id;
		return $id;
	}

	/**
	 * The ISO code of a currency id; the default id gives the base currency option.
	 *
	 * @param string $id Currency id.
	 */
	public function currencyIso( string $id ): string {
		if ( '' === $id || '-99' === $id ) {
			return strtoupper( $this->options->text( 'base_currency' ) );
		}
		$known = array_search( $id, $this->currencies, true );
		if ( false !== $known ) {
			return (string) $known;
		}
		$record = $this->api->get( 'Currencies', $id );
		$iso    = strtoupper( (string) ( $record['attributes']['iso4217'] ?? '' ) );
		if ( '' !== $iso ) {
			$this->currencies[ $iso ] = $id;
		}
		return $iso;
	}

	/**
	 * The oldest opportunity of an order reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function opportunity( string $reference ): ?array {
		return $this->byReferenceName( 'Opportunities', $reference );
	}

	/**
	 * The note of a refund reference.
	 *
	 * @param string $reference WC-{refund id}.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function refundNote( string $reference ): ?array {
		return $this->byReferenceName( 'Notes', 'Refund ' . $reference );
	}

	/**
	 * What was refunded on each opportunity, in minor units, from the refund notes made since a date. A refund note is named
	 * `Refund WC-{refund id} {amount} {currency}`; a note of another shape, or one that is not on an opportunity, is left out, and a refund
	 * that has two notes counts once.
	 *
	 * @param \DateTimeImmutable $from Earliest date a note was made.
	 * @return array<string,int> Opportunity id => minor units refunded.
	 */
	public function refundedByOpportunity( \DateTimeImmutable $from ): array {
		$notes  = $this->api->find(
			'Notes',
			array(
				array( 'name', 'like', 'Refund WC-%' ),
				array( 'date_entered', 'gte', $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ),
			),
			'and',
			'date_entered'
		);
		$totals = array();
		$seen   = array();
		foreach ( $notes as $note ) {
			$attributes = $note['attributes'];
			$parent     = (string) ( $attributes['parent_id'] ?? '' );
			if ( 'Opportunities' !== ( $attributes['parent_type'] ?? '' ) || '' === $parent ) {
				continue;
			}
			if ( 1 !== preg_match( '/^Refund (WC-\d+) (\d+(?:\.\d+)?) ([A-Za-z]{3})$/', (string) ( $attributes['name'] ?? '' ), $parts ) || isset( $seen[ $parts[1] ] ) ) {
				continue;
			}
			$seen[ $parts[1] ] = true;
			$totals[ $parent ] = ( $totals[ $parent ] ?? 0 ) + ( Amounts::minor( $parts[2], strtoupper( $parts[3] ) ) ?? 0 );
		}
		return $totals;
	}

	/**
	 * A product by its part number (the SKU).
	 *
	 * @param string $sku SKU.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function product( string $sku ): ?array {
		foreach ( $this->api->find( 'AOS_Products', array( array( 'part_number', 'eq', trim( $sku ) ) ) ) as $record ) {
			if ( Text::same( (string) ( $record['attributes']['part_number'] ?? '' ), $sku ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Products whose name or part number contains a text (first page of each search).
	 *
	 * @param string $query Search text.
	 * @return array<int,array{id:string,attributes:array<string,mixed>}>
	 */
	public function searchProducts( string $query ): array {
		$out = array();
		foreach ( array( 'name', 'part_number' ) as $field ) {
			foreach ( array_slice( $this->api->find( 'AOS_Products', array( array( $field, 'like', '%' . trim( $query ) . '%' ) ), 'and', '', 1 ), 0, 20 ) as $record ) {
				$out[ $record['id'] ] = $record;
			}
		}
		return array_slice( array_values( $out ), 0, 20 );
	}

	/**
	 * Whether a record is already related to another.
	 *
	 * @param string $module  Module.
	 * @param string $id      Record id.
	 * @param string $link    Link field.
	 * @param string $otherId Id of the other record.
	 */
	public function isLinked( string $module, string $id, string $link, string $otherId ): bool {
		return in_array( $otherId, $this->api->related( $module, $id, $link ), true );
	}

	/**
	 * Relate two records unless they already are.
	 *
	 * @param string $module        Module.
	 * @param string $id            Record id.
	 * @param string $link          Link field.
	 * @param string $relatedModule Module of the other record.
	 * @param string $otherId       Id of the other record.
	 */
	public function ensureLink( string $module, string $id, string $link, string $relatedModule, string $otherId ): void {
		if ( ! $this->isLinked( $module, $id, $link, $otherId ) ) {
			$this->api->link( $module, $id, $link, $relatedModule, $otherId );
		}
	}

	/**
	 * The oldest record whose name is the reference, or starts with the reference and a space.
	 *
	 * @param string $module Module.
	 * @param string $name   Reference text such as WC-123.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	private function byReferenceName( string $module, string $name ): ?array {
		$records = $this->api->find( $module, array( array( 'name', 'eq', $name ), array( 'name', 'like', $name . ' %' ) ), 'or', 'date_entered' );
		foreach ( $records as $record ) {
			$found = (string) ( $record['attributes']['name'] ?? '' );
			if ( $found === $name || str_starts_with( $found, $name . ' ' ) ) {
				return $record;
			}
		}
		return null;
	}
}
