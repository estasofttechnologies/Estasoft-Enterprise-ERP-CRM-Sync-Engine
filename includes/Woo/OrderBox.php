<?php
/**
 * The ESTASOFT SYNC box on the WooCommerce order screen.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, because WordPress requires the order screen to render its own boxes. It sits on the high-performance order storage
 * screen (the id wc_get_page_screen_id( 'shop-order' ) returns, wc-orders) and on the older shop_order screen. It says what happened,
 * what the plugin will do, and what the person can do. Buttons are links with a nonce (a form would nest inside the order form);
 * the handler checks the nonce and the capability again.
 */
final class OrderBox {

	/**
	 * Action name of the admin-post handler.
	 */
	public const ACTION = 'estasoft_sync_order_action';

	/**
	 * Page slug of the React app, for the "View log" link.
	 */
	private const APP_PAGE = 'estasoft-sync';

	/**
	 * Tags and attributes the box may contain.
	 */
	private const ALLOWED_HTML = array(
		'div'  => array( 'class' => true ),
		'p'    => array( 'class' => true ),
		'span' => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'dl'   => array( 'class' => true ),
		'dt'   => array( 'class' => true ),
		'dd'   => array(
			'class' => true,
			'dir'   => true,
		),
		'a'    => array(
			'href'  => true,
			'class' => true,
			'rel'   => true,
		),
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param QueueGateway     $queue       Queue.
	 * @param OrderMeta        $orders      Order meta.
	 * @param OrderEligibility $eligibility Drafts and excluded products.
	 * @param GoLive           $goLive      Whether an order was placed after go-live.
	 * @param OrderActions     $actions     What the buttons do.
	 * @param ConnectionInfo   $connection  Connection facts.
	 * @param StateChip        $chips       State labels.
	 * @param TimeLabel        $times       Time labels.
	 * @param string           $capability  Capability needed to see and use the box.
	 * @param string           $pageUrl     URL of the app page (admin.php?page=estasoft-sync).
	 */
	public function __construct(
		private readonly QueueGateway $queue,
		private readonly OrderMeta $orders,
		private readonly OrderEligibility $eligibility,
		private readonly GoLive $goLive,
		private readonly OrderActions $actions,
		private readonly ConnectionInfo $connection,
		private readonly StateChip $chips,
		private readonly TimeLabel $times,
		private readonly string $capability,
		private readonly string $pageUrl = ''
	) {
	}

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'addBox' ), 20, 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleAction' ) );
	}

	/**
	 * Add the box to the order screens.
	 *
	 * @param mixed $postType Post type (older storage) or screen id.
	 */
	public function addBox( mixed $postType = '' ): void {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = (string) wc_get_page_screen_id( 'shop-order' );
		}
		if ( ! in_array( (string) $postType, array_filter( $screens ), true ) ) {
			return;
		}
		add_meta_box(
			'estasoft-sync-order-box',
			__( 'ESTASOFT SYNC', 'estasoft-enterprise-erpcrm-sync-engine' ),
			array( $this, 'render' ),
			(string) $postType,
			'side',
			'default'
		);
	}

	/**
	 * Print the box.
	 *
	 * @param mixed $subject Order (high-performance storage) or post (older storage).
	 */
	public function render( mixed $subject = null ): void {
		$order = $this->orderFrom( $subject );
		if ( null === $order || ! current_user_can( $this->capability ) ) {
			return;
		}
		echo wp_kses( $this->html( $order ), self::ALLOWED_HTML );
	}

	/**
	 * The box as HTML. Every dynamic value is escaped here; wp_kses() is a second line of defence in render().
	 *
	 * @param \WC_Order $order Order.
	 */
	public function html( \WC_Order $order ): string {
		$info    = $this->orders->read( $order );
		$item    = '' === $info->state ? null : $this->queue->latestForOrder( (int) $order->get_id() );
		$system  = $this->connection->systemName( null !== $item ? (int) ( $item['connection_id'] ?? 0 ) : null );
		$state   = $this->displayState( $order, $info->state );
		$content = match ( $state ) {
			'synced'   => $this->synced( $info, $system ),
			'queued'   => $this->queued( $item ),
			'syncing'  => $this->syncing( $system ),
			'retrying' => $this->retrying( $item ),
			'failed'   => $this->failed( $item ),
			'skipped'  => $this->skipped( $info, $system ),
			'shadow'   => $this->shadow( $item, $system ),
			'excluded' => $this->excluded(),
			'unsaved'  => $this->unsaved(),
			default    => $this->notSent( $order, $system ),
		};

		$actions = $this->actions( $order, $state, $item );
		return sprintf(
			'<div class="estasoft-sync-box estasoft-sync-box--%1$s"><p class="estasoft-sync-box__state">%2$s</p>%3$s%4$s%5$s</div>',
			esc_attr( sanitize_html_class( $state ) ),
			$this->chips->html( $state ),
			$content,
			$this->excludedNote( $order, $state ),
			$actions
		);
	}

	/**
	 * Handle a click on one of the box's buttons.
	 */
	public function handleAction(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below, once the order id is known.
		$todo = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$orderId = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		check_admin_referer( $this->nonceName( $todo, $orderId ) );
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', array( 'response' => 403 ) );
		}
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $orderId ) : false;
		$result = $order instanceof \WC_Order ? $this->actions->perform( $todo, $order, $this->currentUserName() ) : 'failed';
		delete_transient( OrdersList::COUNT_TRANSIENT );
		wp_safe_redirect( add_query_arg( ActionNotices::QUERY, $result, $this->orderUrl( $orderId ) ) );
	}

	/**
	 * The order behind the object WordPress passes to a meta box.
	 *
	 * @param mixed $subject Order or post.
	 */
	private function orderFrom( mixed $subject ): ?\WC_Order {
		if ( $subject instanceof \WC_Order ) {
			return $subject;
		}
		if ( is_object( $subject ) && isset( $subject->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $subject->ID );
			return $order instanceof \WC_Order ? $order : null;
		}
		return null;
	}

	/**
	 * The state to show: the stored one, "unsaved" for a draft (the empty order of the Add order screen), "excluded" for an order whose products are all excluded, or "none".
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state Stored state.
	 */
	private function displayState( \WC_Order $order, string $state ): string {
		if ( '' !== $state ) {
			return $state;
		}
		if ( $this->eligibility->isDraft( $order ) ) {
			return 'unsaved';
		}
		return $this->eligibility->hasOnlyExcludedProducts( $order ) ? 'excluded' : 'none';
	}

	/**
	 * Synced.
	 *
	 * @param OrderSyncInfo $info   Order facts.
	 * @param string        $system System name.
	 */
	private function synced( OrderSyncInfo $info, string $system ): string {
		$rows = array();
		/* translators: %s: name of the connected system, for example Odoo. */
		$rows[ sprintf( __( '%s order', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) ] = $this->link( $info->externalRef, $info->externalUrl );
		if ( '' !== $info->invoiceNumber ) {
			$rows[ __( 'Invoice', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $info->invoiceNumber );
		}
		if ( '' !== $info->invoiceStatus ) {
			$rows[ __( 'Tax authority', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $info->invoiceStatus );
		}
		$rows[ __( 'Last sync', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $this->times->format( $info->lastSync ) );
		if ( null !== $info->mappingVersion ) {
			/* translators: %d: mapping version number. */
			$rows[ __( 'Mapping', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( sprintf( __( 'Version %d', 'estasoft-enterprise-erpcrm-sync-engine' ), $info->mappingVersion ) );
		}
		/* translators: %s: name of the connected system. */
		return $this->facts( $rows ) . $this->note( sprintf( __( 'If you change this order, an update is sent to %s.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
	}

	/**
	 * Queued.
	 *
	 * @param array<string,mixed>|null $item Queue item.
	 */
	private function queued( ?array $item ): string {
		$rows = array();
		if ( null !== $item ) {
			$rows[ __( 'Added', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $this->times->format( (string) ( $item['created_at'] ?? '' ) ) );
		}
		return $this->facts( $rows ) . $this->note( __( 'It will be sent within a minute. Checkout was not delayed.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * Syncing.
	 *
	 * @param string $system System name.
	 */
	private function syncing( string $system ): string {
		/* translators: %s: name of the connected system. */
		return $this->note( sprintf( __( 'It is being sent to %s now.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
	}

	/**
	 * Retrying.
	 *
	 * @param array<string,mixed>|null $item Queue item.
	 */
	private function retrying( ?array $item ): string {
		$rows = array();
		if ( null !== $item ) {
			$rows[ __( 'What happened', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( (string) ( $item['error_message'] ?? '' ) );
			/* translators: 1: number of tries made, 2: number of tries allowed. */
			$rows[ __( 'Try', 'estasoft-enterprise-erpcrm-sync-engine' ) ]      = esc_html( sprintf( __( '%1$d of %2$d', 'estasoft-enterprise-erpcrm-sync-engine' ), (int) ( $item['attempt'] ?? 0 ), (int) ( $item['max_attempts'] ?? 0 ) ) );
			$rows[ __( 'Next try', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $this->times->format( (string) ( $item['next_attempt_at'] ?? '' ) ) );
		}
		return $this->facts( $rows ) . $this->note( __( 'Nothing to do. It will try again by itself.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * Failed.
	 *
	 * @param array<string,mixed>|null $item Queue item.
	 */
	private function failed( ?array $item ): string {
		$rows = array();
		$code = null === $item ? '' : (string) ( $item['error_code'] ?? '' );
		if ( '' !== $code ) {
			$rows[ __( 'Code', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $code );
		}
		$what = null === $item ? '' : (string) ( $item['error_message'] ?? '' );
		// The error text is the queue's own words; when the queue item cannot be found the box still says where to look.
		$rows[ __( 'What happened', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( '' === $what ? __( 'The details are in the log.', 'estasoft-enterprise-erpcrm-sync-engine' ) : $what );
		$rows[ __( 'Order', 'estasoft-enterprise-erpcrm-sync-engine' ) ]         = esc_html__( 'Waits. Later steps for this order wait too.', 'estasoft-enterprise-erpcrm-sync-engine' );
		$remedy = ErrorCode::tryFrom( $code )?->remedy() ?? '';
		return $this->facts( $rows ) . ( '' === $remedy ? '' : $this->note( $remedy . '.' ) );
	}

	/**
	 * Skipped.
	 *
	 * @param OrderSyncInfo $info   Order facts.
	 * @param string        $system System name.
	 */
	private function skipped( OrderSyncInfo $info, string $system ): string {
		$rows = array();
		$skip = $info->skip;
		if ( null !== $skip ) {
			$by   = '' === $skip['by'] ? '' : $skip['by'];
			$when = $this->times->format( $skip['at'] );
			if ( '' !== $by ) {
				/* translators: 1: name of the person, 2: time. */
				$rows[ __( 'Skipped', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( sprintf( __( 'by %1$s, %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ), $by, $when ) );
			}
			if ( '' !== $skip['reason'] ) {
				$rows[ __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $skip['reason'] );
			}
		}
		/* translators: %s: name of the connected system. */
		return $this->facts( $rows ) . $this->note( sprintf( __( 'It is not sent to %s. You can restore it.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
	}

	/**
	 * Shadow mode.
	 *
	 * @param array<string,mixed>|null $item   Queue item.
	 * @param string                   $system System name.
	 */
	private function shadow( ?array $item, string $system ): string {
		$rows = array();
		if ( null !== $item ) {
			$rows[ __( 'Built', 'estasoft-enterprise-erpcrm-sync-engine' ) ] = esc_html( $this->times->format( (string) ( $item['finished_at'] ?? $item['updated_at'] ?? '' ) ) );
		}
		/* translators: %s: name of the connected system. */
		return $this->facts( $rows ) . $this->note( sprintf( __( 'Nothing was sent to %s. Go live when the comparison looks right.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
	}

	/**
	 * Every product is excluded.
	 */
	private function excluded(): string {
		return $this->note( __( 'Every product on this order is marked Exclude from syncing, so the order is not sent.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * A draft that has not been saved: nothing to send yet.
	 */
	private function unsaved(): string {
		return $this->note( __( 'Save the order first. An order that has not been saved is never added to the queue.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * Never queued: either placed before go-live (a person can send it), or placed after it and not queued yet.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $system System name.
	 */
	private function notSent( \WC_Order $order, string $system ): string {
		if ( $this->goLive->placedAfter( $order ) ) {
			$rows = array( __( 'Why', 'estasoft-enterprise-erpcrm-sync-engine' ) => esc_html__( 'Not added to the queue yet', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			/* translators: %s: name of the connected system. */
			return $this->facts( $rows ) . $this->note( sprintf( __( 'It is added when its status moves to Processing, On hold or Completed. You can send it to %s now.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
		}
		$rows = array( __( 'Why', 'estasoft-enterprise-erpcrm-sync-engine' ) => esc_html__( 'Placed before syncing went live', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		/* translators: %s: name of the connected system. */
		return $this->facts( $rows ) . $this->note( sprintf( __( 'Orders from before go-live are not sent to %s. You can send one by hand.', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) );
	}

	/**
	 * The line about excluded products on an order that is otherwise synced.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state State being shown.
	 */
	private function excludedNote( \WC_Order $order, string $state ): string {
		$names = $this->eligibility->excludedNames( $order );
		if ( array() === $names || 'excluded' === $state ) {
			return '';
		}
		/* translators: %s: product names, separated by commas. */
		return $this->note( sprintf( __( 'Contains a product marked Exclude from syncing: %s. The order is sent without that line.', 'estasoft-enterprise-erpcrm-sync-engine' ), implode( ', ', $names ) ) );
	}

	/**
	 * The buttons for a state.
	 *
	 * @param \WC_Order                $order Order.
	 * @param string                   $state State being shown.
	 * @param array<string,mixed>|null $item  Queue item.
	 */
	private function actions( \WC_Order $order, string $state, ?array $item ): string {
		$links = array();
		switch ( $state ) {
			case 'synced':
				$links[] = $this->button( 'sync_again', __( 'Sync again', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				break;
			case 'queued':
				$links[] = $this->button( 'retry', __( 'Sync now', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				break;
			case 'retrying':
				$links[] = $this->button( 'retry', __( 'Retry now', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				break;
			case 'failed':
				$links[] = $this->button( 'retry', __( 'Retry', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				$links[] = $this->button( 'skip', __( 'Skip', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, false );
				break;
			case 'skipped':
				$links[] = $this->button( 'restore', __( 'Restore', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				break;
			case 'none':
				$links[] = $this->button( 'send', __( 'Send this order', 'estasoft-enterprise-erpcrm-sync-engine' ), $order, true );
				break;
			default:
				break;
		}
		if ( null !== $item && 'excluded' !== $state ) {
			$links[] = sprintf(
				'<a class="estasoft-sync-link" href="%1$s">%2$s</a>',
				esc_url( $this->logUrl( (int) ( $item['id'] ?? 0 ) ) ),
				'shadow' === $state ? esc_html__( 'See the comparison', 'estasoft-enterprise-erpcrm-sync-engine' ) : esc_html__( 'View log', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		return array() === $links ? '' : '<p class="estasoft-sync-box__actions">' . implode( ' ', $links ) . '</p>';
	}

	/**
	 * One button, as a link with a nonce.
	 *
	 * @param string    $todo    Action.
	 * @param string    $label   Button text.
	 * @param \WC_Order $order   Order.
	 * @param bool      $primary Whether it is the main button.
	 */
	private function button( string $todo, string $label, \WC_Order $order, bool $primary ): string {
		$orderId = (int) $order->get_id();
		$url     = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $todo,
					'order'  => $orderId,
				),
				admin_url( 'admin-post.php' )
			),
			$this->nonceName( $todo, $orderId )
		);
		return sprintf(
			'<a class="button estasoft-sync-button%1$s" href="%2$s">%3$s</a>',
			$primary ? ' button-primary' : '',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * The nonce name for an action on an order.
	 *
	 * @param string $todo    Action.
	 * @param int    $orderId Order id.
	 */
	private function nonceName( string $todo, int $orderId ): string {
		return 'estasoft_sync_order_' . $todo . '_' . $orderId;
	}

	/**
	 * Where the "View log" link goes.
	 *
	 * @param int $itemId Queue id.
	 */
	private function logUrl( int $itemId ): string {
		$base = '' === $this->pageUrl ? admin_url( 'admin.php?page=' . self::APP_PAGE ) : $this->pageUrl;
		return $base . '#/queue/' . $itemId;
	}

	/**
	 * The order's edit screen address, whichever storage is in use.
	 *
	 * @param int $orderId Order id.
	 */
	private function orderUrl( int $orderId ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $orderId ) : false;
		if ( $order instanceof \WC_Order && method_exists( $order, 'get_edit_order_url' ) ) {
			return (string) $order->get_edit_order_url();
		}
		return admin_url( 'post.php?post=' . $orderId . '&action=edit' );
	}

	/**
	 * Display name of the signed-in person.
	 */
	private function currentUserName(): string {
		$user = wp_get_current_user();
		return $user->exists() ? (string) $user->display_name : '';
	}

	/**
	 * A list of label and value pairs. Values are already escaped HTML.
	 *
	 * @param array<string,string> $rows Label => escaped value.
	 */
	private function facts( array $rows ): string {
		$html = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$html .= sprintf( '<dt>%1$s</dt><dd dir="auto">%2$s</dd>', esc_html( $label ), $value );
		}
		return '' === $html ? '' : '<dl class="estasoft-sync-box__facts">' . $html . '</dl>';
	}

	/**
	 * A short explanation under the facts.
	 *
	 * @param string $text Plain text (escaped here).
	 */
	private function note( string $text ): string {
		return '<p class="estasoft-sync-box__note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A reference as a link when there is an address, otherwise as text. Returns escaped HTML.
	 *
	 * @param string $label Text.
	 * @param string $url   Address.
	 */
	private function link( string $label, string $url ): string {
		if ( '' === $label ) {
			return '';
		}
		return '' === $url ? esc_html( $label ) : sprintf( '<a href="%1$s" rel="noopener noreferrer">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}
}
