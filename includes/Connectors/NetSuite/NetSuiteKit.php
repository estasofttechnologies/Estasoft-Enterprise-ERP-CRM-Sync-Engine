<?php
/**
 * The helpers of the NetSuite connector, built for one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * One object that holds the services for a call, so the connector class stays small.
 */
final class NetSuiteKit {

	/**
	 * Calls.
	 *
	 * @var NetSuiteGateway
	 */
	public readonly NetSuiteGateway $gateway;

	/**
	 * Options.
	 *
	 * @var NetSuiteOptions
	 */
	public readonly NetSuiteOptions $options;

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	public readonly Amounts $amounts;

	/**
	 * Master data reads.
	 *
	 * @var NetSuiteLookups
	 */
	public readonly NetSuiteLookups $lookups;

	/**
	 * Customers.
	 *
	 * @var NetSuiteCustomers
	 */
	public readonly NetSuiteCustomers $customers;

	/**
	 * Orders.
	 *
	 * @var NetSuiteOrders
	 */
	public readonly NetSuiteOrders $orders;

	/**
	 * Refunds.
	 *
	 * @var NetSuiteRefunds
	 */
	public readonly NetSuiteRefunds $refunds;

	/**
	 * Reads for the nightly check and the invoice status.
	 *
	 * @var NetSuiteReader
	 */
	public readonly NetSuiteReader $reader;

	/**
	 * Total check.
	 *
	 * @var TotalCheck
	 */
	public readonly TotalCheck $total;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient       $api     Client pinned to the account.
	 * @param NetSuiteOptions $options Options of the connection.
	 * @param string          $system  System name for messages.
	 * @param string          $host    Account host label (for links).
	 */
	public function __construct( ApiClient $api, NetSuiteOptions $options, string $system, string $host ) {
		$this->options   = $options;
		$this->amounts   = new Amounts();
		$this->gateway   = new NetSuiteGateway( $api );
		$this->lookups   = new NetSuiteLookups( $this->gateway, $options );
		$this->customers = new NetSuiteCustomers( $this->gateway, $this->lookups, $options, $system );
		$this->orders    = new NetSuiteOrders( $this->gateway, $this->lookups, $this->customers, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system, $host );
		$this->refunds   = new NetSuiteRefunds( $this->gateway, $this->lookups, $this->orders, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system, $host );
		$this->reader    = new NetSuiteReader( $this->gateway, $this->lookups, $this->amounts, $host );
		$this->total     = new TotalCheck( $this->amounts, $system, $options->totalMismatch() );
	}
}
