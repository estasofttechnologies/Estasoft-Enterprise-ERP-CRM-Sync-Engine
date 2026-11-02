<?php
/**
 * A scriptable payload builder.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;
use Estasoft\SyncEngine\Sync\PayloadResult;

/**
 * Returns a payload built from the item, unless a payload, an error or an exception was set for that entity id.
 */
final class CoreFakePayloadBuilder implements PayloadBuilderInterface {

	/**
	 * Payload by entity id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $payloads = array();

	/**
	 * Error by entity id.
	 *
	 * @var array<string,ConnectorError>
	 */
	public array $errors = array();

	/**
	 * Exception to throw from build().
	 */
	public ?\Throwable $throw = null;

	/**
	 * Mapping version reported.
	 */
	public int $mappingVersion = 3;

	/**
	 * Ids of the items built, in order.
	 *
	 * @var array<int,int>
	 */
	public array $built = array();

	/**
	 * Build.
	 *
	 * @param Item       $item       Item.
	 * @param Connection $connection Connection.
	 */
	public function build( Item $item, Connection $connection ): PayloadResult {
		$this->built[] = $item->id;
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		if ( isset( $this->errors[ $item->entityId ] ) ) {
			return new PayloadResult( array(), $this->errors[ $item->entityId ], $this->mappingVersion );
		}
		$payload = $this->payloads[ $item->entityId ] ?? array(
			'client_order_ref' => $item->clientReference(),
			'amount_minor'     => 43800,
			'partner'          => array( 'email' => 'noor@example.com' ),
			'lines'            => array( array( 'sku' => 'CS-BOWL-04', 'qty' => 2 ) ),
		);
		return new PayloadResult( $payload, null, $this->mappingVersion );
	}
}
