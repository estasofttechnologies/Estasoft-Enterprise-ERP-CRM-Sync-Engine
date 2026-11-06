<?php
/**
 * Zoho Books connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\AbstractSalesConnector;
use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderData;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderLine;
use Estasoft\SyncEngine\Connectors\CrmCommon\RefundData;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Connectors\Zoho\ZohoDataCentre;
use Estasoft\SyncEngine\Connectors\Zoho\ZohoOAuthProvider;
use Estasoft\SyncEngine\Connectors\Zoho\ZohoTokenAuth;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders become invoices, refunds become credit notes, customers become contacts (docs/connectors/zoho-books.md). Every pass starts
 * from a read; the create steps run once and later steps are repeatable.
 */
final class ZohoBooksConnector extends AbstractSalesConnector implements OAuthConnector {

	/**
	 * Largest contact name Zoho Books takes, characters.
	 */
	private const NAME_LIMIT = 200;

	/**
	 * Calls a day the connector allows itself, by Zoho Books plan: 90 percent of the documented allowance (1,000 Free, 2,000 Standard,
	 * 5,000 Professional, 10,000 Premium, Elite and Ultimate), because other integrations of the organisation draw on the same allowance.
	 */
	private const DAILY_CALLS = array(
		'free'         => 900,
		'standard'     => 1800,
		'professional' => 4500,
		'premium'      => 9000,
	);

	/**
	 * Connector facts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'zoho-books',
			label: 'Zoho Books',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 1.0, concurrency: 1, requestsPerMinute: 60, requestsPerDay: self::DAILY_CALLS['free'] ),
			hosts: ZohoDataCentre::allHosts()
		);
	}

	/**
	 * The limits of this connection: the daily allowance follows the plan the merchant chose (Free when none), so a small plan is never
	 * run into the ground by a busy shop.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function limitsFor( Connection $connection ): RateLimitHints {
		$base = $this->connectorMeta()->limits();
		$plan = $this->options( $connection )->choice( 'plan', array_keys( self::DAILY_CALLS ), 'free' );
		return new RateLimitHints( $base->requestsPerSecond, $base->concurrency, $base->requestsPerMinute, self::DAILY_CALLS[ $plan ] );
	}

	/**
	 * Option keys.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'organization_id', 'organization_currency', 'plan', 'unknown_sku', 'invoice_state', 'refund_state', 'record_payment', 'payment_account', 'payment_mode', 'refund_tax_id', 'total_mismatch' );
	}

	/**
	 * Connect screen fields.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::choice( 'data_centre', __( 'Zoho data centre', 'estasoft-enterprise-erpcrm-sync-engine' ), ZohoDataCentre::options(), 'com', __( 'The address you use to sign in to Zoho decides this.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::clientId(),
			CredentialFields::clientSecret(),
			new CredentialField( 'organization_id', __( 'Organisation id', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Filled in when you sign in and choose the organisation.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField(
				'plan',
				__( 'Zoho Books plan', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				false,
				__( 'Sets the daily call allowance. The sync stops at 90 percent of it and waits for the next day.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'free',
				'select',
				array(
					'free'         => __( 'Free (1,000 calls a day)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'standard'     => __( 'Standard (2,000 calls a day)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'professional' => __( 'Professional (5,000 calls a day)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'premium'      => __( 'Premium, Elite or Ultimate (10,000 calls a day)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField( 'refund_tax_id', __( 'Tax code for refunds', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Optional. The refund amount is treated as including this tax.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'payment_account', __( 'Account that receives payments', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Only needed to record payments.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * Sign-in settings; the payment scopes are asked for only when payments are recorded.
	 */
	public function oauthProvider(): OAuthProvider {
		return new ZohoOAuthProvider(
			function ( Connection $connection ): array {
				$scopes = array(
					'ZohoBooks.contacts.CREATE',
					'ZohoBooks.contacts.READ',
					'ZohoBooks.invoices.CREATE',
					'ZohoBooks.invoices.READ',
					'ZohoBooks.invoices.UPDATE',
					'ZohoBooks.creditnotes.CREATE',
					'ZohoBooks.creditnotes.READ',
					'ZohoBooks.creditnotes.UPDATE',
					'ZohoBooks.settings.READ',
				);
				if ( $this->options( $connection )->flag( 'record_payment', false ) ) {
					$scopes[] = 'ZohoBooks.customerpayments.CREATE';
					$scopes[] = 'ZohoBooks.customerpayments.READ';
				}
				return $scopes;
			}
		);
	}

