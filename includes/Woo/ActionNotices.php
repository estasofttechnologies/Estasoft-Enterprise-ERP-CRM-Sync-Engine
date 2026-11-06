<?php
/**
 * The notice shown after an action on the order screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Actions on the order box and the orders list redirect back with a short result key (and a count for bulk actions). This class turns
 * the key into a message. Only keys from a fixed list are accepted, so nothing from the address is ever printed.
 */
final class ActionNotices {

	/**
	 * Query argument that carries the result key.
	 */
	public const QUERY = 'estasoft_sync_notice';

	/**
	 * Query argument that carries how many orders the action touched.
	 */
	public const COUNT = 'estasoft_sync_count';

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $capability Capability needed to see the notice.
	 */
	public function __construct( private readonly string $capability ) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'show' ) );
	}

	/**
	 * Print the notice, if the address carries a known result key.
	 */
	public function show(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only a key from a fixed list is read, and it only selects a message.
		$key = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a number that only changes the wording.
		$count = isset( $_GET[ self::COUNT ] ) ? absint( wp_unslash( $_GET[ self::COUNT ] ) ) : 0;
		if ( '' === $key || ! current_user_can( $this->capability ) ) {
			return;
		}
		$message = $this->message( $key, $count );
		if ( '' === $message ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $this->level( $key ) ),
			esc_html( $message )
		);
	}

	/**
	 * The message for a result key.
	 *
	 * @param string $key   Result key.
	 * @param int    $count Orders touched.
	 */
	public function message( string $key, int $count = 0 ): string {
		$count = max( 1, $count );
		switch ( $key ) {
			case 'queued':
				return _n( 'The order was added to the queue.', 'The orders were added to the queue.', $count, 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'retried':
				/* translators: %d: number of orders. */
				return sprintf( _n( '%d order was put back in the queue.', '%d orders were put back in the queue.', $count, 'estasoft-enterprise-erpcrm-sync-engine' ), $count );
			case 'skipped':
				/* translators: %d: number of orders. */
				return sprintf( _n( '%d order will not be synced. You can restore it from the queue.', '%d orders will not be synced. You can restore them from the queue.', $count, 'estasoft-enterprise-erpcrm-sync-engine' ), $count );
			case 'restored':
				return _n( 'The order was restored.', 'The orders were restored.', $count, 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'nothing':
				return __( 'There was nothing to do for that order.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'unsaved':
				return __( 'That order has not been saved yet, so it was not added to the queue.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'excluded':
				return __( 'Every product on that order is marked Exclude from syncing, so it was not added to the queue.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'failed':
				return __( 'That could not be done. Open the log to see why.', 'estasoft-enterprise-erpcrm-sync-engine' );
			default:
				return '';
		}
	}

	/**
	 * The notice style for a result key.
	 *
	 * @param string $key Result key.
	 */
	private function level( string $key ): string {
		if ( in_array( $key, array( 'failed', 'error' ), true ) ) {
			return 'error';
		}
		return in_array( $key, array( 'unsaved', 'excluded' ), true ) ? 'warning' : 'success';
	}
}
