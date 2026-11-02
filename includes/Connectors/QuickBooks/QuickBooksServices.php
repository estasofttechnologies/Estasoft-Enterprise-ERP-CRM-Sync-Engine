<?php
/**
 * The helpers of one QuickBooks connection, built around one client.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Built once per connection and options and kept by the connector for the length of the request, so the company and its preferences are read
 * once however many items a worker sends.
 */
final class QuickBooksServices {

	/**
	 * Calls.
	 *
	 * @var QuickBooksApi
	 */
	public readonly QuickBooksApi $api;

	/**
	 * Options.
	 *
	 * @var QuickBooksOptions
	 */
	public readonly QuickBooksOptions $options;

	/**
	 * Company facts.
	 *
	 * @var QuickBooksCompany
	 */
	public readonly QuickBooksCompany $company;

	/**
	 * Customers.
	 *
	 * @var QuickBooksCustomers
	 */
	public readonly QuickBooksCustomers $customers;

	/**
	 * Items.
	 *
	 * @var QuickBooksItems
	 */
	public readonly QuickBooksItems $items;

	/**
	 * Reads.
	 *
	 * @var QuickBooksReader
	 */
	public readonly QuickBooksReader $reader;

	/**
	 * Order flow.
	 *
	 * @var QuickBooksOrderSender
	 */
	public readonly QuickBooksOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var QuickBooksRefundSender
	 */
	public readonly QuickBooksRefundSender $refunds;

	/**
	 * Customer flow.
	 *
	 * @var QuickBooksCustomerSender
	 */
	public readonly QuickBooksCustomerSender $customerSender;

	/**
	 * Build the helpers.
	 *
	 * @param ApiClient  $client     Client for the company.
	 * @param Connection $connection Connection.
	 */
	public function __construct( ApiClient $client, Connection $connection ) {
		$this->options        = QuickBooksOptions::fromConnection( $connection );
		$this->api            = new QuickBooksApi( $client, $this->options->minorVersion() );
		$amounts              = new QuickBooksAmounts();
		$totals               = new QuickBooksTotals( $amounts );
		$builder              = new QuickBooksDocumentBuilder( $this->options, $amounts );
		$documents            = new QuickBooksDocuments( $this->api );
		$this->company        = new QuickBooksCompany( $this->api, QuickBooksCompany::realmOf( $connection ) );
		$this->customers      = new QuickBooksCustomers( $this->api );
		$this->items          = new QuickBooksItems( $this->api );
		$this->reader         = new QuickBooksReader( $this->api, $this->items, $documents, $amounts, $this->company );
		$this->orders         = new QuickBooksOrderSender( $documents, $this->customers, $this->items, $this->company, $builder, $totals, $amounts, $this->options, $connection->id );
		$this->refunds        = new QuickBooksRefundSender( $documents, $this->items, $this->company, $builder, $totals, $amounts, $this->options, $connection->id );
		$this->customerSender = new QuickBooksCustomerSender( $this->customers );
	}
}
