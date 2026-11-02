<?php
/**
 * The helpers of the SAP S/4HANA connector, built for one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * One object that holds the services for a call, so the connector class stays small.
 */
final class SapS4HanaKit {

	/**
	 * Calls.
	 *
	 * @var SapS4HanaGateway
	 */
	public readonly SapS4HanaGateway $gateway;

	/**
	 * Options.
	 *
	 * @var SapS4HanaOptions
	 */
	public readonly SapS4HanaOptions $options;

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	public readonly Amounts $amounts;

	/**
	 * Sales orders.
	 *
	 * @var SapS4HanaOrders
	 */
	public readonly SapS4HanaOrders $orders;

	/**
	 * Credit memo requests.
	 *
	 * @var SapS4HanaRefunds
	 */
	public readonly SapS4HanaRefunds $refunds;

	/**
	 * Reads.
	 *
	 * @var SapS4HanaReader
	 */
	public readonly SapS4HanaReader $reader;

	/**
	 * Total check.
	 *
	 * @var TotalCheck
	 */
	public readonly TotalCheck $total;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient        $api     Client with the base address.
	 * @param SapS4HanaOptions $options Options of the connection.
	 * @param string           $system  System name for messages.
	 */
	public function __construct( ApiClient $api, SapS4HanaOptions $options, string $system ) {
		$this->options = $options;
		$this->amounts = new Amounts();
		$this->gateway = new SapS4HanaGateway( $api );
		$this->orders  = new SapS4HanaOrders( $this->gateway, $options, $this->amounts, $system );
		$this->refunds = new SapS4HanaRefunds( $this->gateway, $this->orders, $options, $this->amounts, $system );
		$this->reader  = new SapS4HanaReader( $this->gateway );
		$this->total   = new TotalCheck( $this->amounts, $system, $options->totalMismatch() );
	}
}
