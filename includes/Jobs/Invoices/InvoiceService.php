<?php
/**
 * Invoices read back from the system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Invoices;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Repositories\Paged;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;

/**
 * INV-1 to INV-6.
 *
 * The plugin never creates, signs, hashes or submits an invoice. This service only reads what the system says about the
 * invoice of an order it has already received, keeps those facts on the order (JSON in `_estasoft_sync_invoice` plus the number,
 * status and PDF link the order box shows) and in the state table, and tells other plugins through
 * `estasoft_sync_invoice_updated( $order, array $invoice )`.
 *
 * Polling: only orders synced in the last 30 days whose invoice is not final (reported, or not required for a posted invoice).
 * All of those orders are looked at, not only the newest few hundred. The ones checked longest ago go first; the call stops when
 * its time is up and continues next time. A rejection is noted once: it is logged and failed again only when its status or reason
 * changes, or when the queue item is no longer failed.
 *
 * Time left: an invoice that waits to be reported is due at the time it was issued plus a window (default 24 hours, editable;
 * the filter `estasoft_sync_invoice_window_hours` overrides it). The screen colours the time left; this class returns `due_at` and
 * `seconds_left` (negative when late). A rejection fails an item of type invoice with SYNC-COMP-001 so it appears under Needs
 * attention; it is resolved when the invoice is reported.
 */
final class InvoiceService {

	/**
	 * Option for the invoice settings.
	 */
	public const OPTION = 'estasoft_sync_invoice_settings';

	/**
	 * Allowed window range in hours: min, max, default.
	 */
	public const WINDOW = array( 1, 168, 24 );

	/**
	 * Default time one call may spend, in seconds.
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * Orders asked about per page of candidates.
	 */
	private const PAGE = 100;

	/**
	 * Most pages of candidates read per connection (a safety cap: 10,000 synced orders of the last 30 days).
	 */
	private const MAX_PAGES = 100;

	/**
	 * Seconds before an order is asked about again.
	 */
	private const RECHECK_SECONDS = 300;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ShopOrders       $orders   WooCommerce orders.
	 * @param StateRepository  $state    Per-order facts.
	 * @param IdMapRepository  $idMap    The system's id of each synced order.
	 * @param QueueRepository  $queue    Queue (candidates and the rejection item).
	 * @param Enqueuer         $enqueuer Queue entry point.
	 * @param ConnectorGateway $gateway  The door to connectors.
	 * @param Redactor         $redactor Redaction for the reason a tax authority gave.
	 * @param Logger           $logger   Log.
	 * @param OptionStore      $options  Options (settings).
	 * @param Clock            $clock    Time source.
	 * @param int              $budget   Seconds one call may spend.
	 * @param int              $pageSize Synced orders read per page when looking for open invoices.
	 */
	public function __construct(
		private readonly ShopOrders $orders,
		private readonly StateRepository $state,
		private readonly IdMapRepository $idMap,
		private readonly QueueRepository $queue,
		private readonly Enqueuer $enqueuer,
		private readonly ConnectorGateway $gateway,
		private readonly Redactor $redactor,
		private readonly Logger $logger,
		private readonly OptionStore $options,
		private readonly Clock $clock,
		private readonly int $budget = self::BUDGET_SECONDS,
		private readonly int $pageSize = self::PAGE
	) {
	}

	/**
	 * The hours between issue and due time.
	 */
	public function window(): int {
		$stored = $this->options->get( self::OPTION, array() );
		$hours  = is_array( $stored ) ? Ranges::read( $stored['window_hours'] ?? null, self::WINDOW ) : null;
		/**
		 * Filter the hours an invoice has to be reported after it was issued.
		 *
		 * @param int $hours Hours (1 to 168).
		 */
		$filtered = apply_filters( 'estasoft_sync_invoice_window_hours', $hours ?? self::WINDOW[2] );
		return Ranges::read( $filtered, self::WINDOW ) ?? self::WINDOW[2];
	}

	/**
	 * The invoice settings (what the invoice settings screen reads): the reporting window in hours.
	 *
	 * @return array{window_hours:int}
	 */
	public function settings(): array {
		return array( 'window_hours' => $this->window() );
	}

