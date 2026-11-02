<?php
/**
 * The helpers of one MYOB connection, built once per request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Built once per connection and options and kept by the connector for the length of the request, so the tax code list is read once however many
 * items a worker sends.
 */
final class MyobServices {

	/**
	 * Calls.
	 *
	 * @var MyobApi
	 */
	public readonly MyobApi $api;

	/**
	 * Options.
	 *
	 * @var MyobOptions
	 */
	public readonly MyobOptions $options;

	/**
	 * Company file facts.
	 *
	 * @var MyobCompany
	 */
	public readonly MyobCompany $company;

	/**
	 * Tax codes, accounts and items.
	 *
	 * @var MyobLookups
	 */
	public readonly MyobLookups $lookups;

	/**
	 * Customers.
	 *
	 * @var MyobCustomers
	 */
	public readonly MyobCustomers $customers;

	/**
	 * Reads.
	 *
	 * @var MyobReader
	 */
	public readonly MyobReader $reader;

	/**
	 * Order flow.
	 *
	 * @var MyobOrderSender
	 */
	public readonly MyobOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var MyobRefundSender
	 */
	public readonly MyobRefundSender $refunds;

	/**
	 * Customer flow.
	 *
	 * @var MyobCustomerSender
	 */
	public readonly MyobCustomerSender $customerSender;

	/**
	 * Build the helpers.
	 *
	 * @param ApiClient  $client     Client for the company file.
	 * @param Connection $connection Connection.
	 */
	public function __construct( ApiClient $client, Connection $connection ) {
		$this->options        = MyobOptions::fromConnection( $connection );
		$this->api            = new MyobApi( $client );
		$amounts              = new MyobAmounts();
		$totals               = new MyobTotals( $amounts );
		$builder              = new MyobInvoiceBuilder( $this->options, $amounts );
		$invoices             = new MyobInvoices( $this->api );
		$this->company        = new MyobCompany( $this->api, MyobCompany::hostOf( $connection ), MyobCompany::fileOf( $connection ), MyobCompany::currencyOf( $connection ) );
		$this->lookups        = new MyobLookups( $this->api );
		$this->customers      = new MyobCustomers( $this->api );
		$this->reader         = new MyobReader( $this->api, $this->lookups, $invoices, $amounts, $this->company );
		$this->orders         = new MyobOrderSender( $invoices, $this->customers, $this->lookups, $this->company, $builder, $totals, $amounts, $this->options );
		$this->refunds        = new MyobRefundSender( $invoices, $this->lookups, $this->company, $builder, $totals, $amounts, $this->options );
		$this->customerSender = new MyobCustomerSender( $this->customers );
	}
}
