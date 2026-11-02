<?php
/**
 * The business a connection works in: its currency and its tax rates, ledger accounts and bank accounts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Sage knows tax rates, ledger accounts and bank accounts by an id; the shop and the merchant know them by a tax code id, a nominal code or a name. These reads turn
 * the one into the other. Each list is read once per object.
 */
final class SageBusiness {

	/**
	 * Tax rates.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $rates = null;

	/**
	 * Ledger accounts.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $ledgers = null;

	/**
	 * Bank accounts.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $banks = null;

	/**
	 * Businesses of the signed-in user.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $businesses = null;

	/**
	 * Base currency.
	 *
	 * @var string|null
	 */
	private ?string $currency = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageApi $api        Client.
	 * @param string  $businessId Id of the business.
	 */
	public function __construct( private readonly SageApi $api, private readonly string $businessId ) {
	}

	/**
	 * The business id of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function businessOf( Connection $connection ): string {
		$id = $connection->config( 'business_id' );
		return is_scalar( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', trim( (string) $id ) ) ? trim( (string) $id ) : '';
	}

	/**
	 * The country of a connection (lower-case ISO code, "gb" by default).
	 *
	 * @param Connection $connection Connection.
	 */
	public static function countryOf( Connection $connection ): string {
		$country = strtolower( SagePayloadReader::text( $connection->config( 'country' ) ) );
		return in_array( $country, array( 'gb', 'ie', 'ca', 'es', 'fr' ), true ) ? $country : 'gb';
	}

	/**
	 * The businesses the signed-in user can open.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function businesses(): array {
		if ( null === $this->businesses ) {
			$this->businesses = $this->api->select( 'businesses' );
		}
		return $this->businesses;
	}

	/**
	 * The entry of this connection's business in the list.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function business(): ?array {
		foreach ( $this->businesses() as $business ) {
			if ( SagePayloadReader::text( $business['id'] ?? '' ) === $this->businessId ) {
				return $business;
			}
		}
		return null;
	}

	/**
	 * The base currency of the business (ISO code), from its financial settings.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function currency(): string {
		if ( null === $this->currency ) {
			$data           = $this->api->get( 'financial_settings' )->json();
			$this->currency = strtoupper( SagePayloadReader::text( $data['base_currency']['id'] ?? ( $data['base_currency'] ?? '' ) ) );
		}
		return $this->currency;
	}

	/**
	 * The tax rates for the tax mapping screen.
	 *
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxList(): array {
		$list = array();
		foreach ( $this->taxRows() as $row ) {
			$list[] = array(
				'code'         => SagePayloadReader::text( $row['id'] ?? '' ),
				'name'         => SagePayloadReader::text( $row['name'] ?? ( $row['displayed_as'] ?? ( $row['id'] ?? '' ) ) ),
				'rate_percent' => number_format( (float) ( $row['percentage'] ?? 0 ), 4, '.', '' ),
			);
		}
		return $list;
	}

	/**
	 * A tax rate by its id.
	 *
	 * @param string $id Tax rate id such as GB_STANDARD.
	 * @return array{id:string,rate:int}|null The id as Sage spells it and the rate in ten-thousandths of a percent, or null when the business has no such rate.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxRate( string $id ): ?array {
		foreach ( $this->taxRows() as $row ) {
			if ( 0 === strcasecmp( SagePayloadReader::text( $row['id'] ?? '' ), $id ) ) {
				return array(
					'id'   => SagePayloadReader::text( $row['id'] ),
					'rate' => (int) round( (float) ( $row['percentage'] ?? 0 ) * 10000 ),
				);
			}
		}
		return null;
	}

	/**
	 * The tax rate to use for a line with no tax: the rate named in the options, else the one whose id ends in _NO_TAX.
	 *
	 * @param string $named Rate id from the options, or empty.
	 * @return array{id:string,rate:int}|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function noTaxRate( string $named ): ?array {
		if ( '' !== $named ) {
			return $this->taxRate( $named );
		}
		foreach ( $this->taxRows() as $row ) {
			if ( 1 === preg_match( '/_NO_TAX$/i', SagePayloadReader::text( $row['id'] ?? '' ) ) ) {
				return $this->taxRate( SagePayloadReader::text( $row['id'] ) );
			}
		}
		return null;
	}

	/**
	 * The id of a ledger account by nominal code or id.
	 *
	 * @param string $codeOrId Nominal code such as 4000, or the id.
	 * @return string The id, or empty when the business has no such account.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function ledgerId( string $codeOrId ): string {
		if ( null === $this->ledgers ) {
			$this->ledgers = $this->api->select( 'ledger_accounts' );
		}
		foreach ( $this->ledgers as $ledger ) {
			if ( SagePayloadReader::text( $ledger['nominal_code'] ?? '' ) === $codeOrId || SagePayloadReader::text( $ledger['id'] ?? '' ) === $codeOrId ) {
				return SagePayloadReader::text( $ledger['id'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * The id of a bank account by id or name.
	 *
	 * @param string $nameOrId Id or the account's name.
	 * @return string The id, or empty when the business has no such account.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function bankId( string $nameOrId ): string {
		if ( null === $this->banks ) {
			$this->banks = $this->api->select( 'bank_accounts' );
		}
		foreach ( $this->banks as $bank ) {
			if ( SagePayloadReader::text( $bank['id'] ?? '' ) === $nameOrId || 0 === strcasecmp( SagePayloadReader::text( $bank['displayed_as'] ?? ( $bank['name'] ?? '' ) ), $nameOrId ) || 0 === strcasecmp( SagePayloadReader::text( $bank['bank_account_details']['account_name'] ?? '' ), $nameOrId ) ) {
				return SagePayloadReader::text( $bank['id'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * The tax rate rows, without retired rates.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function taxRows(): array {
		if ( null === $this->rates ) {
			$this->rates = array_values( array_filter( $this->api->select( 'tax_rates' ), static fn( array $row ): bool => empty( $row['retired'] ) ) );
		}
		return $this->rates;
	}
}
