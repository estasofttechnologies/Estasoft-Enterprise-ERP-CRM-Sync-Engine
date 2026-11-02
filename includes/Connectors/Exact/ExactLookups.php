<?php
/**
 * Customers, items, VAT codes and journals of one division.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;

/**
 * Every search is scoped to the division the connection was pinned to and is paged to the end, so a record on a later page is never
 * missed. Answers that do not change within one run (items, VAT codes, journals) are kept for the life of this object.
 */
final class ExactLookups {

	/**
	 * Items found, by lower-case code.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $items = array();

	/**
	 * VAT codes found, by trimmed code.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $vat = array();

	/**
	 * Journal codes found, by kind.
	 *
	 * @var array<string,string>
	 */
	private array $journals = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param ExactApi   $api     API of the division.
	 * @param CrmOptions $options Connection options.
	 */
	public function __construct( private readonly ExactApi $api, private readonly CrmOptions $options ) {
	}

	/**
	 * A customer account by email address (any letter case), preferring one that can be invoiced.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>|null ID, Code, Name, Email.
	 */
	public function accountByEmail( string $email ): ?array {
		$tries = array( $email );
		if ( Text::lower( $email ) !== $email ) {
			$tries[] = Text::lower( $email );
		}
		foreach ( $tries as $try ) {
			$rows = $this->api->rows(
				'crm/Accounts',
				array(
					'$filter' => 'Email eq ' . ExactApi::str( $try ),
					'$select' => 'ID,Code,Name,Email,IsSales,Status,Blocked',
				)
			);
			$best = null;
			foreach ( $rows as $row ) {
				if ( ! Text::same( (string) ( $row['Email'] ?? '' ), $email ) || true === ( $row['Blocked'] ?? false ) ) {
					continue;
				}
				$usable = true === ( $row['IsSales'] ?? false ) || 'C' === ( $row['Status'] ?? '' );
				if ( ! $usable ) {
					continue;
				}
				if ( null === $best || strcmp( trim( (string) ( $row['Code'] ?? '' ) ), trim( (string) ( $best['Code'] ?? '' ) ) ) < 0 ) {
					$best = $row;
				}
			}
			if ( null !== $best ) {
				return $best;
			}
		}
		return null;
	}

	/**
	 * Create the customer account of a buyer.
	 *
	 * @param array<string,string> $partner Buyer: name, email, company_name, phone, street, city, zip, country_id, vat.
	 * @return array<string,mixed> The new account (ID, Code, Name).
	 */
	public function createAccount( array $partner ): array {
		$body = array(
			'Name'    => '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : ( $partner['name'] ?? '' ),
			'Email'   => $partner['email'],
			'Status'  => 'C',
			'IsSales' => true,
		);
		foreach ( array(
			'Phone'        => 'phone',
			'AddressLine1' => 'street',
			'City'         => 'city',
			'Postcode'     => 'zip',
			'Country'      => 'country_id',
			'VATNumber'    => 'vat',
		) as $field => $key ) {
			if ( '' !== ( $partner[ $key ] ?? '' ) ) {
				$body[ $field ] = $partner[ $key ];
			}
		}
		return $this->api->create( 'crm/Accounts', $body );
	}

	/**
	 * A sellable item by its code (the SKU).
	 *
	 * @param string $code Item code.
	 * @return array<string,mixed>|null ID, Code, Description, IsSalesItem, SalesVatCode.
	 */
	public function item( string $code ): ?array {
		$key = Text::lower( trim( $code ) );
		if ( array_key_exists( $key, $this->items ) ) {
			return $this->items[ $key ];
		}
		$found = null;
		foreach ( $this->api->rows(
			'logistics/Items',
			array(
				'$filter' => 'Code eq ' . ExactApi::str( trim( $code ) ),
				'$select' => 'ID,Code,Description,IsSalesItem,IsStockItem,SalesVatCode',
			)
		) as $row ) {
			if ( Text::same( (string) ( $row['Code'] ?? '' ), $code ) ) {
				$found = $row;
				break;
			}
		}
		$this->items[ $key ] = $found;
		return $found;
	}

	/**
	 * A VAT code by its code.
	 *
	 * @param string $code VAT code.
	 * @return array<string,mixed>|null Code, Description, Percentage (fraction), Type.
	 */
	public function vatCode( string $code ): ?array {
		$code = trim( $code );
		if ( array_key_exists( $code, $this->vat ) ) {
			return $this->vat[ $code ];
		}
		$found = null;
		foreach ( $this->api->rows(
			'vat/VATCodes',
			array(
				'$filter' => 'trim(Code) eq ' . ExactApi::str( $code ),
				'$select' => 'Code,Description,Percentage,Type,VATTransactionType,IsBlocked',
			)
		) as $row ) {
			if ( trim( (string) ( $row['Code'] ?? '' ) ) === $code && true !== ( $row['IsBlocked'] ?? false ) ) {
				$found = $row;
				break;
			}
		}
		$this->vat[ $code ] = $found;
		return $found;
	}

	/**
	 * All VAT codes that can be used for sales.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function salesVatCodes(): array {
		$out = array();
		foreach ( $this->api->rows( 'vat/VATCodes', array( '$select' => 'Code,Description,Percentage,Type,VATTransactionType,IsBlocked' ) ) as $row ) {
			if ( true !== ( $row['IsBlocked'] ?? false ) && in_array( (string) ( $row['VATTransactionType'] ?? 'B' ), array( 'S', 'B' ), true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The journal for invoices or for credit notes.
	 *
	 * @param bool $credit True for the credit note journal.
	 * @return string Journal code.
	 * @throws ExactFailure When no journal can be found.
	 */
	public function journal( bool $credit ): string {
		$kind = $credit ? 'credit' : 'sales';
		if ( isset( $this->journals[ $kind ] ) ) {
			return $this->journals[ $kind ];
		}
		$wanted = $this->options->text( $credit ? 'credit_journal' : 'journal' );
		if ( '' === $wanted && $credit ) {
			$wanted = $this->options->text( 'journal' );
			$code   = $this->pickJournal( 21, '' );
			if ( '' !== $code ) {
				$this->journals[ $kind ] = $code;
				return $code;
			}
		}
		$code = $this->pickJournal( 20, $wanted );
		if ( '' === $code ) {
			ExactFailure::refuse( 'journal', __( 'No sales journal was found in Exact Online. Set the journal in the connection options.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->journals[ $kind ] = $code;
		return $code;
	}

	/**
	 * Find a journal: the named one, or the first of the type that is not blocked.
	 *
	 * @param int    $type  Journal type (20 sales, 21 return invoice).
	 * @param string $named Code the merchant set, or empty.
	 */
	private function pickJournal( int $type, string $named ): string {
		$filter = '' !== $named ? 'Code eq ' . ExactApi::str( $named ) : 'Type eq ' . $type;
		foreach ( $this->api->rows(
			'financial/Journals',
			array(
				'$filter' => $filter,
				'$select' => 'Code,Description,Type,IsBlocked',
			)
		) as $row ) {
			if ( true !== ( $row['IsBlocked'] ?? false ) && '' !== trim( (string) ( $row['Code'] ?? '' ) ) ) {
				return trim( (string) $row['Code'] );
			}
		}
		return '';
	}
}
