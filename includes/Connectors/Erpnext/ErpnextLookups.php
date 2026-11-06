<?php
/**
 * Reads the setup facts an order needs: company, price list, exchange rate, items, countries.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Small, read-only lookups with an in-memory cache for the life of the object (one send). Each lookup that can fail because of the
 * merchant's setup returns a ConnectorError with a plain reason; transport and permission failures throw ApiException as everywhere.
 */
final class ErpnextLookups {

	/**
	 * Cache of answers by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $cache = array();

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient $client  Client.
	 * @param Options       $options Connection options.
	 * @param string        $system  System name.
	 */
	public function __construct( private readonly ErpnextClient $client, private readonly Options $options, private readonly string $system ) {
	}

	/**
	 * The company that gets the documents.
	 *
	 * @return array{name:string,currency:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function company(): array|ConnectorError {
		if ( isset( $this->cache['company'] ) ) {
			return $this->cache['company'];
		}
		$named = $this->options->text( 'company' );
		if ( '' !== $named ) {
			$doc = $this->client->get( 'Company', $named );
			if ( null === $doc ) {
				return Problems::refused( $this->system, 'company', __( 'The company named in the connection settings does not exist, or the user cannot see it.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$this->cache['company'] = array(
				'name'     => (string) ( $doc['name'] ?? $named ),
				'currency' => strtoupper( (string) ( $doc['default_currency'] ?? '' ) ),
			);
			return $this->cache['company'];
		}
		$rows = $this->client->rows( 'Company', array(), array( 'name', 'default_currency' ), 'name asc', 3 );
		if ( 1 !== count( $rows ) ) {
			return Problems::refused(
				$this->system,
				'company',
				array() === $rows
					? __( 'The user can see no company. Give it access to one, or check the roles.', 'estasoft-enterprise-erpcrm-sync-engine' )
					: __( 'The site has several companies. Enter the company in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		$this->cache['company'] = array(
			'name'     => (string) $rows[0]['name'],
			'currency' => strtoupper( (string) ( $rows[0]['default_currency'] ?? '' ) ),
		);
		return $this->cache['company'];
	}

	/**
	 * The selling price list for a currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return string|ConnectorError The price list name.
	 *
	 * @throws ApiException On failure.
	 */
	public function priceList( string $currency ): string|ConnectorError {
		$key = 'pricelist:' . $currency;
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$named = $this->options->text( 'price_list' );
		if ( '' !== $named ) {
			$doc = $this->client->get( 'Price List', $named );
			if ( null === $doc || 1 !== (int) ( $doc['enabled'] ?? 0 ) || 1 !== (int) ( $doc['selling'] ?? 0 ) || strtoupper( (string) ( $doc['currency'] ?? '' ) ) !== $currency ) {
				return Problems::refused(
					$this->system,
					'price_list',
					sprintf(
						/* translators: %s: currency code. */
						__( 'The price list in the connection settings must exist, be enabled, be a selling list and be in %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$currency
					)
				);
			}
			$this->cache[ $key ] = (string) $doc['name'];
			return $this->cache[ $key ];
		}
		$row = $this->client->first( 'Price List', array( array( 'enabled', '=', 1 ), array( 'selling', '=', 1 ), array( 'currency', '=', $currency ) ), array( 'name' ), 'name asc' );
		if ( null === $row ) {
			return Problems::refused(
				$this->system,
				'price_list',
				sprintf(
					/* translators: %s: currency code. */
					__( 'There is no enabled selling price list in %s. Create one, or the order would be priced in another currency.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$currency
				)
			);
		}
		$this->cache[ $key ] = (string) $row['name'];
		return $this->cache[ $key ];
	}

	/**
	 * The exchange rate from the order's currency to the company's.
	 *
	 * @param string $from Order currency.
	 * @param string $to   Company currency.
	 * @param string $date Y-m-d.
	 * @return float|ConnectorError 1.0 for the same currency.
	 *
	 * @throws ApiException On failure.
	 */
	public function exchangeRate( string $from, string $to, string $date ): float|ConnectorError {
		if ( $from === $to || '' === $to ) {
			return 1.0;
		}
		$rate = $this->client->call(
			'erpnext.setup.utils.get_exchange_rate',
			array(
				'from_currency'    => $from,
				'to_currency'      => $to,
				'transaction_date' => '' !== $date ? $date : gmdate( 'Y-m-d' ),
				'args'             => 'for_selling',
			)
		);
		$rate = is_numeric( $rate ) ? (float) $rate : 0.0;
		if ( $rate <= 0.0 ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: order currency, 2: company currency. */
					__( 'ERPNext has no exchange rate from %1$s to %2$s. Add one under Currency Exchange.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$from,
					$to
				)
			);
		}
		return $rate;
	}

	/**
	 * Items by SKU: the canonical item code as ERPNext spells it.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>> Keyed by lower-case SKU; items that do not exist or are disabled are absent.
	 *
	 * @throws ApiException On failure.
	 */
	public function items( array $skus ): array {
		$found = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( $skus, static fn( string $sku ): bool => '' !== $sku ) ) ), 40 ) as $chunk ) {
			$rows = $this->client->rows( 'Item', array( array( 'item_code', 'in', $chunk ), array( 'disabled', '=', 0 ) ), array( 'item_code', 'item_name', 'stock_uom', 'is_stock_item' ), 'item_code asc', 200 );
			foreach ( $rows as $row ) {
				$found[ strtolower( (string) $row['item_code'] ) ] = $row;
			}
		}
		return $found;
	}

	/**
	 * The ERPNext Country name for a two-letter ISO code.
	 *
	 * @param string $iso Two-letter code.
	 * @return string Empty when unknown.
	 *
	 * @throws ApiException On failure.
	 */
	public function country( string $iso ): string {
		$iso = strtolower( trim( $iso ) );
		if ( 1 !== preg_match( '/^[a-z]{2}$/', $iso ) ) {
			return '';
		}
		$key = 'country:' . $iso;
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$row                 = $this->client->first( 'Country', array( array( 'code', '=', $iso ) ), array( 'name' ) );
			$this->cache[ $key ] = null === $row ? '' : (string) $row['name'];
		}
		return (string) $this->cache[ $key ];
	}

	/**
	 * Customer group and territory for a new customer: the options, else the defaults of Selling Settings.
	 *
	 * @return array{customer_group:string,territory:string}
	 *
	 * @throws ApiException On failure.
	 */
	public function customerDefaults(): array {
		if ( isset( $this->cache['defaults'] ) ) {
			return $this->cache['defaults'];
		}
		$group     = $this->options->text( 'customer_group' );
		$territory = $this->options->text( 'territory' );
		if ( '' === $group || '' === $territory ) {
			$settings  = $this->client->get( 'Selling Settings', 'Selling Settings' );
			$group     = '' !== $group ? $group : (string) ( $settings['customer_group'] ?? '' );
			$territory = '' !== $territory ? $territory : (string) ( $settings['territory'] ?? '' );
		}
		$this->cache['defaults'] = array(
			'customer_group' => $group,
			'territory'      => $territory,
		);
		return $this->cache['defaults'];
	}
}
