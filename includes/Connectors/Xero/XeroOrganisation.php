<?php
/**
 * Facts about the connected Xero organisation.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The organisation (name, base currency, region) and the currencies enabled in it, read once per object. The tenant is pinned by the
 * Xero-tenant-id header the client sends on every call, so nothing here can read another organisation.
 */
final class XeroOrganisation {

	/**
	 * The organisation record.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $organisation = null;

	/**
	 * Currency codes enabled in the organisation.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $currencies = null;

	/**
	 * What the daily call counter header said on the last read, or null.
	 *
	 * @var int|null
	 */
	private ?int $dailyLeft = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api Client for the connection.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * The organisation.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call or an answer with no organisation.
	 */
	public function facts(): array {
		if ( null === $this->organisation ) {
			$response = $this->api->get( 'Organisation' );
			$data     = $response->json();
			$row      = isset( $data['Organisations'][0] ) && is_array( $data['Organisations'][0] ) ? $data['Organisations'][0] : null;
			if ( null === $row ) {
				$failure = XeroErrors::exception( XeroErrors::refused( 'Organisation', __( 'Xero did not return the organisation.', 'estasoft-enterprise-erpcrm-sync-engine' ), $response->status() ), $response );
				throw $failure;
			}
			$left = $response->header( 'x-daylimit-remaining' );
			if ( null !== $left && ctype_digit( trim( $left ) ) ) {
				$this->dailyLeft = (int) trim( $left );
			}
			$this->organisation = $row;
		}
		return $this->organisation;
	}

	/**
	 * One fresh read of the organisation for a health probe; what is cached is not trusted.
	 *
	 * @throws ApiException On a failed call or an answer with no organisation.
	 */
	public function probe(): void {
		$this->organisation = null;
		$this->facts();
	}

	/**
	 * Calls left today according to Xero's header, when it sent one.
	 */
	public function dailyLeft(): ?int {
		return $this->dailyLeft;
	}

	/**
	 * The base currency of the organisation.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function baseCurrency(): string {
		return strtoupper( XeroPayloadReader::text( $this->facts()['BaseCurrency'] ?? '' ) );
	}

	/**
	 * Whether a currency can be used in the organisation (the base currency always can).
	 *
	 * @param string $code ISO currency code.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function currencyEnabled( string $code ): bool {
		$code = strtoupper( $code );
		if ( $code === $this->baseCurrency() ) {
			return true;
		}
		if ( null === $this->currencies ) {
			$this->currencies = array();
			foreach ( (array) ( $this->api->get( 'Currencies' )->json()['Currencies'] ?? array() ) as $currency ) {
				if ( is_array( $currency ) ) {
					$this->currencies[] = strtoupper( XeroPayloadReader::text( $currency['Code'] ?? '' ) );
				}
			}
		}
		return in_array( $code, $this->currencies, true );
	}
}
