<?php
/**
 * Shared flow of the connectors that send orders to a CRM or an accounting system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Dispatches an item to the vendor's order, refund or customer step, turns every exception into a ConnectorError and offers the
 * checks all of them need (payload, totals). It never sends anything itself.
 */
abstract class AbstractSalesConnector extends AbstractApiConnector {

	/**
	 * Option keys this connector reads from a connection.
	 *
	 * @return array<int,string>
	 */
	abstract protected function optionKeys(): array;

	/**
	 * Create (or complete) the record of an order.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	abstract protected function sendOrder( Connection $connection, Item $item, array $payload ): SendResult;

	/**
	 * Create (or complete) the record of a refund.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	abstract protected function sendRefund( Connection $connection, Item $item, array $payload ): SendResult;

	/**
	 * Find or create the contact of a customer item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	abstract protected function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult;

	/**
	 * A finished order record, an error, or null.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	abstract protected function findOrder( Connection $connection, Item $item ): ExternalRef|ConnectorError|null;

	/**
	 * A finished refund record, an error, or null.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	abstract protected function findRefund( Connection $connection, Item $item ): ExternalRef|ConnectorError|null;

	/**
	 * Send an item by its entity type. Never throws.
	 *
	 * @param Connection          $connection Connection with secrets.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		try {
			return match ( $item->entityType ) {
				EntityType::Order    => $this->sendOrder( $connection, $item, $payload ),
				EntityType::Refund   => $this->sendRefund( $connection, $item, $payload ),
				EntityType::Customer => $this->sendCustomer( $connection, $item, $payload ),
				default              => SendResult::failure( $this->invalid( 'entity', __( 'This connector does not send that kind of item.', 'estasoft-erp-crm-sync-engine' ) ) ),
			};
		} catch ( \Throwable $throwable ) {
			return SendResult::failure( ErrorBridge::toError( $throwable, $this->label() ) );
		}
	}

	/**
	 * Look for a finished record of the item by its client reference. Never throws.
	 *
	 * @param Connection $connection Connection with secrets.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		try {
			return match ( $item->entityType ) {
				EntityType::Order  => $this->findOrder( $connection, $item ),
				EntityType::Refund => $this->findRefund( $connection, $item ),
				default            => null,
			};
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function options( Connection $connection ): CrmOptions {
		return CrmOptions::fromConnection( $connection, $this->optionKeys() );
	}

	/**
	 * The payload reader, dated with the clock of the plugin.
	 */
	protected function reader(): PayloadReader {
		return new PayloadReader( $this->label(), $this->deps->clock->now()->format( 'Y-m-d' ) );
	}

	/**
	 * Compare what the vendor holds with the order. Returns a warning text when the option allows going on, an error when it does not.
	 *
	 * @param OrderData  $order          Order.
	 * @param int|null   $actualMinor    Total the vendor reports, minor units; null when it reports none.
	 * @param string     $actualCurrency Currency the vendor reports; empty when it reports none.
	 * @param CrmOptions $options        Options (total_mismatch: fail or warn).
	 * @return ConnectorError|string|null
	 */
	protected function totalProblem( OrderData $order, ?int $actualMinor, string $actualCurrency, CrmOptions $options ): ConnectorError|string|null {
		$reason = '';
		if ( '' !== $actualCurrency && strtoupper( $actualCurrency ) !== $order->currency ) {
			/* translators: 1: currency of the order, 2: currency in the system */
			$reason = sprintf( __( 'The order is in %1$s but the system recorded it in %2$s.', 'estasoft-erp-crm-sync-engine' ), $order->currency, strtoupper( $actualCurrency ) );
		} elseif ( null !== $actualMinor && ! Amounts::agree( $order->totalMinor, $actualMinor ) ) {
			/* translators: 1: order total, 2: total in the system, 3: currency */
			$reason = sprintf( __( 'The order total is %1$s but the system recorded %2$s %3$s.', 'estasoft-erp-crm-sync-engine' ), Amounts::decimal( $order->totalMinor, $order->currency ), Amounts::decimal( $actualMinor, $order->currency ), $order->currency );
		}
		if ( '' === $reason ) {
			return null;
		}
		if ( 'warn' === $options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			return $reason;
		}
		return $this->invalid( 'total', $reason );
	}

	/**
	 * A reference to a record, with the facts reconciliation and the contract tests read.
	 *
	 * @param string              $id    Vendor id.
	 * @param string              $ref   Human reference.
	 * @param string|null         $url   Link to the record.
	 * @param array<string,mixed> $extra Extra facts.
	 */
	protected function ref( string $id, string $ref, ?string $url = null, array $extra = array() ): ExternalRef {
		return new ExternalRef( $id, $ref, $url, $extra );
	}

	/**
	 * Validation error with a short reason.
	 *
	 * @param string $field  Field the person can fix.
	 * @param string $reason Short reason.
	 */
	protected function invalid( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $this->label(),
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'invalid ' . $field
		);
	}

	/**
	 * Unknown SKU error.
	 *
	 * @param string $sku SKU.
	 */
	protected function unknownSku( string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'system' => $this->label(),
				'sku'    => $sku,
			),
			null,
			null,
			null,
			'unknown sku'
		);
	}

	/**
	 * Rate-limit error with the wait.
	 *
	 * @param int $seconds Seconds to wait.
	 */
	protected function waitFor( int $seconds ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Rate001,
			array(
				'system' => $this->label(),
				'n'      => $seconds,
			),
			429,
			$seconds,
			null,
			'rate limited'
		);
	}

	/**
	 * Permission error naming the action.
	 *
	 * @param string $action What the user may not do.
	 */
	protected function noPermission( string $action ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => $this->label(),
				'action' => $action,
			),
			403,
			null,
			null,
			'no permission'
		);
	}
}
