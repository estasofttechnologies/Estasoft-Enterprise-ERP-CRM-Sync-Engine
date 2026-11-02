<?php
/**
 * The ESTASOFT SYNC box on the WooCommerce product screen, and the "Not matched" filter on the products list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;

defined( 'ABSPATH' ) || exit;

/**
 * The box says whether the connected system has this product (matched, not matched, no SKU), lets a person exclude it from syncing,
 * and shows the system's last stock quantity. It reads what is already stored; it never calls the connected system. Saving the box
 * writes one meta key through the product object.
 */
final class ProductBox {

	/**
	 * Name of the exclude checkbox.
	 */
	public const FIELD = 'estasoft_sync_exclude';

	/**
	 * Nonce action; the field is <action>_nonce.
	 */
	public const NONCE = 'estasoft_sync_product_box';

	/**
	 * Query argument of the products list filter.
	 */
	public const FILTER = 'estasoft_sync_filter';

	/**
	 * Page slug of the React app.
	 */
	private const APP_PAGE = 'estasoft-sync';

	/**
	 * Allowed markup in the box.
	 */
	private const ALLOWED_HTML = array(
		'div'   => array( 'class' => true ),
		'p'     => array( 'class' => true ),
		'span'  => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'dl'    => array( 'class' => true ),
		'dt'    => array( 'class' => true ),
		'dd'    => array(
			'class' => true,
			'dir'   => true,
		),
		'a'     => array(
			'href'  => true,
			'class' => true,
		),
		'label' => array( 'for' => true ),
		'input' => array(
			'type'    => true,
			'name'    => true,
			'id'      => true,
			'value'   => true,
			'checked' => true,
		),
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param ProductMeta    $meta       Product meta.
	 * @param ConnectionInfo $connection Connection facts.
	 * @param StateChip      $chips      State labels.
	 * @param TimeLabel      $times      Time labels.
	 * @param string         $capability Capability needed to see and use the box.
	 * @param \Closure|null  $buffer     Returns the stock safety buffer in units.
	 */
	public function __construct(
		private readonly ProductMeta $meta,
		private readonly ConnectionInfo $connection,
		private readonly StateChip $chips,
		private readonly TimeLabel $times,
		private readonly string $capability,
		private readonly ?\Closure $buffer = null
	) {
	}

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'addBox' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20, 1 );
		add_filter( 'views_edit-product', array( $this, 'addView' ) );
		add_action( 'pre_get_posts', array( $this, 'filterQuery' ) );
	}

	/**
	 * Add the box.
	 */
	public function addBox(): void {
		if ( current_user_can( $this->capability ) ) {
			add_meta_box( 'estasoft-sync-product-box', __( 'ESTASOFT SYNC', 'estasoft-erp-crm-sync-engine' ), array( $this, 'render' ), 'product', 'side', 'default' );
		}
	}

	/**
	 * Print the box.
	 *
	 * @param mixed $post The product post.
	 */
	public function render( mixed $post = null ): void {
		$product = is_object( $post ) && isset( $post->ID ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post->ID ) : false;
		if ( $product instanceof \WC_Product && current_user_can( $this->capability ) ) {
			echo wp_kses( $this->html( $product ), self::ALLOWED_HTML );
		}
	}

	/**
	 * The box as HTML. Every dynamic value is escaped here.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function html( \WC_Product $product ): string {
		$system = $this->connection->systemName();
		$known  = $this->meta->read( $product );
		$state  = $this->state( $product, $known );
		$body   = match ( $state ) {
			'matched'   => $this->matched( $product, $known, $system ),
			'unmatched' => $this->unmatched( $product, $system ),
			'no_sku'    => $this->noSku(),
			default     => $this->unchecked( $product ),
		};

		return sprintf(
			'<div class="estasoft-sync-box estasoft-sync-box--%1$s"><p class="estasoft-sync-box__state">%2$s</p>%3$s<p class="estasoft-sync-box__exclude">%4$s%5$s</p><p class="estasoft-sync-box__note">%6$s</p></div>',
			esc_attr( sanitize_html_class( $state ) ),
			$this->chips->html( $state ),
			$body,
			$this->nonceField(),
			$this->excludeCheckbox( $product ),
			esc_html__( 'An excluded product is never sent. Orders that contain it are sent without that line.', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * Save the checkbox when the product is saved.
	 *
	 * @param mixed $productId Product id.
	 */
	public function save( mixed $productId ): void {
		$id = absint( $productId );
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) || ! current_user_can( $this->capability ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( $product instanceof \WC_Product ) {
			$this->meta->setExcluded( $product, isset( $_POST[ self::FIELD ] ) );
		}
	}

	/**
	 * Add the "Not matched" link above the products list.
	 *
	 * @param mixed $views Views.
	 * @return array<string,string>
	 */
	public function addView( mixed $views ): array {
		$views = is_array( $views ) ? $views : array();
		if ( ! current_user_can( $this->capability ) ) {
			return $views;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter.
		$active = isset( $_GET[ self::FILTER ] ) && 'not_matched' === sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) );

		// The count is the number of results of the filtered list the page has just run, so no query is added; the link is a plain link
		// until the filter is used.
		$main  = $GLOBALS['wp_query'] ?? null;
		$count = $active && $main instanceof \WP_Query ? sprintf( ' <span class="count">(%s)</span>', esc_html( number_format_i18n( (int) $main->found_posts ) ) ) : '';

		$views['estasoft_sync_unmatched'] = sprintf(
			'<a href="%1$s"%2$s>%3$s%4$s</a>',
			esc_url( add_query_arg( self::FILTER, 'not_matched', admin_url( 'edit.php?post_type=product' ) ) ),
			$active ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Not matched', 'estasoft-erp-crm-sync-engine' ),
			$count
		);
		return $views;
	}

	/**
	 * Narrow the products list to products that are not matched or have no SKU.
	 *
	 * @param mixed $query The query.
	 */
	public function filterQuery( mixed $query ): void {
		if ( ! $query instanceof \WP_Query || ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter.
		$filter = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';
		if ( 'not_matched' === $filter && current_user_can( $this->capability ) ) {
			$query->set( 'meta_query', $this->notMatchedQuery() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin-only list filter.
		}
	}

	/**
	 * The meta query for products that need attention: marked not matched, or without a SKU.
	 *
	 * @return array<int|string,mixed>
	 */
	private function notMatchedQuery(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'   => ProductMeta::MATCH,
				'value' => ProductMeta::UNMATCHED,
			),
			array(
				'key'     => '_sku',
				'value'   => '',
				'compare' => '=',
			),
			array(
				'key'     => '_sku',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * The state to show.
	 *
	 * @param \WC_Product                                                                                     $product Product.
	 * @param array{match:string,external_id:string,external_ref:string,last_stock:?int,last_stock_at:string} $known   What is stored.
	 */
	private function state( \WC_Product $product, array $known ): string {
		if ( '' === trim( (string) $product->get_sku( 'edit' ) ) ) {
			return $product->is_type( 'variable' ) ? 'unchecked' : 'no_sku';
		}
		if ( ProductMeta::UNMATCHED === $known['match'] ) {
			return 'unmatched';
		}
		return ProductMeta::MATCHED === $known['match'] || '' !== $known['external_ref'] ? 'matched' : 'unchecked';
	}

	/**
	 * Matched: the system's reference and stock.
	 *
	 * @param \WC_Product                                                                                     $product Product.
	 * @param array{match:string,external_id:string,external_ref:string,last_stock:?int,last_stock_at:string} $known   What is stored.
	 * @param string                                                                                          $system  System name.
	 */
	private function matched( \WC_Product $product, array $known, string $system ): string {
		$rows = array();
		if ( '' !== $known['external_ref'] ) {
			/* translators: %s: name of the connected system. */
			$rows[ sprintf( __( '%s reference', 'estasoft-erp-crm-sync-engine' ), $system ) ] = $known['external_ref'];
		}
		if ( null !== $known['last_stock'] ) {
			/* translators: %s: name of the connected system. */
			$rows[ sprintf( __( '%s stock', 'estasoft-erp-crm-sync-engine' ), $system ) ] = (string) $known['last_stock'];
			$shown  = $product->get_stock_quantity();
			$buffer = null === $this->buffer ? 0 : (int) ( $this->buffer )();
			if ( null !== $shown ) {
				$rows[ __( 'Shown in shop', 'estasoft-erp-crm-sync-engine' ) ] = $buffer > 0
					/* translators: 1: units shown in the shop, 2: safety buffer in units. */
					? sprintf( __( '%1$d (buffer %2$d)', 'estasoft-erp-crm-sync-engine' ), (int) $shown, $buffer )
					: (string) (int) $shown;
			}
		}
		if ( '' !== $known['last_stock_at'] ) {
			$rows[ __( 'Last stock update', 'estasoft-erp-crm-sync-engine' ) ] = $this->times->format( $known['last_stock_at'] );
		}
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<dt>%1$s</dt><dd dir="auto">%2$s</dd>', esc_html( $label ), esc_html( $value ) );
		}
		$note = null === $known['last_stock']
			? ''
			/* translators: %s: name of the connected system. */
			: '<p class="estasoft-sync-box__note">' . esc_html( sprintf( __( 'Stock comes from %s. Changing the quantity here is replaced at the next stock run.', 'estasoft-erp-crm-sync-engine' ), $system ) ) . '</p>';
		return ( '' === $html ? '' : '<dl class="estasoft-sync-box__facts">' . $html . '</dl>' ) . $note;
	}

	/**
	 * Not matched.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $system  System name.
	 */
	private function unmatched( \WC_Product $product, string $system ): string {
		/* translators: 1: SKU, 2: name of the connected system. */
		$text = sprintf( __( 'SKU %1$s was not found in %2$s. Orders that contain this product will fail with "product not found".', 'estasoft-erp-crm-sync-engine' ), (string) $product->get_sku( 'edit' ), $system );
		return '<p class="estasoft-sync-box__note">' . esc_html( $text ) . '</p><p class="estasoft-sync-box__actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::APP_PAGE . '#/mapping/products' ) ) . '">'
			/* translators: %s: name of the connected system. */
			. esc_html( sprintf( __( 'Map to a %s product', 'estasoft-erp-crm-sync-engine' ), $system ) ) . '</a></p>';
	}

	/**
	 * No SKU.
	 */
	private function noSku(): string {
		return '<p class="estasoft-sync-box__note">' . esc_html__( 'This product has no SKU, so it cannot be matched. Orders that contain it will fail.', 'estasoft-erp-crm-sync-engine' ) . '</p><p class="estasoft-sync-box__actions"><a class="button" href="#_sku">' . esc_html__( 'Go to the SKU field', 'estasoft-erp-crm-sync-engine' ) . '</a></p>';
	}

	/**
	 * Never looked up.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function unchecked( \WC_Product $product ): string {
		$text = $product->is_type( 'variable' )
			? __( 'Each variation is matched by its own SKU.', 'estasoft-erp-crm-sync-engine' )
			: __( 'The SKU is looked up the first time an order contains this product.', 'estasoft-erp-crm-sync-engine' );
		return '<p class="estasoft-sync-box__note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The hidden nonce field.
	 */
	private function nonceField(): string {
		return sprintf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::NONCE . '_nonce' ), esc_attr( wp_create_nonce( self::NONCE ) ) );
	}

	/**
	 * The exclude checkbox with its label.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function excludeCheckbox( \WC_Product $product ): string {
		return sprintf(
			'<input type="checkbox" name="%1$s" id="%1$s" value="1"%2$s /> <label for="%1$s">%3$s</label>',
			esc_attr( self::FIELD ),
			$this->meta->isExcluded( $product ) ? ' checked="checked"' : '',
			esc_html__( 'Exclude this product from syncing', 'estasoft-erp-crm-sync-engine' )
		);
	}
}
