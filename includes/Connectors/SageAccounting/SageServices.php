<?php
/**
 * The helpers of one Sage connection, built once per request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Built once per connection and options and kept by the connector for the length of the request, so the tax rates, the ledger accounts and the base currency are read
 * once however many items a worker sends.
 */
final class SageServices {

	/**
	 * Calls.
	 *
	 * @var SageApi
	 */
	public readonly SageApi $api;

	/**
	 * Options.
	 *
	 * @var SageOptions
	 */
	public readonly SageOptions $options;

	/**
	 * Business facts.
	 *
	 * @var SageBusiness
	 */
	public readonly SageBusiness $business;

	/**
	 * Contacts.
	 *
	 * @var SageContacts
	 */
	public readonly SageContacts $contacts;

	/**
	 * Products.
	 *
	 * @var SageProducts
	 */
	public readonly SageProducts $products;

	/**
	 * Reads.
	 *
	 * @var SageReader
	 */
	public readonly SageReader $reader;

	/**
	 * Order flow.
	 *
	 * @var SageOrderSender
	 */
	public readonly SageOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var SageRefundSender
	 */
	public readonly SageRefundSender $refunds;

	/**
	 * Customer flow.
	 *
	 * @var SageCustomerSender
	 */
	public readonly SageCustomerSender $customerSender;

	/**
	 * Build the helpers.
	 *
	 * @param ApiClient  $client     Client for the business.
	 * @param Connection $connection Connection.
	 */
	public function __construct( ApiClient $client, Connection $connection ) {
		$this->options        = SageOptions::fromConnection( $connection );
		$this->api            = new SageApi( $client );
		$country              = SageBusiness::countryOf( $connection );
		$amounts              = new SageAmounts();
		$totals               = new SageTotals( $amounts );
		$builder              = new SageBuilder( $this->options, $amounts, $country );
		$invoices             = new SageInvoices( $this->api );
		$this->business       = new SageBusiness( $this->api, SageBusiness::businessOf( $connection ) );
		$this->contacts       = new SageContacts( $this->api );
		$this->products       = new SageProducts( $this->api );
		$this->reader         = new SageReader( $this->api, $this->products, $invoices, $this->business, $amounts );
		$this->orders         = new SageOrderSender( $invoices, $this->contacts, $this->products, $this->business, $builder, $totals, $amounts, $this->options, $connection->id, $country );
		$this->refunds        = new SageRefundSender( $invoices, $this->products, $this->business, $builder, $totals, $amounts, $this->options, $connection->id, $country );
		$this->customerSender = new SageCustomerSender( $this->contacts, $connection->id );
	}
}
