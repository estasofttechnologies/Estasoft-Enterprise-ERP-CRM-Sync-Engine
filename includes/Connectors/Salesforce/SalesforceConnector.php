<?php
/**
 * Salesforce connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Salesforce;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\AbstractSalesConnector;
use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmFields;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmMappings;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderData;
use Estasoft\SyncEngine\Connectors\CrmCommon\ReferenceField;
use Estasoft\SyncEngine\Connectors\CrmCommon\Summary;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
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
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders become Opportunities with a primary Contact Role, customers become Contacts (and Accounts), refunds become completed Tasks
 * (docs/connectors/salesforce.md). Every search is SOQL; the Contact Role is written last and marks an Opportunity as finished.
 */
final class SalesforceConnector extends AbstractSalesConnector implements OAuthConnector {

	/**
	 * Calls left in the 24-hour allocation below which the connector stops and waits.
	 */
	private const ALLOCATION_FLOOR = 100;

	/**
	 * Largest Description Salesforce takes, characters.
	 */
	private const DESCRIPTION_LIMIT = 32000;

	/**
	 * API name of the custom field the merchant adds to Opportunity for the order reference: Text(40), External ID, Unique.
	 */
	public const REFERENCE_FIELD = 'Woo_Order_Ref__c';

	/**
	 * Connector facts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'salesforce',
			label: 'Salesforce',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 4.0, concurrency: 2 ),
			hosts: array( '*.salesforce.com' )
		);
	}

	/**
	 * Option keys.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'api_version', 'reference_field', 'stage_paid', 'stage_unpaid', 'org_currency', 'lines', 'pricebook', 'unknown_sku', 'shipping_product', 'fee_product', 'discount_product', 'total_mismatch' );
	}

	/**
	 * Connect screen fields.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField( 'domain', __( 'My Domain address', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'For example mycompany.my.salesforce.com. For a sandbox use its own address.', 'estasoft-erp-crm-sync-engine' ), '', 'text' ),
			new CredentialField( 'client_id', __( 'Consumer key', 'estasoft-erp-crm-sync-engine' ), false, true, '', '', 'text', array(), 'app' ),
			new CredentialField( 'client_secret', __( 'Consumer secret', 'estasoft-erp-crm-sync-engine' ), true, true, __( 'Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ), '', 'password', array(), 'app' ),
			new CredentialField( 'stage_paid', __( 'Stage for paid orders', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Leave empty for Closed Won.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'stage_unpaid', __( 'Stage for unpaid orders', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Leave empty for Prospecting.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'org_currency', __( 'Currency of the org', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Needed only when the org does not use several currencies; orders in another currency are refused.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'api_version', __( 'API version', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'For example v66.0.', 'estasoft-erp-crm-sync-engine' ), 'v66.0', 'text', array(), 'advanced' ),
			new CredentialField(
				'auth_method',
				__( 'Sign-in method', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				'',
				'web_server',
				'select',
				array(
					'web_server' => __( 'Sign in with Salesforce', 'estasoft-erp-crm-sync-engine' ),
					'jwt_bearer' => __( 'Certificate (JWT bearer)', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField( 'jwt_username', __( 'User name for the certificate sign-in', 'estasoft-erp-crm-sync-engine' ), false, false, '', '', 'text', array(), 'advanced' ),
			new CredentialField( 'private_key', __( 'Private key (PEM)', 'estasoft-erp-crm-sync-engine' ), true, false, __( 'Only for the certificate sign-in. Saved encrypted.', 'estasoft-erp-crm-sync-engine' ), '', 'textarea', array(), 'advanced' ),
		);
	}

	/**
	 * Sign-in settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return new SalesforceOAuthProvider();
	}

	/**
	 * The org the signed-in user belongs to.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array {
		$row = $this->salesforce( $connection )->query( 'SELECT Id, Name FROM Organization LIMIT 1' )[0] ?? array();
		return array(
			array(
				'id'   => (string) ( $row['Id'] ?? '' ),
				'name' => (string) ( $row['Name'] ?? 'Salesforce' ),
			),
		);
	}

	/**
	 * The config to store for the org.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $id         Organisation id.
	 * @return array<string,string>
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$list = $this->organisations( $connection );
		return array(
			'org_id'   => $id,
			'org_name' => (string) ( $list[0]['name'] ?? '' ),
		);
	}

	/**
	 * The merchant's My Domain address.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When the address is not a Salesforce address.
	 */
	protected function baseUrl( Connection $connection ): string {
		$host = SalesforceOAuthProvider::host( $connection );
		if ( '' === $host ) {
			throw new ApiException( $this->invalid( 'domain', __( 'Enter your My Domain address, for example mycompany.my.salesforce.com.', 'estasoft-erp-crm-sync-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return 'https://' . $host;
	}

	/**
	 * Bearer token from the shared token manager.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this->oauthProvider() );
	}

	/**
	 * Vendor error mapping.
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return SalesforceErrorMapper::map( $response );
	}

	/**
	 * Mapping fields.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return CrmFields::catalog( $mappingType );
	}

	/**
	 * Default mapping rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return CrmMappings::rules( $mappingType );
	}

	/**
	 * Test connection: the API version, the org, what the user may create, the currency setup and the field that carries the order
	 * reference. A single-currency org must say which currency it works in: an order in another currency would otherwise be stored as an
	 * amount with no currency, which is wrong money. The reference field is looked for among the fields the user can see (the describe
	 * answer lists exactly those); when it is missing the Opportunity name stays the key and the test says so.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When Salesforce refuses the call.
	 */
	protected function identify( Connection $connection ): TestResult {
		$sf      = $this->salesforce( $connection );
		$options = $this->options( $connection );
		$version = $this->version( $options );
		if ( ! in_array( $version, $sf->versions(), true ) ) {
			throw new ApiException( $this->invalid( 'api_version', __( 'This org does not serve the API version you entered.', 'estasoft-erp-crm-sync-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$org         = $this->organisations( $connection )[0];
		$permissions = array();
		foreach ( array( 'Opportunity', 'Contact', 'Task', 'OpportunityContactRole' ) as $object ) {
			$permissions[ 'create_' . strtolower( $object ) ] = $sf->describe( $object )['createable'];
		}
		$multi    = $sf->hasField( 'Opportunity', 'CurrencyIsoCode' );
		$detected = array(
			'api_version'    => $version,
			'multi_currency' => $multi,
		);
		$missing  = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error    = array() === $missing ? null : $this->noPermission( str_replace( array( 'create_', 'opportunitycontactrole' ), array( 'create ', 'contact roles' ), (string) $missing[0] ) );
		if ( null === $error && ! $multi && '' === $this->orgCurrency( $options ) ) {
			$error = $this->currencyMissing();
		}
		if ( null === $error && '' === $options->text( 'reference_field' ) ) {
			// Not stored for later: the field is looked for in the describe answer on every pass, so it is used from the moment it exists.
			$found                               = $sf->hasField( 'Opportunity', self::REFERENCE_FIELD );
			$permissions['find_reference_field'] = $found;
			$detected['reference_mode']          = $found ? 'field' : 'name';
			if ( ! $found ) {
				$detected['warning'] = 'reference_field';
			}
		}
		return new TestResult( null === $error, 'Salesforce API ' . $version, (string) $org['name'], array(), $permissions, $error, $detected );
	}

	/**
	 * Cheap health probe: one query that needs only API access.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== $this->salesforce( $connection )->query( 'SELECT Id FROM Organization LIMIT 1' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Find a contact by email for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		try {
			$row = $this->findContact( $this->salesforce( $connection ), $email );
			return null === $row ? null : new ExternalRef( (string) $row['Id'], (string) ( '' !== trim( (string) ( $row['Name'] ?? '' ) ) ? $row['Name'] : $email ), null, array( 'email' => $email ) );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Find a product by product code for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			$rows = $this->salesforce( $connection )->query( 'SELECT Id, Name, ProductCode FROM Product2 WHERE IsActive = true AND ProductCode = ' . $this->literal( $sku ) . ' ORDER BY CreatedDate ASC' );
			foreach ( $rows as $row ) {
				if ( Text::same( (string) ( $row['ProductCode'] ?? '' ), $sku ) ) {
					return new ExternalRef( (string) $row['Id'], $sku, null, array( 'name' => (string) ( $row['Name'] ?? '' ) ) );
				}
			}
			return null;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Search products by name or code for the SKU mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		try {
			$like = $this->literal( '%' . str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $query ) . '%' );
			$refs = array();
			foreach ( $this->salesforce( $connection )->query( "SELECT Id, Name, ProductCode FROM Product2 WHERE IsActive = true AND (Name LIKE {$like} OR ProductCode LIKE {$like}) ORDER BY Name ASC LIMIT 50" ) as $row ) {
				$refs[] = new ExternalRef( (string) $row['Id'], (string) ( $row['ProductCode'] ?? '' ), null, array( 'name' => (string) ( $row['Name'] ?? '' ) ) );
			}
			return $refs;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Opportunities created since a date that carry a WooCommerce reference, in the reference field or in the name.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			$sf      = $this->salesforce( $connection );
			$options = $this->options( $connection );
			$ref     = $this->reference( $sf, $connection );
			$multi   = $sf->hasField( 'Opportunity', 'CurrencyIsoCode' );
			if ( ! $multi && '' === $this->orgCurrency( $options ) ) {
				return $this->currencyMissing(); // Reading amounts with a guessed currency would make wrong money look right.
			}
			$columns = array_unique( array_merge( array( 'Id' ), $ref->searchFields(), array( 'Amount', 'CreatedDate' ), $multi ? array( 'CurrencyIsoCode' ) : array() ) );
			$tests   = array_map( static fn( string $field ): string => "{$field} LIKE 'WC-%'", $ref->searchFields() );
			$since   = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			$where   = count( $tests ) > 1 ? '(' . implode( ' OR ', $tests ) . ')' : $tests[0];
			$found   = array();
			foreach ( $sf->query( 'SELECT ' . implode( ', ', $columns ) . " FROM Opportunity WHERE {$where} AND CreatedDate >= {$since} ORDER BY CreatedDate ASC" ) as $row ) {
				$reference = $ref->readFrom( $row );
				if ( '' === $reference || isset( $found[ $reference ] ) ) {
					continue;
				}
				$currency            = $multi ? strtoupper( (string) ( $row['CurrencyIsoCode'] ?? '' ) ) : $this->orgCurrency( $options );
				$found[ $reference ] = new ExternalRef(
					(string) $row['Id'],
					$reference,
					null,
					array(
						'total_minor'    => Amounts::minor( $row['Amount'] ?? null, $currency ) ?? 0,
						'refunded_minor' => null,
						'email'          => '',
						'currency'       => $currency,
					)
				);
			}
			return $found;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * An Opportunity is finished when its primary Contact Role exists: the role is written last.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$sf  = $this->salesforce( $connection );
		$opp = $this->findOpportunity( $sf, $this->reference( $sf, $connection ), $item->clientReference() );
		if ( null === $opp || ! $this->hasRole( $sf, (string) $opp['Id'] ) ) {
			return null;
		}
		return $this->oppRef( $opp, $item->clientReference(), $this->options( $connection ) );
	}

	/**
	 * A refund is finished when its Task exists.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$sf    = $this->salesforce( $connection );
		$order = 1 === preg_match( '/^order:(\d+)$/', $item->partitionKey, $parts ) ? 'WC-' . $parts[1] : '';
		$opp   = '' === $order ? null : $this->findOpportunity( $sf, $this->reference( $sf, $connection ), $order );
		if ( null === $opp ) {
			return null;
		}
		$task = $this->findTask( $sf, (string) $opp['Id'], $item->clientReference() );
		return null === $task ? null : new ExternalRef( (string) $task['Id'], $item->clientReference(), null, array( 'opportunity_id' => (string) $opp['Id'] ) );
	}

	/**
	 * Send an order: account, contact, Opportunity (with lines in products mode), total check, then the Contact Role.
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
		$sf      = $this->salesforce( $connection );
		$options = $this->options( $connection );
		$ref     = $this->reference( $sf, $connection );
		$opp     = $this->findOpportunity( $sf, $ref, $order->reference );
		$left    = $sf->remaining();
		if ( null !== $left && $left < self::ALLOCATION_FLOOR ) {
			return SendResult::failure( $this->waitFor( 3600 ) );
		}
		if ( null !== $opp && $this->hasRole( $sf, (string) $opp['Id'] ) ) {
			return SendResult::existing( $this->oppRef( $sf->record( 'Opportunity', (string) $opp['Id'] ) ?? $opp, $order->reference, $options ) );
		}

		$multi = $sf->hasField( 'Opportunity', 'CurrencyIsoCode' );
		if ( ! $multi ) {
			// A single-currency org stores an Amount with no currency. It is only right when the order is in the org's currency, so the org
			// currency must be known and equal; otherwise (an AED order in a USD org) the amount would be wrong money with no label.
			$orgCurrency = $this->orgCurrency( $options );
			if ( '' === $orgCurrency ) {
				return SendResult::failure( $this->currencyMissing() );
			}
			if ( $orgCurrency !== $order->currency ) {
				/* translators: 1: order currency, 2: org currency */
				return SendResult::failure( $this->invalid( 'CurrencyIsoCode', sprintf( __( 'The order is in %1$s but this Salesforce org works in %2$s.', 'estasoft-erp-crm-sync-engine' ), $order->currency, $orgCurrency ) ) );
			}
		}
		$lines = 'products' === $options->choice( 'lines', array( 'description', 'products' ), 'description' ) ? $this->resolveLines( $sf, $options, $order, $multi ) : null;
		if ( $lines instanceof ConnectorError ) {
			return SendResult::failure( $lines );
		}

		$resumed = null !== $opp;
		$cut     = false;
		if ( null === $opp ) {
			$accountId                 = '' !== $order->contact['company'] ? $this->accountId( $sf, $order->contact['company'] ) : null;
			$contactId                 = $this->contactId( $sf, $order->contact, $accountId );
			list( $description, $cut ) = Text::limit( Summary::order( $order ), self::DESCRIPTION_LIMIT );
			$fields                    = array(
				'Name'        => $order->reference,
				'StageName'   => $order->paid ? $options->text( 'stage_paid', 'Closed Won' ) : $options->text( 'stage_unpaid', 'Prospecting' ),
				'CloseDate'   => $order->date,
				'Description' => $description,
			);
			if ( $ref->dedicated ) {
				$fields[ $ref->field ] = $order->reference;
			}
			if ( null === $lines ) {
				$fields['Amount'] = Amounts::number( $order->totalMinor, $order->currency );
			} else {
				$fields['Pricebook2Id'] = $lines['pricebook'];
			}
			if ( $multi ) {
				$fields['CurrencyIsoCode'] = $order->currency;
			}
			if ( null !== $accountId ) {
				$fields['AccountId'] = $accountId;
			}
			$oppId = $sf->create( 'Opportunity', $fields );
			$opp   = array( 'Id' => $oppId );
		} else {
			$contactId = $this->contactId( $sf, $order->contact, null );
			$oppId     = (string) $opp['Id'];
		}
		if ( null !== $lines ) {
			$this->attachLines( $sf, $oppId, $lines['entries'], $order->currency );
		}

		$read    = $sf->record( 'Opportunity', $oppId ) ?? array();
		$actual  = Amounts::minor( $read['Amount'] ?? ( null === $lines ? null : 0 ), $order->currency );
		$problem = $this->totalProblem( $order, $actual, $multi ? (string) ( $read['CurrencyIsoCode'] ?? '' ) : '', $options );
		if ( $problem instanceof ConnectorError ) {
			$this->discard( $sf, $oppId );
			return SendResult::failure( $problem );
		}
		$sf->create(
			'OpportunityContactRole',
			array(
				'OpportunityId' => $oppId,
				'ContactId'     => $contactId,
				'IsPrimary'     => true,
			)
		);

		$warnings = array();
		if ( is_string( $problem ) ) {
			$warnings[] = $problem;
		}
		if ( $cut ) {
			$warnings[] = __( 'The order summary was cut because Salesforce limits the description to 32,000 characters.', 'estasoft-erp-crm-sync-engine' );
		}
		$ref = new ExternalRef(
			$oppId,
			$order->reference,
			null,
			array(
				'total_minor' => $actual ?? $order->totalMinor,
				'currency'    => $order->currency,
				'warnings'    => $warnings,
			)
		);
		return $resumed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as a completed Task on the Opportunity.
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
		$sf  = $this->salesforce( $connection );
		$opp = $this->findOpportunity( $sf, $this->reference( $sf, $connection ), $refund->orderRef );
		if ( null === $opp ) {
			/* translators: 1: order reference */
			return SendResult::failure( $this->invalid( 'Opportunity', sprintf( __( 'The order %s is not in Salesforce yet.', 'estasoft-erp-crm-sync-engine' ), $refund->orderRef ) ) );
		}
		$oppId = (string) $opp['Id'];
		$task  = $this->findTask( $sf, $oppId, $refund->reference );
		if ( null !== $task ) {
			return SendResult::existing( new ExternalRef( (string) $task['Id'], $refund->reference, null, array( 'opportunity_id' => $oppId ) ) );
		}
		list( $body ) = Text::limit( Summary::refund( $refund ), self::DESCRIPTION_LIMIT );
		$id           = $sf->create(
			'Task',
			array(
				'Subject'      => $refund->reference,
				'WhatId'       => $oppId,
				'Status'       => 'Completed',
				'Priority'     => 'Normal',
				'ActivityDate' => $refund->date,
				'Description'  => $body,
			)
		);
		return SendResult::created( new ExternalRef( $id, $refund->reference, null, array( 'opportunity_id' => $oppId ) ) );
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
		$sf  = $this->salesforce( $connection );
		$row = $this->findContact( $sf, $contact['email'] );
		if ( null !== $row ) {
			return SendResult::existing( new ExternalRef( (string) $row['Id'], (string) ( $row['Name'] ?? $contact['email'] ), null, array( 'email' => $contact['email'] ) ) );
		}
		$accountId = '' !== $contact['company'] ? $this->accountId( $sf, $contact['company'] ) : null;
		$id        = $this->contactId( $sf, $contact, $accountId );
		return SendResult::created( new ExternalRef( $id, trim( $contact['first_name'] . ' ' . $contact['last_name'] ), null, array( 'email' => $contact['email'] ) ) );
	}

	/**
	 * API wrapper for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function salesforce( Connection $connection ): SalesforceApi {
		return new SalesforceApi( $this->api( $connection ), $this->version( $this->options( $connection ) ) );
	}

	/**
	 * API version, such as v66.0.
	 *
	 * @param CrmOptions $options Options.
	 */
	private function version( CrmOptions $options ): string {
		$version = $options->text( 'api_version', 'v66.0' );
		return 1 === preg_match( '/^v\d{2,3}\.0$/', $version ) ? $version : 'v66.0';
	}

	/**
	 * The fields that carry the reference: the merchant's own (option), else the External ID field the connector looks for among the fields
	 * the user can see, else the Opportunity name. The describe answer is read anyway for the currency, so this costs no call.
	 *
	 * @param SalesforceApi $sf         API.
	 * @param Connection    $connection Connection.
	 */
	private function reference( SalesforceApi $sf, Connection $connection ): ReferenceField {
		$options = $this->options( $connection );
		$chosen  = ReferenceField::choose( $connection, $options, 'reference_field', 'Name' );
		if ( $chosen->dedicated || '' !== $options->text( 'reference_field' ) || ! $sf->hasField( 'Opportunity', self::REFERENCE_FIELD ) ) {
			return $chosen;
		}
		return new ReferenceField( self::REFERENCE_FIELD, 'Name', true );
	}

	/**
	 * The currency a single-currency org works in, upper case; empty when the connection does not say.
	 *
	 * @param CrmOptions $options Options.
	 */
	private function orgCurrency( CrmOptions $options ): string {
		$code = strtoupper( $options->text( 'org_currency' ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
	}

	/**
	 * The error for a single-currency org whose currency is not known.
	 */
	private function currencyMissing(): ConnectorError {
		return $this->invalid( 'org_currency', __( 'This Salesforce org does not use several currencies, so an amount carries no currency. Enter the currency of the org in the connection settings; orders in any other currency are then refused.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * A quoted SOQL string literal.
	 *
	 * @param string $value Value.
	 */
	private function literal( string $value ): string {
		return "'" . Text::escapeQuoted( $value ) . "'";
	}

	/**
	 * The oldest Opportunity carrying the reference: in the reference field first, then (the documented fallback, which also finds an
	 * Opportunity made before the field existed) in the name.
	 *
	 * @param SalesforceApi  $sf        API.
	 * @param ReferenceField $ref       Fields that carry the reference.
	 * @param string         $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findOpportunity( SalesforceApi $sf, ReferenceField $ref, string $reference ): ?array {
		$select = 'Id, ' . implode( ', ', $ref->searchFields() ) . ', Amount' . ( $sf->hasField( 'Opportunity', 'CurrencyIsoCode' ) ? ', CurrencyIsoCode' : '' );
		foreach ( $ref->searchFields() as $field ) {
			foreach ( $sf->query( "SELECT {$select} FROM Opportunity WHERE {$field} = " . $this->literal( $reference ) . ' ORDER BY CreatedDate ASC' ) as $row ) {
				if ( Text::same( (string) ( $row[ $field ] ?? '' ), $reference ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	/**
	 * Whether an Opportunity has its primary Contact Role.
	 *
	 * @param SalesforceApi $sf    API.
	 * @param string        $oppId Opportunity id.
	 */
	private function hasRole( SalesforceApi $sf, string $oppId ): bool {
		return array() !== $sf->query( 'SELECT Id FROM OpportunityContactRole WHERE OpportunityId = ' . $this->literal( $oppId ) . ' LIMIT 1' );
	}

	/**
	 * The Task of an Opportunity whose subject is the reference.
	 *
	 * @param SalesforceApi $sf        API.
	 * @param string        $oppId     Opportunity id.
	 * @param string        $reference WC-number of the refund.
	 * @return array<string,mixed>|null
	 */
	private function findTask( SalesforceApi $sf, string $oppId, string $reference ): ?array {
		foreach ( $sf->query( 'SELECT Id, Subject FROM Task WHERE WhatId = ' . $this->literal( $oppId ) . ' AND Subject = ' . $this->literal( $reference ) . ' ORDER BY CreatedDate ASC' ) as $row ) {
			if ( Text::same( (string) ( $row['Subject'] ?? '' ), $reference ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Reference to an Opportunity.
	 *
	 * @param array<string,mixed> $row       Opportunity row.
	 * @param string              $reference WC-number.
	 * @param CrmOptions          $options   Options.
	 */
	private function oppRef( array $row, string $reference, CrmOptions $options ): ExternalRef {
		$currency = strtoupper( (string) ( $row['CurrencyIsoCode'] ?? $options->text( 'org_currency' ) ) );
		return new ExternalRef(
			(string) ( $row['Id'] ?? '' ),
			$reference,
			null,
			array(
				'total_minor' => Amounts::minor( $row['Amount'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
				'currency'    => $currency,
			)
		);
	}

	/**
	 * The oldest contact with the email.
	 *
	 * @param SalesforceApi $sf    API.
	 * @param string        $email Email.
	 * @return array<string,mixed>|null
	 */
	private function findContact( SalesforceApi $sf, string $email ): ?array {
		foreach ( $sf->query( 'SELECT Id, Name, Email FROM Contact WHERE Email = ' . $this->literal( $email ) . ' ORDER BY CreatedDate ASC' ) as $row ) {
			if ( Text::same( (string) ( $row['Email'] ?? '' ), $email ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Id of the account with the name, created when none exists.
	 *
	 * @param SalesforceApi $sf   API.
	 * @param string        $name Company name.
	 */
	private function accountId( SalesforceApi $sf, string $name ): string {
		foreach ( $sf->query( 'SELECT Id, Name FROM Account WHERE Name = ' . $this->literal( $name ) . ' ORDER BY CreatedDate ASC' ) as $row ) {
			if ( Text::same( (string) ( $row['Name'] ?? '' ), $name ) ) {
				return (string) $row['Id'];
			}
		}
		return $sf->create( 'Account', array( 'Name' => $name ), array( 'Sforce-Duplicate-Rule-Header' => 'allowSave=true' ) );
	}

	/**
	 * Id of the contact for the buyer: found by email, else created. Salesforce needs a last name, so a buyer with one name gets it there.
	 *
	 * @param SalesforceApi        $sf        API.
	 * @param array<string,string> $contact   Buyer.
	 * @param string|null          $accountId Account id.
	 */
	private function contactId( SalesforceApi $sf, array $contact, ?string $accountId ): string {
		$row = $this->findContact( $sf, $contact['email'] );
		if ( null !== $row ) {
			return (string) $row['Id'];
		}
		$last   = '' !== $contact['last_name'] ? $contact['last_name'] : ( '' !== $contact['first_name'] ? $contact['first_name'] : ( '' !== $contact['company'] ? $contact['company'] : (string) strstr( $contact['email'], '@', true ) ) );
		$fields = array_filter(
			array(
				'LastName'          => $last,
				'FirstName'         => '' !== $contact['last_name'] ? $contact['first_name'] : '',
				'Email'             => $contact['email'],
				'Phone'             => $contact['phone'],
				'MailingStreet'     => $contact['street'],
				'MailingCity'       => $contact['city'],
				'MailingState'      => $contact['state'],
				'MailingPostalCode' => $contact['postcode'],
				'MailingCountry'    => $contact['country'],
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( null !== $accountId ) {
			$fields['AccountId'] = $accountId;
		}
		return $sf->create( 'Contact', $fields, array( 'Sforce-Duplicate-Rule-Header' => 'allowSave=true' ) );
	}

	/**
	 * Price book and price book entry per line for products mode; an unknown SKU is refused before anything is made.
	 *
	 * @param SalesforceApi $sf      API.
	 * @param CrmOptions    $options Options.
	 * @param OrderData     $order   Order.
	 * @param bool          $multi   Whether the org uses several currencies.
	 * @return array{pricebook:string,entries:array<int,array{entry:string,total:int,description:string,quantity:string}>}|ConnectorError
	 */
	private function resolveLines( SalesforceApi $sf, CrmOptions $options, OrderData $order, bool $multi ): array|ConnectorError {
		$name  = $options->text( 'pricebook' );
		$books = $sf->query( 'SELECT Id FROM Pricebook2 WHERE ' . ( '' !== $name ? 'Name = ' . $this->literal( $name ) : 'IsStandard = true' ) . ' LIMIT 1' );
		if ( array() === $books ) {
			return $this->invalid( 'Pricebook2', __( 'Salesforce has no such price book.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$pricebook = (string) $books[0]['Id'];
		$wanted    = array();
		foreach ( $order->lines as $index => $line ) {
			$wanted[ $index ] = array( $line->sku, $line->totalMinor + $line->taxMinor, '' !== $line->sku ? $line->name . ' (' . $line->sku . ')' : $line->name, $line->quantity );
		}
		$extras = array(
			'shipping_product' => array( $order->shippingMinor, __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) ),
			'fee_product'      => array( array_sum( array_column( $order->fees, 'total' ) ), __( 'Fees', 'estasoft-erp-crm-sync-engine' ) ),
			'discount_product' => array( -$order->discountMinor, __( 'Discount', 'estasoft-erp-crm-sync-engine' ) ),
		);
		foreach ( $extras as $key => $extra ) {
			if ( '' !== $options->text( $key ) && 0 !== $extra[0] ) {
				$wanted[ $key ] = array( $options->text( $key ), (int) $extra[0], $extra[1], '1' );
			}
		}
		$codes = array_values( array_unique( array_filter( array_column( $wanted, 0 ), static fn( string $code ): bool => '' !== $code ) ) );
		$found = array();
		if ( array() !== $codes ) {
			$in   = implode( ', ', array_map( fn( string $code ): string => $this->literal( $code ), $codes ) );
			$cur  = $multi ? ' AND CurrencyIsoCode = ' . $this->literal( $order->currency ) : '';
			$rows = $sf->query( 'SELECT Id, Product2.ProductCode FROM PricebookEntry WHERE Pricebook2Id = ' . $this->literal( $pricebook ) . ' AND IsActive = true AND Product2.ProductCode IN (' . $in . ')' . $cur );
			foreach ( $rows as $row ) {
				$found[ strtolower( (string) ( $row['Product2']['ProductCode'] ?? '' ) ) ] = (string) $row['Id'];
			}
		}
		$entries = array();
		foreach ( $wanted as $item ) {
			$entry = $found[ strtolower( $item[0] ) ] ?? null;
			if ( null === $entry ) {
				if ( 'skip' === $options->choice( 'unknown_sku', array( 'refuse', 'skip' ), 'refuse' ) && '' !== $item[0] ) {
					continue;
				}
				return $this->unknownSku( '' !== $item[0] ? $item[0] : $item[2] );
			}
			$entries[] = array(
				'entry'       => $entry,
				'total'       => $item[1],
				'description' => $item[2],
				'quantity'    => $item[3],
			);
		}
		return array(
			'pricebook' => $pricebook,
			'entries'   => $entries,
		);
	}

	/**
	 * Add the lines an Opportunity does not have yet.
	 *
	 * @param SalesforceApi                                                               $sf      API.
	 * @param string                                                                      $oppId   Opportunity id.
	 * @param array<int,array{entry:string,total:int,description:string,quantity:string}> $entries Entries.
	 * @param string                                                                      $currency Currency of the amounts.
	 */
	private function attachLines( SalesforceApi $sf, string $oppId, array $entries, string $currency ): void {
		$present = array();
		foreach ( $sf->query( 'SELECT PricebookEntryId FROM OpportunityLineItem WHERE OpportunityId = ' . $this->literal( $oppId ) ) as $row ) {
			$key             = (string) ( $row['PricebookEntryId'] ?? '' );
			$present[ $key ] = ( $present[ $key ] ?? 0 ) + 1;
		}
		foreach ( $entries as $entry ) {
			if ( ( $present[ $entry['entry'] ] ?? 0 ) > 0 ) {
				--$present[ $entry['entry'] ];
				continue;
			}
			$sf->create(
				'OpportunityLineItem',
				array(
					'OpportunityId'    => $oppId,
					'PricebookEntryId' => $entry['entry'],
					'Quantity'         => ctype_digit( $entry['quantity'] ) ? (int) $entry['quantity'] : (float) $entry['quantity'],
					'TotalPrice'       => Amounts::number( $entry['total'], $currency ),
					'Description'      => $entry['description'],
				)
			);
		}
	}

	/**
	 * Delete an Opportunity that must not stay (wrong money); a failure is left for the next pass to report.
	 *
	 * @param SalesforceApi $sf    API.
	 * @param string        $oppId Opportunity id.
	 */
	private function discard( SalesforceApi $sf, string $oppId ): void {
		try {
			$sf->delete( 'Opportunity', $oppId );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}
}
