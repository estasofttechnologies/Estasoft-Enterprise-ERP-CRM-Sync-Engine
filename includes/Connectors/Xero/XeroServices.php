<?php
/**
 * The helpers of one Xero connection, built around one client.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Built once per connection and options and kept by the connector for the length of the request, so the organisation and its currencies are
 * read once however many items a worker sends.
 */
final class XeroServices {

	/**
	 * Organisation facts.
	 *
	 * @var XeroOrganisation
	 */
	public readonly XeroOrganisation $organisation;

	/**
	 * Contacts.
	 *
	 * @var XeroContacts
	 */
	public readonly XeroContacts $contacts;

	/**
	 * Items.
	 *
	 * @var XeroItems
	 */
	public readonly XeroItems $items;

	/**
	 * Documents.
	 *
	 * @var XeroInvoices
	 */
	public readonly XeroInvoices $invoices;

	/**
	 * Reads.
	 *
	 * @var XeroReader
	 */
	public readonly XeroReader $reader;

	/**
	 * Order flow.
	 *
	 * @var XeroOrderSender
	 */
	public readonly XeroOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var XeroRefundSender
	 */
	public readonly XeroRefundSender $refunds;

	/**
	 * Customer flow.
	 *
	 * @var XeroCustomerSender
	 */
	public readonly XeroCustomerSender $customers;

	/**
	 * Options.
	 *
	 * @var XeroOptions
	 */
	public readonly XeroOptions $options;

	/**
	 * Build the helpers.
	 *
	 * @param ApiClient  $api        Client for the connection.
	 * @param Connection $connection Connection.
	 */
	public function __construct( public readonly ApiClient $api, Connection $connection ) {
		$this->options      = XeroOptions::fromConnection( $connection );
		$amounts            = new XeroAmounts();
		$totals             = new XeroTotals( $amounts );
		$builder            = new XeroDocumentBuilder( $this->options, $amounts );
		$this->organisation = new XeroOrganisation( $api );
		$this->contacts     = new XeroContacts( $api );
		$this->items        = new XeroItems( $api );
		$this->invoices     = new XeroInvoices( $api );
		$this->reader       = new XeroReader( $api, $this->items, $this->invoices, $amounts );
		$this->orders       = new XeroOrderSender( $this->invoices, $this->contacts, $this->items, $this->organisation, $builder, $totals, $amounts, $this->options, $connection->id );
		$this->refunds      = new XeroRefundSender( $this->invoices, $this->items, $builder, $totals, $amounts, $this->options, $connection->id );
		$this->customers    = new XeroCustomerSender( $this->contacts );
	}
}
