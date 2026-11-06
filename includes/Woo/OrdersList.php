<?php
/**
 * The Sync column, state filter and bulk actions on the WooCommerce orders list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Works with high-performance order storage (screen wc-orders, WooCommerce's list table hooks) and with the older posts storage
 * (screen edit-shop_order). The column reads the order meta that StateMirror keeps and asks the queue nothing, so a page of fifty
 * orders costs no queue queries; the filter is a meta query on that state, built through WooCommerce's own query arguments. The three
 * bulk actions use OrderActions, like the order box.
 */
final class OrdersList {

	/**
	 * Column id.
	 */
	public const COLUMN = 'estasoft_sync';

	/**
	 * Query argument of the state filter.
	 */
	public const FILTER = 'estasoft_sync_state';

	/**
	 * Filter choices and the order states each stands for. An empty list means "no state at all".
	 */
	private const CHOICES = array(
		'failed'     => array( 'failed' ),
		'waiting'    => array( 'queued', 'syncing', 'retrying' ),
		'skipped'    => array( 'skipped' ),
		'not_synced' => array(),
	);

	/**
	 * Bulk action ids and the order action each runs.
	 */
	private const BULK = array(
		'estasoft_sync_retry' => 'retry',
		'estasoft_sync_again' => 'sync_again',
		'estasoft_sync_skip'  => 'skip',
	);

	/**
	 * Allowed markup in the cell.
	 */
	private const ALLOWED_HTML = array(
		'span' => array(
			'class'       => true,
			'aria-hidden' => true,
		),
	);

	/**
	 * Seconds the view counts are kept (five minutes; a bulk action or a button on an order clears them sooner).
	 */
	private const COUNT_TTL = 300;

	/**
	 * Transient that holds the view counts.
	 */
	public const COUNT_TRANSIENT = 'estasoft_sync_order_view_counts';

	/**
	 * Set up with what it depends on.
	 *
	 * @param OrderMeta    $orders     Order meta.
	 * @param OrderActions $actions    What the bulk actions do.
	 * @param StateChip    $chips      State labels.
	 * @param string       $capability Capability needed to see and use the column and actions.
	 */
	public function __construct(
		private readonly OrderMeta $orders,
		private readonly OrderActions $actions,
		private readonly StateChip $chips,
		private readonly string $capability
	) {
	}

	/**
	 * Add the hooks for both storage layouts.
	 */
	public function register(): void {
		// High-performance order storage.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'renderCell' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'renderFilter' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filterQueryArgs' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'addBulkActions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handleBulkActions' ), 10, 3 );
		add_filter( 'views_woocommerce_page_wc-orders', array( $this, 'addViews' ) );

		// Older posts storage.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'renderCell' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'renderLegacyFilter' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filterLegacyQuery' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'addBulkActions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handleBulkActions' ), 10, 3 );
		add_filter( 'views_edit-shop_order', array( $this, 'addViews' ) );
	}

	/**
	 * Put the Sync column after Status.
	 *
	 * @param mixed $columns Columns.
	 * @return array<string,string>
	 */
	public function addColumn( mixed $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		if ( ! current_user_can( $this->capability ) ) {
			return $columns;
		}
		$label  = __( 'Sync', 'estasoft-enterprise-erpcrm-sync-engine' );
		$result = array();
		$placed = false;
		foreach ( $columns as $key => $title ) {
			$result[ $key ] = $title;
			if ( 'order_status' === $key ) {
				$result[ self::COLUMN ] = $label;
				$placed                 = true;
			}
		}
		if ( ! $placed ) {
			$result[ self::COLUMN ] = $label;
		}
		return $result;
	}

