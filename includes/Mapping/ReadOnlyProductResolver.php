<?php
/**
 * Product matching for previews: reads only, never writes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ExternalRef;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps SkuMatcher::peek(), so a preview uses overrides and stored matches, asks the connected system only when the person ticked
 * the lookups option, and remembers nothing.
 */
final class ReadOnlyProductResolver implements ProductResolver {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SkuMatcher $matcher Matcher.
	 * @param bool       $ask     Whether a read-only lookup in the connected system is allowed.
	 */
	public function __construct( private readonly SkuMatcher $matcher, private readonly bool $ask ) {
	}

	/**
	 * Find the product without writing.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @param int    $productId    Product id.
	 * @param int    $variationId  Variation id.
	 * @return ExternalRef|null
	 *
	 * @throws LookupFailedException When asked and the system answers with an error.
	 */
	public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
		return $this->matcher->peek( $connectionId, $sku, $productId, $variationId, $this->ask );
	}
}
