<?php
/**
 * The sync facts kept on a WooCommerce order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the order meta keys listed in Core\MetaKeys::order(), through the order object ($order->update_meta_data() and
 * save_meta_data()), so the same code works with high-performance order storage and with the older posts storage. It never calls
 * $order->save(): saving the whole order would fire WooCommerce's update hooks and look like a change to sync.
 */
final class OrderMeta {

	public const STATE            = '_estasoft_sync_state';
	public const EXTERNAL_ID      = '_estasoft_sync_external_id';
	public const EXTERNAL_REF     = '_estasoft_sync_external_ref';
	public const EXTERNAL_URL     = '_estasoft_sync_external_url';
	public const LAST_SYNC        = '_estasoft_sync_last_sync';
	public const MAPPING_VERSION  = '_estasoft_sync_mapping_version';
	public const INVOICE_NUMBER   = '_estasoft_sync_invoice_number';
	public const INVOICE_STATUS   = '_estasoft_sync_invoice_status';
	public const INVOICE_PDF      = '_estasoft_sync_invoice_pdf';
	public const TRACKING_NUMBER  = '_estasoft_sync_tracking_number';
	public const TRACKING_CARRIER = '_estasoft_sync_tracking_carrier';
	public const SKIP             = '_estasoft_sync_skip';

	/**
	 * Every key this class writes (the same list Core removes on uninstall).
	 */
	public const KEYS = array(
		self::STATE,
		self::EXTERNAL_ID,
		self::EXTERNAL_REF,
		self::EXTERNAL_URL,
		self::LAST_SYNC,
		self::MAPPING_VERSION,
		self::INVOICE_NUMBER,
		self::INVOICE_STATUS,
		self::INVOICE_PDF,
		self::TRACKING_NUMBER,
		self::TRACKING_CARRIER,
		self::SKIP,
	);

	/**
	 * States the state key may hold.
	 */
	public const STATES = array( 'queued', 'syncing', 'synced', 'retrying', 'failed', 'skipped', 'shadow' );

	/**
	 * Read everything the plugin knows about an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function read( \WC_Order $order ): OrderSyncInfo {
		$version = $this->get( $order, self::MAPPING_VERSION );
		$state   = $this->get( $order, self::STATE );
		return new OrderSyncInfo(
			in_array( $state, self::STATES, true ) ? $state : '',
			$this->get( $order, self::EXTERNAL_ID ),
			$this->get( $order, self::EXTERNAL_REF ),
			$this->get( $order, self::EXTERNAL_URL ),
			$this->get( $order, self::LAST_SYNC ),
			'' === $version ? null : (int) $version,
			$this->get( $order, self::INVOICE_NUMBER ),
			$this->get( $order, self::INVOICE_STATUS ),
			$this->get( $order, self::INVOICE_PDF ),
			$this->get( $order, self::TRACKING_NUMBER ),
			$this->get( $order, self::TRACKING_CARRIER ),
			$this->skip( $this->get( $order, self::SKIP ) )
		);
	}

	/**
	 * The sync state of an order, or an empty string.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function state( \WC_Order $order ): string {
		$state = $this->get( $order, self::STATE );
		return in_array( $state, self::STATES, true ) ? $state : '';
	}

	/**
	 * Write some keys. An empty string or null removes a key. Keys that are not the plugin's own are ignored.
	 *
	 * @param \WC_Order           $order  Order.
	 * @param array<string,mixed> $values Meta key => value (a skip array is stored as JSON).
	 */
	public function write( \WC_Order $order, array $values ): void {
		$changed = false;
		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$value = null === $value || false === $value ? '' : (string) $value;
			if ( '' === $value ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $value );
			}
			$changed = true;
		}
		if ( $changed ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Remove every key the plugin wrote on an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function clear( \WC_Order $order ): void {
		$this->write( $order, array_fill_keys( self::KEYS, '' ) );
	}

	/**
	 * One meta value as text.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 */
	private function get( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Decode the skip record.
	 *
	 * @param string $json Stored JSON.
	 * @return array{reason:string,by:string,at:string}|null
	 */
	private function skip( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return array(
			'reason' => isset( $data['reason'] ) && is_scalar( $data['reason'] ) ? (string) $data['reason'] : '',
			'by'     => isset( $data['by'] ) && is_scalar( $data['by'] ) ? (string) $data['by'] : '',
			'at'     => isset( $data['at'] ) && is_scalar( $data['at'] ) ? (string) $data['at'] : '',
		);
	}
}
