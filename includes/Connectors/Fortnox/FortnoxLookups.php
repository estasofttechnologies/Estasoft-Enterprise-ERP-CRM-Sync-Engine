<?php
/**
 * Customers and articles of the company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Text;

/**
 * Fortnox filters are not documented as exact matches, so every search reads all pages of the filtered list and compares the value in
 * code. Articles are kept for the life of this object.
 */
final class FortnoxLookups {

	/**
	 * Articles found, by lower-case number.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $articles = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param FortnoxApi $api API.
	 */
	public function __construct( private readonly FortnoxApi $api ) {
	}

	/**
	 * A customer by email address (any letter case); several: the lowest customer number.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>|null CustomerNumber, Name, Email.
	 */
	public function customerByEmail( string $email ): ?array {
		$best = null;
		foreach ( $this->api->rows( 'customers', 'Customers', array( 'email' => $email ) ) as $row ) {
			if ( ! Text::same( (string) ( $row['Email'] ?? '' ), $email ) ) {
				continue;
			}
			if ( null === $best || self::number( $row ) < self::number( $best ) ) {
				$best = $row;
			}
		}
		return $best;
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,string> $partner Buyer: name, email, company_name, phone, street, city, zip, country_id, vat.
	 * @return array<string,mixed> The new customer.
	 */
	public function createCustomer( array $partner ): array {
		$company = '' !== ( $partner['company_name'] ?? '' );
		$record  = array(
			'Name'  => $company ? $partner['company_name'] : ( $partner['name'] ?? '' ),
			'Email' => $partner['email'],
			'Type'  => $company ? 'COMPANY' : 'PRIVATE',
		);
		foreach ( array(
			'Phone1'      => 'phone',
			'Address1'    => 'street',
			'City'        => 'city',
			'ZipCode'     => 'zip',
			'CountryCode' => 'country_id',
			'VATNumber'   => 'vat',
		) as $field => $key ) {
			if ( '' !== ( $partner[ $key ] ?? '' ) ) {
				$record[ $field ] = $partner[ $key ];
			}
		}
		return $this->api->create( 'customers', 'Customer', $record );
	}

	/**
	 * An article by its number (the SKU).
	 *
	 * @param string $sku Article number.
	 * @return array<string,mixed>|null ArticleNumber, Description, QuantityInStock, ReservedQuantity.
	 */
	public function article( string $sku ): ?array {
		$key = Text::lower( trim( $sku ) );
		if ( array_key_exists( $key, $this->articles ) ) {
			return $this->articles[ $key ];
		}
		$found = null;
		foreach ( $this->api->rows( 'articles', 'Articles', array( 'articlenumber' => trim( $sku ) ) ) as $row ) {
			if ( Text::same( (string) ( $row['ArticleNumber'] ?? '' ), $sku ) ) {
				$found = $row;
				break;
			}
		}
		$this->articles[ $key ] = $found;
		return $found;
	}

	/**
	 * The customer number as an integer, for ordering.
	 *
	 * @param array<string,mixed> $row Customer row.
	 */
	private static function number( array $row ): int {
		$number = (string) ( $row['CustomerNumber'] ?? '' );
		return ctype_digit( $number ) ? (int) $number : PHP_INT_MAX;
	}
}