	/**
	 * The organisations the signed-in user can choose from.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array {
		$list = array();
		foreach ( $this->books( $connection, true )->organizations() as $org ) {
			$list[] = array(
				'id'   => (string) ( $org['organization_id'] ?? '' ),
				'name' => (string) ( $org['name'] ?? '' ),
			);
		}
		return $list;
	}

	/**
	 * The config to store for the chosen organisation.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $id         Organisation id.
	 * @return array<string,string>
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		foreach ( $this->books( $connection, true )->organizations() as $org ) {
			if ( (string) ( $org['organization_id'] ?? '' ) === $id ) {
				return array(
					'organization_id'       => $id,
					'organization_name'     => (string) ( $org['name'] ?? '' ),
					'organization_currency' => strtoupper( (string) ( $org['currency_code'] ?? '' ) ),
				);
			}
		}
		return array( 'organization_id' => $id );
	}

	/**
	 * API host of the chosen data centre.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		return 'https://' . ZohoDataCentre::forConnection( $connection )->apiHost();
	}

	/**
	 * Zoho token header, refreshed through the shared token manager.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new ZohoTokenAuth( $this->oauthProvider(), $this->deps->tokens );
	}

	/**
	 * Vendor error mapping.
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ZohoBooksErrorMapper::map( $response );
	}

	/**
	 * Mapping fields.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ZohoBooksModel::fields( $mappingType );
	}

	/**
	 * Default mapping rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ZohoBooksModel::rules( $mappingType );
	}

	/**
	 * Tax codes of the organisation for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		try {
			$codes = array();
			foreach ( $this->books( $connection )->all( 'settings/taxes', 'taxes' ) as $tax ) {
				$codes[] = array(
					'code'         => (string) ( $tax['tax_id'] ?? '' ),
					'name'         => (string) ( $tax['tax_name'] ?? '' ),
					'rate_percent' => (string) ( $tax['tax_percentage'] ?? '0' ),
				);
			}
			return $codes;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Test connection: the organisation, what the token may read, the taxes found.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When Zoho refuses the sign-in.
	 */
	protected function identify( Connection $connection ): TestResult {
		$options = $this->options( $connection );
		$wanted  = $options->text( 'organization_id' );
		$orgs    = $this->books( $connection, true )->organizations();
		$org     = null;
		foreach ( $orgs as $candidate ) {
			if ( '' !== $wanted && (string) ( $candidate['organization_id'] ?? '' ) === $wanted ) {
				$org = $candidate;
			}
		}
		if ( null === $org && '' !== $wanted ) {
			throw new ApiException( $this->invalid( 'organization_id', __( 'Zoho Books has no organisation with this id for the signed-in user.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$org ??= $orgs[0] ?? array();
		$books = $this->books( $connection );
		if ( '' === $wanted ) {
			return new TestResult( false, 'Zoho Books API v3', (string) ( $org['name'] ?? 'Zoho Books' ), array(), array(), $this->invalid( 'organization_id', __( 'Choose the Zoho Books organisation in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) ), array() );
		}
		$permissions = array();
		foreach ( array( 'contacts', 'invoices', 'creditnotes' ) as $module ) {
			$permissions[ 'read_' . $module ] = $this->readable( $books, $module );
		}
		$taxes                     = $permissions['read_contacts'] ? $this->listTaxCodes( $connection ) : array();
		$permissions['read_taxes'] = is_array( $taxes );
		$missing                   = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error                     = array() === $missing ? null : $this->noPermission( str_replace( 'read_', 'read ', (string) $missing[0] ) );
		$detected                  = array(
			'currency'    => strtoupper( (string) ( $org['currency_code'] ?? '' ) ),
			'taxes'       => is_array( $taxes ) ? count( $taxes ) : null,
			'data_centre' => ZohoDataCentre::forConnection( $connection )->id(),
			'daily_calls' => $this->limitsFor( $connection )->requestsPerDay,
		);
		return new TestResult( null === $error, 'Zoho Books API v3', (string) ( $org['name'] ?? 'Zoho Books' ), array(), $permissions, $error, $detected );
	}

	/**
	 * Cheap health probe: one call that needs no organisation and proves the sign-in works.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== $this->books( $connection, true )->organizations();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Find a customer contact by email for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		try {
			$row = $this->findContact( $this->books( $connection ), $email );
			return null === $row ? null : new ExternalRef(
				(string) $row['contact_id'],
				(string) ( $row['contact_name'] ?? $email ),
				null,
				array(
					'email'    => $email,
					'currency' => (string) ( $row['currency_code'] ?? '' ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Find an item by SKU for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			$item = $this->findItem( $this->books( $connection ), $sku );
			return null === $item ? null : new ExternalRef( (string) $item['item_id'], $sku, null, array( 'name' => (string) ( $item['name'] ?? '' ) ) );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Search items by name or SKU for the SKU mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		try {
			$books = $this->books( $connection );
			$rows  = $books->all( 'items', 'items', array( 'search_text' => $query ) );
			if ( array() === $rows && '' !== $query ) {
				// Zoho searches names and descriptions; a SKU that is in neither is found by reading the item list.
				$rows = array_values( array_filter( $books->all( 'items', 'items' ), static fn( array $row ): bool => str_contains( Text::lower( (string) ( $row['sku'] ?? '' ) ), Text::lower( $query ) ) ) );
			}
			$refs = array();
			foreach ( array_slice( $rows, 0, 50 ) as $item ) {
				$refs[] = new ExternalRef( (string) ( $item['item_id'] ?? '' ), (string) ( $item['sku'] ?? '' ), null, array( 'name' => (string) ( $item['name'] ?? '' ) ) );
			}
			return $refs;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Status of an invoice for the order box.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The invoice reference returned by send().
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		try {
			$invoice = $this->books( $connection )->call( 'GET', 'invoices/' . rawurlencode( $ref->id ), array(), array(), true )['invoice'] ?? null;
			if ( ! is_array( $invoice ) ) {
				return null;
			}
			return array(
				'number'           => (string) ( $invoice['invoice_number'] ?? '' ),
				'status'           => (string) ( $invoice['status'] ?? '' ),
				'authority_status' => '',
				'pdf_url'          => (string) ( $invoice['invoice_url'] ?? '' ),
				'qr'               => '',
				'issued_at'        => (string) ( $invoice['date'] ?? '' ),
			);
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Invoices of the order references since a date, with totals.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			$found = array();
			$rows  = $this->books( $connection )->all(
				'invoices',
				'invoices',
				array(
					'reference_number_startswith' => 'WC-',
					'date_start'                  => $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' ),
				)
			);
			foreach ( $rows as $row ) {
				$reference = (string) ( $row['reference_number'] ?? '' );
				if ( 1 === preg_match( '/^WC-\d+$/', $reference ) && ! isset( $found[ $reference ] ) ) {
					$found[ $reference ] = $this->invoiceRef( $row, $reference, true );
				}
			}
			return $found;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * An invoice counts as found only when this connector's steps are done according to the options.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$options = $this->options( $connection );
		$row     = $this->findInvoice( $this->books( $connection ), $item->clientReference() );
		if ( null === $row ) {
			return null;
		}
		$status = (string) ( $row['status'] ?? '' );
		if ( 'void' !== $status && ( ( 'draft' === $status && 'sent' === $this->invoiceState( $options ) ) || ( $options->flag( 'record_payment', false ) && 'paid' !== $status ) ) ) {
			return null;
		}
		return $this->invoiceRef( $row, $item->clientReference(), false );
	}

	/**
	 * A credit note counts as found only when it is open if the options say so.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$row = $this->findCreditNote( $this->books( $connection ), $item->clientReference() );
		if ( null === $row || ( 'draft' === ( $row['status'] ?? '' ) && 'open' === $this->refundState( $this->options( $connection ) ) ) ) {
			return null;
		}
		return $this->creditRef( $row, $item->clientReference() );
	}

	/**
	 * Send an order as an invoice. Items and currency are settled before anything is created.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	protected function sendOrder( Connection $connection, Item $item, array $payload ): SendResult {
		$order = $this->reader()->order( $item, $payload );
		if ( $order instanceof ConnectorError ) {
			return SendResult::failure( $order );
		}
		$books   = $this->books( $connection );
		$options = $this->options( $connection );
		$row     = $this->findInvoice( $books, $order->reference );
		$existed = null !== $row;
		if ( null === $row ) {
			$created = $this->createInvoice( $books, $options, $order );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$invoice = $created;
		} else {
			$invoice = (array) ( $books->call( 'GET', 'invoices/' . rawurlencode( (string) $row['invoice_id'] ) )['invoice'] ?? $row );
		}

		$warnings = array();
		$problem  = $this->totalProblem( $order, Amounts::minor( $invoice['total'] ?? null, $order->currency ), (string) ( $invoice['currency_code'] ?? '' ), $options );
		if ( $problem instanceof ConnectorError ) {
			return SendResult::failure( $problem );
		}
		if ( is_string( $problem ) ) {
			$warnings[] = $problem;
		}
		$id = (string) ( $invoice['invoice_id'] ?? '' );
		if ( 'draft' === ( $invoice['status'] ?? '' ) && 'sent' === $this->invoiceState( $options ) ) {
			$books->call( 'POST', 'invoices/' . rawurlencode( $id ) . '/status/sent' );
			$invoice['status'] = 'sent';
		}
		if ( $order->paid && $options->flag( 'record_payment', false ) && '' !== $options->text( 'payment_account' ) && 'sent' === $this->invoiceState( $options ) ) {
			$invoice = $this->recordPayment( $books, $options, $order, $invoice );
		}
		$ref = $this->invoiceRef( $invoice, $order->reference, false, $warnings );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as a credit note.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	protected function sendRefund( Connection $connection, Item $item, array $payload ): SendResult {
		$refund = $this->reader()->refund( $item, $payload );
		if ( $refund instanceof ConnectorError ) {
			return SendResult::failure( $refund );
		}
		$books   = $this->books( $connection );
		$options = $this->options( $connection );
		$row     = $this->findCreditNote( $books, $refund->reference );
		if ( null === $row ) {
			$created = $this->createCreditNote( $books, $options, $refund );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$row     = $created;
			$existed = false;
		} else {
			$existed = true;
		}
		if ( 'draft' === ( $row['status'] ?? '' ) && 'open' === $this->refundState( $options ) ) {
			$books->call( 'POST', 'creditnotes/' . rawurlencode( (string) $row['creditnote_id'] ) . '/status/open' );
			$row['status'] = 'open';
		}
		$ref = $this->creditRef( $row, $refund->reference );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a customer: find the contact by email or create it.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	protected function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$contact = $this->reader()->contact( $payload );
		if ( '' === $contact['email'] ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Map003,
					array(
						'order'  => $item->entityId,
						'system' => $this->label(),
					),
					null,
					null,
					null,
					'no email'
				)
			);
		}
		$books = $this->books( $connection );
		$row   = $this->findContact( $books, $contact['email'] );
		if ( null !== $row ) {
			return SendResult::existing( new ExternalRef( (string) $row['contact_id'], (string) ( $row['contact_name'] ?? $contact['email'] ), null, array( 'email' => $contact['email'] ) ) );
		}
		$currency = strtoupper( (string) $this->options( $connection )->text( 'organization_currency' ) );
		$id       = $this->currencyId( $books, $currency );
		$created  = $this->createContact( $books, $contact, $id );
		return SendResult::created( new ExternalRef( (string) $created['contact_id'], (string) ( $created['contact_name'] ?? $contact['email'] ), null, array( 'email' => $contact['email'] ) ) );
	}

	/**
	 * API wrapper for a connection.
	 *
	 * @param Connection $connection Connection.
	 * @param bool       $anyOrg     True for the calls that need no organisation (listing them).
	 */
	private function books( Connection $connection, bool $anyOrg = false ): ZohoBooksApi {
		return new ZohoBooksApi( $this->api( $connection ), $anyOrg ? '' : $this->options( $connection )->text( 'organization_id' ), $this->label() );
	}

	/**
	 * Whether the token may read a module; a scope or permission refusal means no.
	 *
	 * @param ZohoBooksApi $books  API.
	 * @param string       $module Module path.
	 * @throws ApiException For any other failure.
	 */
	private function readable( ZohoBooksApi $books, string $module ): bool {
		try {
			$books->call( 'GET', $module, array( 'per_page' => 1 ) );
			return true;
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Auth004 === $exception->error()->code ) {
				return false;
			}
			throw $exception;
		}
	}

	/**
	 * Option invoice_state: sent (default) or draft.
	 *
	 * @param CrmOptions $options Options.
	 */
	private function invoiceState( CrmOptions $options ): string {
		return $options->choice( 'invoice_state', array( 'sent', 'draft' ), 'sent' );
	}

	/**
	 * Option refund_state: draft (default) or open.
	 *
	 * @param CrmOptions $options Options.
	 */
	private function refundState( CrmOptions $options ): string {
		return $options->choice( 'refund_state', array( 'draft', 'open' ), 'draft' );
	}

	/**
	 * Order two rows oldest first by creation time, then id.
	 *
	 * @param array<string,mixed> $a One row.
	 * @param array<string,mixed> $b Another row.
	 */
	private function byAge( array $a, array $b ): int {
		$by = strcmp( (string) ( $a['created_time'] ?? '' ), (string) ( $b['created_time'] ?? '' ) );
		if ( 0 !== $by ) {
			return $by;
		}
		return (int) ( $a['invoice_id'] ?? $a['creditnote_id'] ?? $a['contact_id'] ?? 0 ) <=> (int) ( $b['invoice_id'] ?? $b['creditnote_id'] ?? $b['contact_id'] ?? 0 );
	}

	/**
	 * The oldest row of a list whose field equals the value, ignoring case.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 * @param string                         $field Field to compare.
	 * @param string                         $value Wanted value.
	 * @return array<string,mixed>|null
	 */
	private function oldestWhere( array $rows, string $field, string $value ): ?array {
		usort( $rows, array( $this, 'byAge' ) );
		foreach ( $rows as $row ) {
			if ( Text::same( (string) ( $row[ $field ] ?? '' ), $value ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The oldest invoice with the reference.
	 *
	 * @param ZohoBooksApi $books     API.
	 * @param string       $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findInvoice( ZohoBooksApi $books, string $reference ): ?array {
		return $this->oldestWhere( $books->all( 'invoices', 'invoices', array( 'reference_number' => $reference ) ), 'reference_number', $reference );
	}

	/**
	 * The oldest credit note with the reference.
	 *
	 * @param ZohoBooksApi $books     API.
	 * @param string       $reference WC-number of the refund.
	 * @return array<string,mixed>|null
	 */
	private function findCreditNote( ZohoBooksApi $books, string $reference ): ?array {
		return $this->oldestWhere( $books->all( 'creditnotes', 'creditnotes', array( 'reference_number' => $reference ) ), 'reference_number', $reference );
	}

	/**
	 * Customer contacts with the email, oldest first.
	 *
	 * @param ZohoBooksApi $books API.
	 * @param string       $email Email.
	 * @return array<int,array<string,mixed>>
	 */
	private function contactsByEmail( ZohoBooksApi $books, string $email ): array {
		$rows = array_values( array_filter( $books->all( 'contacts', 'contacts', array( 'email' => $email ) ), static fn( array $row ): bool => Text::same( (string) ( $row['email'] ?? '' ), $email ) ) );
		usort( $rows, array( $this, 'byAge' ) );
		return $rows;
	}

	/**
	 * The oldest customer contact with the email.
	 *
	 * @param ZohoBooksApi $books API.
	 * @param string       $email Email.
	 * @return array<string,mixed>|null
	 */
	private function findContact( ZohoBooksApi $books, string $email ): ?array {
		return $this->contactsByEmail( $books, $email )[0] ?? null;
	}

	/**
	 * The item whose SKU equals the value, ignoring case. Zoho Books documents no SKU filter on the item list: `search_text` looks at names
	 * and descriptions. So the text search is tried first (cheap, and right when the SKU is part of the name), and when it holds no exact
	 * SKU match the item list is read page by page and compared here. The list is kept in $catalogue so an order with several lines reads
	 * it once.
	 *
	 * @param ZohoBooksApi                        $books     API.
	 * @param string                              $sku       SKU.
	 * @param array<int,array<string,mixed>>|null $catalogue Item list read earlier in this pass; filled when it is read.
	 * @return array<string,mixed>|null
	 */
	private function findItem( ZohoBooksApi $books, string $sku, ?array &$catalogue = null ): ?array {
		$hit = $this->oldestWhere( $books->all( 'items', 'items', array( 'search_text' => $sku ) ), 'sku', $sku );
		if ( null !== $hit ) {
			return $hit;
		}
		$catalogue ??= $books->all( 'items', 'items' );
		return $this->oldestWhere( $catalogue, 'sku', $sku );
	}

	/**
	 * Id of a currency in the organisation; empty currency means the organisation's own, which Zoho picks.
	 *
	 * @param ZohoBooksApi $books    API.
	 * @param string       $currency ISO code.
	 * @throws ApiException When the currency is not set up in Zoho Books.
	 */
	private function currencyId( ZohoBooksApi $books, string $currency ): string {
		if ( '' === $currency ) {
			return '';
		}
		foreach ( $books->all( 'settings/currencies', 'currencies' ) as $row ) {
			if ( Text::same( (string) ( $row['currency_code'] ?? '' ), $currency ) ) {
				return (string) ( $row['currency_id'] ?? '' );
			}
		}
		throw new ApiException( $this->invalid( 'currency', sprintf( /* translators: 1: currency code */ __( 'The currency %s is not set up in Zoho Books.', 'estasoft-enterprise-erpcrm-sync-engine' ), $currency ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}

	/**
	 * Reference to an invoice.
	 *
	 * @param array<string,mixed> $row       Invoice row.
	 * @param string              $reference WC-number.
	 * @param bool                $reconcile Add the keys reconciliation reads.
	 * @param array<int,string>   $warnings  Warnings for the result.
	 */
	private function invoiceRef( array $row, string $reference, bool $reconcile, array $warnings = array() ): ExternalRef {
		$currency = strtoupper( (string) ( $row['currency_code'] ?? '' ) );
		$extra    = array(
			'total_minor'    => Amounts::minor( $row['total'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
			'currency'       => $currency,
			'status'         => (string) ( $row['status'] ?? '' ),
			'invoice_number' => (string) ( $row['invoice_number'] ?? '' ),
			'warnings'       => $warnings,
		);
		if ( $reconcile ) {
			$extra += array(
				'refunded_minor' => null,
				'email'          => (string) ( $row['email'] ?? '' ),
			);
		}
		return new ExternalRef( (string) ( $row['invoice_id'] ?? '' ), $reference, isset( $row['invoice_url'] ) ? (string) $row['invoice_url'] : null, $extra );
	}

	/**
	 * Reference to a credit note.
	 *
	 * @param array<string,mixed> $row       Credit note row.
	 * @param string              $reference WC-number of the refund.
	 */
	private function creditRef( array $row, string $reference ): ExternalRef {
		$currency = strtoupper( (string) ( $row['currency_code'] ?? '' ) );
		return new ExternalRef(
			(string) ( $row['creditnote_id'] ?? '' ),
			$reference,
			null,
			array(
				'total_minor'       => Amounts::minor( $row['total'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
				'currency'          => $currency,
				'status'            => (string) ( $row['status'] ?? '' ),
				'creditnote_number' => (string) ( $row['creditnote_number'] ?? '' ),
			)
		);
	}

	/**
	 * Create a contact for the buyer. Zoho Books refuses a name that another contact already has (code 1001, "The customer ... already
	 * exists. Please specify a different name."), and two different buyers can share a name, so a refused name is tried once more with the
	 * buyer's email after it. The search by email has already said this buyer has no contact of their own.
	 *
	 * @param ZohoBooksApi         $books      API.
	 * @param array<string,string> $contact    Buyer.
	 * @param string               $currencyId Currency id, may be empty.
	 * @param string               $suffix     Text added to the name, for a second contact of the same buyer.
	 * @return array<string,mixed>
	 * @throws ApiException When Zoho refuses the contact for another reason, or refuses the name with the email too.
	 */
	private function createContact( ZohoBooksApi $books, array $contact, string $currencyId, string $suffix = '' ): array {
		try {
			return $this->postContact( $books, $contact, $currencyId, $suffix );
		} catch ( ApiException $exception ) {
			if ( '' === $contact['email'] || ! $this->nameTaken( $exception ) ) {
				throw $exception;
			}
			list( $email ) = Text::limit( $contact['email'], 120 );
			return $this->postContact( $books, $contact, $currencyId, $suffix . ' (' . $email . ')' );
		}
	}

	/**
	 * Whether Zoho refused a contact because its name is taken (code 1001, or the wording of that answer).
	 *
	 * @param ApiException $exception The refusal.
	 */
	private function nameTaken( ApiException $exception ): bool {
		$error = $exception->error();
		return ErrorCode::Val002 === $error->code && ( 'zoho code 1001' === $error->detail || 1 === preg_match( '/already exists/i', (string) ( $error->context['reason'] ?? '' ) ) );
	}

	/**
	 * One create call for a contact.
	 *
	 * @param ZohoBooksApi         $books      API.
	 * @param array<string,string> $contact    Buyer.
	 * @param string               $currencyId Currency id, may be empty.
	 * @param string               $suffix     Text added to the name.
	 * @return array<string,mixed>
	 */
	private function postContact( ZohoBooksApi $books, array $contact, string $currencyId, string $suffix ): array {
		$person       = trim( $contact['first_name'] . ' ' . $contact['last_name'] );
		$name         = '' !== $person ? $person : ( '' !== $contact['company'] ? $contact['company'] : $contact['email'] );
		list( $name ) = Text::limit( $name, self::NAME_LIMIT - Text::length( $suffix ) );
		$name        .= $suffix;
		$address      = array_filter(
			array(
				'address' => $contact['street'],
				'city'    => $contact['city'],
				'state'   => $contact['state'],
				'zip'     => $contact['postcode'],
				'country' => $contact['country'],
			),
			static fn( string $value ): bool => '' !== $value
		);
		$body         = array_filter(
			array(
				'contact_name'      => $name,
				'company_name'      => $contact['company'],
				'contact_type'      => 'customer',
				'customer_sub_type' => '' !== $contact['company'] ? 'business' : 'individual',
				'currency_id'       => $currencyId,
				'billing_address'   => $address,
				'contact_persons'   => array(
					array_filter(
						array(
							'first_name'         => $contact['first_name'],
							'last_name'          => $contact['last_name'],
							'email'              => $contact['email'],
							'phone'              => $contact['phone'],
							'is_primary_contact' => true,
						),
						static fn( $value ): bool => '' !== $value
					),
				),
			),
			static fn( $value ): bool => '' !== $value && array() !== $value
		);
		$json         = $books->call( 'POST', 'contacts', array(), $body );
		return (array) ( $json['contact'] ?? array() );
	}

	/**
	 * Item per line index, or an error before anything is created.
	 *
	 * @param ZohoBooksApi $books   API.
	 * @param CrmOptions   $options Options.
	 * @param OrderData    $order   Order.
	 * @return array<int,array<string,mixed>|null>|ConnectorError
	 */
	private function resolveItems( ZohoBooksApi $books, CrmOptions $options, OrderData $order ): array|ConnectorError {
		$items     = array();
		$catalogue = null;
		foreach ( $order->lines as $index => $line ) {
			$item = '' !== $line->sku ? $this->findItem( $books, $line->sku, $catalogue ) : null;
			if ( null === $item && 'free_text' !== $options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return $this->unknownSku( '' !== $line->sku ? $line->sku : $line->name );
			}
			$items[ $index ] = $item;
		}
		return $items;
	}

	/**
	 * Create the invoice: items, currency and contact are settled first.
	 *
	 * @param ZohoBooksApi $books   API.
	 * @param CrmOptions   $options Options.
	 * @param OrderData    $order   Order.
	 * @return array<string,mixed>|ConnectorError The created invoice.
	 */
	private function createInvoice( ZohoBooksApi $books, CrmOptions $options, OrderData $order ): array|ConnectorError {
		$items = $this->resolveItems( $books, $options, $order );
		if ( $items instanceof ConnectorError ) {
			return $items;
		}
		$currencyId = $this->currencyId( $books, $order->currency );
		$contacts   = $this->contactsByEmail( $books, $order->email() );
		$contact    = null;
		foreach ( $contacts as $candidate ) {
			if ( '' === (string) ( $candidate['currency_code'] ?? '' ) || strtoupper( (string) $candidate['currency_code'] ) === $order->currency ) {
				$contact = $candidate;
				break;
			}
		}
		// A Zoho Books contact has one currency, and its documents must use it: a buyer who pays in another currency gets a second contact.
		$suffix    = array() !== $contacts ? ' (' . $order->currency . ')' : '';
		$contactId = null !== $contact ? (string) $contact['contact_id'] : (string) ( $this->createContact( $books, $order->contact, $currencyId, $suffix )['contact_id'] ?? '' );

		$lines = array();
		foreach ( $order->lines as $index => $line ) {
			$lines[] = $this->lineBody( $order->currency, $line, $items[ $index ] ?? null );
		}
		if ( $order->shippingMinor > 0 ) {
			$lines[] = $this->extraLine( __( 'Shipping', 'estasoft-enterprise-erpcrm-sync-engine' ), $order->shippingMinor, $order->currency, $order->shippingTaxCode );
		}
		foreach ( $order->fees as $fee ) {
			$lines[] = $this->extraLine( '' !== $fee['name'] ? $fee['name'] : __( 'Fee', 'estasoft-enterprise-erpcrm-sync-engine' ), $fee['total'], $order->currency, $fee['tax_code'] );
		}
		$body = array(
			'customer_id'      => $contactId,
			'reference_number' => $order->reference,
			'date'             => $order->date,
			'currency_id'      => $currencyId,
			'is_inclusive_tax' => $order->pricesIncludeTax,
			'line_items'       => $lines,
		);
		if ( '' !== $order->note ) {
			list( $body['notes'] ) = Text::limit( $order->note, 5000 );
		}
		if ( $order->discountMinor > 0 ) {
			$body['discount']               = Amounts::decimal( $order->discountMinor, $order->currency );
			$body['is_discount_before_tax'] = true;
			$body['discount_type']          = 'entity_level';
		}
		$json = $books->call( 'POST', 'invoices', array(), array_filter( $body, static fn( $value ): bool => '' !== $value ) );
		return (array) ( $json['invoice'] ?? array() );
	}

	/**
	 * One invoice line: unit price and quantity when they multiply to the line total, else the total as one unit.
	 *
	 * @param string                   $currency Currency.
	 * @param OrderLine                $line     Line.
	 * @param array<string,mixed>|null $item     Matching Zoho item, if any.
	 * @return array<string,mixed>
	 */
	private function lineBody( string $currency, OrderLine $line, ?array $item ): array {
		$exact = $line->isWhole() && $line->unitMinor * (int) $line->quantity === $line->totalMinor;
		$body  = array(
			'name'     => $exact ? $line->name : $line->name . ' x ' . $line->quantity,
			'rate'     => Amounts::number( $exact ? $line->unitMinor : $line->totalMinor, $currency ),
			'quantity' => $exact ? (int) $line->quantity : 1,
		);
		if ( null !== $item ) {
			$body['item_id'] = (string) $item['item_id'];
		} elseif ( '' !== $line->sku ) {
			$body['description'] = $line->sku;
		}
		if ( '' !== $line->taxCode ) {
			$body['tax_id'] = $line->taxCode;
		}
		return $body;
	}

	/**
	 * A free-text line for shipping or a fee.
	 *
	 * @param string $name     Name.
	 * @param int    $minor    Amount without tax, minor units.
	 * @param string $currency Currency.
	 * @param string $taxCode  Tax code, may be empty.
	 * @return array<string,mixed>
	 */
	private function extraLine( string $name, int $minor, string $currency, string $taxCode ): array {
		$body = array(
			'name'     => $name,
			'rate'     => Amounts::number( max( 0, $minor ), $currency ),
			'quantity' => 1,
		);
		if ( '' !== $taxCode ) {
			$body['tax_id'] = $taxCode;
		}
		return $body;
	}

	/**
	 * Record the payment of a paid order once: look first, create only when none exists and the invoice still has a balance. The amount
	 * is the invoice's balance as Zoho reports it now, not the order total: an invoice that is part paid or has a credit applied owes less,
	 * and Zoho refuses a payment above the balance. The date is the day the order was paid.
	 *
	 * @param ZohoBooksApi        $books   API.
	 * @param CrmOptions          $options Options.
	 * @param OrderData           $order   Order.
	 * @param array<string,mixed> $invoice Invoice.
	 * @return array<string,mixed> The invoice as read after the payment.
	 */
	private function recordPayment( ZohoBooksApi $books, CrmOptions $options, OrderData $order, array $invoice ): array {
		$id      = (string) $invoice['invoice_id'];
		$current = (array) ( $books->call( 'GET', 'invoices/' . rawurlencode( $id ) )['invoice'] ?? $invoice );
		$balance = Amounts::minor( $current['balance'] ?? null, $this->documentCurrency( $current, $order ) );
		if ( 'paid' === ( $current['status'] ?? '' ) || null === $balance || $balance <= 0 ) {
			return $current;
		}
		$existing = $this->oldestWhere( $books->all( 'customerpayments', 'customerpayments', array( 'reference_number' => $order->reference ) ), 'reference_number', $order->reference );
		if ( null === $existing ) {
			$amount = Amounts::number( $balance, $this->documentCurrency( $current, $order ) );
			$books->call(
				'POST',
				'customerpayments',
				array(),
				array(
					'customer_id'      => (string) ( $current['customer_id'] ?? '' ),
					'payment_mode'     => $options->text( 'payment_mode', 'Others' ),
					'amount'           => $amount,
					'date'             => '' !== $order->paidDate ? $order->paidDate : $order->date,
					'reference_number' => $order->reference,
					'account_id'       => $options->text( 'payment_account' ),
					'invoices'         => array(
						array(
							'invoice_id'     => $id,
							'amount_applied' => $amount,
						),
					),
				)
			);
		}
		return (array) ( $books->call( 'GET', 'invoices/' . rawurlencode( $id ) )['invoice'] ?? $current );
	}

	/**
	 * Currency of a document as Zoho reports it, else the order's.
	 *
	 * @param array<string,mixed> $document Invoice row.
	 * @param OrderData           $order    Order.
	 */
	private function documentCurrency( array $document, OrderData $order ): string {
		$code = strtoupper( (string) ( $document['currency_code'] ?? '' ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : $order->currency;
	}

	/**
	 * Create the credit note of a refund against the customer and currency of the order's invoice.
	 *
	 * @param ZohoBooksApi $books   API.
	 * @param CrmOptions   $options Options.
	 * @param RefundData   $refund  Refund.
	 * @return array<string,mixed>|ConnectorError
	 */
	private function createCreditNote( ZohoBooksApi $books, CrmOptions $options, RefundData $refund ): array|ConnectorError {
		$row = $this->findInvoice( $books, $refund->orderRef );
		if ( null === $row ) {
			/* translators: 1: order reference */
			return $this->invalid( 'order', sprintf( __( 'The order %s is not in Zoho Books yet.', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->orderRef ) );
		}
		$invoice  = (array) ( $books->call( 'GET', 'invoices/' . rawurlencode( (string) $row['invoice_id'] ) )['invoice'] ?? $row );
		$currency = '' !== $refund->currency ? $refund->currency : strtoupper( (string) ( $invoice['currency_code'] ?? '' ) );
		$line     = array(
			'name'        => $refund->orderRef,
			'description' => '' !== $refund->reason ? $refund->reason : $refund->reference,
			'rate'        => Amounts::number( $refund->amountMinor, $currency ),
			'quantity'    => 1,
		);
		if ( '' !== $options->text( 'refund_tax_id' ) ) {
			$line['tax_id'] = $options->text( 'refund_tax_id' );
		}
		$body = array(
			'customer_id'      => (string) ( $invoice['customer_id'] ?? '' ),
			'reference_number' => $refund->reference,
			'date'             => $refund->date,
			'is_inclusive_tax' => true,
			'line_items'       => array( $line ),
			/* translators: 1: order reference, 2: invoice number */
			'notes'            => sprintf( __( 'Refund of order %1$s (invoice %2$s)', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->orderRef, (string) ( $invoice['invoice_number'] ?? '' ) ),
		);
		if ( isset( $invoice['currency_id'] ) ) {
			$body['currency_id'] = (string) $invoice['currency_id'];
		}
		$json = $books->call( 'POST', 'creditnotes', array(), $body );
		return (array) ( $json['creditnote'] ?? array() );
	}
}
