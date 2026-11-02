<?php
/**
 * Tax rates, accounts and items of a Wafeq organisation, read once and kept for a while.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Wafeq has no SKU filter on items and no code filter on accounts [documented]. The account and tax-rate lists are small and are read in full,
 * kept in memory for ten minutes per connection. A SKU is found through the item index (`WafeqItemIndex`), which is kept between requests and
 * stops reading as soon as the SKU is found; a name or SKU search for the mapping screen still reads the whole list.
 */
final class WafeqCatalog {

	/**
	 * Seconds a list is kept.
	 */
	public const TTL = 600;

	/**
	 * Lists by cache key: [expires at, rows].
	 *
	 * @var array<string,array{0:int,1:array<int,array<string,mixed>>}>
	 */
	private array $cache = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqPager     $pager   Reads every page of a list.
	 * @param Clock          $clock   Time source.
	 * @param WafeqAmounts   $amounts Conversion helper.
	 * @param WafeqItemIndex $index  SKU index of the items.
	 */
	public function __construct( private readonly WafeqPager $pager, private readonly Clock $clock, private readonly WafeqAmounts $amounts, private readonly WafeqItemIndex $index ) {
	}

	/**
	 * Forget everything that was read (after a change, and in tests).
	 */
	public function forget(): void {
		$this->cache = array();
	}

