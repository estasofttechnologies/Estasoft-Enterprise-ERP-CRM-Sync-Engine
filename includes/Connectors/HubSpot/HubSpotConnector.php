<?php
/**
 * HubSpot connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\HubSpot;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\AbstractSalesConnector;
use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmFields;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmMappings;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderData;
use Estasoft\SyncEngine\Connectors\CrmCommon\OrderLine;
use Estasoft\SyncEngine\Connectors\CrmCommon\Summary;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BearerAuth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
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
 * Orders become deals with line items, customers become contacts, refunds become notes on the deal (docs/connectors/hubspot.md).
 */
final class HubSpotConnector extends AbstractSalesConnector {

	/**
	 * API versions the connector accepts.
	 */
	private const VERSIONS = array( '2026-03', '2026-09' );

	/**
	 * Daily requests left below which the connector stops and waits.
	 */
	private const QUOTA_FLOOR = 50;

	/**
	 * Default association type ids, HUBSPOT_DEFINED (HubSpot association table).
	 */
	private const ASSOC_DEAL_TO_CONTACT = 3;
	private const ASSOC_DEAL_TO_LINE    = 19;
	private const ASSOC_NOTE_TO_DEAL    = 214;

	/**
	 * Connector facts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'hubspot',
			label: 'HubSpot',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 4.0, concurrency: 1 ),
			hosts: array( 'api.hubapi.com' )
		);
	}

	/**
	 * Option keys.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'api_version', 'pipeline', 'deal_stage', 'deal_stage_paid', 'reference_property', 'product_matching', 'unknown_sku', 'total_mismatch', 'portal_id' );
	}

	/**
	 * Connect screen fields.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField( 'access_token', __( 'Service key', 'estasoft-erp-crm-sync-engine' ), true, true, __( 'Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ), '', 'password' ),
			new CredentialField( 'pipeline', __( 'Deal pipeline id', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Leave empty to use the first pipeline.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'deal_stage', __( 'Stage id for unpaid orders', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Leave empty to use the first stage of the pipeline.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'deal_stage_paid', __( 'Stage id for paid orders', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Leave empty to use the pipeline\'s closed-won stage.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'portal_id', __( 'Hub ID', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Optional. Only used to build links to deals.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'api_version', __( 'API version', 'estasoft-erp-crm-sync-engine' ), false, false, '', '2026-03', 'select', array_combine( self::VERSIONS, self::VERSIONS ), 'advanced' ),
		);
	}

	/**
	 * API host.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.hubapi.com';
	}

	/**
	 * Bearer service key.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new BearerAuth( 'access_token' );
	}

	/**
	 * Vendor error mapping.
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return HubSpotErrorMapper::map( $response );
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
	 * Test connection: the scopes for contacts, deals, line items and notes, the account, the pipelines.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When HubSpot refuses the key.
	 */
	protected function identify( Connection $connection ): TestResult {
		$records     = $this->records( $connection );
		$permissions = array();
		foreach ( array( 'contacts', 'deals', 'line_items', 'notes' ) as $object ) {
			try {
				$records->searchAll( $object, array(), array(), 1 );
				$permissions[ 'read_' . $object ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 !== $exception->status() ) {
					throw $exception;
				}
				$permissions[ 'read_' . $object ] = false;
			}
		}
		$options  = $this->options( $connection );
		$company  = '';
		$detected = array( 'api_version' => $this->version( $connection ) );
		$details  = $this->api( $connection )->get( '/account-info/v3/details', array(), null, array( 'allow' => array( 403, 404 ) ) );
		if ( $details->isSuccess() ) {
			$json     = $details->json();
			$company  = 'HubSpot ' . (string) ( $json['portalId'] ?? '' );
			$detected = $detected + array_filter(
				array(
					'currency' => (string) ( $json['companyCurrency'] ?? '' ),
					'timezone' => (string) ( $json['timeZone'] ?? '' ),
				)
			);
		}
		$pipelines             = $records->pipelines();
		$detected['pipelines'] = null === $pipelines ? null : count( $pipelines );
		$wanted                = $options->text( 'pipeline' );
		if ( null !== $pipelines && '' !== $wanted && ! in_array( $wanted, array_map( static fn( array $row ): string => (string) ( $row['id'] ?? '' ), $pipelines ), true ) ) {
			return new TestResult( false, 'HubSpot API ' . $detected['api_version'], '' !== $company ? $company : 'HubSpot', array(), $permissions, $this->invalid( 'pipeline', __( 'HubSpot has no deal pipeline with this id.', 'estasoft-erp-crm-sync-engine' ) ), $detected );
		}
		$missing = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error   = array() === $missing ? null : $this->noPermission( sprintf( /* translators: 1: object name */ __( 'read %s', 'estasoft-erp-crm-sync-engine' ), str_replace( 'read_', '', (string) $missing[0] ) ) );
		return new TestResult( null === $error, 'HubSpot API ' . $detected['api_version'], '' !== $company ? $company : ( '' !== $options->text( 'portal_id' ) ? 'HubSpot ' . $options->text( 'portal_id' ) : 'HubSpot' ), array(), $permissions, $error, $detected );
	}

