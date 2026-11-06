<?php
/**
 * The Pennylane connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadSchema;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BearerAuth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
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
 * Sends WooCommerce orders to Pennylane as customer invoices (draft by default) and customers as company or individual customers, and reads the
 * invoice number and status and a night's list back. Refunds are not sent: the body that creates a credit note could not be read (see
 * docs/connectors/pennylane.md section 9). See that document for the facts, their sources and what could not be verified.
 */
final class PennylaneConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'pennylane';

	/**
	 * Name shown to the person.
	 */
	public const LABEL = 'Pennylane';

	/**
	 * Advanced settings.
	 */
	private const OPTIONS = array(
		'invoice_state'                => array(
			'default' => 'draft',
			'allowed' => array( 'draft', 'finalized' ),
		),
		'customer_policy'              => array(
			'default' => 'match_or_create',
			'allowed' => array( 'match_or_create', 'match_only', 'always_create', 'collective' ),
		),
		'collective_customer_id'       => array( 'default' => '' ),
		'unknown_sku'                  => array(
			'default' => 'refuse',
			'allowed' => array( 'refuse', 'custom' ),
		),
		'total_mismatch'               => array(
			'default' => 'fail',
			'allowed' => array( 'fail', 'warn' ),
		),
		'total_tolerance'              => array( 'default' => '' ),
		'payment_days'                 => array(
			'default' => 30,
			'min'     => 0,
			'max'     => 90,
		),
		'sales_ledger_account'         => array( 'default' => '' ),
		'shipping_ledger_account'      => array( 'default' => '' ),
		'customer_invoice_template_id' => array( 'default' => '' ),
		'timezone'                     => array( 'default' => 'Europe/Paris' ),
	);

	/**
	 * Months a token lasts, by the choice on the connect form.
	 */
	private const LIFETIMES = array(
		'1'  => 1,
		'6'  => 6,
		'12' => 12,
	);

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'FR' ),
			rateLimit: new RateLimitHints( 4.0, 1 ),
			hosts: array( 'app.pennylane.com' )
		);
	}

	/**
	 * The API address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://app.pennylane.com/api/external/v2';
	}

	/**
	 * Bearer token.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new BearerAuth( 'api_token' );
	}

	/**
	 * The connect form: the token and how long it lasts.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField(
				'api_token',
				__( 'API token', 'estasoft-enterprise-erpcrm-sync-engine' ),
				true,
				true,
				__( 'In Pennylane: Settings, Connectivity, Developers. Pennylane shows it once.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'password'
			),
			new CredentialField(
				'token_lifetime',
				__( 'Token lasts', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				true,
				__( 'The same choice you made when you created the token. You get a warning before it ends.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'12',
				'select',
				array(
					'1'         => __( '1 month', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'6'         => __( '6 months', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'12'        => __( '12 months', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'unlimited' => __( 'Unlimited', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
		);
	}

	/**
	 * Pennylane's 400, 401 (expired), 403 (missing scope), 409 and 422 answers.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new PennylaneErrorMapper() )->map( $response );
	}

	/**
	 * Is the token accepted: one cheap read, `GET /me` (it needs no scope).
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->api( $connection )->get( 'me' )->isSuccess();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Test connection.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api    = $this->api( $connection );
		$me     = $api->get( 'me' )->json();
		$scopes = $this->scopes( $me );
		$api->get( 'customers', array( 'limit' => 1 ) );
		$api->get( 'customer_invoices', array( 'limit' => 1 ) );
		$api->get( 'products', array( 'limit' => 1 ) );
		$known       = array() !== $scopes;
		$permissions = array(
			'customers'         => ! $known || in_array( 'customers:all', $scopes, true ),
			'customer_invoices' => ! $known || in_array( 'customer_invoices:all', $scopes, true ),
		);
		$company     = '';
		foreach ( array( $me['company']['name'] ?? null, $me['company_name'] ?? null, $me['name'] ?? null ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				$company = trim( $candidate );
				break;
			}
		}
		$detected = array( 'scopes' => $scopes );
		$months   = self::LIFETIMES[ PayloadReader::text( $connection->config( 'token_lifetime' ) ) ] ?? null;
		if ( null !== $months ) {
			$detected += $this->expiry( $connection, $months );
		}
		return new TestResult( true, 'API v2', '' !== $company ? $company : self::LABEL, array(), $permissions, null, $detected );
	}

	/**
	 * Target fields for a mapping type. Refunds have none: the connector sends no refunds.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return 'refunds' === $mappingType ? array() : PayloadSchema::fields( $mappingType, array(), true );
	}

	/**
	 * Starting rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return 'refunds' === $mappingType ? array() : PayloadSchema::rules( $mappingType, array(), true );
	}

	/**
	 * Look up the invoice by its external reference, and report it only when this connector's own send() has finished it (a draft in draft mode,
	 * a finalised invoice in finalised mode).
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$invoice = $this->documents()->findByReference( $this->api( $connection ), $item->clientReference() );
		if ( null === $invoice ) {
			return null;
		}
		$full = $this->documents()->read( $this->api( $connection ), PayloadReader::text( $invoice['id'] ?? '' ) ) ?? $invoice;
		if ( $options->is( 'invoice_state', 'finalized' ) && $this->documents()->isDraft( $full ) ) {
			return null;
		}
		return $this->documents()->ref( $full, array() );
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$api     = $this->api( $connection );
		switch ( $item->entityType ) {
			case EntityType::Order:
				$order = OrderPayload::parse( $payload, $item, self::LABEL );
				if ( $order instanceof ConnectorError ) {
					return SendResult::failure( $order );
				}
				return $this->documents()->sendOrder( $api, $item, $order, $options );
			case EntityType::Customer:
				$customer = OrderPayload::customer( $payload['customer'] ?? null );
				if ( '' === $customer['email'] ) {
					return SendResult::failure( Refusal::email( self::LABEL, $item->entityId ) );
				}
				$resolved = $this->customers()->resolve( $api, $customer, $options, $item->clientReference() );
				if ( $resolved instanceof ConnectorError ) {
					return SendResult::failure( $resolved );
				}
				$ref = new ExternalRef( $resolved['id'], $customer['name'], null, array( 'name' => $customer['name'] ) );
				return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
			default:
				return SendResult::failure( Refusal::value( self::LABEL, 'entity', __( 'This connector does not send this kind of item.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'unsupported entity' ) );
		}
	}

	/**
	 * Find the customer this connector made for an email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$customers = $this->customers();
		$found     = $customers->findByEmail( $this->api( $connection ), $email );
		if ( null === $found ) {
			return null;
		}
		$name = $customers->nameOf( $found );
		return new ExternalRef( PayloadReader::text( $found['id'] ?? '' ), $name, null, array( 'name' => $name ) );
	}

	/**
	 * Find a product by its reference.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        Reference.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$product = $this->lines()->product( $this->api( $connection ), trim( $sku ) );
		return null === $product ? null : $this->productRef( $product );
	}

	/**
	 * Search products by exact label or reference (the product filters offer no free text).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$api   = $this->api( $connection );
		$found = array();
		foreach ( array( 'reference', 'label' ) as $field ) {
			foreach ( ( new PennylaneApi() )->page( $api, 'products', array( array( $field, 'eq', $query ) ), null )['items'] as $product ) {
				$found[ PayloadReader::text( $product['id'] ?? '' ) ] = $this->productRef( $product );
			}
		}
		return array_values( $found );
	}

	/**
	 * Number and status of an invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		$invoice = $this->documents()->read( $this->api( $connection ), $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		$draft = $this->documents()->isDraft( $invoice );
		return array(
			'number'           => PayloadReader::text( $invoice['invoice_number'] ?? '' ),
			'status'           => $draft ? 'draft' : PayloadReader::text( $invoice['status'] ?? 'finalized' ),
			'authority_status' => '',
			'pdf_url'          => PayloadReader::text( $invoice['public_file_url'] ?? '' ),
			'qr'               => '',
			'issued_at'        => $draft ? '' : PayloadReader::text( $invoice['date'] ?? '' ),
		);
	}

	/**
	 * Invoices from a date on, keyed by client reference.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       Window start (UTC).
	 * @return array<string,ExternalRef>
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		$amounts = new Amounts();
		$rows    = ( new PennylaneApi() )->all(
			$this->api( $connection ),
			'customer_invoices',
			array(
				array( 'date', 'gteq', $from->format( 'Y-m-d' ) ),
				array( 'credit_note', 'eq', false ),
			)
		);
		$out     = array();
		foreach ( $rows as $invoice ) {
			$reference = PayloadReader::text( $invoice['external_reference'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $reference ) ) {
				continue;
			}
			$out[ $reference ] = new ExternalRef(
				PayloadReader::text( $invoice['id'] ?? '' ),
				PayloadReader::text( $invoice['invoice_number'] ?? '' ),
				null,
				array(
					'total_minor'    => (int) $amounts->toMinor( $invoice['currency_amount'] ?? 0, 2 ),
					'refunded_minor' => 0,
					'email'          => '',
				)
			);
		}
		return $out;
	}

	/**
	 * VAT codes the tax mapping can use. `FR_200` and `exempt` are documented; the other French codes are recalled and labelled so.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		unset( $connection );
		$unconfirmed = ' (' . __( 'to be confirmed', 'estasoft-enterprise-erpcrm-sync-engine' ) . ')';
		return array(
			array(
				'code'         => 'FR_200',
				'name'         => __( 'France 20 %', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '20',
			),
			array(
				'code'         => 'FR_100',
				'name'         => __( 'France 10 %', 'estasoft-enterprise-erpcrm-sync-engine' ) . $unconfirmed,
				'rate_percent' => '10',
			),
			array(
				'code'         => 'FR_55',
				'name'         => __( 'France 5.5 %', 'estasoft-enterprise-erpcrm-sync-engine' ) . $unconfirmed,
				'rate_percent' => '5.5',
			),
			array(
				'code'         => 'FR_21',
				'name'         => __( 'France 2.1 %', 'estasoft-enterprise-erpcrm-sync-engine' ) . $unconfirmed,
				'rate_percent' => '2.1',
			),
			array(
				'code'         => 'exempt',
				'name'         => __( 'Exempt from VAT', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
		);
	}

	/**
	 * The end date of the token. Pennylane shows none, so it is the day of the first test plus the lifetime the merchant chose. A later test of the
	 * same token (same fingerprint, same lifetime) keeps that first date, so the date does not slide forward with every test and the warning before
	 * it still comes. A new token or another lifetime starts again from now. The fingerprint is the first 16 hex characters of the SHA-256 of the token.
	 *
	 * @param Connection $connection Connection.
	 * @param int        $months     Months the token lasts.
	 * @return array<string,string>
	 */
	private function expiry( Connection $connection, int $months ): array {
		$fingerprint = substr( hash( 'sha256', $connection->secret( 'api_token' ) ), 0, 16 );
		$until       = $this->deps->clock->now()->modify( '+' . $months . ' months' )->format( 'Y-m-d H:i:s' );
		$stored      = $connection->config( 'detected' );
		$stored      = is_array( $stored ) ? $stored : array();
		$known       = PayloadReader::text( $stored['token_expires_on'] ?? '' );
		if (
			PayloadReader::text( $stored['token_fingerprint'] ?? '' ) === $fingerprint
			&& PayloadReader::text( $stored['token_months'] ?? '' ) === (string) $months
			&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $known )
			&& $known < $until
		) {
			$until = $known;
		}
		return array(
			'credential_expires_at' => $until,
			'token_expires_on'      => $until,
			'token_fingerprint'     => $fingerprint,
			'token_months'          => (string) $months,
		);
	}

	/**
	 * The token scopes in the answer of `GET /me`: every text that looks like `resource:readonly`, `resource:all` or a bare scope name.
	 *
	 * @param array<string,mixed> $me Decoded answer.
	 * @return array<int,string>
	 */
	private function scopes( array $me ): array {
		$found = array();
		$walk  = static function ( mixed $value ) use ( &$found, &$walk ): void {
			if ( is_string( $value ) && 1 === preg_match( '/^[a-z_]+(:[a-z]+)?$/', $value ) ) {
				$found[] = $value;
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $inner ) {
					$walk( $inner );
				}
			}
		};
		$walk( $me['scopes'] ?? array() );
		return array_values( array_unique( $found ) );
	}

	/**
	 * The reference of a product.
	 *
	 * @param array<string,mixed> $product Product from the API.
	 */
	private function productRef( array $product ): ExternalRef {
		return new ExternalRef(
			PayloadReader::text( $product['id'] ?? '' ),
			PayloadReader::text( $product['reference'] ?? '' ),
			null,
			array( 'name' => PayloadReader::text( $product['label'] ?? '' ) )
		);
	}

	/**
	 * Request helpers.
	 */
	private function pennylane(): PennylaneApi {
		return new PennylaneApi();
	}

	/**
	 * Customer helper.
	 */
	private function customers(): PennylaneCustomers {
		return new PennylaneCustomers( $this->pennylane() );
	}

	/**
	 * Line helper.
	 */
	private function lines(): PennylaneLines {
		return new PennylaneLines( new Amounts(), $this->pennylane() );
	}

	/**
	 * Order flow helper.
	 */
	private function documents(): PennylaneDocuments {
		return new PennylaneDocuments( $this->customers(), $this->lines(), $this->pennylane(), new Amounts() );
	}
}
