<?php
/**
 * Delivery status and tracking numbers in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Tracking;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;
use Estasoft\SyncEngine\Jobs\Support\StatusRank;

/**
 * TRK-1 to TRK-3.
 *
 * Reads the deliveries the system finished since the last run. For each one that belongs to a WooCommerce order (client reference
 * `WC-{id}`): the carrier and number are saved on the order (JSON in `_estasoft_sync_tracking`, plus the latest number and carrier
 * for the order box), an order note is added, and `estasoft_sync_tracking_saved( $order, array $tracking )` fires for tracking
 * plugins; the order moves forward to the status the person chose (default Completed) and never backward; a cash-on-delivery
 * order that was delivered queues one "payment collected" event (`delivered`) to the system. The same delivery read again changes
 * nothing. During the shadow window deliveries are only counted.
 */
final class TrackingService {

	/**
	 * Option: where each connection's reading continues from.
	 */
	public const CURSOR = 'estasoft_sync_tracking_cursor';

	/**
	 * Days to look back on the first run.
	 */
	public const FIRST_LOOKBACK_DAYS = 7;

	/**
	 * Set up with what it depends on.
	 *
	 * @param TrackingSettings $settings   Tracking settings.
	 * @param ConnectorGateway $gateway    The door to connectors.
	 * @param ShopOrders       $orders     WooCommerce orders.
	 * @param StateRepository  $state      Per-order facts.
	 * @param Enqueuer         $enqueuer   Queue entry point (cash-on-delivery event).
	 * @param TrackingLog      $log        Recent tracking.
	 * @param OptionStore      $options    Options (cursor).
	 * @param OnboardingState  $onboarding Shadow window.
	 * @param Logger           $logger     Log.
	 * @param Clock            $clock      Time source.
	 */
	public function __construct(
		private readonly TrackingSettings $settings,
		private readonly ConnectorGateway $gateway,
		private readonly ShopOrders $orders,
		private readonly StateRepository $state,
		private readonly Enqueuer $enqueuer,
		private readonly TrackingLog $log,
		private readonly OptionStore $options,
		private readonly OnboardingState $onboarding,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * The tracking settings of a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array{tracking_enabled:bool,delivered_becomes:string,cod_payment_on_delivery:bool}
	 */
	public function settings( int $connectionId ): array {
		return $this->settings->get( $connectionId );
	}

	/**
	 * Save the tracking settings.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $settings     Fields to change.
	 * @return array{tracking_enabled:bool,delivered_becomes:string,cod_payment_on_delivery:bool}
	 *
	 * @throws ValidationException With one message per bad field.
	 */
	public function saveSettings( int $connectionId, array $settings ): array {
		return $this->settings->save( $connectionId, $settings );
	}

	/**
	 * Read deliveries and apply them.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed> Summary: connections, deliveries, saved, moved, cod_events, unknown_orders, skipped (deliveries with no usable time), errors, shadow.
	 */
	public function run( ?int $connectionId = null ): array {
		$now     = $this->clock->now();
		$shadow  = $this->onboarding->isShadowActive( $now );
		$summary = array(
			'connections'    => 0,
			'deliveries'     => 0,
			'saved'          => 0,
			'moved'          => 0,
			'cod_events'     => 0,
			'unknown_orders' => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'shadow'         => $shadow,
		);
		foreach ( $this->gateway->targets( Capability::TrackingIn, $connectionId ) as $target ) {
			$connection = $target['connection'];
			$settings   = $this->settings->get( $connection->id );
			if ( ! $settings['tracking_enabled'] ) {
				continue;
			}
			++$summary['connections'];
			$since = $this->since( $connection->id, $now );
			$rows  = $this->gateway->call( $connection, $target['connector'], fn() => $target['connector']->fetchTracking( $connection, $since ) );
			if ( $rows instanceof ConnectorError || ! is_array( $rows ) ) {
				++$summary['errors'];
				continue;
			}
			$latest  = null;
			$skipped = 0;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$text   = trim( (string) ( $row['done_at'] ?? '' ) );
				$doneAt = '' === $text ? false : date_create_immutable( $text );
				if ( false === $doneAt ) {
					// A delivery without a time cannot be placed: it is not applied, and it is not allowed to move the cursor
					// (an empty time would otherwise read as "now" and push the cursor past deliveries not yet read).
					++$skipped;
					++$summary['skipped'];
					continue;
				}
				if ( null === $latest || $doneAt > $latest ) {
					$latest = $doneAt;
				}
				$this->deliver( $connection->id, $target['connector']->label(), $settings, $row, $shadow, $summary );
			}
			if ( $skipped > 0 ) {
				$this->logger->warning(
					sprintf(
						/* translators: %d: number of deliveries. */
						_n( '%d delivery from your system has no completion time, so it was not applied. It is read again next time.', '%d deliveries from your system have no completion time, so they were not applied. They are read again next time.', $skipped, 'estasoft-erp-crm-sync-engine' ),
						$skipped
					),
					array( 'connection_id' => $connection->id )
				);
				continue;
			}
			$this->saveCursor( $connection->id, null === $latest ? Time::plus( $now, -3600 ) : $latest );
		}
		return $summary;
	}

