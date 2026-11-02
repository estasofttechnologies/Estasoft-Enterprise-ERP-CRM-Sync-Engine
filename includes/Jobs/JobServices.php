<?php
/**
 * Container keys of the Jobs services.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Names of the services JobsModule registers. Each public service is also registered under its class
 * name, so `$container->get( StockService::class )` returns the same object as `$container->get( JobServices::STOCK )`.
 * The REST, Admin and CLI layers read the services below and nothing else from this module; the ports
 * and adapters are internal to Jobs.
 */
final class JobServices {

	/**
	 * Stock sync service: rules, overview, conflicts, run.
	 */
	public const STOCK = 'jobs.stock';

	/**
	 * Tracking read-back service: settings, run.
	 */
	public const TRACKING = 'jobs.tracking';

	/**
	 * Invoice read-back service: list, run.
	 */
	public const INVOICES = 'jobs.invoices';

	/**
	 * Reconciliation service: overview, run, repair, ignore, settings.
	 */
	public const RECONCILIATION = 'jobs.reconciliation';

	/**
	 * Shadow mode report and window control.
	 */
	public const SHADOW = 'jobs.shadow';

	/**
	 * Go-live switch (shadow or live).
	 */
	public const GO_LIVE = 'jobs.go_live';

	/**
	 * Readiness checks.
	 */
	public const READINESS = 'jobs.readiness';

	/**
	 * Support report builder.
	 */
	public const SUPPORT_REPORT = 'jobs.support_report';

	/**
	 * Settings export and import.
	 */
	public const PORTER = 'jobs.porter';

	/**
	 * E-mail notifications.
	 */
	public const NOTIFICATIONS = 'jobs.notifications';

	/**
	 * Capacity test.
	 */
	public const CAPACITY = 'jobs.capacity';

	/**
	 * Inbound webhook hint handler.
	 */
	public const INBOUND = 'jobs.inbound';

	/**
	 * Dashboard status, widget data and failed-count bubble.
	 */
	public const DASHBOARD = 'jobs.dashboard';

	/**
	 * Connection management: summaries, create, update, test, disconnect, catalog.
	 */
	public const CONNECTIONS = 'jobs.connections';

	/**
	 * Onboarding state and sample orders.
	 */
	public const ONBOARDING = 'jobs.onboarding';

	/*
	 * Internal services. Other modules should not need them; they are listed so tests and the module agree on the names.
	 */

	/**
	 * The shop and server facts (Ports\ShopEnvironment).
	 */
	public const ENVIRONMENT = 'jobs.environment';

	/**
	 * WooCommerce orders (Ports\ShopOrders).
	 */
	public const SHOP_ORDERS = 'jobs.shop_orders';

	/**
	 * WooCommerce stock (Ports\ShopStock).
	 */
	public const SHOP_STOCK = 'jobs.shop_stock';

	/**
	 * WooCommerce products for the readiness checks (Ports\ShopCatalog).
	 */
	public const SHOP_CATALOG = 'jobs.shop_catalog';

	/**
	 * WooCommerce customers for the readiness checks (Ports\ShopCustomers).
	 */
	public const SHOP_CUSTOMERS = 'jobs.shop_customers';

	/**
	 * The door from jobs to connectors (Support\ConnectorGateway).
	 */
	public const GATEWAY = 'jobs.gateway';

	/**
	 * Links into the admin app (Urls).
	 */
	public const URLS = 'jobs.urls';
}
