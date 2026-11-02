<?php
/**
 * Shared set-up for transform tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Diagnostics;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformContext;
use PHPUnit\Framework\TestCase;

abstract class TransformTestCase extends TestCase {

	/**
	 * A context for one transform call.
	 *
	 * @param array<string,mixed> $order  Canonical data.
	 * @param string              $source First source path of the rule.
	 * @param int|null            $row    Row of a list rule.
	 */
	protected function context( array $order = array(), string $source = '', ?int $row = null, ?TaxResolver $taxes = null, ?ProductResolver $products = null, ?ErrorCode $code = null ): TransformContext {
		$context = new TransformContext(
			new CanonicalOrder( $order ),
			new Diagnostics(),
			new MappingEnvironment( 1, 'Odoo', $taxes, $products )
		);
		$context->enter( 'r1', 'target.path', $source, $code, $row );
		return $context;
	}

	/**
	 * Warning messages collected by a context.
	 *
	 * @return array<int,string>
	 */
	protected function warnings( TransformContext $context ): array {
		return array_map( static fn( array $warning ): string => $warning['message'], $context->diagnostics->warnings() );
	}
}
