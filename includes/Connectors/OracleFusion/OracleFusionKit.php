<?php
/**
 * The helpers of the Oracle Fusion connector, built for one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * One object that holds the services for a call, so the connector class stays small.
 */
final class OracleFusionKit {

	/**
	 * Calls.
	 *
	 * @var OracleFusionGateway
	 */
	public readonly OracleFusionGateway $gateway;

	/**
	 * Options.
	 *
	 * @var OracleFusionOptions
	 */
	public readonly OracleFusionOptions $options;

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	public readonly Amounts $amounts;

	/**
	 * Items.
	 *
	 * @var OracleFusionItems
	 */
	public readonly OracleFusionItems $items;

	/**
	 * Invoices.
	 *
	 * @var OracleFusionOrders
	 */
	public readonly OracleFusionOrders $orders;

	/**
	 * Credit memos.
	 *
	 * @var OracleFusionRefunds
	 */
	public readonly OracleFusionRefunds $refunds;

	/**
	 * Reads.
	 *
	 * @var OracleFusionReader
	 */
	public readonly OracleFusionReader $reader;

	/**
	 * Total check.
	 *
	 * @var TotalCheck
	 */
	public readonly TotalCheck $total;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient           $api     Client with the base address.
	 * @param OracleFusionOptions $options Options of the connection.
	 * @param string              $system  System name for messages.
	 */
	public function __construct( ApiClient $api, OracleFusionOptions $options, string $system ) {
		$this->options = $options;
		$this->amounts = new Amounts();
		$this->gateway = new OracleFusionGateway( $api );
		$this->items   = new OracleFusionItems( $this->gateway );
		$this->orders  = new OracleFusionOrders( $this->gateway, $this->items, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system );
		$this->refunds = new OracleFusionRefunds( $this->gateway, $this->orders, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system );
		$this->reader  = new OracleFusionReader( $this->gateway, $this->orders, $options, $system );
		$this->total   = new TotalCheck( $this->amounts, $system, $options->totalMismatch() );
	}
}
