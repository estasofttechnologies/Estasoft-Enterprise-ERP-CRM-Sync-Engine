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

/**
 * Three settings, kept with the connection: whether delivery records are read, the order status a delivery moves an order to,
 * and whether a cash-on-delivery order queues a "payment collected" event. The stock rules screen shows and saves them too, so
 * this class is shared by the tracking and stock services.
 */
final class TrackingSettings {

	/**
	 * Area name inside the connection's job settings.
	 */
	public const AREA = 'tracking';

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
			$status = is_string( $input['delivered_becomes'] ) ? (string) preg_replace( '/^wc-/', '', strtolower( trim( $input['delivered_becomes'] ) ) ) : '';
			if ( '' === $status || ! array_key_exists( $status, $this->orders->statuses() ) ) {
				$errors['delivered_becomes'] = __( 'Choose one of the order statuses of your shop.', 'estasoft-erp-crm-sync-engine' );
			} else {
				$values['delivered_becomes'] = $status;
			}
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
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
			throw new ValidationException( $checked['errors'] );
		}
		$this->config->save( $connectionId, self::AREA, array_replace( $this->get( $connectionId ), $checked['values'] ) );
		return $this->get( $connectionId );
	}
}
