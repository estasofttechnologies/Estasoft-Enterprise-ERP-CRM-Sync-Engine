<?php
/**
 * Connector contract, version 1 (spec section 10).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A connector adapts one external system to the engine. It must never touch WordPress tables,
 * must never log secrets, must honour the 30 second timeout, and must classify its own failures.
 *
 * Methods that need a capability the connector does not declare are never called.
 */
interface ConnectorInterface {

	/**
	 * Major version of this contract. The core refuses connectors whose major differs.
	 */
	public const CONTRACT_VERSION = '1.0.0';

	/**
	 * Stable id, for example odoo.
	 */
	public function id(): string;

	/**
	 * Name shown to the person, for example Odoo.
	 */
	public function label(): string;

	/**
	 * Erp, accounting or crm.
	 */
	public function type(): ConnectorType;

	/**
	 * The contract version the connector was written against.
	 */
	public function contractVersion(): string;

	/**
	 * Capability flags.
	 *
	 * @return array<string,bool> Keys are Capability values.
	 */
	public function capabilities(): array;

	/**
	 * Fields of the connect screen (key style connectors).
	 *
	 * @return array<int,CredentialField>
	 */
	public function credentialFields(): array;

	/**
	 * Check the connection and report version, company, warehouses and permissions (CON-4).
	 *
	 * @param Connection $connection Connection with decrypted secrets.
	 */
	public function test( Connection $connection ): TestResult;

	/**
	 * Cheap health probe used by the circuit breaker.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool;

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function describeFields( string $mappingType ): array;

	/**
	 * Version 1 mapping rules for a type (MAP-1).
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>> Rule list (see docs/ARCHITECTURE.md, mapping rule model).
	 */
	public function defaultMapping( string $mappingType ): array;

	/**
	 * Look up a record by the client reference WC-{id}; makes retries safe (QUE-5).
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 * @return ExternalRef|ConnectorError|null The record, an error, or null when none exists.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null;

	/**
	 * Send an item. The payload is already mapped to this connector's target fields.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item being sent.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult;

	/**
	 * Find a customer by email, for previews and matching.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null;

	/**
	 * Find a product by SKU, for previews and matching.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null;

	/**
	 * Search products by name or reference, for SKU mapping (MAP-8).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError;

	/**
	 * Quantities per warehouse for the given SKUs (needs stock_in).
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse ids that count toward shop stock; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError Keyed by SKU.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError;

	/**
	 * Delivery records changed since a time (needs tracking_in).
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>|ConnectorError
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError;

	/**
	 * Invoice facts for a synced order (needs invoice_readback).
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref       The synced order in the system.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null;

	/**
	 * Read-only list for the nightly check (needs reconciliation).
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError Keyed by client reference WC-{id}; extra holds total_minor, refunded_minor, email.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError;

	/**
	 * Override the catalog classification of a failure (optional; return null to keep it).
	 *
	 * @param ConnectorError $error Failure.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass;

	/**
	 * Suggested limits for this system (optional).
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array;

	/**
	 * Entity types the connector can send.
	 *
	 * @return array<int,EntityType>
	 */
	public function supportedEntities(): array;
}