	/**
	 * Find a contact by email for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		try {
			$row = $this->findContact( $this->records( $connection ), $email );
			return null === $row ? null : $this->contactRef( $row );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Find a product by SKU for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			$id = $this->findProduct( $this->records( $connection ), $sku );
			return null === $id ? null : new ExternalRef( $id['id'], $sku, null, array( 'name' => $id['name'] ) );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Search products by name or SKU for the SKU mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		try {
			$refs = array();
			foreach ( $this->records( $connection )->searchAll( 'products', array(), array( 'name', 'hs_sku' ), 50, $query ) as $row ) {
				$properties = (array) ( $row['properties'] ?? array() );
				$refs[]     = new ExternalRef( (string) ( $row['id'] ?? '' ), (string) ( $properties['hs_sku'] ?? '' ), null, array( 'name' => (string) ( $properties['name'] ?? '' ) ) );
			}
			return $refs;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Deals created since a date whose name is a WooCommerce reference.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			$records = $this->records( $connection );
			$prop    = $this->options( $connection )->text( 'reference_property', 'dealname' );
			$start   = (string) ( (int) $from->format( 'U' ) * 1000 );
			$found   = array();
			for ( $round = 0; $round < 20; ++$round ) {
				$rows = $records->searchAll(
					'deals',
					array(
						array(
							'propertyName' => 'createdate',
							'operator'     => 'GTE',
							'value'        => $start,
						),
					),
					array_unique( array_merge( self::dealProperties(), array( $prop ) ) ),
					HubSpotRecords::SEARCH_CAP
				);
				foreach ( $rows as $row ) {
					$properties = (array) ( $row['properties'] ?? array() );
					$reference  = (string) ( $properties[ $prop ] ?? '' );
					if ( 1 === preg_match( '/^WC-\d+$/', $reference ) && ! isset( $found[ $reference ] ) ) {
						$found[ $reference ] = $this->dealRef( $row, $reference, $connection, true );
					}
				}
				$last = array() === $rows ? null : (array) end( $rows );
				if ( count( $rows ) < HubSpotRecords::SEARCH_CAP || ! isset( $last['properties']['createdate'] ) ) {
					break;
				}
				$next = strtotime( (string) $last['properties']['createdate'] );
				if ( false === $next || (string) ( $next * 1000 ) === $start ) {
					break;
				}
				$start = (string) ( $next * 1000 );
			}
			return $found;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * A deal is complete when it exists: contact and line items are linked in the create call.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$deal = $this->findDeal( $this->records( $connection ), $this->options( $connection ), $item->clientReference() );
		return null === $deal ? null : $this->dealRef( $deal, $item->clientReference(), $connection, false );
	}

	/**
	 * A refund is complete when its note exists on the deal.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$records = $this->records( $connection );
		$order   = $this->orderReference( $item );
		$deal    = '' === $order ? null : $this->findDeal( $records, $this->options( $connection ), $order );
		if ( null === $deal ) {
			return null;
		}
		$note = $this->findNote( $records, (string) $deal['id'], $item->clientReference() );
		return null === $note ? null : new ExternalRef( (string) $note['id'], $item->clientReference(), null, array( 'deal_id' => (string) $deal['id'] ) );
	}

	/**
	 * Send an order: contact, line items, then the deal with every association in one call.
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
		$records = $this->records( $connection );
		$options = $this->options( $connection );

		$existing = $this->findDeal( $records, $options, $order->reference );
		if ( null !== $this->quotaWait( $records ) ) {
			return SendResult::failure( $this->waitFor( 3600 ) );
		}
		if ( null !== $existing ) {
			return SendResult::existing( $this->dealRef( $existing, $order->reference, $connection, false ) );
		}

		$products = $this->resolveProducts( $records, $options, $order );
		if ( $products instanceof ConnectorError ) {
			return SendResult::failure( $products );
		}
		$stage = $this->stage( $records, $options, $order->paid );
		if ( $stage instanceof ConnectorError ) {
			return SendResult::failure( $stage );
		}
		$contactId    = $this->contactId( $records, $order->contact );
		$associations = array( $this->association( $contactId, self::ASSOC_DEAL_TO_CONTACT ) );
		foreach ( $this->lineItemRows( $order, $products ) as $properties ) {
			$line           = $records->create( 'line_items', $properties )->json();
			$associations[] = $this->association( (string) ( $line['id'] ?? '' ), self::ASSOC_DEAL_TO_LINE );
		}

		$reference                 = $options->text( 'reference_property', 'dealname' );
		list( $description, $cut ) = Text::limit( Summary::order( $order ), 60000 );
		$properties                = array(
			$reference           => $order->reference,
			'amount'             => Amounts::decimal( $order->totalMinor, $order->currency ),
			'deal_currency_code' => $order->currency,
			'closedate'          => $order->date . 'T00:00:00Z',
			'description'        => $description,
			'pipeline'           => $stage['pipeline'],
			'dealstage'          => $stage['stage'],
		);
		if ( 'dealname' !== $reference ) {
			/* translators: 1: order number */
			$properties['dealname'] = sprintf( __( 'Order %s', 'estasoft-erp-crm-sync-engine' ), substr( $order->reference, 3 ) );
		}
		$deal = $records->create( 'deals', $properties, $associations )->json();
		$id   = (string) ( $deal['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( $this->invalid( 'deal', __( 'HubSpot did not return the new deal.', 'estasoft-erp-crm-sync-engine' ) ) );
		}

		$read = (array) ( $deal['properties'] ?? array() );
		if ( ! $this->totalAgrees( $order, $read ) ) {
			$read = (array) ( $records->update( 'deals', $id, array( 'amount' => Amounts::decimal( $order->totalMinor, $order->currency ) ) )->json()['properties'] ?? $read );
		}
		$actual  = Amounts::minor( $read['amount'] ?? null, $order->currency );
		$problem = $this->totalProblem( $order, $actual, (string) ( $read['deal_currency_code'] ?? '' ), $options );
		if ( $problem instanceof ConnectorError ) {
			$this->discard( $records, $id );
			return SendResult::failure( $problem );
		}
		$warnings = array();
		if ( is_string( $problem ) ) {
			$warnings[] = $problem;
		}
		if ( $cut ) {
			$warnings[] = __( 'The order summary was longer than HubSpot allows and was cut.', 'estasoft-erp-crm-sync-engine' );
		}
		return SendResult::created(
			new ExternalRef(
				$id,
				$order->reference,
				$this->dealUrl( $connection, $id ),
				array(
					'total_minor' => $actual ?? $order->totalMinor,
					'currency'    => $order->currency,
					'warnings'    => $warnings,
				)
			)
		);
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
		$records = $this->records( $connection );
		$deal    = $this->findDeal( $records, $this->options( $connection ), $refund->orderRef );
		if ( null === $deal ) {
			/* translators: 1: order reference */
			return SendResult::failure( $this->invalid( 'order', sprintf( __( 'The order %s is not in HubSpot yet.', 'estasoft-erp-crm-sync-engine' ), $refund->orderRef ) ) );
		}
		$dealId = (string) $deal['id'];
		$note   = $this->findNote( $records, $dealId, $refund->reference );
		if ( null !== $note ) {
			return SendResult::existing( new ExternalRef( (string) $note['id'], $refund->reference, null, array( 'deal_id' => $dealId ) ) );
		}
		list( $body ) = Text::limit( Summary::refund( $refund ), 65000 );
		$created      = $records->create(
			'notes',
			array(
				'hs_timestamp' => $refund->date . 'T00:00:00Z',
				'hs_note_body' => $body,
			),
			array( $this->association( $dealId, self::ASSOC_NOTE_TO_DEAL ) )
		)->json();
		return SendResult::created( new ExternalRef( (string) ( $created['id'] ?? '' ), $refund->reference, null, array( 'deal_id' => $dealId ) ) );
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
		$records = $this->records( $connection );
		$row     = $this->findContact( $records, $contact['email'] );
		if ( null !== $row ) {
			return SendResult::existing( $this->contactRef( $row ) );
		}
		$id = $this->contactId( $records, $contact );
		return SendResult::created( new ExternalRef( $id, trim( $contact['first_name'] . ' ' . $contact['last_name'] ), null, array( 'email' => $contact['email'] ) ) );
	}

	/**
	 * Record client for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function records( Connection $connection ): HubSpotRecords {
		return new HubSpotRecords( $this->api( $connection ), $this->version( $connection ) );
	}

	/**
	 * API version chosen for the connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function version( Connection $connection ): string {
		return $this->options( $connection )->choice( 'api_version', self::VERSIONS, self::VERSIONS[0] );
	}

	/**
	 * Properties asked for on deals.
	 *
	 * @return array<int,string>
	 */
	private static function dealProperties(): array {
		return array( 'dealname', 'amount', 'deal_currency_code', 'createdate', 'closedate' );
	}

	/**
	 * Order reference of a refund item: the partition key is order:{id}.
	 *
	 * @param Item $item Refund item.
	 */
	private function orderReference( Item $item ): string {
		return 1 === preg_match( '/^order:(\d+)$/', $item->partitionKey, $parts ) ? 'WC-' . $parts[1] : '';
	}

	/**
	 * The oldest deal carrying the reference.
	 *
	 * @param HubSpotRecords $records   Records.
	 * @param CrmOptions     $options   Options.
	 * @param string         $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findDeal( HubSpotRecords $records, CrmOptions $options, string $reference ): ?array {
		$property = $options->text( 'reference_property', 'dealname' );
		$rows     = $records->searchAll(
			'deals',
			array(
				array(
					'propertyName' => $property,
					'operator'     => 'EQ',
					'value'        => $reference,
				),
			),
			array_unique( array_merge( self::dealProperties(), array( $property ) ) )
		);
		foreach ( $rows as $row ) {
			if ( Text::same( (string) ( $row['properties'][ $property ] ?? '' ), $reference ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The note of a deal that carries a reference on its first line.
	 *
	 * @param HubSpotRecords $records   Records.
	 * @param string         $dealId    Deal id.
	 * @param string         $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findNote( HubSpotRecords $records, string $dealId, string $reference ): ?array {
		$rows = $records->searchAll(
			'notes',
			array(
				array(
					'propertyName' => 'associations.deal',
					'operator'     => 'EQ',
					'value'        => $dealId,
				),
			),
			array( 'hs_note_body' )
		);
		foreach ( $rows as $row ) {
			$first = strtok( (string) ( $row['properties']['hs_note_body'] ?? '' ), "\n" );
			if ( false !== $first && Text::same( trim( $first ), $reference ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Reference to a deal.
	 *
	 * @param array<string,mixed> $row        Deal row.
	 * @param string              $reference  WC-number.
	 * @param Connection          $connection Connection.
	 * @param bool                $reconcile  Add the keys reconciliation reads.
	 */
	private function dealRef( array $row, string $reference, Connection $connection, bool $reconcile ): ExternalRef {
		$properties = (array) ( $row['properties'] ?? array() );
		$currency   = strtoupper( (string) ( $properties['deal_currency_code'] ?? '' ) );
		$total      = Amounts::minor( $properties['amount'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0;
		$extra      = array(
			'total_minor' => $total,
			'currency'    => $currency,
		);
		if ( $reconcile ) {
			$extra += array(
				'refunded_minor' => null,
				'email'          => '',
			);
		}
		return new ExternalRef( (string) ( $row['id'] ?? '' ), $reference, $this->dealUrl( $connection, (string) ( $row['id'] ?? '' ) ), $extra );
	}

	/**
	 * Link to a deal when the Hub ID is known.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $id         Deal id.
	 */
	private function dealUrl( Connection $connection, string $id ): ?string {
		$portal = $this->options( $connection )->text( 'portal_id' );
		return ctype_digit( $portal ) && '' !== $id ? 'https://app.hubspot.com/contacts/' . $portal . '/record/0-3/' . rawurlencode( $id ) : null;
	}

	/**
	 * The oldest contact with the email.
	 *
	 * @param HubSpotRecords $records Records.
	 * @param string         $email   Email.
	 * @return array<string,mixed>|null
	 */
	private function findContact( HubSpotRecords $records, string $email ): ?array {
		$rows = $records->searchAll(
			'contacts',
			array(
				array(
					'propertyName' => 'email',
					'operator'     => 'EQ',
					'value'        => $email,
				),
			),
			array( 'email', 'firstname', 'lastname' )
		);
		foreach ( $rows as $row ) {
			if ( Text::same( (string) ( $row['properties']['email'] ?? '' ), $email ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Reference to a contact; the reference text is the name as typed.
	 *
	 * @param array<string,mixed> $row Contact row.
	 */
	private function contactRef( array $row ): ExternalRef {
		$properties = (array) ( $row['properties'] ?? array() );
		$name       = trim( (string) ( $properties['firstname'] ?? '' ) . ' ' . (string) ( $properties['lastname'] ?? '' ) );
		return new ExternalRef( (string) ( $row['id'] ?? '' ), '' !== $name ? $name : (string) ( $properties['email'] ?? '' ), null, array( 'email' => (string) ( $properties['email'] ?? '' ) ) );
	}

	/**
	 * Id of the contact for the buyer: found by email, else created; a 409 answer gives the existing id.
	 *
	 * @param HubSpotRecords       $records Records.
	 * @param array<string,string> $contact Buyer.
	 * @throws ApiException When HubSpot refuses a call.
	 */
	private function contactId( HubSpotRecords $records, array $contact ): string {
		$row = $this->findContact( $records, $contact['email'] );
		if ( null !== $row ) {
			return (string) $row['id'];
		}
		$properties = array_filter(
			array(
				'email'     => $contact['email'],
				'firstname' => $contact['first_name'],
				'lastname'  => $contact['last_name'],
				'phone'     => $contact['phone'],
				'company'   => $contact['company'],
				'address'   => $contact['street'],
				'city'      => $contact['city'],
				'state'     => $contact['state'],
				'zip'       => $contact['postcode'],
				'country'   => $contact['country'],
			),
			static fn( string $value ): bool => '' !== $value
		);
		$response   = $records->create( 'contacts', $properties, array(), array( 409 ) );
		if ( 409 === $response->status() ) {
			$text = (string) ( $response->json()['message'] ?? '' );
			if ( 1 === preg_match( '/Existing ID:\s*(\d+)/', $text, $parts ) ) {
				return $parts[1];
			}
			$again = $this->findContact( $records, $contact['email'] );
			if ( null !== $again ) {
				return (string) $again['id'];
			}
			throw new ApiException( $this->invalid( 'email', __( 'HubSpot says the contact exists but did not say which one.', 'estasoft-erp-crm-sync-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return (string) ( $response->json()['id'] ?? '' );
	}

	/**
	 * An association entry for a create call.
	 *
	 * @param string $id     Target record id.
	 * @param int    $typeId Default association type id.
	 * @return array<string,mixed>
	 */
	private function association( string $id, int $typeId ): array {
		return array(
			'to'    => array( 'id' => ctype_digit( $id ) ? (int) $id : $id ),
			'types' => array(
				array(
					'associationCategory' => 'HUBSPOT_DEFINED',
					'associationTypeId'   => $typeId,
				),
			),
		);
	}

	/**
	 * The HubSpot product (id and name) for a SKU.
	 *
	 * @param HubSpotRecords $records Records.
	 * @param string         $sku     SKU.
	 * @return array{id:string,name:string}|null
	 */
	private function findProduct( HubSpotRecords $records, string $sku ): ?array {
		$rows = $records->searchAll(
			'products',
			array(
				array(
					'propertyName' => 'hs_sku',
					'operator'     => 'EQ',
					'value'        => $sku,
				),
			),
			array( 'name', 'hs_sku' )
		);
		foreach ( $rows as $row ) {
			if ( Text::same( (string) ( $row['properties']['hs_sku'] ?? '' ), $sku ) ) {
				return array(
					'id'   => (string) $row['id'],
					'name' => (string) ( $row['properties']['name'] ?? '' ),
				);
			}
		}
		return null;
	}

	/**
	 * Product ids per line index when product matching is on; an unknown SKU is refused before anything is created.
	 *
	 * @param HubSpotRecords $records Records.
	 * @param CrmOptions     $options Options.
	 * @param OrderData      $order   Order.
	 * @return array<int,string>|ConnectorError
	 */
	private function resolveProducts( HubSpotRecords $records, CrmOptions $options, OrderData $order ): array|ConnectorError {
		if ( 'on' !== $options->choice( 'product_matching', array( 'on', 'off' ), 'off' ) ) {
			return array();
		}
		$ids = array();
		foreach ( $order->lines as $index => $line ) {
			if ( '' === $line->sku ) {
				continue;
			}
			$product = $this->findProduct( $records, $line->sku );
			if ( null !== $product ) {
				$ids[ $index ] = $product['id'];
			} elseif ( 'free_text' !== $options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return $this->unknownSku( $line->sku );
			}
		}
		return $ids;
	}

	/**
	 * Pipeline and stage for a new deal: the options, else the first pipeline and its stages.
	 *
	 * @param HubSpotRecords $records Records.
	 * @param CrmOptions     $options Options.
	 * @param bool           $paid    Whether the order is paid.
	 * @return array{pipeline:string,stage:string}|ConnectorError
	 */
	private function stage( HubSpotRecords $records, CrmOptions $options, bool $paid ): array|ConnectorError {
		$pipeline = $options->text( 'pipeline' );
		$unpaid   = $options->text( 'deal_stage' );
		$closed   = $options->text( 'deal_stage_paid' );
		if ( '' !== $pipeline && '' !== $unpaid ) {
			return array(
				'pipeline' => $pipeline,
				'stage'    => $paid && '' !== $closed ? $closed : $unpaid,
			);
		}
		$pipelines = $records->pipelines();
		if ( null === $pipelines || array() === $pipelines ) {
			return $this->invalid( 'pipeline', __( 'Enter the pipeline and stage ids in the connection settings: HubSpot did not let the key read the pipelines.', 'estasoft-erp-crm-sync-engine' ) );
		}
		usort( $pipelines, static fn( array $a, array $b ): int => (int) ( $a['displayOrder'] ?? 0 ) <=> (int) ( $b['displayOrder'] ?? 0 ) );
		$chosen = $pipelines[0];
		foreach ( $pipelines as $candidate ) {
			if ( '' !== $pipeline && (string) ( $candidate['id'] ?? '' ) === $pipeline ) {
				$chosen = $candidate;
			}
		}
		$stages = array_values( array_filter( (array) ( $chosen['stages'] ?? array() ), 'is_array' ) );
		usort( $stages, static fn( array $a, array $b ): int => (int) ( $a['displayOrder'] ?? 0 ) <=> (int) ( $b['displayOrder'] ?? 0 ) );
		if ( array() === $stages ) {
			return $this->invalid( 'dealstage', __( 'The HubSpot pipeline has no stages.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$first = '' !== $unpaid ? $unpaid : (string) $stages[0]['id'];
		$won   = $first;
		foreach ( $stages as $stage ) {
			$meta = (array) ( $stage['metadata'] ?? array() );
			if ( 'true' === strtolower( (string) ( $meta['isClosed'] ?? '' ) ) && 1.0 === (float) ( $meta['probability'] ?? 0 ) ) {
				$won = (string) $stage['id'];
				break;
			}
		}
		return array(
			'pipeline' => (string) $chosen['id'],
			'stage'    => $paid ? ( '' !== $closed ? $closed : $won ) : $first,
		);
	}

	/**
	 * Line item property sets: lines, then shipping and fees.
	 *
	 * @param OrderData         $order    Order.
	 * @param array<int,string> $products Product ids by line index.
	 * @return array<int,array<string,mixed>>
	 */
	private function lineItemRows( OrderData $order, array $products ): array {
		$rows = array();
		foreach ( $order->lines as $index => $line ) {
			$rows[] = $this->lineProperties( $order->currency, $line, $products[ $index ] ?? '' );
		}
		if ( $order->shippingMinor > 0 ) {
			$rows[] = array(
				'name'     => __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
				'price'    => Amounts::decimal( $order->shippingMinor, $order->currency ),
				'quantity' => '1',
			);
		}
		foreach ( $order->fees as $fee ) {
			$rows[] = array(
				'name'     => '' !== $fee['name'] ? $fee['name'] : __( 'Fee', 'estasoft-erp-crm-sync-engine' ),
				'price'    => Amounts::decimal( max( 0, $fee['total'] ), $order->currency ),
				'quantity' => '1',
			);
		}
		return $rows;
	}

	/**
	 * Properties of one line item: unit price and quantity when they multiply to the line total, else the total as one unit.
	 *
	 * @param string    $currency  Currency.
	 * @param OrderLine $line      Line.
	 * @param string    $productId HubSpot product id, may be empty.
	 * @return array<string,mixed>
	 */
	private function lineProperties( string $currency, OrderLine $line, string $productId ): array {
		$exact = $line->isWhole() && $line->unitMinor * (int) $line->quantity === $line->totalMinor;
		$props = array(
			'name'     => $exact ? $line->name : $line->name . ' x ' . $line->quantity,
			'price'    => Amounts::decimal( $exact ? $line->unitMinor : $line->totalMinor, $currency ),
			'quantity' => $exact ? (string) (int) $line->quantity : '1',
		);
		if ( '' !== $line->sku ) {
			$props['hs_sku'] = $line->sku;
		}
		if ( '' !== $productId ) {
			$props['hs_product_id'] = $productId;
		}
		return $props;
	}

	/**
	 * Whether the deal properties carry the expected amount.
	 *
	 * @param OrderData           $order Order.
	 * @param array<string,mixed> $read  Deal properties.
	 */
	private function totalAgrees( OrderData $order, array $read ): bool {
		$actual = Amounts::minor( $read['amount'] ?? null, $order->currency );
		return null === $actual || Amounts::agree( $order->totalMinor, $actual );
	}

	/**
	 * Archive a deal that must not stay (wrong money); a failure to archive is left for the next pass to report.
	 *
	 * @param HubSpotRecords $records Records.
	 * @param string         $id      Deal id.
	 */
	private function discard( HubSpotRecords $records, string $id ): void {
		try {
			$records->archive( 'deals', $id );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Seconds to wait when HubSpot said the daily quota is nearly used.
	 *
	 * @param HubSpotRecords $records Records.
	 */
	private function quotaWait( HubSpotRecords $records ): ?int {
		$left = $records->dailyRemaining();
		return null !== $left && $left < self::QUOTA_FLOOR ? 3600 : null;
	}
}
