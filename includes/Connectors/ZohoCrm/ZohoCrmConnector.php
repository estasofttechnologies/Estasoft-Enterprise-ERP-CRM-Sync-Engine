<?php
/**
 * Zoho CRM connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoCrm;

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
 * Orders become deals with a summary note, customers become contacts (and accounts), refunds become notes (docs/connectors/zoho-crm.md).
 */
final class ZohoCrmConnector extends AbstractSalesConnector implements OAuthConnector {

	/**
	 * Credits left below which the connector stops and waits.
	 */
	private const CREDIT_FLOOR = 50;

	/**
	 * Largest note Zoho takes, characters.
	 */
	private const NOTE_LIMIT = 32000;

	/**
	 * Connector facts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'zoho-crm',
			label: 'Zoho CRM',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 ),
			hosts: ZohoDataCentre::allHosts()
		);
	}

	/**
	 * Option keys.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'reference_field', 'stage_paid', 'stage_unpaid', 'pipeline', 'total_mismatch', 'org_currency', 'org_id' );
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
			new CredentialField( 'stage_paid', __( 'Deal stage for paid orders', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave empty for Closed Won.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'stage_unpaid', __( 'Deal stage for unpaid orders', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave empty for Qualification.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'pipeline', __( 'Pipeline name', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Needed when your Deals use pipelines. Test connection lists the names it finds.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * Sign-in settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return new ZohoOAuthProvider(
			static fn(): array => array(
				'ZohoCRM.modules.deals.CREATE',
				'ZohoCRM.modules.deals.READ',
				'ZohoCRM.modules.deals.UPDATE',
				'ZohoCRM.modules.deals.DELETE',
				'ZohoCRM.modules.contacts.CREATE',
				'ZohoCRM.modules.contacts.READ',
				'ZohoCRM.modules.accounts.CREATE',
				'ZohoCRM.modules.accounts.READ',
				'ZohoCRM.modules.notes.CREATE',
				'ZohoCRM.modules.notes.READ',
				'ZohoCRM.modules.products.READ',
				'ZohoCRM.org.READ',
				'ZohoCRM.coql.READ',
				'ZohoCRM.settings.layouts.READ',
				'ZohoCRM.settings.pipeline.READ',
				'ZohoCRM.settings.fields.READ',
				'ZohoCRM.settings.fields.CREATE',
			)
		);
	}

	/**
	 * The organisation the signed-in user belongs to (Zoho CRM has one per sign-in).
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array {
		$org = $this->zoho( $connection )->org();
		return array(
			array(
				'id'   => (string) ( $org['id'] ?? '' ),
				'name' => (string) ( $org['company_name'] ?? 'Zoho CRM' ),
			),
		);
	}

	/**
	 * The config to store for the organisation: its id and currency.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $id         Organisation id.
	 * @return array<string,string>
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$org = $this->zoho( $connection )->org();
		return array(
			'org_id'       => $id,
			'org_name'     => (string) ( $org['company_name'] ?? '' ),
			'org_currency' => $this->orgCurrency( $org ),
		);
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
		return ZohoCrmErrorMapper::map( $response );
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
	 * Test connection: the organisation, what the token may read, the deal pipelines and the field that carries the order reference.
	 * The field is created when it is missing and the user may; when it cannot be, the deal name stays the key and the test says so.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When Zoho refuses a call for a reason that is not a missing right.
	 */
	protected function identify( Connection $connection ): TestResult {
		$zoho        = $this->zoho( $connection );
		$options     = $this->options( $connection );
		$org         = $zoho->org();
		$permissions = array(
			'read_deals'    => $zoho->readable( 'Deals', 'Deal_Name' ),
			'read_contacts' => $zoho->readable( 'Contacts', 'Last_Name' ),
			'read_notes'    => $zoho->readable( 'Notes', 'Note_Title' ),
		);
		$missing     = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error       = array() === $missing ? null : $this->noPermission( str_replace( 'read_', 'read ', (string) $missing[0] ) );
		$detected    = array(
			'currency'       => $this->orgCurrency( $org ),
			'data_centre'    => ZohoDataCentre::forConnection( $connection )->id(),
			'multi_currency' => isset( $org['mc_status'] ) ? ( ! empty( $org['mc_status'] ) ? 'yes' : 'no' ) : '',
		);
		if ( null === $error ) {
			$setup     = new ZohoCrmSetup( $zoho );
			$pipelines = $setup->pipelines( $options->text( 'pipeline' ) );
			$error     = $this->pipelineProblem( $pipelines );

			$permissions['read_pipelines'] = 'unreadable' !== $pipelines['status'];
			if ( 'chosen' === $pipelines['status'] && '' === $options->text( 'pipeline' ) ) {
				$detected['pipeline'] = $pipelines['pipeline'];
			}
			if ( null === $error ) {
				$detected += $this->referenceSetup( $setup, $options, $permissions );
			}
		}
		return new TestResult( null === $error, 'Zoho CRM API v8', (string) ( $org['company_name'] ?? 'Zoho CRM' ), array(), $permissions, $error, array_filter( $detected ) );
	}