	/**
	 * Print the cell. High-performance storage passes the order; the older storage passes the post id.
	 *
	 * @param mixed $column  Column id.
	 * @param mixed $subject Order or post id.
	 */
	public function renderCell( mixed $column = '', mixed $subject = null ): void {
		if ( self::COLUMN !== $column || ! current_user_can( $this->capability ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = $subject instanceof \WC_Order ? $subject : wc_get_order( (int) $subject );
		if ( $order instanceof \WC_Order ) {
			echo wp_kses( $this->cell( $order ), self::ALLOWED_HTML );
		}
	}

	/**
	 * The cell as HTML.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function cell( \WC_Order $order ): string {
		$state = $this->orders->state( $order );
		if ( '' === $state ) {
			return '<span class="estasoft-sync-muted"><span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not synced', 'estasoft-enterprise-erpcrm-sync-engine' ) . '</span></span>';
		}
		return $this->chips->html( $state );
	}

	/**
	 * Print the state filter on the high-performance storage list.
	 *
	 * @param mixed $orderType Order type.
	 * @param mixed $which     top or bottom.
	 */
	public function renderFilter( mixed $orderType = '', mixed $which = '' ): void {
		unset( $orderType );
		if ( 'top' === $which ) {
			$this->printFilter();
		}
	}

	/**
	 * Print the state filter on the older posts list.
	 *
	 * @param mixed $postType Post type.
	 * @param mixed $which    top or bottom.
	 */
	public function renderLegacyFilter( mixed $postType = '', mixed $which = '' ): void {
		if ( 'shop_order' === $postType && 'top' === $which ) {
			$this->printFilter();
		}
	}

	/**
	 * Narrow the high-performance storage query by the chosen state.
	 *
	 * @param mixed $args Query arguments given to wc_get_orders().
	 * @return array<string,mixed>
	 */
	public function filterQueryArgs( mixed $args ): array {
		$args  = is_array( $args ) ? $args : array();
		$state = $this->requestedState();
		if ( '' === $state || ! current_user_can( $this->capability ) ) {
			return $args;
		}
		$args['meta_query'] = array_merge( is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array(), $this->metaQuery( $state ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed meta key; this is the supported way to filter orders by meta.
		return $args;
	}

	/**
	 * Narrow the older posts query by the chosen state.
	 *
	 * @param mixed $query The query.
	 */
	public function filterLegacyQuery( mixed $query ): void {
		if ( ! $query instanceof \WP_Query || ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		$state = $this->requestedState();
		if ( '' !== $state && current_user_can( $this->capability ) ) {
			$existing = $query->get( 'meta_query' );
			$query->set( 'meta_query', array_merge( is_array( $existing ) ? $existing : array(), $this->metaQuery( $state ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one meta key; the supported way to filter by meta.
		}
	}

	/**
	 * Add the bulk actions.
	 *
	 * @param mixed $actions Actions.
	 * @return array<string,string>
	 */
	public function addBulkActions( mixed $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! current_user_can( $this->capability ) ) {
			return $actions;
		}
		$actions['estasoft_sync_retry'] = __( 'Retry failed orders', 'estasoft-enterprise-erpcrm-sync-engine' );
		$actions['estasoft_sync_again'] = __( 'Sync again', 'estasoft-enterprise-erpcrm-sync-engine' );
		$actions['estasoft_sync_skip']  = __( 'Skip sync', 'estasoft-enterprise-erpcrm-sync-engine' );
		return $actions;
	}

	/**
	 * Run a bulk action. WordPress has already checked the list's nonce; the capability is checked here.
	 *
	 * @param mixed $redirect Address to return to.
	 * @param mixed $action   Bulk action id.
	 * @param mixed $ids      Order ids.
	 * @return string Address to return to, with the result added.
	 */
	public function handleBulkActions( mixed $redirect = '', mixed $action = '', mixed $ids = array() ): string {
		$redirect = is_string( $redirect ) ? $redirect : '';
		if ( ! is_string( $action ) || ! isset( self::BULK[ $action ] ) || ! function_exists( 'wc_get_order' ) ) {
			return $redirect;
		}
		if ( ! current_user_can( $this->capability ) ) {
			return add_query_arg( ActionNotices::QUERY, 'failed', $redirect );
		}

		$by      = $this->currentUserName();
		$results = array();
		foreach ( array_map( 'absint', is_array( $ids ) ? $ids : array() ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && '' !== $this->orders->state( $order ) ) {
				$result             = $this->actions->perform( self::BULK[ $action ], $order, $by );
				$results[ $result ] = ( $results[ $result ] ?? 0 ) + 1;
			}
		}
		unset( $results['nothing'], $results['failed'] );
		delete_transient( self::COUNT_TRANSIENT );
		arsort( $results );
		$key = (string) array_key_first( $results );

		return add_query_arg(
			array(
				ActionNotices::QUERY => '' === $key ? 'nothing' : $key,
				ActionNotices::COUNT => '' === $key ? 0 : $results[ $key ],
			),
			remove_query_arg( array( ActionNotices::QUERY, ActionNotices::COUNT ), $redirect )
		);
	}

	/**
	 * Add the state links above the list: Failed, Waiting, Skipped and Not synced yet, with counts.
	 *
	 * @param mixed $views Views.
	 * @return array<string,string>
	 */
	public function addViews( mixed $views ): array {
		$views = is_array( $views ) ? $views : array();
		if ( ! current_user_can( $this->capability ) || ! function_exists( 'wc_get_orders' ) ) {
			return $views;
		}
		$counts = $this->counts();
		$active = $this->requestedState();
		$labels = array(
			'failed'     => __( 'Failed', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'waiting'    => __( 'Waiting', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'skipped'    => __( 'Skipped', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'not_synced' => __( 'Not synced yet', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		foreach ( $labels as $choice => $label ) {
			$views[ 'estasoft_sync_' . $choice ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( self::FILTER, $choice, remove_query_arg( array( self::FILTER, 'paged' ) ) ) ),
				$active === $choice ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $choice ] ?? 0 ) )
			);
		}
		return $views;
	}

	/**
	 * The filter's dropdown.
	 */
	private function printFilter(): void {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}
		$active  = $this->requestedState();
		$choices = array(
			''           => __( 'All sync states', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'failed'     => __( 'Failed', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'waiting'    => __( 'Waiting', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'skipped'    => __( 'Skipped', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'not_synced' => __( 'Not synced yet', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		echo '<label class="screen-reader-text" for="estasoft-sync-state-filter">' . esc_html__( 'Filter by sync state', 'estasoft-enterprise-erpcrm-sync-engine' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER ) . '" id="estasoft-sync-state-filter">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $active, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * The chosen filter value if it is one of the known choices, otherwise an empty string.
	 */
	private function requestedState(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter; the value is checked against a fixed list.
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';
		return isset( self::CHOICES[ $value ] ) ? $value : '';
	}

	/**
	 * The meta query for a filter choice.
	 *
	 * @param string $choice failed, waiting, skipped, not_synced, or any (an order with any state).
	 * @return array<int,array<string,mixed>>
	 */
	private function metaQuery( string $choice ): array {
		if ( 'any' === $choice ) {
			return array(
				array(
					'key'     => OrderMeta::STATE,
					'value'   => OrderMeta::STATES,
					'compare' => 'IN',
				),
			);
		}
		$states = self::CHOICES[ $choice ] ?? array();
		if ( array() === $states ) {
			return array(
				array(
					'key'     => OrderMeta::STATE,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		return array(
			array(
				'key'     => OrderMeta::STATE,
				'value'   => $states,
				'compare' => 'IN',
			),
		);
	}

	/**
	 * Orders in each filter choice. Kept for five minutes. "Not synced yet" is every order minus the orders that have a state, because
	 * asking for orders without a meta key makes the database look at every order.
	 *
	 * @return array<string,int>
	 */
	private function counts(): array {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = array();
		foreach ( self::CHOICES as $choice => $states ) {
			if ( array() !== $states ) {
				$counts[ $choice ] = $this->countOrders( $this->metaQuery( $choice ) );
			}
		}
		$all                  = $this->countOrders( array() );
		$counts['not_synced'] = max( 0, $all - $this->countOrders( $this->metaQuery( 'any' ) ) );

		set_transient( self::COUNT_TRANSIENT, $counts, self::COUNT_TTL );
		return $counts;
	}

	/**
	 * How many orders match a meta query (every order when it is empty).
	 *
	 * @param array<int,array<string,mixed>> $metaQuery Meta query.
	 */
	private function countOrders( array $metaQuery ): int {
		$args = array(
			'limit'    => 1,
			'paginate' => true,
			'return'   => 'ids',
			'status'   => array_keys( wc_get_order_statuses() ),
		);
		if ( array() !== $metaQuery ) {
			$args['meta_query'] = $metaQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one meta key; cached for five minutes.
		}
		$result = wc_get_orders( $args );
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Display name of the signed-in person.
	 */
	private function currentUserName(): string {
		$user = wp_get_current_user();
		return $user->exists() ? (string) $user->display_name : '';
	}
}