	/**
	 * Change the reporting window (what `PUT /invoices/settings` saves).
	 *
	 * @param int $hours Hours, 1 to 168.
	 * @return array{window_hours:int} The settings now.
	 *
	 * @throws ValidationException With a message under `window_hours` when the number is not from 1 to 168.
	 */
	public function saveWindow( int $hours ): array {
		if ( null === Ranges::read( $hours, self::WINDOW ) ) {
			throw new ValidationException( array( 'window_hours' => Ranges::message( self::WINDOW ) ) );
		}
		$this->options->set( self::OPTION, array( 'window_hours' => $hours ) );
		return array( 'window_hours' => $hours );
	}

	/**
	 * The Invoices list (`Paged<InvoiceRow>`).
	 *
	 * @param array<string,mixed> $query Keys: status (waiting, rejected, reported, not_required), page, per_page (25, 50, 100), search.
	 * @return array<string,mixed>
	 */
	public function list( array $query ): array {
		$status  = isset( $query['status'] ) ? (string) $query['status'] : '';
		$status  = in_array( $status, InvoiceStatus::ALL, true ) ? $status : null;
		$page    = max( 1, (int) ( $query['page'] ?? 1 ) );
		$perPage = (int) ( $query['per_page'] ?? 25 );
		$perPage = in_array( $perPage, QueueQuery::PER_PAGE, true ) ? $perPage : 25;
		$search  = isset( $query['search'] ) ? mb_substr( trim( (string) $query['search'] ), 0, 100 ) : '';

		$result = $this->orders->invoiceRows( $status, $search, $page, $perPage );
		$now    = $this->clock->now();
		$window = $this->window();
		$items  = array();
		foreach ( $result['items'] as $row ) {
			$items[] = $this->row( $row, $now, $window );
		}
		return ( new Paged( $items, $result['total'], $page, $perPage ) )->toArray();
	}

	/**
	 * Ask the system about the open invoices.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed> Summary: connections, checked, updated, rejected, errors, complete, seconds.
	 */
	public function run( ?int $connectionId = null ): array {
		$started = $this->clock->now();
		$summary = array(
			'connections' => 0,
			'checked'     => 0,
			'updated'     => 0,
			'rejected'    => 0,
			'errors'      => 0,
			'complete'    => true,
			'seconds'     => 0.0,
		);
		foreach ( $this->gateway->targets( Capability::InvoiceReadback, $connectionId ) as $target ) {
			++$summary['connections'];
			$this->poll( $target['connection'], $target['connector'], $started, $summary );
		}
		$summary['seconds'] = round( $this->elapsed( $started ), 3 );
		return $summary;
	}

	/**
	 * Poll the open invoices of one connection.
	 *
	 * @param Connection          $connection Connection.
	 * @param ConnectorInterface  $connector  Connector.
	 * @param \DateTimeImmutable  $started    Start of the call.
	 * @param array<string,mixed> $summary    Summary (updated).
	 */
	private function poll( Connection $connection, ConnectorInterface $connector, \DateTimeImmutable $started, array &$summary ): void {
		$now = $this->clock->now();
		foreach ( $this->candidates( $connection->id, $now, $started, $summary ) as $candidate ) {
			if ( $this->elapsed( $started ) >= $this->budget ) {
				$summary['complete'] = false;
				return;
			}
			$ref    = new ExternalRef( $candidate['external_id'], $candidate['external_ref'], $candidate['external_url'] );
			$answer = $this->gateway->call( $connection, $connector, fn() => $connector->fetchInvoice( $connection, $ref ) );
			if ( $answer instanceof ConnectorError ) {
				++$summary['errors'];
				return;
			}
			++$summary['checked'];
			$this->record( $connection->id, $candidate['order_id'], is_array( $answer ) ? $answer : null, $summary );
		}
	}

