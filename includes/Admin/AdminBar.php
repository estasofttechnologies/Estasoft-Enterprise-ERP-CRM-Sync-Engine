<?php
/**
 * The ESTASOFT SYNC item in the WordPress admin bar (UI-3).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Settings;

/**
 * "ESTASOFT SYNC: 14 waiting, 5 failed" with a coloured dot and a word, linking to the queue. The dot is green when all is well, amber
 * when orders wait, red when an order failed or syncing is paused, and grey when no system is connected; the word is in the text
 * and for screen readers, so colour is never the only signal. The item shows on admin screens to people who can view the queue,
 * unless the setting "Show ESTASOFT SYNC in the admin bar" is off. It costs one cached count (see Snapshot).
 */
final class AdminBar {

	/**
	 * Node id.
	 */
	public const NODE_ID = 'estasoft-sync';

	/**
	 * Create the admin bar item.
	 *
	 * @param Snapshot $snapshot Queue and connection picture.
	 * @param Settings $settings Settings (the on and off switch).
	 * @param Urls     $urls     Addresses of the app.
	 */
	public function __construct(
		private readonly Snapshot $snapshot,
		private readonly Settings $settings,
		private readonly Urls $urls
	) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'addNode' ), 100 );
	}

	/**
	 * Whether the item shows to the current person on the current screen.
	 */
	public function shows(): bool {
		return is_admin() && Capabilities::userCanManage() && $this->settings->adminBar();
	}

	/**
	 * Add the item. Runs on `admin_bar_menu`.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar.
	 */
	public function addNode( \WP_Admin_Bar $bar ): void {
		if ( ! $this->shows() ) {
			return;
		}
		$view = $this->describe( $this->snapshot->current() );
		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => '<span class="estasoft-sync-dot estasoft-sync-dot--' . esc_attr( $view['state'] ) . '" aria-hidden="true"></span>'
					. '<span class="ab-label">' . esc_html( $view['label'] ) . '</span>'
					. '<span class="screen-reader-text"> ' . esc_html( $view['word'] ) . '</span>',
				'href'  => $this->urls->route( 'failed' === $view['state'] ? '/queue?state=failed' : '/queue' ),
				'meta'  => array(
					'class' => 'estasoft-sync-bar',
					'title' => $view['word'],
				),
			)
		);
	}

	/**
	 * The words for a picture of the queue.
	 *
	 * @param array{state:string,waiting:int,failed:int} $picture From Snapshot::current().
	 * @return array{state:string,label:string,word:string}
	 */
	public function describe( array $picture ): array {
		$name    = __( 'ESTASOFT SYNC', 'estasoft-erp-crm-sync-engine' );
		$waiting = (int) $picture['waiting'];
		$failed  = (int) $picture['failed'];
		/* translators: %s: number of orders waiting to be sent. */
		$waitingText = sprintf( __( '%s waiting', 'estasoft-erp-crm-sync-engine' ), number_format_i18n( $waiting ) );
		/* translators: %s: number of orders that failed. */
		$failedText = sprintf( __( '%s failed', 'estasoft-erp-crm-sync-engine' ), number_format_i18n( $failed ) );

		switch ( $picture['state'] ) {
			case 'not_connected':
				return array(
					'state' => 'idle',
					'label' => $name . ': ' . __( 'not connected', 'estasoft-erp-crm-sync-engine' ),
					'word'  => __( 'Not connected.', 'estasoft-erp-crm-sync-engine' ),
				);
			case 'paused':
				return array(
					'state' => 'paused',
					'label' => $name . ': ' . ( $waiting > 0 ? __( 'paused', 'estasoft-erp-crm-sync-engine' ) . ', ' . $waitingText : __( 'paused', 'estasoft-erp-crm-sync-engine' ) ),
					'word'  => __( 'Syncing is paused.', 'estasoft-erp-crm-sync-engine' ),
				);
			case 'attention':
				return array(
					'state' => 'failed',
					'label' => $name . ': ' . $waitingText . ', ' . $failedText,
					'word'  => __( 'Some orders need a person.', 'estasoft-erp-crm-sync-engine' ),
				);
			case 'waiting':
				return array(
					'state' => 'waiting',
					'label' => $name . ': ' . $waitingText,
					'word'  => __( 'Orders are waiting to be sent.', 'estasoft-erp-crm-sync-engine' ),
				);
		}
		return array(
			'state' => 'ok',
			'label' => $name . ': ' . $waitingText,
			'word'  => __( 'All is well.', 'estasoft-erp-crm-sync-engine' ),
		);
	}
}
