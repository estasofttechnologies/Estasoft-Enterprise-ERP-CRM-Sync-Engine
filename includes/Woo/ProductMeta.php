<?php
/**
 * The sync facts kept on a WooCommerce product.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Keys of Core\MetaKeys::product() plus _estasoft_sync_match, which this module adds to that list through the filter
 * estasoft_sync_meta_keys. Written through the product object and save_meta_data(); the product itself is never saved here.
 */
final class ProductMeta {

	public const EXCLUDE       = '_estasoft_sync_exclude';
	public const EXTERNAL_ID   = '_estasoft_sync_external_id';
	public const EXTERNAL_REF  = '_estasoft_sync_external_ref';
	public const LAST_STOCK    = '_estasoft_sync_last_stock';
	public const LAST_STOCK_AT = '_estasoft_sync_last_stock_at';
	public const MATCH         = '_estasoft_sync_match';

	/**
	 * Verdicts the match key may hold.
	 */
	public const MATCHED   = 'matched';
	public const UNMATCHED = 'unmatched';

	/**
	 * Keys this module adds to Core's list of product meta (for removal on uninstall).
	 */
	public const EXTRA_KEYS = array( self::MATCH );

	/**
	 * Whether a product is marked "Exclude from syncing". A variation counts as excluded when its parent product is.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public function isExcluded( \WC_Product $product ): bool {
		if ( $this->yes( $product->get_meta( self::EXCLUDE, true ) ) ) {
			return true;
		}
		$parent = $product->get_parent_id();
		if ( $parent > 0 && function_exists( 'wc_get_product' ) ) {
			$parentProduct = wc_get_product( $parent );
			return $parentProduct instanceof \WC_Product && $this->yes( $parentProduct->get_meta( self::EXCLUDE, true ) );
		}
		return false;
	}

	/**
	 * Mark or unmark a product as excluded.
	 *
	 * @param \WC_Product $product  Product.
	 * @param bool        $excluded Whether to exclude it.
	 */
	public function setExcluded( \WC_Product $product, bool $excluded ): void {
		if ( $excluded ) {
			$product->update_meta_data( self::EXCLUDE, 'yes' );
		} else {
			$product->delete_meta_data( self::EXCLUDE );
		}
		$product->save_meta_data();
	}

	/**
	 * Record whether the connected system has this product.
	 *
	 * @param \WC_Product $product Product.
	 * @param bool        $matched Whether it matched.
	 */
	public function recordMatch( \WC_Product $product, bool $matched ): void {
		$verdict = $matched ? self::MATCHED : self::UNMATCHED;
		if ( $product->get_meta( self::MATCH, true ) === $verdict ) {
			return;
		}
		$product->update_meta_data( self::MATCH, $verdict );
		$product->save_meta_data();
	}

	/**
	 * Record the last quantity read from the system and when.
	 *
	 * @param \WC_Product $product  Product.
	 * @param int         $quantity Quantity in the system.
	 * @param string      $at       UTC "Y-m-d H:i:s".
	 */
	public function recordStock( \WC_Product $product, int $quantity, string $at ): void {
		$product->update_meta_data( self::LAST_STOCK, (string) $quantity );
		$product->update_meta_data( self::LAST_STOCK_AT, $at );
		$product->save_meta_data();
	}

	/**
	 * What the plugin knows about a product, for the product box.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{match:string,external_id:string,external_ref:string,last_stock:?int,last_stock_at:string}
	 */
	public function read( \WC_Product $product ): array {
		$stock = $product->get_meta( self::LAST_STOCK, true );
		return array(
			'match'         => $this->text( $product->get_meta( self::MATCH, true ) ),
			'external_id'   => $this->text( $product->get_meta( self::EXTERNAL_ID, true ) ),
			'external_ref'  => $this->text( $product->get_meta( self::EXTERNAL_REF, true ) ),
			'last_stock'    => is_numeric( $stock ) ? (int) $stock : null,
			'last_stock_at' => $this->text( $product->get_meta( self::LAST_STOCK_AT, true ) ),
		);
	}

	/**
	 * Whether a stored flag means yes.
	 *
	 * @param mixed $value Stored value.
	 */
	private function yes( mixed $value ): bool {
		return in_array( $value, array( 'yes', '1', 1, true ), true );
	}

	/**
	 * A stored value as text.
	 *
	 * @param mixed $value Stored value.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
