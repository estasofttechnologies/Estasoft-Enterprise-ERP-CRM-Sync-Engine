<?php
/**
 * The ESTASOFT SYNC box on the WordPress dashboard (UI-4, board A05).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;

/**
 * Five states, each with its own headline and one button: needs attention, all clear, paused, shadow mode, not connected. The numbers
 * come from the dashboard service's small `widget()` array, which has the keys of the app's `Status` that the box needs: `mode`
 * (not_connected, first_run, normal, paused or shadow), `synced_today`, `waiting`, `needs_attention`, `connection` (label, status,
 * last_success_at, paused_until) and `shadow` (day, of_days, items, matches, differs). Missing keys count as zero or empty.
 */
final class DashboardWidget {

	/**
	 * Widget id.
	 */
	public const ID = 'estasoft_sync_widget';

	/**
	 * Create the dashboard widget.
	 *
	 * @param \Closure $data       Returns the small counts (DashboardService::widget()).
	 * @param Urls     $urls       Addresses of the app.
	 * @param \Closure $formatTime Turns an ISO time into the store's time of day.
	 *
	 * @phpstan-param \Closure():array<string,mixed> $data
	 * @phpstan-param \Closure(string):string        $formatTime
	 */
	public function __construct(
		private readonly \Closure $data,
		private readonly Urls $urls,
		private readonly \Closure $formatTime
	) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Add the box for people who can view the queue. Runs on `wp_dashboard_setup`.
	 */
	public function add(): void {
		if ( ! Capabilities::userCanManage() ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'ESTASOFT SYNC', 'estasoft-enterprise-erpcrm-sync-engine' ), array( $this, 'render' ) );
	}

	/**
	 * Print the box.
	 */
	public function render(): void {
		$view = $this->describe( ( $this->data )() );
		echo '<div class="estasoft-sync-widget estasoft-sync-widget--' . esc_attr( $view['state'] ) . '">';
		if ( array() !== $view['counts'] ) {
			echo '<ul class="estasoft-sync-widget__counts">';
			foreach ( $view['counts'] as $count ) {
				echo '<li><span class="estasoft-sync-widget__number">' . esc_html( number_format_i18n( $count['value'] ) ) . '</span> <span class="estasoft-sync-widget__label">' . esc_html( $count['label'] ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p class="estasoft-sync-widget__headline"><strong>' . esc_html( $view['headline'] ) . '</strong></p>';
		if ( '' !== $view['detail'] ) {
			echo '<p class="estasoft-sync-widget__detail">' . esc_html( $view['detail'] ) . '</p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $this->urls->route( $view['route'] ) ) . '">' . esc_html( $view['action'] ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * What the box says for the numbers.
	 *
	 * @param array<string,mixed> $data The small counts.
	 * @return array{state:string,counts:array<int,array{label:string,value:int}>,headline:string,detail:string,action:string,route:string}
	 */
	public function describe( array $data ): array {
		$mode       = (string) ( $data['mode'] ?? 'not_connected' );
		$failed     = (int) ( $data['needs_attention'] ?? $data['failed'] ?? 0 );
		$waiting    = (int) ( $data['waiting'] ?? 0 );
		$synced     = (int) ( $data['synced_today'] ?? 0 );
		$connection = is_array( $data['connection'] ?? null ) ? $data['connection'] : array();
		$shadow     = is_array( $data['shadow'] ?? null ) ? $data['shadow'] : array();
		$system     = '' !== (string) ( $connection['label'] ?? '' ) ? (string) $connection['label'] : __( 'The system', 'estasoft-enterprise-erpcrm-sync-engine' );

		if ( in_array( $mode, array( 'not_connected', 'first_run' ), true ) ) {
			return array(
				'state'    => 'not-connected',
				'counts'   => array(),
				'headline' => __( 'Not connected', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'detail'   => __( 'Connect a system to start syncing. Setup takes about ten minutes.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'action'   => __( 'Start setup', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'route'    => '/setup',
			);
		}
		if ( 'shadow' === $mode ) {
			return array(
				'state'    => 'shadow',
				'counts'   => array(
					array(
						'label' => __( 'Compared', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'value' => (int) ( $shadow['items'] ?? 0 ),
					),
					array(
						'label' => __( 'Match', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'value' => (int) ( $shadow['matches'] ?? 0 ),
					),
					array(
						'label' => __( 'Differ', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'value' => (int) ( $shadow['differs'] ?? 0 ),
					),
				),
				/* translators: 1: current day of shadow mode, 2: number of days. */
				'headline' => sprintf( __( 'Shadow mode, day %1$d of %2$d', 'estasoft-enterprise-erpcrm-sync-engine' ), (int) ( $shadow['day'] ?? 1 ), (int) ( $shadow['of_days'] ?? 7 ) ),
				/* translators: %s: name of the connected system. */
				'detail'   => sprintf( __( 'Nothing is sent to %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ),
				'action'   => __( 'See the comparison', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'route'    => '/shadow',
			);
		}

		$counts = array(
			array(
				'label' => __( 'Synced today', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'value' => $synced,
			),
			array(
				'label' => __( 'Waiting', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'value' => $waiting,
			),
			array(
				'label' => __( 'Failed', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'value' => $failed,
			),
		);

		if ( 'paused' === $mode ) {
			$unreachable = 'paused_circuit' === (string) ( $connection['status'] ?? '' );
			return array(
				'state'    => 'paused',
				'counts'   => $counts,
				/* translators: %s: name of the connected system. */
				'headline' => $unreachable ? sprintf( __( 'Paused: %s is not answering', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) : __( 'Syncing is paused', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'detail'   => $this->pausedDetail( $connection ),
				'action'   => __( 'Open the dashboard', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'route'    => '/',
			);
		}

		$connected = sprintf(
			/* translators: %s: name of the connected system. */
			__( '%s connected.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$system
		);
		$success = $this->time( $connection['last_success_at'] ?? null );
		/* translators: %s: time of day. */
		$detail = '' === $success ? $connected : $connected . ' ' . sprintf( __( 'Last success %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $success );

		if ( $failed > 0 ) {
			return array(
				'state'    => 'attention',
				'counts'   => $counts,
				/* translators: %s: number of failed orders. */
				'headline' => sprintf( _n( '%s order needs a person', '%s orders need a person', $failed, 'estasoft-enterprise-erpcrm-sync-engine' ), number_format_i18n( $failed ) ),
				'detail'   => $detail,
				/* translators: %s: number of failed orders. */
				'action'   => sprintf( _n( 'Review %s failed order', 'Review %s failed orders', $failed, 'estasoft-enterprise-erpcrm-sync-engine' ), number_format_i18n( $failed ) ),
				'route'    => '/queue?state=failed',
			);
		}
		return array(
			'state'    => 'clear',
			'counts'   => $counts,
			'headline' => __( 'Everything is in step', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'detail'   => $detail,
			'action'   => __( 'Open ESTASOFT SYNC', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'route'    => '/',
		);
	}

	/**
	 * "Last answer 11:48. Next check 12:07." when the times are known.
	 *
	 * @param array<string,mixed> $connection Connection summary.
	 */
	private function pausedDetail( array $connection ): string {
		$parts = array();
		$last  = $this->time( $connection['last_success_at'] ?? null );
		$next  = $this->time( $connection['paused_until'] ?? null );
		if ( '' !== $last ) {
			/* translators: %s: time of day. */
			$parts[] = sprintf( __( 'Last answer %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $last );
		}
		if ( '' !== $next ) {
			/* translators: %s: time of day. */
			$parts[] = sprintf( __( 'Next check %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $next );
		}
		return implode( ' ', $parts );
	}

	/**
	 * A time of day in the store's time zone, or an empty string.
	 *
	 * @param mixed $iso ISO time or null.
	 */
	private function time( mixed $iso ): string {
		return is_string( $iso ) && '' !== $iso ? ( $this->formatTime )( $iso ) : '';
	}
}