	/**
	 * Apply one delivery.
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $system       System name, for notes.
	 * @param array<string,mixed> $settings     Tracking settings.
	 * @param array<string,mixed> $row          Delivery row from the connector.
	 * @param bool                $shadow       Whether to only count.
	 * @param array<string,mixed> $summary      Summary (updated).
	 */
	private function deliver( int $connectionId, string $system, array $settings, array $row, bool $shadow, array &$summary ): void {
		if ( 1 !== preg_match( '/^WC-(\d+)$/', trim( (string) ( $row['order_ref'] ?? '' ) ), $match ) ) {
			return;
		}
		$orderId = (int) $match[1];
		$order   = $this->orders->find( $orderId );
		if ( null === $order ) {
			++$summary['unknown_orders'];
			return;
		}
		++$summary['deliveries'];
		if ( $shadow ) {
			return;
		}
		$carrier = trim( (string) ( $row['carrier'] ?? '' ) );
		$number  = trim( (string) ( $row['tracking'] ?? '' ) );
		$status  = strtolower( trim( (string) ( $row['status'] ?? 'done' ) ) );
		$at      = trim( (string) ( $row['done_at'] ?? '' ) );

		if ( '' !== $number && $this->saveNumber( $connectionId, $system, $order, $carrier, $number, $at ) ) {
			++$summary['saved'];
		}
		if ( ! in_array( $status, array( 'done', 'delivered' ), true ) ) {
			return;
		}
		$target = (string) $settings['delivered_becomes'];
		if ( TrackingSettings::NONE !== $target && StatusRank::isForward( (string) $order['status'], $target ) ) {
			$note = sprintf(
				/* translators: %s: name of the connected system, for example Odoo. */
				__( 'Delivery completed in %s. The order was moved forward.', 'estasoft-erp-crm-sync-engine' ),
				$system
			);
			if ( $this->orders->updateStatus( $orderId, $target, $note ) ) {
				++$summary['moved'];
			}
		}
		if ( ! empty( $settings['cod_payment_on_delivery'] ) && 'cod' === $order['payment_method'] ) {
			$queued = $this->enqueuer->enqueue(
				EntityType::Order,
				(string) $orderId,
				'delivered',
				$connectionId,
				array(
					'label'       => (string) $order['label'],
					'customer'    => (string) $order['customer'],
					'total_minor' => (int) $order['total_minor'],
					'currency'    => (string) $order['currency'],
					'search'      => (string) $order['email'],
				)
			);
			if ( null !== $queued && $this->markCodQueued( $connectionId, $orderId ) ) {
				++$summary['cod_events'];
			}
		}
	}

