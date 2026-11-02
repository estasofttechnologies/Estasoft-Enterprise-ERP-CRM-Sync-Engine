<?php
/**
 * Canonical data held in an array.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Ports\CanonicalSource;
use Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException;

final class ArraySource implements CanonicalSource {

	/**
	 * Canonical arrays by "type:id".
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $data = array();

	/**
	 * Meta keys asked for in the last read.
	 *
	 * @var array<int,string>
	 */
	public array $lastMetaKeys = array();

	public function read( EntityType $type, string $entityId, array $metaKeys = array() ): CanonicalOrder {
		$this->lastMetaKeys = $metaKeys;
		if ( ! isset( $this->data[ $type->value . ':' . $entityId ] ) ) {
			throw new SourceNotFoundException( 'missing' );
		}
		return new CanonicalOrder( $this->data[ $type->value . ':' . $entityId ] );
	}
}
