<?php
/**
 * Pipedrive connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pipedrive;

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
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
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
 * Orders become deals with a summary note, customers become persons (and organizations), refunds become notes (docs/connectors/pipedrive.md).
 */
final class PipedriveConnector extends AbstractSalesConnector {

	/**
	 * Connector facts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'pipedrive',
			label: 'Pipedrive',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 3.0, concurrency: 1 ),
			hosts: array( '*.pipedrive.com' )
		);
	}

	/**
	 * Option keys.
	 *
	 * @return array<int,string>
	 */
	protected function optionKeys(): array {
		return array( 'pipeline_id', 'stage_id', 'lines', 'unknown_sku', 'mark_won', 'total_mismatch', 'reference_field' );
	}

	/**
	 * Connect screen fields.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField( 'company_domain', __( 'Company address', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The part before .pipedrive.com in your Pipedrive address.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text' ),
			new CredentialField( 'api_token', __( 'Personal API token', 'estasoft-enterprise-erpcrm-sync-engine' ), true, true, __( 'Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'password' ),
			new CredentialField( 'pipeline_id', __( 'Pipeline id', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave empty to use your default pipeline.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'number', array(), 'advanced' ),
			new CredentialField( 'stage_id', __( 'Stage id', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave empty to use the first stage of the pipeline.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'number', array(), 'advanced' ),
		);
	}

	/**
	 * The company host, from the address the merchant typed.
	 *
	 * @param Connection $connection Connection.
	 * @throws ApiException When the address is not a Pipedrive company address.
	 */
	protected function baseUrl( Connection $connection ): string {
		$typed = strtolower( trim( (string) $connection->config( 'company_domain', '' ) ) );
		$typed = (string) preg_replace( '~^https?://~', '', $typed );
		$typed = (string) strtok( $typed, '/' );
		$typed = (string) preg_replace( '~\.pipedrive\.com$~', '', $typed );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/', $typed ) ) {
			throw new ApiException( $this->invalid( 'company_domain', __( 'Enter the part of your Pipedrive address before .pipedrive.com.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return 'https://' . $typed . '.pipedrive.com';
	}

	/**
	 * Personal token in the x-api-token header.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'x-api-token', 'api_token' );
	}

	/**
	 * Vendor error mapping.
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return PipedriveErrorMapper::map( $response );
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
	 * Test connection: the user, the company, what the token may read, and the deal field that carries the order reference (created when it
	 * is missing and the user may manage custom fields; otherwise the deal title stays the key and the test says so).
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api         = $this->pipedrive( $connection );
		$me          = $api->me();
		$permissions = array();
		foreach ( array( 'persons', 'organizations', 'deals' ) as $collection ) {
			$permissions[ 'read_' . $collection ] = $api->readable( '/api/v2/' . $collection );
		}
		$permissions['read_notes'] = $api->readable( '/api/v1/notes' );
		$company                   = (string) ( $me['company_name'] ?? '' );
		$company                   = '' !== $company ? $company : (string) ( $me['company_domain'] ?? '' );
		$detected                  = array_filter(
			array(
				'user'     => (string) ( $me['name'] ?? '' ),
				'currency' => (string) ( $me['default_currency'] ?? '' ),
			)
		);
		$missing                   = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error                     = array() === $missing ? null : $this->noPermission( str_replace( 'read_', 'read ', (string) $missing[0] ) );
		if ( null === $error ) {
			$detected += $this->referenceSetup( $api, $this->options( $connection ), $permissions );
		}
		return new TestResult( null === $error, 'Pipedrive API v2', '' !== $company ? $company : 'Pipedrive', array(), $permissions, $error, $detected );
	}

	/**
	 * Find or create the reference field, and report which key the connector will use.
	 *
	 * @param PipedriveApi       $api          API.
	 * @param CrmOptions         $options      Options.
	 * @param array<string,bool> &$permissions Permission list; gets `create_reference_field`.
	 * @return array<string,string> Facts for `detected`.
	 */
	private function referenceSetup( PipedriveApi $api, CrmOptions $options, array &$permissions ): array {
		$typed = $options->text( 'reference_field' );
		if ( '' !== $typed ) {
			// The merchant chose: a field of their own (its key), or `title` on purpose. Nothing is created and nothing is warned about.
			$own = 'title' !== Text::lower( $typed ) && 'name' !== Text::lower( $typed ) && ReferenceField::valid( $typed );
			return array( 'reference_mode' => $own ? 'field' : 'name' );
		}
		$made                                  = ( new PipedriveSetup( $api ) )->referenceField();
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
	 * Cheap health probe: one read of the signed-in user.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== $this->pipedrive( $connection )->me();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Find a person by email for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		try {
			$person = $this->findPerson( $this->pipedrive( $connection ), $email );
			return null === $person ? null : new ExternalRef( (string) $person['id'], (string) ( $person['name'] ?? $email ), null, array( 'email' => $email ) );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Find a product by code for previews.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			$product = $this->findProduct( $this->pipedrive( $connection ), $sku );
			return null === $product ? null : new ExternalRef( (string) $product['id'], $sku, null, array( 'name' => (string) ( $product['name'] ?? '' ) ) );
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
			$refs = array();
			foreach ( $this->pipedrive( $connection )->search( 'product', 'code,name', $query, false ) as $product ) {
				$refs[] = new ExternalRef( (string) ( $product['id'] ?? '' ), (string) ( $product['code'] ?? '' ), null, array( 'name' => (string) ( $product['name'] ?? '' ) ) );
			}
			return $refs;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * Deals changed since a date whose title is a WooCommerce reference.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			$found = array();
			$ref   = $this->reference( $connection );
			$since = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			foreach ( $this->pipedrive( $connection )->dealsUpdatedSince( $since, $ref->dedicated ? $ref->field : '' ) as $deal ) {
				$values    = array( $ref->name => $deal['title'] ?? '' );
				$custom    = $deal['custom_fields'] ?? array();
				$reference = $ref->readFrom( ( $ref->dedicated && is_array( $custom ) ? array( $ref->field => $custom[ $ref->field ] ?? '' ) : array() ) + $values );
				if ( '' === $reference || isset( $found[ $reference ] ) ) {
					continue;
				}
				$found[ $reference ] = $this->dealRef( $connection, $deal, $reference, true );
			}
			return $found;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * A deal is finished when its summary note exists: the note is written last.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$api  = $this->pipedrive( $connection );
		$deal = $this->findDeal( $api, $this->reference( $connection ), $item->clientReference() );
		if ( null === $deal || ! $this->hasNote( $api, (string) $deal['id'], $item->clientReference() ) ) {
			return null;
		}
		return $this->dealRef( $connection, $api->deal( (string) $deal['id'] ) ?? $deal, $item->clientReference(), false );
	}

	/**
	 * A refund is finished when its note is on the deal.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$api   = $this->pipedrive( $connection );
		$order = 1 === preg_match( '/^order:(\d+)$/', $item->partitionKey, $parts ) ? 'WC-' . $parts[1] : '';
		$deal  = '' === $order ? null : $this->findDeal( $api, $this->reference( $connection ), $order );
		if ( null === $deal ) {
			return null;
		}
		$note = $this->findNote( $api, (string) $deal['id'], $item->clientReference() );
		return null === $note ? null : new ExternalRef( (string) ( $note['id'] ?? '' ), $item->clientReference(), null, array( 'deal_id' => (string) $deal['id'] ) );
	}

	/**
	 * Send an order: person, organization, deal, products (option), total check, won (option), then the summary note.
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
		$api     = $this->pipedrive( $connection );
		$options = $this->options( $connection );
		$ref     = $this->reference( $connection );
		$deal    = $this->findDeal( $api, $ref, $order->reference );
		if ( null !== $deal && $this->hasNote( $api, (string) $deal['id'], $order->reference ) ) {
			return SendResult::existing( $this->dealRef( $connection, $api->deal( (string) $deal['id'] ) ?? $deal, $order->reference, false ) );
		}

		$products = 'products' === $options->choice( 'lines', array( 'note', 'products' ), 'note' ) ? $this->matchProducts( $api, $options, $order ) : null;
		if ( $products instanceof ConnectorError ) {
			return SendResult::failure( $products );
		}
		$resumed = null !== $deal;
		if ( null === $deal ) {
			$deal = $api->create( 'deals', $this->dealBody( $api, $options, $order, $ref ) );
		}
		$dealId = (string) ( $deal['id'] ?? '' );
		if ( '' === $dealId ) {
			return SendResult::failure( $this->invalid( 'deal', __( 'Pipedrive did not return the new deal.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		if ( null !== $products ) {
			$this->attachProducts( $api, $options, $dealId, $order, $products );
		}

		$read   = $api->deal( $dealId ) ?? $deal;
		$actual = Amounts::minor( $read['value'] ?? null, $order->currency );
		if ( null !== $products && null !== $actual && ! Amounts::agree( $order->totalMinor, $actual ) ) {
			$read   = $api->patchDeal( $dealId, array( 'value' => Amounts::number( $order->totalMinor, $order->currency ) ) ) + $read;
			$actual = Amounts::minor( $read['value'] ?? null, $order->currency );
		}
		$problem = $this->totalProblem( $order, $actual, (string) ( $read['currency'] ?? '' ), $options );
		if ( $problem instanceof ConnectorError ) {
			$this->discard( $api, $dealId );
			return SendResult::failure( $problem );
		}
		if ( $order->paid && $options->flag( 'mark_won', false ) && 'won' !== ( $read['status'] ?? '' ) ) {
			$read = $api->patchDeal( $dealId, array( 'status' => 'won' ) ) + $read;
		}
		list( $content, $cut ) = Text::limit( Summary::order( $order ), 60000 );
		$api->createNote( $dealId, $this->noteHtml( $content ) );

		$warnings = array();
		if ( is_string( $problem ) ) {
			$warnings[] = $problem;
		}
		if ( $cut ) {
			$warnings[] = __( 'The order summary was cut because it was very long.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		$ref = new ExternalRef(
			$dealId,
			$order->reference,
			$this->dealUrl( $connection, $dealId ),
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
		$api  = $this->pipedrive( $connection );
		$deal = $this->findDeal( $api, $this->reference( $connection ), $refund->orderRef );
		if ( null === $deal ) {
			/* translators: 1: order reference */
			return SendResult::failure( $this->invalid( 'order', sprintf( __( 'The order %s is not in Pipedrive yet.', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->orderRef ) ) );
		}
		$dealId = (string) $deal['id'];
		$note   = $this->findNote( $api, $dealId, $refund->reference );
		if ( null !== $note ) {
			return SendResult::existing( new ExternalRef( (string) ( $note['id'] ?? '' ), $refund->reference, null, array( 'deal_id' => $dealId ) ) );
		}
		list( $content ) = Text::limit( Summary::refund( $refund ), 60000 );
		$created         = $api->createNote( $dealId, $this->noteHtml( $content ) );
		return SendResult::created( new ExternalRef( (string) ( $created['id'] ?? '' ), $refund->reference, null, array( 'deal_id' => $dealId ) ) );
	}

	/**
	 * Send a customer: find the person by email or create it.
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
		$api    = $this->pipedrive( $connection );
		$person = $this->findPerson( $api, $contact['email'] );
		if ( null !== $person ) {
			return SendResult::existing( new ExternalRef( (string) $person['id'], (string) ( $person['name'] ?? $contact['email'] ), null, array( 'email' => $contact['email'] ) ) );
		}
		$created = $api->create( 'persons', $this->personBody( $contact, null ) );
		return SendResult::created( new ExternalRef( (string) ( $created['id'] ?? '' ), $this->personName( $contact ), null, array( 'email' => $contact['email'] ) ) );
	}

	/**
	 * API wrapper for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function pipedrive( Connection $connection ): PipedriveApi {
		return new PipedriveApi( $this->api( $connection ) );
	}

	/**
	 * Link to a deal in the company's Pipedrive.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $id         Deal id.
	 */
	private function dealUrl( Connection $connection, string $id ): string {
		return $this->baseUrl( $connection ) . '/deal/' . rawurlencode( $id );
	}

	/**
	 * Reference to a deal.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $deal       Deal data.
	 * @param string              $reference  WC-number.
	 * @param bool                $reconcile  Add the keys reconciliation reads.
	 */
	private function dealRef( Connection $connection, array $deal, string $reference, bool $reconcile ): ExternalRef {
		$currency = strtoupper( (string) ( $deal['currency'] ?? '' ) );
		$extra    = array(
			'total_minor' => Amounts::minor( $deal['value'] ?? null, '' !== $currency ? $currency : 'USD' ) ?? 0,
			'currency'    => $currency,
			'status'      => (string) ( $deal['status'] ?? '' ),
		);
		if ( $reconcile ) {
			$extra += array(
				'refunded_minor' => null,
				'email'          => '',
			);
		}
		return new ExternalRef( (string) ( $deal['id'] ?? '' ), $reference, $this->dealUrl( $connection, (string) ( $deal['id'] ?? '' ) ), $extra );
	}

	/**
	 * The properties that carry the reference: the custom field Test connection found or made (or the merchant's own), then the title.
	 *
	 * @param Connection $connection Connection.
	 */
	private function reference( Connection $connection ): ReferenceField {
		return ReferenceField::choose( $connection, $this->options( $connection ), 'reference_field', 'title' );
	}

	/**
	 * The oldest deal carrying the reference: in the custom field first, then (the documented fallback, which also finds a deal made before
	 * the field existed) in the title. The custom field is searched through the item search over custom fields (it cannot say which field
	 * matched), so each hit is read and its field compared.
	 *
	 * @param PipedriveApi   $api       API.
	 * @param ReferenceField $ref       Fields that carry the reference.
	 * @param string         $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findDeal( PipedriveApi $api, ReferenceField $ref, string $reference ): ?array {
		if ( $ref->dedicated ) {
			$hits = $api->search( 'deal', 'custom_fields', $reference );
			usort( $hits, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			foreach ( $hits as $hit ) {
				if ( ! isset( $hit['id'] ) ) {
					continue;
				}
				$deal   = $api->deal( (string) $hit['id'] );
				$custom = null === $deal ? null : ( $deal['custom_fields'] ?? null );
				if ( null !== $deal && is_array( $custom ) && Text::same( is_scalar( $custom[ $ref->field ] ?? null ) ? (string) $custom[ $ref->field ] : '', $reference ) ) {
					return $deal;
				}
			}
		}
		return $this->oldest( $api->search( 'deal', 'title', $reference ), 'title', $reference );
	}

	/**
	 * The oldest person with the email.
	 *
	 * @param PipedriveApi $api   API.
	 * @param string       $email Email.
	 * @return array<string,mixed>|null
	 */
	private function findPerson( PipedriveApi $api, string $email ): ?array {
		$items = $api->search( 'person', 'email', $email );
		usort( $items, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		return $items[0] ?? null;
	}

	/**
	 * The product with the code.
	 *
	 * @param PipedriveApi $api API.
	 * @param string       $sku SKU.
	 * @return array<string,mixed>|null
	 */
	private function findProduct( PipedriveApi $api, string $sku ): ?array {
		return $this->oldest( $api->search( 'product', 'code', $sku ), 'code', $sku );
	}

	/**
	 * The lowest-id item whose field equals the value, ignoring case.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param string                         $field Field to compare.
	 * @param string                         $value Wanted value.
	 * @return array<string,mixed>|null
	 */
	private function oldest( array $items, string $field, string $value ): ?array {
		usort( $items, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		foreach ( $items as $item ) {
			if ( Text::same( (string) ( $item[ $field ] ?? '' ), $value ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Name of a person as typed.
	 *
	 * @param array<string,string> $contact Buyer.
	 */
	private function personName( array $contact ): string {
		$name = trim( $contact['first_name'] . ' ' . $contact['last_name'] );
		return '' !== $name ? $name : ( '' !== $contact['company'] ? $contact['company'] : $contact['email'] );
	}

	/**
	 * Body of a person.
	 *
	 * @param array<string,string> $contact Buyer.
	 * @param string|null          $orgId   Organization id.
	 * @return array<string,mixed>
	 */
	private function personBody( array $contact, ?string $orgId ): array {
		$body = array(
			'name'   => $this->personName( $contact ),
			'emails' => array(
				array(
					'value'   => $contact['email'],
					'primary' => true,
					'label'   => 'work',
				),
			),
		);
		if ( '' !== $contact['phone'] ) {
			$body['phones'] = array(
				array(
					'value'   => $contact['phone'],
					'primary' => true,
					'label'   => 'work',
				),
			);
		}
		if ( null !== $orgId ) {
			$body['org_id'] = (int) $orgId;
		}
		return $body;
	}

	/**
	 * Body of a new deal; creates the person and organization first.
	 *
	 * @param PipedriveApi   $api     API.
	 * @param CrmOptions     $options Options.
	 * @param OrderData      $order   Order.
	 * @param ReferenceField $ref     Fields that carry the reference.
	 * @return array<string,mixed>
	 */
	private function dealBody( PipedriveApi $api, CrmOptions $options, OrderData $order, ReferenceField $ref ): array {
		$orgId = null;
		if ( '' !== $order->contact['company'] ) {
			$org   = $this->oldest( $api->search( 'organization', 'name', $order->contact['company'] ), 'name', $order->contact['company'] );
			$orgId = null !== $org ? (string) $org['id'] : (string) ( $api->create(
				'organizations',
				array_filter(
					array(
						'name'    => $order->contact['company'],
						'address' => $this->addressValue( $order->contact ),
					)
				)
			)['id'] ?? '' );
		}
		$person = $this->findPerson( $api, $order->contact['email'] );
		$id     = null !== $person ? (string) $person['id'] : (string) ( $api->create( 'persons', $this->personBody( $order->contact, $orgId ) )['id'] ?? '' );
		$body   = array(
			'title'     => $order->reference,
			'person_id' => (int) $id,
			'value'     => Amounts::number( $order->totalMinor, $order->currency ),
			'currency'  => $order->currency,
			'status'    => 'open',
		);
		// No add_time: Pipedrive documents it as "Requires admin user API token", and the order date is in the summary note.
		if ( $ref->dedicated ) {
			$body['custom_fields'] = array( $ref->field => $order->reference );
		}
		if ( null !== $orgId && '' !== $orgId ) {
			$body['org_id'] = (int) $orgId;
		}
		if ( ctype_digit( $options->text( 'pipeline_id' ) ) ) {
			$body['pipeline_id'] = (int) $options->text( 'pipeline_id' );
		}
		if ( ctype_digit( $options->text( 'stage_id' ) ) ) {
			$body['stage_id'] = (int) $options->text( 'stage_id' );
		}
		return $body;
	}

	/**
	 * Organization address as one line, or an empty array when the buyer gave none.
	 *
	 * @param array<string,string> $contact Buyer.
	 * @return array<string,string>
	 */
	private function addressValue( array $contact ): array {
		$line = implode( ', ', array_filter( array( $contact['street'], $contact['city'], $contact['postcode'], $contact['country'] ), static fn( string $part ): bool => '' !== $part ) );
		return '' === $line ? array() : array( 'value' => $line );
	}

	/**
	 * Product id per line index for products mode; null means "create it". Unknown SKUs are refused before anything is made.
	 *
	 * @param PipedriveApi $api     API.
	 * @param CrmOptions   $options Options.
	 * @param OrderData    $order   Order.
	 * @return array<int,string|null>|ConnectorError
	 */
	private function matchProducts( PipedriveApi $api, CrmOptions $options, OrderData $order ): array|ConnectorError {
		$ids = array();
		foreach ( $order->lines as $index => $line ) {
			if ( '' === $line->sku ) {
				continue;
			}
			$product = $this->findProduct( $api, $line->sku );
			if ( null !== $product ) {
				$ids[ $index ] = (string) $product['id'];
			} elseif ( 'create' === $options->choice( 'unknown_sku', array( 'refuse', 'create' ), 'refuse' ) ) {
				$ids[ $index ] = null;
			} else {
				return $this->unknownSku( $line->sku );
			}
		}
		return $ids;
	}

	/**
	 * Attach the order's products to the deal, creating unknown ones and skipping those already attached.
	 *
	 * @param PipedriveApi           $api      API.
	 * @param CrmOptions             $options  Options.
	 * @param string                 $dealId   Deal id.
	 * @param OrderData              $order    Order.
	 * @param array<int,string|null> $products Product ids by line index.
	 */
	private function attachProducts( PipedriveApi $api, CrmOptions $options, string $dealId, OrderData $order, array $products ): void {
		unset( $options );
		$present = array();
		foreach ( $api->dealProducts( $dealId ) as $row ) {
			$key             = (string) ( $row['product_id'] ?? '' );
			$present[ $key ] = ( $present[ $key ] ?? 0 ) + 1;
		}
		foreach ( $products as $index => $productId ) {
			$line = $order->lines[ $index ];
			if ( null === $productId ) {
				$productId = (string) ( $api->create(
					'products',
					array(
						'name' => $line->name,
						'code' => $line->sku,
					)
				)['id'] ?? '' );
			}
			if ( ( $present[ $productId ] ?? 0 ) > 0 ) {
				--$present[ $productId ];
				continue;
			}
			$exact = $line->isWhole() && $line->unitMinor * (int) $line->quantity === $line->totalMinor;
			$api->addDealProduct(
				$dealId,
				array(
					'product_id' => (int) $productId,
					'item_price' => Amounts::number( $exact ? $line->unitMinor : $line->totalMinor, $order->currency ),
					'quantity'   => $exact ? (int) $line->quantity : 1,
					'comments'   => $exact ? $line->name : $line->name . ' x ' . $line->quantity,
				)
			);
		}
	}

	/**
	 * The note text as HTML, line breaks kept.
	 *
	 * @param string $text Plain text.
	 */
	private function noteHtml( string $text ): string {
		return implode( '<br>', array_map( static fn( string $line ): string => htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' ), explode( "\n", $text ) ) );
	}

	/**
	 * First line of a note, as plain text.
	 *
	 * @param array<string,mixed> $note Note.
	 */
	private function firstLine( array $note ): string {
		$text  = str_ireplace( array( '<br>', '<br/>', '<br />', '</p>', '</div>' ), "\n", (string) ( $note['content'] ?? '' ) );
		$text  = html_entity_decode( (string) preg_replace( '/<[^>]*>/', '', $text ), ENT_QUOTES, 'UTF-8' );
		$parts = explode( "\n", trim( $text ), 2 );
		return trim( $parts[0] );
	}

	/**
	 * The note of a deal whose first line is the reference.
	 *
	 * @param PipedriveApi $api       API.
	 * @param string       $dealId    Deal id.
	 * @param string       $reference WC-number.
	 * @return array<string,mixed>|null
	 */
	private function findNote( PipedriveApi $api, string $dealId, string $reference ): ?array {
		foreach ( $api->notes( $dealId ) as $note ) {
			if ( Text::same( $this->firstLine( $note ), $reference ) ) {
				return $note;
			}
		}
		return null;
	}

	/**
	 * Whether the summary note exists.
	 *
	 * @param PipedriveApi $api       API.
	 * @param string       $dealId    Deal id.
	 * @param string       $reference WC-number.
	 */
	private function hasNote( PipedriveApi $api, string $dealId, string $reference ): bool {
		return null !== $this->findNote( $api, $dealId, $reference );
	}

	/**
	 * Delete a deal that must not stay (wrong money); a failure is left for the next pass to report.
	 *
	 * @param PipedriveApi $api    API.
	 * @param string       $dealId Deal id.
	 */
	private function discard( PipedriveApi $api, string $dealId ): void {
		try {
			$api->deleteDeal( $dealId );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}
}
