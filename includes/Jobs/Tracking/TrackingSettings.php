<?php
/**
 * The tracking settings of a connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Tracking;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Support\JobConfig;
use Estasoft\SyncEngine\Jobs\Support\StatusRank;

/**
 * Three settings, kept with the connection: whether delivery records are read, the order status a delivery moves an order to,
 * and whether a cash-on-delivery order queues a "payment collected" event. The stock rules screen shows and saves them too, so
 * this class is shared by the tracking and stock services.
 *
 * `delivered_becomes` is `none` (the order status is left alone; tracking numbers and notes are still saved) or a status of the
 * shop that `StatusRank` can move an order up to: pending, on-hold, processing or completed. Cancelled, refunded, failed, draft and
 * custom statuses are refused, because the plugin never moves an order into one.
 */
final class TrackingSettings {

	/**
	 * Area name inside the connection's job settings.
	 */
	public const AREA = 'tracking';

	/**
	 * The value that leaves the order status alone.
	 */
	public const NONE = 'none';

	/**
	 * Defaults: nothing happens until the person turns it on.
	 */
	public const DEFAULTS = array(
		'tracking_enabled'        => false,
		'delivered_becomes'       => 'completed',
		'cod_payment_on_delivery' => false,
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param JobConfig  $config Per-connection settings.
	 * @param ShopOrders $orders Order statuses.
	 */
	public function __construct( private readonly JobConfig $config, private readonly ShopOrders $orders ) {
	}

	/**
	 * The settings.
	 *
	 * @param int $connectionId Connection.
	 * @return array{tracking_enabled:bool,delivered_becomes:string,cod_payment_on_delivery:bool}
	 */
	public function get( int $connectionId ): array {
		$values = $this->config->get( $connectionId, self::AREA, self::DEFAULTS );
		return array(
			'tracking_enabled'        => (bool) $values['tracking_enabled'],
			'delivered_becomes'       => (string) $values['delivered_becomes'],
			'cod_payment_on_delivery' => (bool) $values['cod_payment_on_delivery'],
		);
	}

	/**
	 * Check supplied fields without saving.
	 *
	 * @param array<string,mixed> $input Fields (any of the three).
	 * @return array{values:array<string,mixed>,errors:array<string,string>} The cleaned values of the fields supplied, and one message per bad field.
	 */
	public function check( array $input ): array {
		$values = array();
		$errors = array();
		foreach ( array( 'tracking_enabled', 'cod_payment_on_delivery' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$bool = is_bool( $input[ $key ] ) ? $input[ $key ] : filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null === $bool ) {
				$errors[ $key ] = __( 'Choose on or off.', 'estasoft-erp-crm-sync-engine' );
			} else {
				$values[ $key ] = $bool;
			}
		}
		if ( array_key_exists( 'delivered_becomes', $input ) ) {
			$status  = is_string( $input['delivered_becomes'] ) ? (string) preg_replace( '/^wc-/', '', strtolower( trim( $input['delivered_becomes'] ) ) ) : '';
			$allowed = $this->allowedStatuses();
			if ( self::NONE === $status || ( '' !== $status && in_array( $status, $allowed, true ) ) ) {
				$values['delivered_becomes'] = $status;
			} else {
				$errors['delivered_becomes'] = sprintf(
					/* translators: %s: list of order statuses, for example processing, completed. */
					__( 'Choose none (leave the order status alone) or a status an order can be moved up to: %s.', 'estasoft-erp-crm-sync-engine' ),
					implode( ', ', $allowed )
				);
			}
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * The statuses of the shop an order can be moved up to.
	 *
	 * @return array<int,string> Slugs without the wc- prefix.
	 */
	private function allowedStatuses(): array {
		$shop = array_keys( $this->orders->statuses() );
		return array_values( array_filter( StatusRank::targets(), static fn( string $status ): bool => in_array( $status, $shop, true ) ) );
	}

	/**
	 * Validate and save.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $input        Fields to change.
	 * @return array{tracking_enabled:bool,delivered_becomes:string,cod_payment_on_delivery:bool} The settings after saving.
	 *
	 * @throws ValidationException With one message per bad field.
	 */
	public function save( int $connectionId, array $input ): array {
		$checked = $this->check( $input );
		if ( array() !== $checked['errors'] ) {
			throw new ValidationException( array_map( 'esc_html', $checked['errors'] ) );
		}
		$this->config->save( $connectionId, self::AREA, array_replace( $this->get( $connectionId ), $checked['values'] ) );
		return $this->get( $connectionId );
	}
}
