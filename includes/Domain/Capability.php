<?php
/**
 * Connector capability flags (CMP-3). Screens show a feature only when its flag is true.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum Capability: string {
	case Orders          = 'orders';
	case Customers       = 'customers';
	case Refunds         = 'refunds';
	case StockIn         = 'stock_in';
	case TrackingIn      = 'tracking_in';
	case PricesIn        = 'prices_in';
	case InvoiceReadback = 'invoice_readback';
	case Reconciliation  = 'reconciliation';
	case Webhooks        = 'webhooks';
	case ShadowLookup    = 'shadow_lookup';
}
