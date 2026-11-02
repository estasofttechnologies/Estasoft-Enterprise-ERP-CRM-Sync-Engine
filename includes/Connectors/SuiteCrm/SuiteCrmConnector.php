<?php
/**
 * The SuiteCRM connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\AbstractSalesConnector;
use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmFields;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmMappings;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderData;
use Estasoft\SyncEngine\Connectors\CrmCommon\Summary;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
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
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders become opportunities linked to a contact (and an account for a company), customers become contacts, refunds become notes on the
 * opportunity. The server is the merchant's own: its address is typed in, and sign-in uses a client made in Admin, OAuth2 Clients and
 * Tokens. See docs/connectors/suitecrm.md.
 */
final class SuiteCrmConnector extends AbstractSalesConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'suitecrm';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'SuiteCRM';

	/**
	 * Stock probability of each sales stage (the `sales_probability_dom` list of SuiteCRM 8).
	 *
	 * @var array<string,int>
	 */
	private const PROBABILITY = array(
		'Prospecting'          => 10,
		'Qualification'        => 20,
		'Needs Analysis'       => 25,
		'Value Proposition'    => 30,
		'Id. Decision Makers'  => 40,
		'Perception Analysis'  => 50,
		'Proposal/Price Quote' => 65,
		'Negotiation/Review'   => 80,
		'Closed Won'           => 100,
		'Closed Lost'          => 0,
	);

	/**
	 * Identity, flags, limits and the fact that the merchant types the host.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Crm,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( 2.0, 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * Option keys read from a connection.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'base_currency', 'stage_new', 'stage_paid', 'lead_source', 'create_accounts', 'match_products', 'unknown_sku', 'customer_policy', 'reference_field', 'total_mismatch' );
	}

	/**
	 * The API address of the server the merchant typed.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		return SuiteCrmAddress::base( $connection );
	}

	/**
	 * Bearer token from the shared OAuth engine (client credentials or password grant).
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( new SuiteCrmOAuth() );
	}

	/**
	 * The JSON:API media type is the one SuiteCRM documents.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		unset( $connection );
		return array( 'Accept' => SuiteCrmApi::MEDIA );
	}

	/**
	 * The connect form: the server, the keys made on it and, for the password grant, the sync user.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::address( 'address', __( 'SuiteCRM address', 'estasoft-erp-crm-sync-engine' ), __( 'The address you open SuiteCRM at, for example https://crm.example.com. A folder is allowed.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::choice(
				'version',
				__( 'SuiteCRM version', 'estasoft-erp-crm-sync-engine' ),
				array(
					'v8' => __( 'SuiteCRM 8 (API under /legacy/Api)', 'estasoft-erp-crm-sync-engine' ),
					'v7' => __( 'SuiteCRM 7 (API under /Api)', 'estasoft-erp-crm-sync-engine' ),
				),
				'v8'
			),
			CredentialFields::choice(
				'grant',
				__( 'Client type', 'estasoft-erp-crm-sync-engine' ),
				array(
					'client_credentials' => __( 'Client credentials (the client acts as its own user)', 'estasoft-erp-crm-sync-engine' ),
					'password'           => __( 'Password (a named sync user)', 'estasoft-erp-crm-sync-engine' ),
				),
				'client_credentials'
			),
			CredentialFields::clientId( 'client_id', __( 'From Admin, OAuth2 Clients and Tokens.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret(),
			new CredentialField( 'username', __( 'Sync user name', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Only for the password client type.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'app' ),
			new CredentialField( 'password', __( 'Sync user password', 'estasoft-erp-crm-sync-engine' ), true, false, __( 'Only for the password client type. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ), '', 'password', array(), 'app' ),
		);
	}

	/**
	 * Test connection: the user, and one read on each module the connector uses.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api     = new SuiteCrmApi( $this->api( $connection ) );
		$options = $this->options( $connection );
		$modules = array( 'Accounts', 'Contacts', 'Opportunities', 'Notes', 'Currencies' );
		if ( $options->flag( 'match_products', false ) ) {
			$modules[] = 'AOS_Products';
		}
		try {
			$user        = $api->currentUser();
			$permissions = array();
			foreach ( $modules as $module ) {
				$permissions[ 'read_' . strtolower( $module ) ] = $api->canRead( $module );
			}
		} catch ( SuiteCrmFailure $failure ) {
			return new TestResult( false, '', '', array(), array(), $failure->error );
		}
		$ok   = array() === array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed );
		$name = trim( (string) ( $user['full_name'] ?? $user['user_name'] ?? $user['name'] ?? '' ) );
		return new TestResult( $ok, 'SuiteCRM API v8', $name, array(), $permissions, null, array( 'api_prefix' => SuiteCrmAddress::prefix( $connection ) ) );
	}

	/**
	 * Target fields for the mapping screens (the neutral CRM paths).
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return CrmFields::catalog( $mappingType );
	}

	/**
	 * Version 1 mapping rules (the same for every CRM connector).
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return CrmMappings::rules( $mappingType );
	}

	/**
	 * A 404 page instead of JSON means URL rewriting is off or the address is wrong.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		if ( 404 === $response->status() && array() === $response->json() ) {
			return SuiteCrmFailure::notApi()->error;
		}
		return null;
	}

	/**
	 * Create or finish the opportunity of an order.
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
		try {
			$options  = $this->options( $connection );
			$records  = $this->records( $connection, $options );
			$existing = $records->opportunity( $order->reference );
			if ( null !== $existing ) {
				$problem = $this->amountProblem( $order, $existing['attributes'], $records, $options );
				if ( $problem instanceof ConnectorError ) {
					return SendResult::failure( $problem );
				}
				$this->finish( $records, $options, $order, $existing['id'] );
				return SendResult::existing( $this->orderRef( $existing, $order, $problem ) );
			}
			$this->checkProducts( $records, $options, $order );
			$currencyId = $records->currencyId( $order->currency );
			$contact    = $this->contact( $records, $options, $order->contact );
			$account    = $this->account( $records, $options, $order->contact );
			$stage      = $order->paid ? $options->text( 'stage_paid', 'Closed Won' ) : $options->text( 'stage_new', 'Prospecting' );
			$attributes = array(
				'name'        => $this->dealName( $order ),
				'amount'      => Amounts::decimal( $order->totalMinor, $order->currency ),
				'currency_id' => $currencyId,
				'date_closed' => $order->date,
				'sales_stage' => $stage,
				'probability' => self::PROBABILITY[ $stage ] ?? 10,
				'lead_source' => $options->text( 'lead_source', 'Web Site' ),
				'description' => Summary::order( $order ),
			);
			$field      = $options->text( 'reference_field' );
			if ( '' !== $field ) {
				$attributes[ $field ] = $order->reference;
			}
			$api     = $this->suiteApi( $connection );
			$created = $api->create( 'Opportunities', $attributes );
			$read    = $api->get( 'Opportunities', $created['id'] ) ?? $created;
			$problem = $this->amountProblem( $order, $read['attributes'], $records, $options, $currencyId );
			if ( $problem instanceof ConnectorError ) {
				return SendResult::failure( $problem );
			}
			$this->link( $records, $created['id'], $contact['id'], $account['id'] ?? '' );
			return SendResult::created( $this->orderRef( $created, $order, $problem ) );
		} catch ( SuiteCrmFailure $failure ) {
			return SendResult::failure( $failure->error );
		}
	}

	/**
	 * Create the note of a refund on the opportunity of its order.
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
		try {
			$records = $this->records( $connection, $this->options( $connection ) );
			$note    = $records->refundNote( $refund->reference );
			if ( null !== $note ) {
				return SendResult::existing( $this->ref( $note['id'], (string) ( $note['attributes']['name'] ?? $refund->reference ) ) );
			}
			$deal = $records->opportunity( $refund->orderRef );
			if ( null === $deal ) {
				SuiteCrmFailure::refuse( 'order_ref', __( 'The order of this refund is not in SuiteCRM.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$amount  = '' !== $refund->currency ? Amounts::decimal( $refund->amountMinor, $refund->currency ) . ' ' . $refund->currency : (string) $refund->amountMinor;
			$name    = $refund->reference . ' ' . $amount;
			$created = $this->suiteApi( $connection )->create(
				'Notes',
				array(
					'name'        => Text::limit( 'Refund ' . $name, 255 )[0],
					'description' => Summary::refund( $refund ),
					'parent_type' => 'Opportunities',
					'parent_id'   => $deal['id'],
				)
			);
			return SendResult::created( $this->ref( $created['id'], 'Refund ' . $name ) );
		} catch ( SuiteCrmFailure $failure ) {
			return SendResult::failure( $failure->error );
		}
	}

	/**
	 * Find or create the contact of a customer item.
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
						'system' => self::LABEL,
					),
					null,
					null,
					null,
					'email missing'
				)
			);
		}
		try {
			$options = $this->options( $connection );
			$records = $this->records( $connection, $options );
			$found   = $records->contactByEmail( $contact['email'] );
			if ( null !== $found ) {
				return SendResult::existing( $this->ref( $found['id'], $contact['email'] ) );
			}
			$made = $this->contact( $records, $options, $contact );
			return SendResult::created( $this->ref( $made['id'], $contact['email'] ) );
		} catch ( SuiteCrmFailure $failure ) {
			return SendResult::failure( $failure->error );
		}
	}

	/**
	 * A finished order: the opportunity exists and is linked to its contact.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 * @return ExternalRef|ConnectorError|null
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		try {
			$records = $this->records( $connection, $this->options( $connection ) );
			$deal    = $records->opportunity( $item->clientReference() );
			if ( null === $deal || array() === $this->suiteApi( $connection )->related( 'Opportunities', $deal['id'], 'contacts' ) ) {
				return null;
			}
			return $this->dealRef( $deal, (string) ( $deal['attributes']['name'] ?? $item->clientReference() ), $records, 0 );
		} catch ( SuiteCrmFailure $failure ) {
			return $failure->error;
		}
	}

	/**
	 * A finished refund: the note exists.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 * @return ExternalRef|ConnectorError|null
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		try {
			$note = $this->records( $connection, $this->options( $connection ) )->refundNote( $item->clientReference() );
			return null === $note ? null : $this->ref( $note['id'], (string) ( $note['attributes']['name'] ?? '' ) );
		} catch ( SuiteCrmFailure $failure ) {
			return $failure->error;
		}
	}

	/**
	 * Find a contact by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $email ): ?ExternalRef {
				$found = $this->records( $connection, $this->options( $connection ) )->contactByEmail( $email );
				if ( null === $found ) {
					return null;
				}
				$name = trim( (string) ( $found['attributes']['first_name'] ?? '' ) . ' ' . (string) ( $found['attributes']['last_name'] ?? '' ) );
				return $this->ref( $found['id'], '' !== $name ? $name : $email, null, array( 'email' => $email ) );
			}
		);
	}

	/**
	 * Find a product by part number (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $sku ): ?ExternalRef {
				$found = $this->records( $connection, $this->options( $connection ) )->product( $sku );
				return null === $found ? null : $this->ref( $found['id'], (string) ( $found['attributes']['part_number'] ?? $sku ), null, array( 'name' => (string) ( $found['attributes']['name'] ?? '' ) ) );
			}
		);
	}

	/**
	 * Search products by name or part number (the first 20).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return $this->guard(
			function () use ( $connection, $query ): array {
				$out = array();
				foreach ( $this->records( $connection, $this->options( $connection ) )->searchProducts( $query ) as $found ) {
					$out[] = $this->ref( $found['id'], (string) ( $found['attributes']['part_number'] ?? '' ), null, array( 'name' => (string) ( $found['attributes']['name'] ?? '' ) ) );
				}
				return $out;
			}
		);
	}

	/**
	 * Opportunities of the shop created from a day on, for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->guard(
			function () use ( $connection, $from ): array {
				$options = $this->options( $connection );
				$records = $this->records( $connection, $options );
				$rows    = $this->suiteApi( $connection )->find(
					'Opportunities',
					array(
						array( 'name', 'like', 'WC-%' ),
						array( 'date_entered', 'gte', $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ),
					),
					'and',
					'date_entered'
				);
				$refunds = $records->refundedByOpportunity( $from );
				$out     = array();
				foreach ( $rows as $row ) {
					$name = (string) ( $row['attributes']['name'] ?? '' );
					if ( 1 !== preg_match( '/^(WC-\d+)(?: |$)/', $name, $parts ) ) {
						continue;
					}
					$out[ $parts[1] ] = $this->dealRef( $row, $name, $records, $refunds[ $row['id'] ] ?? 0 );
				}
				return $out;
			}
		);
	}

	/**
	 * The name of an opportunity: the reference first, then the buyer, cut to the length SuiteCRM keeps.
	 *
	 * @param OrderData $order Order.
	 * @throws SuiteCrmFailure When the reference alone is too long.
	 */
	private function dealName( OrderData $order ): string {
		if ( Text::length( $order->reference ) >= SuiteCrmRecords::NAME_MAX ) {
			SuiteCrmFailure::refuse( 'reference', __( 'The order number is too long for the name of an opportunity.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return Text::limit( trim( $order->reference . ' ' . $order->fullName() ), SuiteCrmRecords::NAME_MAX )[0];
	}

	/**
	 * Compare the amount SuiteCRM holds with the order.
	 *
	 * @param OrderData           $order      Order.
	 * @param array<string,mixed> $attributes Attributes of the opportunity.
	 * @param SuiteCrmRecords     $records    Records.
	 * @param CrmOptions          $options    Options.
	 * @param string              $sentId     Currency id that was sent, or empty for an opportunity found.
	 * @return ConnectorError|string|null A warning text, an error, or null.
	 */
	private function amountProblem( OrderData $order, array $attributes, SuiteCrmRecords $records, CrmOptions $options, string $sentId = '' ): ConnectorError|string|null {
		$id = (string) ( $attributes['currency_id'] ?? '' );
		if ( '' !== $sentId && '' !== $id && $id !== $sentId ) {
			return $this->invalid( 'currency', __( 'SuiteCRM recorded the opportunity in another currency than the order.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$actual = '' !== $id ? $records->currencyIso( $id ) : '';
		if ( '' === $sentId && '' !== $actual && $actual !== $order->currency ) {
			return $this->totalProblem( $order, null, $actual, $options );
		}
		return $this->totalProblem( $order, Amounts::minor( $attributes['amount'] ?? null, $order->currency ), '', $options );
	}

	/**
	 * Check that every SKU exists as a product when the options ask for it.
	 *
	 * @param SuiteCrmRecords $records Records.
	 * @param CrmOptions      $options Options.
	 * @param OrderData       $order   Order.
	 * @throws SuiteCrmFailure When a SKU is unknown and free text is not allowed.
	 */
	private function checkProducts( SuiteCrmRecords $records, CrmOptions $options, OrderData $order ): void {
		if ( ! $options->flag( 'match_products', false ) || 'free_text' === $options->choice( 'unknown_sku', array( 'fail', 'free_text' ), 'fail' ) ) {
			return;
		}
		foreach ( $order->lines as $line ) {
			if ( '' !== $line->sku && null === $records->product( $line->sku ) ) {
				SuiteCrmFailure::refuseSku( $line->sku );
			}
		}
	}

	/**
	 * The contact of a buyer: found by email, or created.
	 *
	 * @param SuiteCrmRecords      $records Records.
	 * @param CrmOptions           $options Options.
	 * @param array<string,string> $contact Buyer.
	 * @return array{id:string,attributes:array<string,mixed>}
	 * @throws SuiteCrmFailure When the policy allows no new contact.
	 */
	private function contact( SuiteCrmRecords $records, CrmOptions $options, array $contact ): array {
		$found = $records->contactByEmail( $contact['email'] );
		if ( null !== $found ) {
			return $found;
		}
		if ( 'match_only' === $options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) ) {
			SuiteCrmFailure::refuse( 'contact.email', __( 'No contact with this email address exists in SuiteCRM, and the connection only matches existing contacts.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $records->createContact( $contact );
	}

	/**
	 * The account of a buyer's company, or null when the buyer gave none or accounts are off.
	 *
	 * @param SuiteCrmRecords      $records Records.
	 * @param CrmOptions           $options Options.
	 * @param array<string,string> $contact Buyer.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	private function account( SuiteCrmRecords $records, CrmOptions $options, array $contact ): ?array {
		if ( '' === ( $contact['company'] ?? '' ) || ! $options->flag( 'create_accounts', true ) ) {
			return null;
		}
		return $records->accountByName( $contact['company'] ) ?? $records->createAccount( $contact );
	}

	/**
	 * Finish an opportunity found: make sure it is linked to its contact and, for a company, its account.
	 *
	 * @param SuiteCrmRecords $records Records.
	 * @param CrmOptions      $options Options.
	 * @param OrderData       $order   Order.
	 * @param string          $dealId  Opportunity id.
	 * @throws SuiteCrmFailure When the policy allows no new contact.
	 */
	private function finish( SuiteCrmRecords $records, CrmOptions $options, OrderData $order, string $dealId ): void {
		$contact = $this->contact( $records, $options, $order->contact );
		$account = $this->account( $records, $options, $order->contact );
		$this->link( $records, $dealId, $contact['id'], $account['id'] ?? '' );
	}

	/**
	 * Link an opportunity to its account first and its contact last (the contact link is what marks the order finished).
	 *
	 * @param SuiteCrmRecords $records   Records.
	 * @param string          $dealId    Opportunity id.
	 * @param string          $contactId Contact id.
	 * @param string          $accountId Account id or empty.
	 */
	private function link( SuiteCrmRecords $records, string $dealId, string $contactId, string $accountId ): void {
		if ( '' !== $accountId ) {
			$records->ensureLink( 'Opportunities', $dealId, 'accounts', 'Accounts', $accountId );
		}
		$records->ensureLink( 'Opportunities', $dealId, 'contacts', 'Contacts', $contactId );
	}

	/**
	 * The reference of an opportunity.
	 *
	 * @param array{id:string,attributes:array<string,mixed>} $record  Opportunity.
	 * @param OrderData                                       $order   Order.
	 * @param ConnectorError|string|null                      $warning A warning text when a difference was accepted.
	 */
	private function orderRef( array $record, OrderData $order, ConnectorError|string|null $warning ): ExternalRef {
		return $this->ref(
			$record['id'],
			(string) ( $record['attributes']['name'] ?? $order->reference ),
			null,
			array(
				'total_minor' => $order->totalMinor,
				'currency'    => $order->currency,
				'warnings'    => is_string( $warning ) ? array( $warning ) : array(),
			)
		);
	}

	/**
	 * The reference of an opportunity read from SuiteCRM: its total in minor units by the exponent of its currency.
	 *
	 * @param array{id:string,attributes:array<string,mixed>} $deal     Opportunity.
	 * @param string                                          $name     Name of the opportunity.
	 * @param SuiteCrmRecords                                 $records  Records helper of the connection.
	 * @param int                                             $refunded Minor units refunded.
	 */
	private function dealRef( array $deal, string $name, SuiteCrmRecords $records, int $refunded ): ExternalRef {
		$currency = $records->currencyIso( (string) ( $deal['attributes']['currency_id'] ?? '' ) );
		return $this->ref(
			$deal['id'],
			$name,
			null,
			array(
				'total_minor'    => Amounts::minor( $deal['attributes']['amount'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
				'refunded_minor' => $refunded,
				'currency'       => $currency,
				'email'          => '',
			)
		);
	}

	/**
	 * Run a read and turn every failure into a ConnectorError.
	 *
	 * @param \Closure $work Work to run.
	 * @return mixed The work's result, or a ConnectorError.
	 */
	private function guard( \Closure $work ): mixed {
		try {
			return $work();
		} catch ( SuiteCrmFailure $failure ) {
			return $failure->error;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, self::LABEL );
		}
	}

	/**
	 * The API of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function suiteApi( Connection $connection ): SuiteCrmApi {
		return new SuiteCrmApi( $this->api( $connection ) );
	}

	/**
	 * The records helper of a connection.
	 *
	 * @param Connection $connection Connection.
	 * @param CrmOptions $options    Options.
	 */
	private function records( Connection $connection, CrmOptions $options ): SuiteCrmRecords {
		return new SuiteCrmRecords( $this->suiteApi( $connection ), $options );
	}
}