	/**
	 * Synced orders of the last 30 days whose invoice is not final, the longest unchecked first. Every page of synced orders is read
	 * (newest first) so an old order whose invoice is still open is not left out behind newer ones; reading stops early only when
	 * half of the call's time is gone, and then the summary says the call was not complete.
	 *
	 * @param int                 $connectionId Connection.
	 * @param \DateTimeImmutable  $now          Current moment.
	 * @param \DateTimeImmutable  $started      Start of the call.
	 * @param array<string,mixed> $summary      Summary (updated).
	 * @return array<int,array{order_id:int,external_id:string,external_ref:string,external_url:?string}>
	 */
	private function candidates( int $connectionId, \DateTimeImmutable $now, \DateTimeImmutable $started, array &$summary ): array {
		$found = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			if ( $this->elapsed( $started ) >= $this->budget / 2 ) {
				$summary['complete'] = false;
				break;
			}
			$result = $this->queue->query( new QueueQuery( 'synced', EntityType::Order->value, '30d', null, null, $connectionId, 'live', $page, $this->pageSize ) );
			foreach ( $result->items as $item ) {
				$orderId  = (int) ( $item['entity_id'] ?? 0 );
				$external = (string) ( $item['external_id'] ?? '' );
				if ( $orderId <= 0 || '' === $external || isset( $found[ $orderId ] ) ) {
					continue;
				}
				$extra = $this->state->get( $connectionId, EntityType::Order, (string) $orderId )['extra'] ?? array();
				if ( ! empty( $extra['invoice_final'] ) ) {
					continue;
				}
				$checked = isset( $extra['invoice_checked_at'] ) ? (int) strtotime( (string) $extra['invoice_checked_at'] ) : 0;
				if ( $checked > 0 && ( $now->getTimestamp() - $checked ) < self::RECHECK_SECONDS ) {
					continue;
				}
				$link              = $this->idMap->get( $connectionId, EntityType::Order, (string) $orderId );
				$found[ $orderId ] = array(
					'order_id'     => $orderId,
					'external_id'  => null !== $link ? $link['external_id'] : $external,
					'external_ref' => null !== $link ? (string) $link['external_ref'] : (string) ( $item['external_ref'] ?? '' ),
					'external_url' => null !== $link ? $link['external_url'] : ( $item['external_url'] ?? null ),
					'checked'      => $checked,
				);
			}
			if ( $page >= $result->totalPages() ) {
				break;
			}
			if ( self::MAX_PAGES === $page ) {
				$summary['complete'] = false;
			}
		}
		usort( $found, static fn( array $a, array $b ): int => $a['checked'] <=> $b['checked'] );
		return array_map( static fn( array $row ): array => array_diff_key( $row, array( 'checked' => 1 ) ), $found );
	}

	/**
	 * Keep what the system said about an invoice.
	 *
	 * @param int                      $connectionId Connection.
	 * @param int                      $orderId      Order id.
	 * @param array<string,mixed>|null $answer       Connector answer; null when the system has no invoice for the order yet.
	 * @param array<string,mixed>      $summary      Summary (updated).
	 */
	private function record( int $connectionId, int $orderId, ?array $answer, array &$summary ): void {
		$nowIso = (string) Time::iso( Time::sql( $this->clock->now() ) );
		if ( null === $answer ) {
			$this->state->mergeExtra( $connectionId, EntityType::Order, (string) $orderId, array( 'invoice_checked_at' => $nowIso ) );
			return;
		}
		$number  = trim( (string) ( $answer['number'] ?? '' ) );
		$status  = InvoiceStatus::from( (string) ( $answer['authority_status'] ?? '' ), $number );
		$facts   = array(
			'number'           => $number,
			'status'           => $status,
			'authority_status' => mb_substr( trim( (string) ( $answer['authority_status'] ?? '' ) ), 0, 190 ),
			'invoice_state'    => mb_substr( trim( (string) ( $answer['status'] ?? '' ) ), 0, 40 ),
			'issued_at'        => $this->isoOrEmpty( (string) ( $answer['issued_at'] ?? '' ) ),
			'pdf_url'          => $this->webUrl( (string) ( $answer['pdf_url'] ?? '' ) ),
			'qr'               => mb_substr( trim( (string) ( $answer['qr'] ?? '' ) ), 0, 1000 ),
			'reject_reason'    => mb_substr( $this->redactor->message( trim( (string) ( $answer['reject_reason'] ?? '' ) ) ), 0, 300 ),
		);
		$before  = $this->orders->invoice( $orderId );
		$changed = null === $before || $this->significant( $before ) !== $this->significant( $facts );
		if ( $changed ) {
			$this->orders->saveInvoice( $orderId, $facts + array( 'checked_at' => $nowIso ) );
			++$summary['updated'];
			$object = $this->orders->orderObject( $orderId );
			if ( null !== $object ) {
				/**
				 * Fires after the invoice facts of an order changed.
				 *
				 * @param object              $order   The WooCommerce order.
				 * @param array<string,mixed> $invoice number, status, authority_status, issued_at, pdf_url, qr, reject_reason.
				 */
				do_action( 'estasoft_sync_invoice_updated', $object, $facts );
			}
		}
		$stored = $this->state->get( $connectionId, EntityType::Order, (string) $orderId )['extra'] ?? array();
		$extra  = array(
			'invoice_number'     => $number,
			'invoice_status'     => $status,
			'invoice_final'      => InvoiceStatus::isFinal( $status, $number ),
			'invoice_checked_at' => $nowIso,
			'invoice_queue_id'   => $this->settleRejection( $connectionId, $orderId, $facts, $stored['invoice_queue_id'] ?? null, $changed, $summary ),
		);
		$this->state->mergeExtra( $connectionId, EntityType::Order, (string) $orderId, $extra );
	}

	/**
	 * Create or update the failed item of a rejection (SYNC-COMP-001), or resolve it when the invoice was reported.
	 *
	 * @param int                 $connectionId Connection.
	 * @param int                 $orderId      Order id.
	 * @param array<string,mixed> $facts        Invoice facts.
	 * @param mixed               $queueId      Id of the item made earlier, if any.
	 * @param bool                $changed      Whether the status, the reason or any other fact differs from what was saved before.
	 * @param array<string,mixed> $summary      Summary (updated).
	 * @return int|null The item id to remember.
	 */
	private function settleRejection( int $connectionId, int $orderId, array $facts, mixed $queueId, bool $changed, array &$summary ): ?int {
		$queueId = is_numeric( $queueId ) ? (int) $queueId : null;
		if ( InvoiceStatus::REJECTED !== $facts['status'] ) {
			if ( null !== $queueId && InvoiceStatus::WAITING !== $facts['status'] ) {
				$this->queue->markSynced( $queueId, $facts['number'], $facts['number'], null, null, null, false );
			}
			return $queueId;
		}
		if ( null !== $queueId ) {
			$existing = $this->queue->find( $queueId );
			if ( null === $existing ) {
				$queueId = null;
			} elseif ( ! $changed && ItemState::Failed === $existing->state ) {
				// The same rejection, already shown under Needs attention: say nothing more, log nothing more.
				return $queueId;
			}
		}
		$order  = $this->orders->find( $orderId ) ?? array();
		$reason = '' !== $facts['reject_reason'] ? $facts['reject_reason'] : __( 'no reason was given', 'estasoft-erp-crm-sync-engine' );
		if ( null === $queueId ) {
			$queueId = $this->enqueuer->enqueue(
				EntityType::Invoice,
				(string) $orderId,
				'rejected',
				$connectionId,
				array(
					'partition'   => 'invoice:' . $orderId,
					'label'       => (string) ( $order['label'] ?? $orderId ),
					'customer'    => (string) ( $order['customer'] ?? '' ),
					'total_minor' => (int) ( $order['total_minor'] ?? 0 ),
					'currency'    => (string) ( $order['currency'] ?? '' ),
					'search'      => (string) ( $order['email'] ?? '' ),
				)
			);
		}
		if ( null === $queueId ) {
			return null;
		}
		$message = sprintf(
			/* translators: 1: invoice number, 2: reason given by the tax authority. */
			__( 'The tax authority rejected invoice %1$s: %2$s.', 'estasoft-erp-crm-sync-engine' ),
			'' !== $facts['number'] ? $facts['number'] : '#' . $orderId,
			$reason
		);
		$this->queue->markFailed( $queueId, ErrorCode::Comp001->value, mb_substr( $message, 0, 480 ), '' !== $facts['number'] ? 'Invoice ' . $facts['number'] : '', false );
		$this->logger->error(
			$message,
			array(
				'connection_id' => $connectionId,
				'queue_id'      => $queueId,
				'entity_ref'    => (string) ( $order['label'] ?? $orderId ),
				'code'          => ErrorCode::Comp001,
			)
		);
		++$summary['rejected'];
		return $queueId;
	}

	/**
	 * One row of the Invoices list.
	 *
	 * @param array<string,mixed> $row    Order summary with `invoice`.
	 * @param \DateTimeImmutable  $now    Current moment.
	 * @param int                 $window Hours.
	 * @return array<string,mixed> An `InvoiceRow`.
	 */
	private function row( array $row, \DateTimeImmutable $now, int $window ): array {
		$invoice = is_array( $row['invoice'] ?? null ) ? $row['invoice'] : array();
		$number  = (string) ( $invoice['number'] ?? '' );
		$status  = (string) ( $invoice['status'] ?? '' );
		$status  = in_array( $status, InvoiceStatus::ALL, true ) ? $status : InvoiceStatus::from( (string) ( $invoice['authority_status'] ?? '' ), $number );
		$issued  = (string) ( $invoice['issued_at'] ?? '' );
		$issuedT = '' === $issued ? false : strtotime( $issued );
		$open    = in_array( $status, array( InvoiceStatus::WAITING, InvoiceStatus::REJECTED ), true ) && false !== $issuedT;
		$dueT    = $open ? (int) $issuedT + ( $window * 3600 ) : null;
		return array(
			'order_id'         => (int) $row['id'],
			'order_label'      => (string) $row['label'],
			'number'           => '' === $number ? null : $number,
			'status'           => $status,
			'authority_status' => '' === (string) ( $invoice['authority_status'] ?? '' ) ? null : (string) $invoice['authority_status'],
			'issued_at'        => '' === $issued ? null : $issued,
			'due_at'           => null === $dueT ? null : gmdate( Time::ISO, $dueT ),
			'seconds_left'     => null === $dueT ? null : $dueT - $now->getTimestamp(),
			'pdf_url'          => '' === (string) ( $invoice['pdf_url'] ?? '' ) ? null : (string) $invoice['pdf_url'],
			'reject_reason'    => '' === (string) ( $invoice['reject_reason'] ?? '' ) ? null : (string) $invoice['reject_reason'],
			'total'            => array(
				'minor'    => (int) $row['total_minor'],
				'currency' => (string) $row['currency'],
			),
		);
	}

	/**
	 * The facts that make an update worth saving (not the time checked).
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<string,mixed>
	 */
	private function significant( array $facts ): array {
		return array_intersect_key( $facts, array_flip( array( 'number', 'status', 'authority_status', 'invoice_state', 'issued_at', 'pdf_url', 'qr', 'reject_reason' ) ) );
	}

	/**
	 * A time as ISO text, or an empty string when it cannot be read.
	 *
	 * @param string $value Time text from the connector.
	 */
	private function isoOrEmpty( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}
		$parsed = date_create_immutable( $value, new \DateTimeZone( 'UTC' ) );
		return false === $parsed ? '' : $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Time::ISO );
	}

	/**
	 * A link, only when it is a web address.
	 *
	 * @param string $url Address from the connector.
	 */
	private function webUrl( string $url ): string {
		$url = trim( $url );
		return 1 === preg_match( '#^https?://#i', $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) ? mb_substr( $url, 0, 500 ) : '';
	}

	/**
	 * Seconds since a moment.
	 *
	 * @param \DateTimeImmutable $started Start.
	 */
	private function elapsed( \DateTimeImmutable $started ): float {
		return max( 0.0, (float) $this->clock->now()->format( 'U.u' ) - (float) $started->format( 'U.u' ) );
	}
}
