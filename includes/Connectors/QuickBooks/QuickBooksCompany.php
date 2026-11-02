<?php
/**
 * Facts about the connected QuickBooks company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The company (name, country) and its preferences (custom transaction numbers, multi-currency, home currency), read once per object. The
 * company is pinned by the realm id in the base address of the client.
 */
final class QuickBooksCompany {

	/**
	 * The company record.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $info = null;

	/**
	 * The preferences record.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $preferences = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksApi $api   Client.
	 * @param string        $realm Realm id of the company.
	 */
	public function __construct( private readonly QuickBooksApi $api, private readonly string $realm ) {
	}

	/**
	 * The realm id of a connection: the one stored after sign-in, else the one in the sign-in callback, as digits only.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function realmOf( Connection $connection ): string {
		$realm = $connection->config( 'realm_id' );
		if ( ! is_scalar( $realm ) || '' === trim( (string) $realm ) ) {
			$callback = $connection->config( 'oauth_callback' );
			$realm    = is_array( $callback ) ? ( $callback['realmId'] ?? '' ) : '';
		}
		return is_scalar( $realm ) ? (string) preg_replace( '/\D/', '', (string) $realm ) : '';
	}

	/**
	 * Company information.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function info(): array {
		if ( null === $this->info ) {
			$this->info = $this->api->entity( $this->api->get( 'companyinfo/' . rawurlencode( $this->realm ) ), 'CompanyInfo' );
		}
		return $this->info;
	}

	/**
	 * One fresh read of the company record for a health probe; what is cached is not trusted.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function probe(): void {
		$this->info = null;
		$this->info();
	}

	/**
	 * Company preferences.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function preferences(): array {
		if ( null === $this->preferences ) {
			$this->preferences = $this->api->entity( $this->api->get( 'preferences' ), 'Preferences' );
		}
		return $this->preferences;
	}

	/**
	 * The company name.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function name(): string {
		return QuickBooksPayloadReader::text( $this->info()['CompanyName'] ?? '' );
	}

	/**
	 * The country code of the company (US, GB, CA, AU ...).
	 *
	 * @throws ApiException On a failed call.
	 */
	public function country(): string {
		return strtoupper( QuickBooksPayloadReader::text( $this->info()['Country'] ?? '' ) );
	}

	/**
	 * Whether the company is a US company (it has the US tax model).
	 *
	 * @throws ApiException On a failed call.
	 */
	public function isUs(): bool {
		return 'US' === $this->country();
	}

	/**
	 * Whether Custom transaction numbers is on, so a document number sent by the API is kept.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function customNumbers(): bool {
		return true === ( $this->preferences()['SalesFormsPrefs']['CustomTxnNumbers'] ?? false );
	}

	/**
	 * Whether the company uses more than one currency.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function multiCurrency(): bool {
		return true === ( $this->preferences()['CurrencyPrefs']['MultiCurrencyEnabled'] ?? false );
	}

	/**
	 * The home currency.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function homeCurrency(): string {
		return strtoupper( QuickBooksPayloadReader::text( $this->preferences()['CurrencyPrefs']['HomeCurrency']['value'] ?? '' ) );
	}
}
