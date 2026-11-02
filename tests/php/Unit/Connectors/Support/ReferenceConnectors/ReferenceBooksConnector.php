<?php
/**
 * A small accounting connector built on the shared base: the model for a key-based connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Core\Support\Money;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Flow of send(): find the invoice by its reference first (a retry or a crash never duplicates), find or create the contact by email, find every item by
 * code (an unknown SKU is SYNC-MAP-001 before anything is sent), create the invoice with an idempotency key, then read the currency and the total back and
 * compare them with the order. Money stays in integer minor units; the decimal strings of the vendor are made and read at the edge only.
 */
class ReferenceBooksConnector extends AbstractApiConnector {

	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'reference-books',
			label: 'Acme Books',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'AE', 'KW' ),
			rateLimit: new RateLimitHints( requestsPerSecond: 5.0, concurrency: 1 ),
			hosts: array( 'api.acme-books.test' ),
		);
	}

	protected function baseUrl( Connection $connection ): string {
		return 'https://api.acme-books.test/v1';
	}

	protected function authStrategy( Connection $connection ): AuthStrategy {
		return new HeaderKeyAuth( 'X-Api-Key', 'api_key' );
	}

	protected function credentialDefinition(): array {
		return array( CredentialFields::apiKey() );
	}

	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		$code = (string) ( $response->json()['code'] ?? '' );
		return match ( $code ) {
			'plan_required' => new ConnectorError( ErrorCode::Auth006, array(), $response->status() ),
			'key_expired'   => new ConnectorError( ErrorCode::Auth005, array( 'date' => (string) ( $response->json()['expired_on'] ?? '' ) ), $response->status() ),
			default         => $this->validation( $response ),
		};
	}

	private function validation( ApiResponse $response ): ?ConnectorError {
		$errors = $response->json()['errors'][0] ?? null;
		if ( 422 !== $response->status() || ! is_array( $errors ) ) {
			return null;
		}
		return new ConnectorError( ErrorCode::Val002, array( 'field' => (string) $errors['field'], 'reason' => (string) $errors['message'] ), 422 );
	}

	protected function identify( Connection $connection ): TestResult {
		$company = $this->api( $connection )->get( '/company' )->json();
		$rates   = $this->api( $connection )->get( '/tax-rates' )->json()['items'] ?? array();
		return new TestResult(
			true,
			'Acme Books ' . $company['version'],
			(string) $company['name'],
			array(),
			array( 'create_invoices' => (bool) ( $company['permissions']['invoices:write'] ?? false ) ),
			null,
			array( 'plan' => $company['plan'], 'tax_codes' => count( $rates ), 'currency' => $company['currency'] )
		);
	}

	protected function fieldCatalog( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders' => FieldCatalogBuilder::make()
				->group( 'Invoice' )->text( 'invoice.reference', 'Reference', true )->text( 'invoice.currency', 'Currency', true )
				->group( 'Customer' )->text( 'contact.name', 'Name', true )->field( 'contact.email', 'Email', 'email', true )
				->group( 'Lines' )->sku( 'lines[].sku', 'Item code', true )->number( 'lines[].quantity', 'Quantity', true )->money( 'lines[].unit_price', 'Unit price', true )->taxCode( 'lines[].tax', 'Tax code' )
				->build(),
			'customers' => FieldCatalogBuilder::make()->group( 'Customer' )->text( 'contact.name', 'Name', true )->field( 'contact.email', 'Email', 'email', true )->build(),
			default => array(),
		};
	}

	protected function mappings( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders' => array(
				array( 'id' => 'ref', 'target' => 'invoice.reference', 'source' => 'order.client_ref', 'required' => true ),
				array( 'id' => 'currency', 'target' => 'invoice.currency', 'source' => 'order.currency', 'required' => true ),
				array( 'id' => 'name', 'target' => 'contact.name', 'source' => array( 'billing.first_name', 'billing.last_name' ), 'transforms' => array( array( 'type' => 'join', 'args' => array( 'separator' => ' ' ) ) ), 'required' => true ),
				array( 'id' => 'email', 'target' => 'contact.email', 'source' => 'billing.email', 'required' => true, 'error_code' => 'SYNC-MAP-003' ),
				array( 'id' => 'sku', 'target' => 'lines[].sku', 'source' => 'lines[].sku', 'required' => true, 'error_code' => 'SYNC-MAP-001' ),
				array( 'id' => 'qty', 'target' => 'lines[].quantity', 'source' => 'lines[].quantity', 'required' => true ),
				array( 'id' => 'price', 'target' => 'lines[].unit_price', 'source' => 'lines[].unit_price', 'required' => true ),
			),
			'customers' => array(
				array( 'id' => 'name', 'target' => 'contact.name', 'source' => 'billing.first_name', 'required' => true ),
				array( 'id' => 'email', 'target' => 'contact.email', 'source' => 'billing.email', 'required' => true ),
			),
			default => array(),
		};
	}

	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( $this->api( $connection )->get( '/tax-rates' )->json()['items'] ?? array() as $rate ) {
			$codes[] = array( 'code' => (string) $rate['code'], 'name' => (string) $rate['name'], 'rate_percent' => (string) $rate['rate'] );
		}
		return $codes;
	}

	/**
	 * The invoice with the order's reference, searched on the server and paged through.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$cursor = null;
		do {
			$page = $this->api( $connection )->get( '/invoices', array( 'reference' => $item->clientReference(), 'cursor' => $cursor ) )->json();
			foreach ( $page['items'] ?? array() as $invoice ) {
				if ( $invoice['reference'] === $item->clientReference() ) {
					return $this->ref( $invoice );
				}
			}
			$cursor = $page['next_cursor'] ?? null;
		} while ( null !== $cursor );
		return null;
	}

	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( EntityType::Customer === $item->entityType ) {
			$contact = $this->contact( $connection, (array) $payload['contact'] );
			return SendResult::created( new ExternalRef( $contact['id'], $contact['name'] ) );
		}
		if ( EntityType::Order !== $item->entityType ) {
			return SendResult::failure( new ConnectorError( ErrorCode::Val002, array( 'system' => $this->label(), 'field' => $item->entityType->value, 'reason' => 'Not supported' ) ) );
		}
		$found = $this->findExisting( $connection, $item );
		if ( null !== $found ) {
			return SendResult::existing( $found );
		}
		$invoice  = (array) $payload['invoice'];
		$currency = (string) $invoice['currency'];
		$contact  = $this->contact( $connection, (array) $payload['contact'] );
		$lines    = array();
		$expected = 0;
		foreach ( (array) $payload['lines'] as $line ) {
			$thing = $this->api( $connection )->get( '/items', array( 'code' => (string) $line['sku'] ) )->json()['items'][0] ?? null;
			if ( null === $thing ) {
				return SendResult::failure( new ConnectorError( ErrorCode::Map001, array( 'sku' => (string) $line['sku'], 'system' => $this->label() ) ) );
			}
			$lines[]   = array( 'item_id' => $thing['id'], 'quantity' => (int) $line['quantity'], 'unit_price' => Money::toDecimalString( (int) $line['unit_price'], $currency ) );
			$expected += (int) $line['quantity'] * (int) $line['unit_price'];
		}
		$created = $this->api( $connection )->post(
			'/invoices',
			array(),
			array( 'reference' => $item->clientReference(), 'currency' => $currency, 'contact_id' => $contact['id'], 'lines' => $lines ),
			array( 'idempotency_key' => $item->idempotencyKey )
		)->json();
		if ( $created['currency'] !== $currency || Money::fromDecimalString( (string) $created['total'], $currency ) !== $expected ) {
			return SendResult::failure( new ConnectorError( ErrorCode::Val002, array( 'system' => $this->label(), 'field' => 'total', 'reason' => 'The total in Acme Books differs from the order.' ) ) );
		}
		return SendResult::created( $this->ref( $created ) );
	}

	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = $this->api( $connection )->get( '/contacts', array( 'email' => $email ) )->json()['items'][0] ?? null;
		return null === $found ? null : new ExternalRef( $found['id'], $found['name'] );
	}

	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->api( $connection )->get( '/items', array( 'code' => $sku ) )->json()['items'][0] ?? null;
		return null === $found ? null : new ExternalRef( $found['id'], $found['code'] );
	}

	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return array_map(
			static fn( array $item ): ExternalRef => new ExternalRef( $item['id'], $item['code'], null, array( 'name' => $item['name'] ) ),
			$this->api( $connection )->get( '/items', array( 'q' => $query ) )->json()['items'] ?? array()
		);
	}

	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		$response = $this->api( $connection )->get( '/invoices/' . rawurlencode( $ref->id ), array(), null, array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		$invoice = $response->json();
		return array( 'number' => (string) $invoice['number'], 'status' => (string) $invoice['status'], 'authority_status' => '', 'pdf_url' => '', 'qr' => '', 'issued_at' => (string) $invoice['created_at'] );
	}

	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		$list   = array();
		$cursor = null;
		do {
			$page = $this->api( $connection )->get( '/invoices', array( 'since' => $from->format( 'Y-m-d\TH:i:s\Z' ), 'cursor' => $cursor ) )->json();
			foreach ( $page['items'] ?? array() as $invoice ) {
				$list[ (string) $invoice['reference'] ] = $this->ref( $invoice );
			}
			$cursor = $page['next_cursor'] ?? null;
		} while ( null !== $cursor );
		return $list;
	}

	/**
	 * Find or create a contact by email (case-insensitive), names as typed.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $contact    Payload contact.
	 * @return array{id:string,name:string,email:string}
	 */
	private function contact( Connection $connection, array $contact ): array {
		$found = $this->api( $connection )->get( '/contacts', array( 'email' => strtolower( (string) $contact['email'] ) ) )->json()['items'][0] ?? null;
		if ( null !== $found ) {
			return $found;
		}
		return $this->api( $connection )->post( '/contacts', array(), array( 'name' => (string) $contact['name'], 'email' => (string) $contact['email'] ) )->json();
	}

	/**
	 * A reference with the facts reconciliation needs.
	 *
	 * @param array<string,mixed> $invoice Vendor invoice.
	 */
	private function ref( array $invoice ): ExternalRef {
		$currency = (string) $invoice['currency'];
		return new ExternalRef(
			(string) $invoice['id'],
			(string) $invoice['number'],
			'https://app.acme-books.test/invoices/' . $invoice['id'],
			array(
				'total_minor'    => Money::fromDecimalString( (string) $invoice['total'], $currency ),
				'refunded_minor' => Money::fromDecimalString( (string) ( $invoice['refunded'] ?? '0' ), $currency ),
				'email'          => (string) ( $invoice['contact']['email'] ?? '' ),
			)
		);
	}
}
