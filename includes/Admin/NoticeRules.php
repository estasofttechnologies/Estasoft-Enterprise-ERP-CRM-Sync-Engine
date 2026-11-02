<?php
/**
 * When each admin notice shows, and what it says.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules over a facts array (see NoticeFacts): no WordPress calls except translation, so every rule is unit tested.
 *
 * Each notice says what is wrong and offers one action. Where it shows:
 * - `everywhere`: on every admin screen, printed by PHP (welcome, encryption key missing, a connection that needs the person to reconnect);
 * - `plugin`: only inside the app, handed over in the boot data (failed orders, queue backlog, shadow mode ends).
 * Only the welcome notice can be dismissed for good.
 */
final class NoticeRules {

	/**
	 * Every notice that applies to the facts, for the person the facts describe.
	 *
	 * @param array<string,mixed> $facts Facts from NoticeFacts::gather().
	 * @return array<int,array{id:string,kind:string,text:string,action_label:string,action_route:string,dismissible:bool,scope:string,key_line?:string}>
	 */
	public function build( array $facts ): array {
		$notices   = array();
		$configure = ! empty( $facts['can_configure'] );
		$manage    = ! empty( $facts['can_manage'] );
		$dismissed = is_array( $facts['dismissed'] ?? null ) ? $facts['dismissed'] : array();

		if ( $configure && empty( $facts['connected'] ) && empty( $facts['setup_done'] ) && ! in_array( 'welcome', $dismissed, true ) ) {
			$notices[] = $this->notice( 'welcome', 'info', __( 'ESTASOFT SYNC is active. Set it up in about ten minutes. Nothing is sent to your system until you say so.', 'estasoft-erp-crm-sync-engine' ), __( 'Start setup', 'estasoft-erp-crm-sync-engine' ), '/setup', 'everywhere', true );
		}
		if ( $configure && ! empty( $facts['key_missing'] ) ) {
			$notice = $this->notice( 'encryption_key', 'error', __( 'The encryption key is missing from wp-config.php. Saved credentials cannot be stored safely until you add it.', 'estasoft-erp-crm-sync-engine' ), __( 'Show me how', 'estasoft-erp-crm-sync-engine' ), '/connections', 'everywhere', false );
			if ( is_string( $facts['key_line'] ?? null ) && '' !== $facts['key_line'] ) {
				$notice['key_line'] = $facts['key_line'];
			}
			$notices[] = $notice;
		}
		if ( $manage ) {
			foreach ( is_array( $facts['reconnect'] ?? null ) ? $facts['reconnect'] : array() as $connection ) {
				$notices[] = $this->notice(
					'reconnect_' . (int) $connection['id'],
					'error',
					/* translators: %s: name of the connection, for example Odoo. */
					sprintf( __( 'Syncing to %s is paused because its sign-in no longer works. Orders are waiting safely.', 'estasoft-erp-crm-sync-engine' ), (string) $connection['label'] ),
					/* translators: %s: name of the connection, for example Odoo. */
					sprintf( __( 'Reconnect %s', 'estasoft-erp-crm-sync-engine' ), (string) $connection['label'] ),
					'/connections/' . (int) $connection['id'],
					'everywhere',
					false
				);
			}
			$failed = (int) ( $facts['failed'] ?? 0 );
			if ( $failed > 0 ) {
				$notices[] = $this->notice(
					'failed_orders',
					'warning',
					/* translators: %s: number of failed orders. */
					sprintf( _n( '%s order failed to sync. It needs a person.', '%s orders failed to sync. They need a person.', $failed, 'estasoft-erp-crm-sync-engine' ), number_format_i18n( $failed ) ),
					__( 'Review failed orders', 'estasoft-erp-crm-sync-engine' ),
					'/queue?state=failed',
					'plugin',
					false
				);
			}
			$waiting   = (int) ( $facts['waiting'] ?? 0 );
			$threshold = (int) ( $facts['backlog_threshold'] ?? 0 );
			if ( $threshold > 0 && $waiting > $threshold ) {
				$notices[] = $this->notice(
					'backlog',
					'warning',
					/* translators: 1: number of waiting items, 2: the queue warning limit. */
					sprintf( __( '%1$s items are waiting. That is more than the limit of %2$s. The queue is moving, but slower than orders arrive.', 'estasoft-erp-crm-sync-engine' ), number_format_i18n( $waiting ), number_format_i18n( $threshold ) ),
					__( 'Open the queue', 'estasoft-erp-crm-sync-engine' ),
					'/queue?state=waiting',
					'plugin',
					false
				);
			}
		}
		if ( $configure && ! empty( $facts['shadow_ends_soon'] ) ) {
			$notices[] = $this->notice( 'shadow_ends', 'info', __( 'Shadow mode ends tomorrow. Review the results, then go live.', 'estasoft-erp-crm-sync-engine' ), __( 'See the results', 'estasoft-erp-crm-sync-engine' ), '/shadow', 'plugin', false );
		}
		return $notices;
	}

	/**
	 * The notices the app shows (`Notice[]` of src/api/types.ts): every notice except the welcome one, because the setup wizard is the welcome.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,array{id:string,kind:string,text:string,action_label:string,action_route:string,dismissible:bool}>
	 */
	public function forApp( array $facts ): array {
		$list = array();
		foreach ( $this->build( $facts ) as $notice ) {
			if ( 'welcome' === $notice['id'] ) {
				continue;
			}
			$list[] = array(
				'id'           => $notice['id'],
				'kind'         => $notice['kind'],
				'text'         => $notice['text'],
				'action_label' => $notice['action_label'],
				'action_route' => $notice['action_route'],
				'dismissible'  => $notice['dismissible'],
			);
		}
		return $list;
	}

	/**
	 * The notices PHP prints on other admin screens.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,array{id:string,kind:string,text:string,action_label:string,action_route:string,dismissible:bool,scope:string,key_line?:string}>
	 */
	public function forScreens( array $facts ): array {
		return array_values( array_filter( $this->build( $facts ), static fn( array $notice ): bool => 'everywhere' === $notice['scope'] ) );
	}

	/**
	 * One notice.
	 *
	 * @param string $id          Stable id.
	 * @param string $kind        info, warning, error or success.
	 * @param string $text        What is wrong, in plain words.
	 * @param string $label       Action label.
	 * @param string $route       App route the action opens.
	 * @param string $scope       everywhere or plugin.
	 * @param bool   $dismissible Whether it can be dismissed for good.
	 * @return array{id:string,kind:string,text:string,action_label:string,action_route:string,dismissible:bool,scope:string}
	 */
	private function notice( string $id, string $kind, string $text, string $label, string $route, string $scope, bool $dismissible ): array {
		return array(
			'id'           => $id,
			'kind'         => $kind,
			'text'         => $text,
			'action_label' => $label,
			'action_route' => $route,
			'dismissible'  => $dismissible,
			'scope'        => $scope,
		);
	}
}