	/**
	 * Tax rates by id: id, name, tax type and the rate in basis points (null when the answer has no readable rate).
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id (cache key).
	 * @return array<string,array{id:string,name:string,type:string,rate_bp:?int}>
	 *
	 * @throws ApiException On failure.
	 */
	public function taxRates( ApiClient $api, int $connectionId ): array {
		$rates = array();
		foreach ( $this->rows( $api, $connectionId, 'tax-rates/' ) as $row ) {
			$id = WafeqPayloadReader::text( $row['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$bp = null;
			foreach ( array( 'rate', 'percentage', 'rate_percent', 'percent' ) as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$bp = $this->amounts->basisPoints( $row[ $key ] );
					if ( null !== $bp ) {
						break;
					}
				}
			}
			$rates[ $id ] = array(
				'id'      => $id,
				'name'    => WafeqPayloadReader::text( $row['name'] ?? $id ),
				'type'    => strtoupper( WafeqPayloadReader::text( $row['tax_type'] ?? '' ) ),
				'rate_bp' => $bp,
			);
		}
		return $rates;
	}

	/**
	 * Accounts.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function accounts( ApiClient $api, int $connectionId ): array {
		return $this->rows( $api, $connectionId, 'accounts/' );
	}

	/**
	 * The account a setting names: by id, then by code, then by exact name (case-insensitive).
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $setting      Id, code or name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findAccount( ApiClient $api, int $connectionId, string $setting ): ?array {
		$setting = trim( $setting );
		if ( '' === $setting ) {
			return null;
		}
		$accounts = $this->accounts( $api, $connectionId );
		foreach ( array( 'id', 'code', 'name' ) as $key ) {
			foreach ( $accounts as $account ) {
				$value = WafeqPayloadReader::text( $account[ $key ] ?? '' );
				if ( '' !== $value && ( 'name' === $key ? 0 === strcasecmp( $value, $setting ) : $value === $setting ) ) {
					return $account;
				}
			}
		}
		return null;
	}

	/**
	 * The id of the account sales lines are booked to: the one the setting names, else the only revenue account.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $setting      The sales_account option.
	 * @return string|ConnectorError The account id, or SYNC-VAL-002 asking the person to choose.
	 *
	 * @throws ApiException On failure.
	 */
	public function salesAccountId( ApiClient $api, int $connectionId, string $setting ): string|ConnectorError {
		if ( '' !== trim( $setting ) ) {
			$account = $this->findAccount( $api, $connectionId, $setting );
			if ( null === $account || '' === WafeqPayloadReader::text( $account['id'] ?? '' ) ) {
				return $this->refused( 'sales_account', __( 'Wafeq has no account that matches the sales account setting.', 'estasoft-erp-crm-sync-engine' ) );
			}
			return WafeqPayloadReader::text( $account['id'] );
		}
		$revenue = array();
		foreach ( $this->accounts( $api, $connectionId ) as $account ) {
			if ( 'REVENUE' === strtoupper( WafeqPayloadReader::text( $account['classification'] ?? '' ) ) && '' !== WafeqPayloadReader::text( $account['id'] ?? '' ) ) {
				$revenue[] = WafeqPayloadReader::text( $account['id'] );
			}
		}
		if ( 1 === count( $revenue ) ) {
			return $revenue[0];
		}
		return $this->refused( 'sales_account', __( 'Choose the sales account in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The id of the account payments are recorded against.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $setting      The payment_account option.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function paymentAccountId( ApiClient $api, int $connectionId, string $setting ): string|ConnectorError {
		if ( '' === trim( $setting ) ) {
			return $this->refused( 'payment_account', __( 'Choose the payment account in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$account = $this->findAccount( $api, $connectionId, $setting );
		if ( null === $account || '' === WafeqPayloadReader::text( $account['id'] ?? '' ) ) {
			return $this->refused( 'payment_account', __( 'Wafeq has no account that matches the payment account setting.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return WafeqPayloadReader::text( $account['id'] );
	}

	/**
	 * The item with a SKU (exact, case-insensitive).
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $sku          SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function itemBySku( ApiClient $api, int $connectionId, string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$found = $this->index->find( $api, $connectionId, $sku );
		return null === $found ? null : array(
			'id'   => $found['id'],
			'sku'  => $found['sku'],
			'name' => '',
		);
	}

	/**
	 * Drop the kept SKU index when Wafeq refused an item of a document, because the index may hold an id that no longer exists.
	 *
	 * @param ApiException $exception The refusal of a create.
	 * @param int          $connectionId Connection id.
	 */
	public function itemRefused( ApiException $exception, int $connectionId ): void {
		$error = $exception->error();
		if ( ErrorCode::Val002 === $error->code && 1 === preg_match( '/(^|\.)item$/', (string) ( $error->context['field'] ?? '' ) ) ) {
			$this->index->forget( $connectionId );
		}
	}

	/**
	 * Drop the kept SKU index of a connection (Wafeq refused an item id, or the catalogue changed).
	 *
	 * @param int $connectionId Connection id.
	 */
	public function forgetItems( int $connectionId ): void {
		$this->index->forget( $connectionId );
	}

	/**
	 * Items whose name or SKU contains the text.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $query        Search text.
	 * @param int       $limit        Most items returned.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchItems( ApiClient $api, int $connectionId, string $query, int $limit = 20 ): array {
		$query = mb_strtolower( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$found = array();
		foreach ( $this->rows( $api, $connectionId, 'items/' ) as $item ) {
			$haystack = mb_strtolower( WafeqPayloadReader::text( $item['name'] ?? '' ) . ' ' . WafeqPayloadReader::text( $item['sku'] ?? '' ) );
			if ( str_contains( $haystack, $query ) ) {
				$found[] = $item;
				if ( count( $found ) >= $limit ) {
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * One list, from the cache when it is fresh.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $path         List path.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, int $connectionId, string $path ): array {
		$key = $connectionId . '|' . $path;
		$now = $this->clock->now()->getTimestamp();
		if ( isset( $this->cache[ $key ] ) && $this->cache[ $key ][0] > $now ) {
			return $this->cache[ $key ][1];
		}
		$rows                = $this->pager->all( $api, $path );
		$this->cache[ $key ] = array( $now + self::TTL, $rows );
		return $rows;
	}

	/**
	 * A SYNC-VAL-002 for a setting that cannot be used.
	 *
	 * @param string $field  Setting name.
	 * @param string $reason Short reason.
	 */
	private function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => WafeqConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'setting refused'
		);
	}
}