	/**
	 * The error for a pipeline that cannot be used, or null.
	 *
	 * @param array{status:string,pipeline:string,names:array<int,string>} $pipelines Result of ZohoCrmSetup::pipelines().
	 */
	private function pipelineProblem( array $pipelines ): ?ConnectorError {
		$names = implode( ', ', array_slice( $pipelines['names'], 0, 8 ) );
		if ( 'unknown' === $pipelines['status'] ) {
			/* translators: 1: pipeline names found in Zoho CRM */
			return $this->invalid( 'pipeline', sprintf( __( 'Zoho CRM has no pipeline with that name. Its pipelines are: %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $names ) );
		}
		if ( 'ambiguous' === $pipelines['status'] ) {
			/* translators: 1: pipeline names found in Zoho CRM */
			return $this->invalid( 'pipeline', sprintf( __( 'Zoho CRM has several pipelines (%s). Enter the one new orders go to in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ), $names ) );
		}
		return null;
	}

	/**
	 * Find or create the reference field, and report which key the connector will use.
	 *
	 * @param ZohoCrmSetup       $setup       Setup helper.
	 * @param CrmOptions         $options     Options.
	 * @param array<string,bool> &$permissions Permission list; gets `create_reference_field`.
	 * @return array<string,string> Facts for `detected`.
	 */
	private function referenceSetup( ZohoCrmSetup $setup, CrmOptions $options, array &$permissions ): array {
		$typed = $options->text( 'reference_field' );
		if ( '' !== $typed ) {
			// The merchant chose: a field of their own, or `name` on purpose. Nothing is created and nothing is warned about.
			$own = 'name' !== Text::lower( $typed ) && ! Text::same( $typed, 'Deal_Name' ) && ReferenceField::valid( $typed );
			return array( 'reference_mode' => $own ? 'field' : 'name' );
		}
		$made                                  = $setup->referenceField();
		$permissions['create_reference_field'] = null !== $made;
		if ( null === $made ) {
			return array(
				'reference_mode' => 'name',
				'warning'        => 'reference_field',
			);
		}
		return array(
			'reference_mode'         => 'field',
			ReferenceField::DETECTED => $made['field'],
		);
	}

	/**
	 * Cheap health probe: one read of the organisation.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== $this->zoho( $connection )->org();
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
			$row = $this->findContact( $this->zoho( $connection ), $email );
			return null === $row ? null : new ExternalRef( (string) $row['id'], $this->contactName( $row, $email ), null, array( 'email' => $email ) );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Find a product by its product code for previews and the SKU mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			foreach ( $this->zoho( $connection )->search( 'Products', 'criteria', '(Product_Code:equals:' . $this->escapeCriteria( $sku ) . ')' ) as $row ) {
				if ( Text::same( (string) ( $row['Product_Code'] ?? '' ), $sku ) ) {
					return new ExternalRef( (string) $row['id'], $sku, null, array( 'name' => (string) ( $row['Product_Name'] ?? '' ) ) );
				}
			}
			return null;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Search products by word for the SKU mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		try {
			$refs = array();
			foreach ( array_slice( $this->zoho( $connection )->search( 'Products', 'word', $query ), 0, 50 ) as $row ) {
				$refs[] = new ExternalRef( (string) ( $row['id'] ?? '' ), (string) ( $row['Product_Code'] ?? '' ), null, array( 'name' => (string) ( $row['Product_Name'] ?? '' ) ) );
			}
			return $refs;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Deals created since a date that carry a WooCommerce reference, in the dedicated field or in the name. Each deal's own currency is read
	 * (multi-currency organisations, `mc_status` of the Organization API), because an amount in KWD or BHD has three decimals and cannot be
	 * read with the organisation's two.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			$zoho     = $this->zoho( $connection );
			$ref      = $this->reference( $connection );
			$org      = $zoho->org();
			$home     = strtoupper( $this->options( $connection )->text( 'org_currency' ) );
			$home     = 1 === preg_match( '/^[A-Z]{3}$/', $home ) ? $home : ( '' !== $this->orgCurrency( $org ) ? $this->orgCurrency( $org ) : 'USD' );
			$since    = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s+00:00' );
			$tests    = array_map( static fn( string $field ): string => "{$field} like 'WC-%'", $ref->searchFields() );
			$where    = '(' . ( count( $tests ) > 1 ? '(' . implode( ' or ', $tests ) . ')' : $tests[0] ) . " and Created_Time >= '{$since}')";
			$columns  = array_values( array_unique( array_merge( array( 'id' ), $ref->searchFields(), array( 'Amount', 'Currency', 'Created_Time' ) ) ) );
			$currency = false !== ( $org['mc_status'] ?? null ); // Organization API: mc_status says whether several currencies are on.
			$found    = array();
			for ( $offset = 0; $offset < 10000; $offset += 200 ) {
				$page = $this->coqlPage( $zoho, $columns, $where, $offset, $currency );
				foreach ( $page['rows'] as $row ) {
					$reference = $ref->readFrom( $row );
					if ( '' !== $reference && ! isset( $found[ $reference ] ) ) {
						$code                = $currency && is_scalar( $row['Currency'] ?? null ) && 1 === preg_match( '/^[A-Za-z]{3}$/', trim( (string) $row['Currency'] ) ) ? strtoupper( trim( (string) $row['Currency'] ) ) : $home;
						$found[ $reference ] = new ExternalRef(
							(string) ( $row['id'] ?? '' ),
							$reference,
							null,
							array(
								'total_minor'    => Amounts::minor( $row['Amount'] ?? null, $code ) ?? 0,
								'refunded_minor' => null,
								'email'          => '',
								'currency'       => $code,
							)
						);
					}
				}
				if ( ! $page['more'] ) {
					break;
				}
			}
			return $found;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * One page of the reconciliation query. The Currency column exists only in organisations that use several currencies. When the
	 * organisation did not say (no `mc_status`) and Zoho refuses the first page with it, the query is asked again without it and every
	 * amount is read in the organisation's currency.
	 *
	 * @param ZohoCrmApi        $zoho      API.
	 * @param array<int,string> $columns   Columns to select.
	 * @param string            $where     Where clause with its parentheses.
	 * @param int               $offset    Offset.
	 * @param bool              &$currency Whether the Currency column is selected; set to false when Zoho refuses it.
	 * @return array{rows:array<int,array<string,mixed>>,more:bool}
	 * @throws ApiException When Zoho refuses the query for another reason.
	 */
	private function coqlPage( ZohoCrmApi $zoho, array $columns, string $where, int $offset, bool &$currency ): array {
		$select = $currency ? $columns : array_values( array_diff( $columns, array( 'Currency' ) ) );
		try {
			return $zoho->coql( 'select ' . implode( ', ', $select ) . " from Deals where {$where} order by Created_Time asc limit 200 offset {$offset}" );
		} catch ( ApiException $exception ) {
			if ( ! $currency || 0 !== $offset || ErrorCode::Val002 !== $exception->error()->code ) {
				throw $exception;
			}
			$currency = false;
			return $this->coqlPage( $zoho, $columns, $where, $offset, $currency );
		}
	}

	/**
	 * A deal is finished when its summary note exists: the note is written last.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$zoho    = $this->zoho( $connection );
		$options = $this->options( $connection );
		$deal    = $this->findDeal( $zoho, $this->reference( $connection ), $item->clientReference() );
		if ( null === $deal || null === $this->findNote( $zoho, (string) $deal['id'], $item->clientReference() ) ) {
			return null;
		}
		return $this->dealRef( $zoho->record( 'Deals', (string) $deal['id'], 'Amount,Currency' ) ?? $deal, $item->clientReference(), $options );
	}

	/**
	 * A refund is finished when its note is on the deal.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$zoho  = $this->zoho( $connection );
		$order = 1 === preg_match( '/^order:(\d+)$/', $item->partitionKey, $parts ) ? 'WC-' . $parts[1] : '';
		$deal  = '' === $order ? null : $this->findDeal( $zoho, $this->reference( $connection ), $order );
		if ( null === $deal ) {
			return null;
		}
		$note = $this->findNote( $zoho, (string) $deal['id'], $item->clientReference() );
		return null === $note ? null : new ExternalRef( (string) ( $note['id'] ?? '' ), $item->clientReference(), null, array( 'deal_id' => (string) $deal['id'] ) );
	}

	/**
	 * Send an order: contact, account, deal, total check, then the summary note.
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
		$zoho    = $this->zoho( $connection );
		$options = $this->options( $connection );
		$deal    = $this->findDeal( $zoho, $this->reference( $connection ), $order->reference );
		if ( null !== $this->creditWait( $zoho ) ) {
			return SendResult::failure( $this->waitFor( 3600 ) );
		}
		if ( null !== $deal && null !== $this->findNote( $zoho, (string) $deal['id'], $order->reference ) ) {
			return SendResult::existing( $this->dealRef( $zoho->record( 'Deals', (string) $deal['id'], 'Amount,Currency' ) ?? $deal, $order->reference, $options ) );
		}

		$resumed = null !== $deal;
		if ( null === $deal ) {
			$deal = array( 'id' => $zoho->insert( 'Deals', $this->dealRecord( $zoho, $options, $order, $this->reference( $connection ), $this->pipeline( $connection ) ) )['id'] );
		}
		$dealId = (string) $deal['id'];
		if ( '' === $dealId ) {
			return SendResult::failure( $this->invalid( 'Deals', __( 'Zoho CRM did not return the new deal.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}

		$read    = $zoho->record( 'Deals', $dealId, 'Amount,Currency' ) ?? array();
		$actual  = Amounts::minor( $read['Amount'] ?? null, $order->currency );
		$problem = $this->totalProblem( $order, $actual, $this->readCurrency( $read, $options ), $options );
		if ( $problem instanceof ConnectorError ) {
			$this->discard( $zoho, $dealId );
			return SendResult::failure( $problem );
		}
		list( $content, $cut ) = Text::limit( Summary::order( $order ), self::NOTE_LIMIT );
		$zoho->insert( 'Notes', $this->noteRecord( $order->reference, $content, $dealId ) );

		$warnings = array();
		if ( is_string( $problem ) ) {
			$warnings[] = $problem;
		}
		if ( $cut ) {
			$warnings[] = __( 'The order summary was cut because Zoho CRM limits notes to 32,000 characters.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		$ref = new ExternalRef(
			$dealId,
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
	 * Send a refund as a note on the deal.
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
		$zoho = $this->zoho( $connection );
		$deal = $this->findDeal( $zoho, $this->reference( $connection ), $refund->orderRef );
		if ( null === $deal ) {
			/* translators: 1: order reference */
			return SendResult::failure( $this->invalid( 'Deals', sprintf( __( 'The order %s is not in Zoho CRM yet.', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->orderRef ) ) );
		}
		$dealId = (string) $deal['id'];
		$note   = $this->findNote( $zoho, $dealId, $refund->reference );
		if ( null !== $note ) {
			return SendResult::existing( new ExternalRef( (string) ( $note['id'] ?? '' ), $refund->reference, null, array( 'deal_id' => $dealId ) ) );
		}
		list( $content ) = Text::limit( Summary::refund( $refund ), self::NOTE_LIMIT );
		$created         = $zoho->insert( 'Notes', $this->noteRecord( $refund->reference, $content, $dealId ) );
		return SendResult::created( new ExternalRef( $created['id'], $refund->reference, null, array( 'deal_id' => $dealId ) ) );
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
		$zoho = $this->zoho( $connection );
		$row  = $this->findContact( $zoho, $contact['email'] );
		if ( null !== $row ) {
			return SendResult::existing( new ExternalRef( (string) $row['id'], $this->contactName( $row, $contact['email'] ), null, array( 'email' => $contact['email'] ) ) );
		}
		$created = $zoho->insert( 'Contacts', $this->contactRecord( $contact, null ), true );
		return SendResult::created( new ExternalRef( $created['id'], trim( $contact['first_name'] . ' ' . $contact['last_name'] ), null, array( 'email' => $contact['email'] ) ) );
	}

	/**
	 * API wrapper for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function zoho( Connection $connection ): ZohoCrmApi {
		return new ZohoCrmApi( $this->api( $connection ) );
	}

	/**
	 * The fields that carry the reference: the dedicated field Test connection found or made (or the merchant's own), then the deal name.
	 *
	 * @param Connection $connection Connection.
	 */
	private function reference( Connection $connection ): ReferenceField {
		return ReferenceField::choose( $connection, $this->options( $connection ), 'reference_field', 'Deal_Name' );
	}

	/**
	 * The pipeline new deals go to: the option, else the one Test connection chose, else none (an account without pipelines).
	 *
	 * @param Connection $connection Connection.
	 */
	private function pipeline( Connection $connection ): string {
		$typed = $this->options( $connection )->text( 'pipeline' );
		if ( '' !== $typed ) {
			return $typed;
		}
		$detected = $connection->config( 'detected' );
		return is_array( $detected ) && is_scalar( $detected['pipeline'] ?? null ) ? trim( (string) $detected['pipeline'] ) : '';
	}

	/**
	 * Currency code of an organisation record.
	 *
	 * @param array<string,mixed> $org Organisation.
	 */
	private function orgCurrency( array $org ): string {
		foreach ( array( 'iso_code', 'currency' ) as $key ) {
			$value = strtoupper( (string) ( $org[ $key ] ?? '' ) );
			if ( 1 === preg_match( '/^[A-Z]{3}$/', $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Currency Zoho reports for a deal: the Currency field in multi-currency organisations, else the organisation's own.
	 *
	 * @param array<string,mixed> $read    Deal row.
	 * @param CrmOptions          $options Options.
	 */
	private function readCurrency( array $read, CrmOptions $options ): string {
		return strtoupper( (string) ( $read['Currency'] ?? $options->text( 'org_currency' ) ) );
	}

	/**
	 * The oldest deal carrying the reference: in the dedicated field first, then (the documented fallback, which also finds a deal made
	 * before the field existed) in the deal name.
	 *
	 * @param ZohoCrmApi     $zoho      API.
	 * @param ReferenceField $ref       Fields that carry the reference.
	 * @param string         $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findDeal( ZohoCrmApi $zoho, ReferenceField $ref, string $reference ): ?array {
		foreach ( $ref->searchFields() as $field ) {
			$rows = $zoho->search( 'Deals', 'criteria', '(' . $field . ':equals:' . $this->escapeCriteria( $reference ) . ')' );
			usort( $rows, array( $this, 'byAge' ) );
			foreach ( $rows as $row ) {
				if ( Text::same( (string) ( $row[ $field ] ?? '' ), $reference ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	/**
	 * Order two records oldest first: by creation time, then id.
	 *
	 * @param array<string,mixed> $a One record.
	 * @param array<string,mixed> $b Another record.
	 */
	private function byAge( array $a, array $b ): int {
		$by = strcmp( (string) ( $a['Created_Time'] ?? '' ), (string) ( $b['Created_Time'] ?? '' ) );
		return 0 !== $by ? $by : strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
	}

	/**
	 * Escape a criteria value: parentheses, commas and backslashes get a backslash.
	 *
	 * @param string $value Value.
	 */
	private function escapeCriteria( string $value ): string {
		return (string) preg_replace( '/([\\\\(),])/', '\\\\$1', $value );
	}

	/**
	 * The note of a deal titled with the reference.
	 *
	 * @param ZohoCrmApi $zoho      API.
	 * @param string     $dealId    Deal id.
	 * @param string     $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findNote( ZohoCrmApi $zoho, string $dealId, string $reference ): ?array {
		foreach ( $zoho->related( 'Deals', $dealId, 'Notes', 'Note_Title' ) as $note ) {
			if ( Text::same( (string) ( $note['Note_Title'] ?? '' ), $reference ) ) {
				return $note;
			}
		}
		return null;
	}

	/**
	 * Reference to a deal.
	 *
	 * @param array<string,mixed> $deal      Deal row.
	 * @param string              $reference WC-number.
	 * @param CrmOptions          $options   Options.
	 */
	private function dealRef( array $deal, string $reference, CrmOptions $options ): ExternalRef {
		$currency = $this->readCurrency( $deal, $options );
		return new ExternalRef(
			(string) ( $deal['id'] ?? '' ),
			$reference,
			null,
			array(
				'total_minor' => Amounts::minor( $deal['Amount'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
				'currency'    => $currency,
			)
		);
	}

	/**
	 * The oldest contact with the email.
	 *
	 * @param ZohoCrmApi $zoho  API.
	 * @param string     $email Email.
	 * @return array<string,mixed>|null
	 */
	private function findContact( ZohoCrmApi $zoho, string $email ): ?array {
		$rows = $zoho->search( 'Contacts', 'email', $email );
		usort( $rows, array( $this, 'byAge' ) );
		foreach ( $rows as $row ) {
			if ( Text::same( (string) ( $row['Email'] ?? '' ), $email ) || Text::same( (string) ( $row['Secondary_Email'] ?? '' ), $email ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Name of a contact as typed.
	 *
	 * @param array<string,mixed> $row      Contact row.
	 * @param string              $fallback Text when the contact has no name.
	 */
	private function contactName( array $row, string $fallback ): string {
		$name = trim( (string) ( $row['First_Name'] ?? '' ) . ' ' . (string) ( $row['Last_Name'] ?? '' ) );
		return '' !== $name ? $name : $fallback;
	}

	/**
	 * Contact fields. Zoho needs a last name, so a buyer with one name gets it there.
	 *
	 * @param array<string,string> $contact   Buyer.
	 * @param string|null          $accountId Account id.
	 * @return array<string,mixed>
	 */
	private function contactRecord( array $contact, ?string $accountId ): array {
		$last  = '' !== $contact['last_name'] ? $contact['last_name'] : ( '' !== $contact['first_name'] ? $contact['first_name'] : ( '' !== $contact['company'] ? $contact['company'] : strstr( $contact['email'], '@', true ) ) );
		$first = '' !== $contact['last_name'] ? $contact['first_name'] : '';
		$row   = array_filter(
			array(
				'Last_Name'       => (string) $last,
				'First_Name'      => $first,
				'Email'           => $contact['email'],
				'Phone'           => $contact['phone'],
				'Mailing_Street'  => $contact['street'],
				'Mailing_City'    => $contact['city'],
				'Mailing_State'   => $contact['state'],
				'Mailing_Zip'     => $contact['postcode'],
				'Mailing_Country' => $contact['country'],
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( null !== $accountId ) {
			$row['Account_Name'] = array( 'id' => $accountId );
		}
		return $row;
	}

	/**
	 * Fields of a new deal; creates the account and contact first.
	 *
	 * @param ZohoCrmApi     $zoho     API.
	 * @param CrmOptions     $options  Options.
	 * @param OrderData      $order    Order.
	 * @param ReferenceField $ref      Fields that carry the reference.
	 * @param string         $pipeline Pipeline name, empty for an account without pipelines.
	 * @return array<string,mixed>
	 */
	private function dealRecord( ZohoCrmApi $zoho, CrmOptions $options, OrderData $order, ReferenceField $ref, string $pipeline ): array {
		$accountId = null;
		if ( '' !== $order->contact['company'] ) {
			$rows      = $zoho->search( 'Accounts', 'criteria', '(Account_Name:equals:' . $this->escapeCriteria( $order->contact['company'] ) . ')' );
			$accountId = isset( $rows[0]['id'] ) ? (string) $rows[0]['id'] : $zoho->insert( 'Accounts', array( 'Account_Name' => $order->contact['company'] ), true )['id'];
		}
		$contact   = $this->findContact( $zoho, $order->contact['email'] );
		$contactId = null !== $contact ? (string) $contact['id'] : $zoho->insert( 'Contacts', $this->contactRecord( $order->contact, $accountId ), true )['id'];

		$record = array(
			'Deal_Name'    => $order->reference,
			'Stage'        => $order->paid ? $options->text( 'stage_paid', 'Closed Won' ) : $options->text( 'stage_unpaid', 'Qualification' ),
			'Amount'       => Amounts::number( $order->totalMinor, $order->currency ),
			'Closing_Date' => $order->date,
			'Contact_Name' => array( 'id' => $contactId ),
		);
		if ( $ref->dedicated ) {
			$record[ $ref->field ] = $order->reference;
		}
		if ( null !== $accountId ) {
			$record['Account_Name'] = array( 'id' => $accountId );
		}
		if ( '' !== $pipeline ) {
			$record['Pipeline'] = $pipeline;
		}
		if ( '' !== $order->note ) {
			$record['Description'] = $order->note;
		}
		$home = '' !== $options->text( 'org_currency' ) ? $options->text( 'org_currency' ) : $this->orgCurrency( $zoho->org() );
		if ( '' !== $home && $home !== $order->currency ) {
			$record['Currency'] = $order->currency;
		}
		return $record;
	}

	/**
	 * A note on a deal.
	 *
	 * @param string $title   Title: the reference.
	 * @param string $content Text.
	 * @param string $dealId  Deal id.
	 * @return array<string,mixed>
	 */
	private function noteRecord( string $title, string $content, string $dealId ): array {
		return array(
			'Note_Title'   => $title,
			'Note_Content' => $content,
			'Parent_Id'    => array(
				'module' => array( 'api_name' => 'Deals' ),
				'id'     => $dealId,
			),
			'$se_module'   => 'Deals',
		);
	}

	/**
	 * Delete a deal that must not stay (wrong money); a failure is left for the next pass to report.
	 *
	 * @param ZohoCrmApi $zoho   API.
	 * @param string     $dealId Deal id.
	 */
	private function discard( ZohoCrmApi $zoho, string $dealId ): void {
		try {
			$zoho->delete( 'Deals', $dealId );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Seconds to wait when Zoho said the credits are nearly used.
	 *
	 * @param ZohoCrmApi $zoho API.
	 */
	private function creditWait( ZohoCrmApi $zoho ): ?int {
		$left = $zoho->credits();
		return null !== $left && $left < self::CREDIT_FLOOR ? 3600 : null;
	}
}
