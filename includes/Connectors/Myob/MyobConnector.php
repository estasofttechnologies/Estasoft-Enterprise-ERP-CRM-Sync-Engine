<?php
/**
 * The MYOB connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Connects the engine to a MYOB company file through the merchant's own developer key (OAuth 2 authorization code plus the key header). Orders become
 * item invoices, refunds become credit invoices, customers become customer cards. See docs/connectors/myob.md for every endpoint, field and limit, and what is
 * not verified.
 */
final class MyobConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * Scopes the connector asks for.
	 *
	 * @var array<int,string>
	 */
	private const SCOPES = array( 'sme-company-file', 'sme-general-ledger', 'sme-sales', 'sme-inventory', 'sme-contacts-customer' );

	/**
	 * Helpers per connection, kept for the length of the request.
	 *
	 * @var array<string,MyobServices>
	 */
	private array $services = array();

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'myob',
			label: 'MYOB',
			type: ConnectorType::Accounting,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::StockIn,
				Capability::InvoiceReadback,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array( 'AU', 'NZ' ),
			rateLimit: new RateLimitHints( requestsPerSecond: 4.0, concurrency: 1 ),
			hosts: array( 'api.myob.com', 'arl2.api.myob.com', 'secure.myob.com' )
		);
	}

	/**
	 * The connect form: the developer key and secret, the currency of the file, the optional file login and the host.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::clientId( 'client_id', __( 'The API key of your app in my.MYOB (Developer tab).', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret(),
			CredentialFields::choice(
				'currency',
				__( 'Currency of the company file', 'estasoft-erp-crm-sync-engine' ),
				array(
					'AUD' => __( 'Australian dollar (AUD)', 'estasoft-erp-crm-sync-engine' ),
					'NZD' => __( 'New Zealand dollar (NZD)', 'estasoft-erp-crm-sync-engine' ),
				),
				'AUD',
				__( 'Orders in another currency are not sent.', 'estasoft-erp-crm-sync-engine' )
			),
			new CredentialField( 'company_file_user', __( 'Company file user name', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Only when the company file asks for its own login.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'company_file_password', __( 'Company file password', 'estasoft-erp-crm-sync-engine' ), true, false, __( 'Only when the company file asks for its own login.', 'estasoft-erp-crm-sync-engine' ), '', 'password', array(), 'advanced' ),
			new CredentialField(
				'api_host',
				__( 'MYOB address', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'Leave the default unless MYOB told you to use the other one.', 'estasoft-erp-crm-sync-engine' ),
				MyobCompany::HOST,
				'select',
				array(
					'api.myob.com'      => 'api.myob.com',
					'arl2.api.myob.com' => 'arl2.api.myob.com',
				),
				'advanced'
			),
			new CredentialField( 'company_file_id', __( 'Company file ID', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Filled in when you sign in. Leave empty.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * The API root: the host and /accountright, with the company file once one is chosen.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		$file = MyobCompany::fileOf( $connection );
		return 'https://' . MyobCompany::hostOf( $connection ) . '/accountright' . ( '' !== $file ? '/' . $file : '' );
	}

	/**
	 * The developer key, the API version and, when the file asks for its own login, that login.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		$headers = array(
			'x-myobapi-key'     => (string) $connection->config( 'client_id' ),
			'x-myobapi-version' => 'v2',
		);
		$token   = self::fileToken( $connection );
		if ( '' !== $token ) {
			$headers['x-myobapi-cftoken'] = $token;
		}
		return $headers;
	}

	/**
	 * Sign-in with the merchant's own app.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this->oauthProvider() );
	}

	/**
	 * Remove the company file's id, name and login from every message.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$user   = MyobPayloadReader::text( $connection->config( 'company_file_user' ) );
		foreach ( array( MyobCompany::fileOf( $connection ), MyobPayloadReader::text( $connection->config( 'company_file_name' ) ), $user, self::fileToken( $connection ) ) as $value ) {
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * Map MYOB's own error shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return MyobErrors::fromResponse( $response, $this->deps->clock->now()->getTimestamp() );
	}

	/**
	 * This connector is its own provider of OAuth settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * OAuth settings: MYOB's sign-in page (always asking for consent, which is what makes MYOB name the company file), its token endpoint, the client id and secret in the body, no PKCE.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		unset( $connection );
		return new OAuthConfig(
			authorizeUrl: 'https://secure.myob.com/oauth2/account/authorize',
			tokenUrl: 'https://secure.myob.com/oauth2/v1/authorize',
			scopes: self::SCOPES,
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'body',
			extraAuthorizeParams: array( 'prompt' => 'consent' ),
			extraTokenParams: array( 'scope' => implode( ' ', self::SCOPES ) ),
			accessTokenFallbackSeconds: 1200,
			allowedHosts: array( 'secure.myob.com' )
		);
	}

	/**
	 * The company files the person can open. The one named in the sign-in redirect comes first.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function organisations( Connection $connection ): array {
		$files = $this->servicesFor( $connection, false )->company->files();
		$first = MyobCompany::fileOf( $connection );
		$list  = array();
		foreach ( $files as $file ) {
			$id = strtolower( MyobPayloadReader::text( $file['Id'] ?? '' ) );
			if ( ! MyobCompany::isGuid( $id ) ) {
				continue;
			}
			$entry = array(
				'id'   => $id,
				'name' => MyobPayloadReader::text( $file['Name'] ?? $id ),
			);
			if ( '' !== $first && $id === $first ) {
				array_unshift( $list, $entry );
			} else {
				$list[] = $entry;
			}
		}
		return $list;
	}

	/**
	 * Remember the company file.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         File id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$id     = strtolower( trim( $id ) );
		$config = array( 'company_file_id' => MyobCompany::isGuid( $id ) ? $id : '' );
		foreach ( $this->organisations( $connection ) as $file ) {
			if ( $file['id'] === $id ) {
				$config['company_file_name'] = $file['name'];
			}
		}
		return $config;
	}

	/**
	 * Test connection: the company file, its country and the tax codes, and the items and account the options name.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On a failed call.
	 */
	protected function identify( Connection $connection ): TestResult {
		$services    = $this->servicesFor( $connection );
		$company     = $services->company;
		$file        = $company->file();
		$name        = MyobPayloadReader::text( $file['Name'] ?? $connection->config( 'company_file_name' ) );
		$codes       = $services->lookups->taxList();
		$permissions = array(
			'read_company_file' => null !== $file,
			'tax_codes_found'   => array() !== $codes,
		);
		foreach ( array( 'generic', 'fee', 'discount' ) as $kind ) {
			$number = $services->options->text( $kind . '_item' );
			if ( '' !== $number ) {
				$permissions[ $kind . '_item_found' ] = null !== $services->lookups->item( $number );
			}
		}
		$account = $services->options->text( 'payment_account' );
		if ( '' !== $account ) {
			$permissions['payment_account_found'] = '' !== $services->lookups->accountUid( $account );
		}
		$version  = MyobPayloadReader::text( $file['ProductVersion'] ?? '' );
		$detected = array(
			'country'   => $company->country(),
			'currency'  => $company->currency(),
			'tax_codes' => count( $codes ),
			'api_host'  => MyobCompany::hostOf( $connection ),
		);
		return new TestResult( true, 'MYOB Business API v2' . ( '' !== $version ? ' (' . $version . ')' : '' ), $name, array(), $permissions, null, $detected );
	}

	/**
	 * One cheap read for the circuit breaker.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->servicesFor( $connection, false )->company->files();
			return true;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new MyobFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new MyobDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record of an item before sending (finished records only).
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$services = $this->servicesFor( $connection );
		return match ( $item->entityType ) {
			EntityType::Order  => $services->orders->find( $item ),
			EntityType::Refund => $services->refunds->find( $item ),
			default            => null,
		};
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$services = $this->servicesFor( $connection );
		$result   = match ( $item->entityType ) {
			EntityType::Order    => $services->orders->send( $item, $payload ),
			EntityType::Refund   => $services->refunds->send( $item, $payload ),
			EntityType::Customer => $services->customerSender->send( $item, $payload ),
			default              => SendResult::failure( MyobErrors::refused( 'entity', __( 'MYOB takes orders, refunds and customers only.', 'estasoft-erp-crm-sync-engine' ) ) ),
		};
		$this->logWarnings( $result, $item );
		return $result;
	}

	/**
	 * Find a customer by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$card = $this->servicesFor( $connection )->customers->findByEmail( $email );
		if ( null === $card ) {
			return null;
		}
		$name = MyobPayloadReader::text( $card['CompanyName'] ?? '' );
		$name = '' !== $name ? $name : trim( MyobPayloadReader::text( $card['FirstName'] ?? '' ) . ' ' . MyobPayloadReader::text( $card['LastName'] ?? '' ) );
		return new ExternalRef( MyobPayloadReader::text( $card['UID'] ?? '' ), $name );
	}

	/**
	 * Find a product by item number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->servicesFor( $connection )->reader->product( $sku );
	}

	/**
	 * Search products by number or name.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->searchProducts( $query );
	}

	/**
	 * Stock of inventoried items.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       Item numbers.
	 * @param array<int,string> $warehouses Location identifiers; empty for the total.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->stock( $skus, $warehouses );
	}

	/**
	 * Facts about a synced order's invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The invoice.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->servicesFor( $connection )->reader->invoiceFacts( $ref );
	}

	/**
	 * Invoices for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       Window start (UTC).
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->reconcile( $from );
	}

	/**
	 * Tax codes for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		return $this->servicesFor( $connection )->lookups->taxList();
	}

	/**
	 * The company file login as MYOB's header wants it, or an empty string when the connection has none.
	 *
	 * @param Connection $connection Connection.
	 */
	private static function fileToken( Connection $connection ): string {
		$user = MyobPayloadReader::text( $connection->config( 'company_file_user' ) );
		if ( '' === $user ) {
			return '';
		}
		return base64_encode( $user . ':' . $connection->secret( 'company_file_password' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the company file login travels as MYOB's x-myobapi-cftoken header; this is not code.
	}

	/**
	 * The helpers for a connection, built once for the same connection and options.
	 *
	 * @param Connection $connection  Connection.
	 * @param bool       $requireFile Whether a company file must be chosen.
	 *
	 * @throws ApiException When a company file is required and none is chosen.
	 */
	private function servicesFor( Connection $connection, bool $requireFile = true ): MyobServices {
		if ( $requireFile && '' === MyobCompany::fileOf( $connection ) ) {
			$failure = MyobErrors::exception( MyobErrors::refused( 'company_file_id', __( 'No company file is chosen yet. Sign in with MYOB and choose the company file.', 'estasoft-erp-crm-sync-engine' ) ) );
			throw $failure;
		}
		$key = $connection->id . ':' . md5( (string) wp_json_encode( $connection->config ) );
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new MyobServices( $this->api( $connection ), $connection );
		}
		return $this->services[ $key ];
	}

	/**
	 * Write notes a send carried to the log (a cut value). Nothing here holds a secret.
	 *
	 * @param SendResult $result Result.
	 * @param Item       $item   Item.
	 */
	private function logWarnings( SendResult $result, Item $item ): void {
		$warnings = $result->ref?->extra['warnings'] ?? array();
		if ( ! is_array( $warnings ) || array() === $warnings ) {
			return;
		}
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) && '' !== $warning ) {
				$this->deps->logger->warning(
					$warning,
					array(
						'item'      => $item->id,
						'connector' => 'myob',
					)
				);
			}
		}
	}
}