	/**
	 * Save a tracking number on an order when it is new.
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $system       System name.
	 * @param array<string,mixed> $order        Order summary.
	 * @param string              $carrier      Carrier.
	 * @param string              $number       Tracking number.
	 * @param string              $at           ISO time of the delivery.
	 * @return bool Whether it was new.
	 */
	private function saveNumber( int $connectionId, string $system, array $order, string $carrier, string $number, string $at ): bool {
		$orderId = (int) $order['id'];
		$saved   = $this->orders->tracking( $orderId );
		foreach ( $saved as $entry ) {
			if ( 0 === strcasecmp( $entry['number'], $number ) && 0 === strcasecmp( $entry['carrier'], $carrier ) ) {
				return false;
			}
		}
		$entry   = array(
			'carrier' => $carrier,
			'number'  => $number,
			'at'      => $at,
		);
		$saved[] = $entry;
		$this->orders->saveTracking( $orderId, $saved );
		$this->orders->addNote(
			$orderId,
			'' !== $carrier
				/* translators: 1: name of the connected system, 2: carrier, 3: tracking number. */
				? sprintf( __( 'Tracking from %1$s: %2$s, %3$s.', 'estasoft-erp-crm-sync-engine' ), $system, $carrier, $number )
				/* translators: 1: name of the connected system, 2: tracking number. */
				: sprintf( __( 'Tracking from %1$s: %2$s.', 'estasoft-erp-crm-sync-engine' ), $system, $number )
		);
		$this->state->mergeExtra( $connectionId, EntityType::Order, (string) $orderId, array( 'tracking' => $saved ) );
		$this->log->add( $connectionId, $orderId, (string) $order['label'], $carrier, $number, $at );
		$object = $this->orders->orderObject( $orderId );
		if ( null !== $object ) {
			/**
			 * Fires after a tracking number was saved on an order, for tracking plugins.
			 *
			 * @param object              $order    The WooCommerce order.
			 * @param array<string,string> $tracking carrier, number and at (ISO time).
			 */
			do_action( 'estasoft_sync_tracking_saved', $object, $entry );
		}
		return true;
	}

	/**
	 * Remember that the payment-collected event was queued, so it is counted once.
	 *
	 * @param int $connectionId Connection.
	 * @param int $orderId      Order id.
	 * @return bool True the first time.
	 */
	private function markCodQueued( int $connectionId, int $orderId ): bool {
		$known = $this->state->get( $connectionId, EntityType::Order, (string) $orderId );
		if ( ! empty( $known['extra']['cod_queued'] ) ) {
			return false;
		}
		$this->state->mergeExtra( $connectionId, EntityType::Order, (string) $orderId, array( 'cod_queued' => true ) );
		return true;
	}

	/**
	 * Where reading continues from.
	 *
	 * @param int                $connectionId Connection.
	 * @param \DateTimeImmutable $now          Current moment.
	 */
	private function since( int $connectionId, \DateTimeImmutable $now ): \DateTimeImmutable {
		$cursors = $this->options->get( self::CURSOR, array() );
		$stored  = is_array( $cursors ) && isset( $cursors[ (string) $connectionId ] ) ? date_create_immutable( (string) $cursors[ (string) $connectionId ] ) : false;
		return false === $stored ? Time::plus( $now, -self::FIRST_LOOKBACK_DAYS * 86400 ) : $stored;
	}

	/**
	 * Remember where reading continues from.
	 *
	 * @param int                $connectionId Connection.
	 * @param \DateTimeImmutable $at           Moment.
	 */
	private function saveCursor( int $connectionId, \DateTimeImmutable $at ): void {
		$cursors                           = $this->options->get( self::CURSOR, array() );
		$cursors                           = is_array( $cursors ) ? $cursors : array();
		$cursors[ (string) $connectionId ] = $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Time::ISO );
		$this->options->set( self::CURSOR, $cursors );
	}
}
