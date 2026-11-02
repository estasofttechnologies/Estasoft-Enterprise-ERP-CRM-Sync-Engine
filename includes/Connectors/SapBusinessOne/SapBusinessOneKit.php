<?php
/**
 * The helpers of the SAP Business One connector, built for one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * One object that holds the services for a call, so the connector class stays small.
 */
final class SapBusinessOneKit {

	/**
	 * Calls.
	 *
	 * @var SapBusinessOneGateway
	 */
	public readonly SapBusinessOneGateway $gateway;

	/**
	 * Options.
	 *
	 * @var SapBusinessOneOptions
	 */
	public readonly SapBusinessOneOptions $options;

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	public readonly Amounts $amounts;

	/**
	 * Customers.
	 *
	 * @var SapBusinessOnePartners
	 */
	public readonly SapBusinessOnePartners $partners;

	/**
	 * Orders and invoices.
	 *
	 * @var SapBusinessOneOrders
	 */
	public readonly SapBusinessOneOrders $orders;

	/**
	 * Credit notes.
	 *
	 * @var SapBusinessOneRefunds
	 */
	public readonly SapBusinessOneRefunds $refunds;

	/**
	 * Reads.
	 *
	 * @var SapBusinessOneReader
	 */
	public readonly SapBusinessOneReader $reader;

	/**
	 * Total check.
	 *
	 * @var TotalCheck
	 */
	public readonly TotalCheck $total;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient             $api     Client with the Service Layer base address.
	 * @param SapBusinessOneOptions $options Options of the connection.
	 * @param string                $system  System name for messages.
	 */
	public function __construct( ApiClient $api, SapBusinessOneOptions $options, string $system ) {
		$this->options  = $options;
		$this->amounts  = new Amounts();
		$this->gateway  = new SapBusinessOneGateway( $api, $options->apiVersion() );
		$this->partners = new SapBusinessOnePartners( $this->gateway, $options, $system );
		$this->orders   = new SapBusinessOneOrders( $this->gateway, $this->partners, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system );
		$this->refunds  = new SapBusinessOneRefunds( $this->gateway, $this->orders, $options, $this->amounts, new ShopTotalMarker( $this->amounts ), $system );
		$this->reader   = new SapBusinessOneReader( $this->gateway, $this->orders, $this->amounts );
		$this->total    = new TotalCheck( $this->amounts, $system, $options->totalMismatch() );
	}
}
