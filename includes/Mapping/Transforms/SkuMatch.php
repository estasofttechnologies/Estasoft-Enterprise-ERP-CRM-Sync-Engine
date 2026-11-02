<?php
/**
 * Transform: SKU to the connected system's product.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The source is a SKU (lines[].sku). Args: output ("id", the default, gives the system's product id; "ref" gives its reference,
 * for connectors that look products up by reference). Uses a mapping you set by hand first, then the SKU. A SKU the system does not
 * know, or a product without a SKU, stops the order with SYNC-MAP-001 before anything is sent.
 */
final class SkuMatch extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'sku_match';
	}

	/**
	 * Find the product.
	 *
	 * @param mixed               $value   SKU.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws MappingException SYNC-MAP-001 when no product matches (a LookupFailedException from the resolver passes through).
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$resolver = $context->environment->products;
		$sku      = is_scalar( $value ) ? Text::trim( Text::toString( $value ) ) : '';
		if ( null === $resolver ) {
			$context->warn( __( 'No product matching is available, so the SKU was sent as it is.', 'estasoft-erp-crm-sync-engine' ) );
			return $value;
		}
		$found = $resolver->resolve(
			$context->environment->connectionId,
			$sku,
			$this->lineId( $context, 'lines[].product_id' ),
			$this->lineId( $context, 'lines[].variation_id' )
		);
		if ( null === $found ) {
			$failure = new MappingException(
				ErrorCode::Map001,
				array( 'sku' => $this->label( $sku, $context ) ) + $context->messageContext(),
				$context->ruleId(),
				$context->target()
			);
			throw $failure;
		}
		return 'ref' === $this->text( $args, 'output', 'id' ) ? $found->ref : $found->id;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		return in_array( $this->text( $args, 'output', 'id' ), array( 'id', 'ref' ), true ) ? array() : array( $this->missing( 'output' ) );
	}

	/**
	 * What to call the product in the message: its SKU, or its name when it has none.
	 *
	 * @param string           $sku     SKU.
	 * @param TransformContext $context Context.
	 */
	private function label( string $sku, TransformContext $context ): string {
		if ( '' !== $sku ) {
			return $sku;
		}
		$name = null === $context->row() ? null : $context->read( 'lines[].name' );
		/* translators: %s: product name. The product has no SKU. */
		return sprintf( __( '%s (no SKU)', 'estasoft-erp-crm-sync-engine' ), is_scalar( $name ) ? Text::toString( $name ) : __( 'A product', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * A WooCommerce id of the current line; 0 outside a list rule.
	 *
	 * @param TransformContext $context Context.
	 * @param string           $path    Canonical path of the id.
	 */
	private function lineId( TransformContext $context, string $path ): int {
		if ( null === $context->row() ) {
			return 0;
		}
		$value = $context->read( $path );
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
