/**
 * REST contract for the admin app. Namespace: /wp-json/estasoft-sync/v1
 *
 * This file is the single source of truth for response shapes. The PHP controllers in
 * includes/Rest must return exactly these shapes. Amounts are integer minor units plus an
 * ISO currency code. Times are ISO 8601 strings in UTC ("2026-11-02T07:40:00Z"); the app
 * formats them in store time using `Status.store_timezone`.
 *
 * Errors: { code: "SYNC-...", message: string, data: { status: number, class: ErrorClass } }
 */

import type { ErrorClass } from './errors';

export type ItemState =
	'queued' | 'syncing' | 'synced' | 'retrying' | 'failed' | 'skipped';
export type ItemMode = 'live' | 'shadow';
export type EntityType =
	'order' | 'refund' | 'customer' | 'stock' | 'tracking' | 'invoice';
export type ConnectionStatus =
	'active' | 'paused' | 'paused_circuit' | 'needs_reconnect' | 'disconnected';
export type ConnectorType = 'erp' | 'accounting' | 'crm';
export type MappingType =
	'orders' | 'customers' | 'products' | 'refunds' | 'stock' | 'tracking';
export type LogLevel = 'info' | 'warning' | 'error';

export interface Money {
	/** Integer minor units (fils, cents). Never a float. */
	minor: number;
	/** ISO 4217 code. */
	currency: string;
}

export interface Paged< T > {
	items: T[];
	total: number;
	total_pages: number;
	page: number;
	per_page: number;
}

/* ---------------------------------------------------------------- status (GET /status) */

export type DashboardMode =
	'not_connected' | 'first_run' | 'normal' | 'paused' | 'shadow';

export interface ConnectionSummary {
	id: number;
	connector: string;
	label: string;
	type: ConnectorType;
	status: ConnectionStatus;
	/** Text such as "Odoo 18 Enterprise". */
	version: string;
	address: string;
	paused_until: string | null;
	/** "circuit" when the breaker paused it, "person" when paused by hand, null otherwise. */
	paused_reason: 'circuit' | 'person' | 'sign_in' | 'key_missing' | null;
	last_success_at: string | null;
	last_failure_at: string | null;
	failures_in_a_row: number;
	typical_response_ms: number | null;
	/** Credential end date when the system tells us (for the expiry warning). */
	credential_expires_at: string | null;
	capabilities: Record< string, boolean >;
}

export interface HourlyPoint {
	/** "2026-11-02T07:00:00Z" */
	hour: string;
	synced: number;
	failed: number;
}

export interface ShadowInfo {
	started_at: string;
	ends_at: string;
	day: number;
	of_days: number;
	items: number;
	matches: number;
	differs: number;
	not_found: number;
}

export interface Status {
	mode: DashboardMode;
	now: string;
	store_timezone: string;
	currency: string;
	synced_today: number;
	waiting: number;
	needs_attention: number;
	skipped: number;
	oldest_waiting_at: string | null;
	oldest_waiting_order: string | null;
	by_state: Record< ItemState, number >;
	hourly: HourlyPoint[];
	events: LogEntry[];
	connection: ConnectionSummary | null;
	shadow: ShadowInfo | null;
	/** True when the encryption key constant is missing (blocks saving credentials). */
	encryption_key_missing: boolean;
	/** True when background tasks only run on site visits. */
	cron_on_visits: boolean;
}

/* ---------------------------------------------------------------- queue */

export interface QueueItem {
	id: number;
	connection_id: number;
	entity_type: EntityType;
	entity_id: string;
	/** Order number for display, e.g. "10490". */
	entity_label: string;
	event: string;
	state: ItemState;
	mode: ItemMode;
	attempt: number;
	max_attempts: number;
	next_attempt_at: string | null;
	error_code: string | null;
	error_message: string | null;
	external_id: string | null;
	external_ref: string | null;
	external_url: string | null;
	skip_reason: string | null;
	customer_name: string | null;
	total: Money | null;
	/** True when an earlier step of the same order is unfinished. */
	waiting_for_earlier: boolean;
	created_at: string;
	updated_at: string;
	finished_at: string | null;
	order_edit_url: string | null;
}

export interface TimelineEvent {
	at: string;
	level: LogLevel;
	code: string | null;
	message: string;
}

export interface QueueItemDetail extends QueueItem {
	idempotency_key: string;
	partition_key: string;
	mapping_version: number | null;
	timeline: TimelineEvent[];
	request_excerpt: string | null;
	response_excerpt: string | null;
	log_id: number | null;
	/** Sibling items of the same order, oldest first. */
	siblings: Pick<
		QueueItem,
		'id' | 'event' | 'state' | 'error_code' | 'created_at'
	>[];
}

export interface QueueQuery {
	state?: ItemState | 'waiting' | 'all';
	entity_type?: EntityType;
	day?: 'today' | 'yesterday' | '7d' | '30d';
	search?: string;
	error_code?: string;
	connection_id?: number;
	page?: number;
	per_page?: 25 | 50 | 100;
}

export interface AttentionGroup {
	error_code: string;
	/** For example "SKU CS-LAMP-07". */
	cause: string;
	count: number;
	oldest_at: string;
	item_ids: number[];
}

export interface RetryRequest {
	ids?: number[];
	error_code?: string;
	cause?: string;
}

/* ---------------------------------------------------------------- connections */

export type CredentialFieldType =
	| 'text'
	| 'password'
	| 'select'
	| 'textarea'
	| 'url'
	| 'number'
	/** A read-only value to copy (the redirect address of a sign-in app). */
	| 'display';

export type CredentialFieldGroup = 'connection' | 'app' | 'advanced';

/** How a connector signs in to its system (`AuthStyle` in PHP). It decides which connect form is drawn. */
export type AuthStyle =
	/** An API key or token the merchant pastes. */
	| 'key'
	/** A user name and password sent as Basic authentication. */
	| 'basic'
	/** The person signs in on the system's page (a stable redirect address, then `POST /connections/{id}/oauth/organisation`). */
	| 'oauth_authorization_code'
	/** The merchant's app id and secret; tested at once, no sign-in page. */
	| 'oauth_client_credentials'
	/** A signed assertion (private key); tested at once, no sign-in page. */
	| 'oauth_jwt_bearer'
	/** A server address, database, user and password (a session login). */
	| 'server_login'
	/** The plugin writes a file for the merchant to import; no connection to test. */
	| 'export_file'
	| 'none';

export interface CredentialFieldDef {
	name: string;
	label: string;
	secret: boolean;
	required: boolean;
	hint: string;
	default: string;
	/** How the field is drawn (default text). Always sent by the server. */
	type?: CredentialFieldType;
	/** Value to label pairs of a select. Empty for the other types. Always sent by the server. */
	options?: Record< string, string >;
	/** Where the field belongs on the connect form: the system's address and key, the merchant's own app, or advanced (default connection). Always sent by the server. */
	group?: CredentialFieldGroup;
}

export interface ReliabilitySettings {
	retry_delays_seconds: number[];
	jitter: boolean;
	breaker_threshold: number;
	probe_interval_minutes: number;
	requests_per_second: number;
	concurrency: number;
	obey_retry_after: boolean;
}

export interface Connection extends ConnectionSummary {
	company: string;
	warehouses: {
		id: string;
		name: string;
		counts_toward_stock: boolean;
		countries: string[];
	}[];
	settings: ReliabilitySettings;
	/** Credential fields with values for non-secret ones; secrets are never returned. */
	credentials: ( CredentialFieldDef & {
		value: string | null;
		has_value: boolean;
	} )[];
	inbound_enabled: boolean;
	created_at: string;
}

export interface ConnectionTestResult {
	ok: boolean;
	version: string;
	company: string;
	warehouses: { id: string; name: string }[];
	product_count: number | null;
	permissions: Record< string, boolean >;
	missing_permissions: string[];
	error: ApiErrorBody | null;
}

export interface ConnectionInput {
	connector: string;
	label?: string;
	/** Non-secret fields (address, database, user). */
	config: Record< string, string >;
	/** Secret fields; write-only. */
	secrets?: Record< string, string >;
	settings?: Partial< ReliabilitySettings >;
}

export interface CatalogEntry {
	name: string;
	type: ConnectorType;
	regions: string;
	/** Connector id when installed (odoo), null when not built yet. */
	connector: string | null;
	/** How the merchant connects: key, own_app, cloud_app, server_login, export_file, none, unknown. */
	connection_method: string;
	needs: string;
	/** True when a connector for this system (or one of its variants) is installed. */
	available: boolean;
	/** Why a system that is not available cannot be chosen yet (set when its document says `available: false`); empty otherwise. Shown next to the hand-written `needs`. Always sent by the server. */
	unavailable_reason?: string;
	/** False until the connector has been tried on a real account. Always sent by the server. */
	verified?: boolean;
	/** Rows that hold several products (Sage 200, 300 and X3) list them here; each has its own connector id. Always sent by the server. */
	variants?: CatalogVariant[];
	/** Every host the plugin contacts for this system (for the external services disclosure). An entry that starts with `*.` is a pattern: any subdomain of it (a system that gives each customer a host of its own). Always sent by the server. */
	hosts?: string[];
	terms_url?: string;
	privacy_url?: string;
	/** The vendor's developer page where the merchant makes an app or a key. */
	developer_url?: string;
	/** At most six short steps (140 characters each) of how the merchant connects. */
	steps?: string[];
	/** The connect form of this system's connector, so the screen can be drawn without another request. Empty when the system is not available. Secret fields never carry a default. Always sent by the server. */
	credential_fields?: CredentialFieldDef[];
	/** True when the person signs in on the system's own page (the connector has the sign-in routes). Always sent by the server. */
	can_sign_in?: boolean;
	/** The shape of the connection: for a row that holds variants, the style of the connection method; each variant carries its own. Always sent by the server. */
	auth_style?: AuthStyle;
	wave: number;
}

/** One product of a catalog row that holds several (Sage 200, 300 and X3). Has its own connector id and its own form. */
export interface CatalogVariant {
	/** Connector id. */
	id: string;
	label: string;
	/** True when this connector is installed. Always sent by the server. */
	available?: boolean;
	/** Only when the variant's document says why it cannot be chosen yet. */
	unavailable_reason?: string;
	/** How this product connects; it can differ from its family row (Sage 50 writes a file, Sage 300 signs in on the merchant's own server). Sent when the product's document says. */
	connection_method?:
		| 'key'
		| 'own_app'
		| 'cloud_app'
		| 'server_login'
		| 'export_file'
		| 'none'
		| 'unknown';
	/** The hosts this product contacts (empty for a product that only talks to the address the person types). Sent when the product's document says. */
	hosts?: string[];
	terms_url?: string;
	privacy_url?: string;
	developer_url?: string;
	/** This product's own steps; the family row holds the first product's. Sent when the product's document says. */
	steps?: string[];
	/** Empty when the variant is not available. Always sent by the server. */
	credential_fields?: CredentialFieldDef[];
	/** Always sent by the server. */
	can_sign_in?: boolean;
	/** Always sent by the server. */
	auth_style?: AuthStyle;
}

/* ---------------------------------------------------------------- exports */

/** A second file that goes with an export file. The server sends no link: download it with `GET /exports/{id}/download?part=N` (N from 1, in list order), through the signed-in request. */
export interface ExportAttachmentInfo {
	/** What the file is ("Masters list"). */
	name: string;
	filename: string;
	size_bytes: number;
}

export interface ExportFileInfo {
	id: string;
	connection_id: number;
	filename: string;
	size_bytes: number;
	orders: number;
	/** ISO 8601, UTC. */
	created_at: string;
	/** ISO 8601, UTC. Files are kept 30 days. */
	expires_at: string;
	created_by: string;
	/** Name of the file format ("Sage 50 CSV import"). */
	format_label: string;
	/** Orders that are not in the file, and why (SYNC-EXP-002). */
	left_out: { order: string; reason: string }[];
	/** Extra files that go with this one (a list of customers and items to import first); empty when there are none. Always sent by the server. Download with `GET /exports/{id}/download?part=1`, 2, ... */
	attachments: ExportAttachmentInfo[];
}

export interface ExportsResponse {
	files: ExportFileInfo[];
	/** Orders waiting for the next file of the connection, or null. */
	open_batch: { connection_id: number; orders: number; since: string } | null;
}

/* ---------------------------------------------------------------- mapping */

export interface MappingTransform {
	type: string;
	args?: Record<
		string,
		string | number | boolean | Record< string, string >
	>;
}

export interface MappingRule {
	id: string;
	target: string;
	source: string | string[] | { const: string | number | boolean };
	transforms: MappingTransform[];
	required: boolean;
	error_code?: string;
	/** Optional condition: only apply when `when.source` equals `when.equals`. */
	when?: { source: string; equals: string };
	changed?: boolean;
	added?: boolean;
}

export interface MappingVersion {
	version: number;
	status: 'draft' | 'live' | 'retired';
	note: string | null;
	created_by: string;
	created_at: string;
	published_at: string | null;
	rules: MappingRule[];
}

export interface MappingSet {
	type: MappingType;
	versioned: boolean;
	live: MappingVersion | null;
	draft: MappingVersion | null;
	versions: Omit< MappingVersion, 'rules' >[];
	target_fields: {
		path: string;
		label: string;
		type: string;
		required: boolean;
		group: string;
	}[];
	source_fields: {
		path: string;
		label: string;
		type: string;
		group: string;
	}[];
}

export interface MappingPreviewRequest {
	type: MappingType;
	order_id: number;
	use_draft: boolean;
	lookups: boolean;
}

export interface MappingPreview {
	source: Record< string, unknown >;
	target: Record< string, unknown >;
	/** Dotted target paths whose value differs from the plain source. */
	changed_paths: string[];
	rule_notes: { path: string; note: string }[];
	lookups: { label: string; found: boolean; detail: string }[];
	errors: ApiErrorBody[];
}

export interface TaxMapRow {
	country: string;
	tax_name: string;
	rate_percent: number;
	/** Code in the connected system, or null when not mapped. */
	system_code: string | null;
	orders_30d: number;
}

export interface TaxMap {
	rows: TaxMapRow[];
	system_codes: { code: string; label: string; rate_percent: number }[];
	buyer_tax_rules: {
		country: string;
		digits: number | null;
		required_for_business: boolean;
	}[];
}

export interface ProductMatch {
	product_id: number;
	sku: string;
	name: string;
	status: 'matched' | 'unmatched' | 'no_sku' | 'override';
	external_id: string | null;
	external_ref: string | null;
	external_name: string | null;
	orders_blocked: number;
}

/* ---------------------------------------------------------------- stock, tracking */

export interface StockRules {
	enabled: boolean;
	interval_minutes: number;
	safety_buffer: number;
	subtract_held: boolean;
	system_wins: true;
	warehouses: {
		id: string;
		name: string;
		counts_toward_stock: boolean;
		countries: string[];
	}[];
	tracking_enabled: boolean;
	delivered_becomes: string;
	cod_payment_on_delivery: boolean;
}

export interface StockChange {
	id: number;
	sku: string;
	product_name: string;
	system_quantity: number;
	shop_before: number;
	shop_after: number;
	at: string;
	unmatched?: boolean;
}

export interface StockConflict {
	id: number;
	sku: string;
	product_name: string;
	system_quantity: number;
	sold_unsynced: number;
	detected_at: string;
	code: 'SYNC-STK-001';
}

export interface StockOverview {
	last_run_at: string | null;
	matched: number;
	unmatched: number;
	changes: StockChange[];
	conflicts: StockConflict[];
	tracking_recent: {
		order_id: number;
		order_label: string;
		carrier: string;
		number: string;
		at: string;
	}[];
}

/* ---------------------------------------------------------------- reconciliation, invoices */

export type DiffType =
	'missing_in_system' | 'total_differs' | 'refund_missing' | 'status_differs';

export interface ReconDiff {
	id: number;
	run_id: number;
	/** The connection the difference was found in (always sent by the server; the screens do not need it). */
	connection_id?: number;
	order_id: number;
	order_label: string;
	type: DiffType;
	wc_value: string;
	system_value: string;
	status: 'open' | 'repaired' | 'ignored';
	note: string | null;
	acted_by: string | null;
	acted_at: string | null;
}

export interface ReconRun {
	id: number;
	started_at: string;
	finished_at: string | null;
	window_days: number;
	compared: number;
	matched: number;
	differences: number;
	repaired: number;
	status: 'running' | 'done' | 'failed';
}

export interface ReconOverview {
	last_run: ReconRun | null;
	runs: ReconRun[];
	diffs: ReconDiff[];
	schedule_time: string;
	window_days: number;
	tolerance_minor: number;
	auto_repair_missing: boolean;
}

export type InvoiceStatus =
	'waiting' | 'rejected' | 'reported' | 'not_required';

export interface InvoiceRow {
	order_id: number;
	order_label: string;
	number: string | null;
	status: InvoiceStatus;
	authority_status: string | null;
	issued_at: string | null;
	due_at: string | null;
	/** Seconds left to report; negative when late. */
	seconds_left: number | null;
	pdf_url: string | null;
	reject_reason: string | null;
	total: Money | null;
}

/* ---------------------------------------------------------------- logs */

export interface LogEntry {
	id: number;
	ts: string;
	level: LogLevel;
	connection_id: number | null;
	queue_id: number | null;
	entity_ref: string | null;
	code: string | null;
	message: string;
	http_status: number | null;
	duration_ms: number | null;
}

export interface LogEntryDetail extends LogEntry {
	request_excerpt: string | null;
	response_excerpt: string | null;
}

/* ---------------------------------------------------------------- settings and tools */

export interface Settings {
	display_time: 'store' | 'utc';
	log_retention_days: 7 | 14 | 30;
	log_excerpt_bytes: 0 | 2048 | 8192;
	redaction: boolean;
	payload_cap_bytes: number;
	delete_on_uninstall: boolean;
	runtime_profile: 'standard' | 'high_volume';
	global_concurrency: number;
	backlog_threshold: number;
	admin_bar: boolean;
	notifications: {
		recipients: string[];
		on_paused: boolean;
		daily_digest: boolean;
		digest_time: string;
		on_backlog: boolean;
		on_signin_expired: boolean;
	};
}

export interface ReadinessCheck {
	id: string;
	title: string;
	status: 'passed' | 'worth_fixing' | 'needs_fixing';
	detail: string;
	/** Admin URL or app route that explains or fixes it. */
	link: string | null;
	link_label: string | null;
}

export interface Readiness {
	ran_at: string | null;
	checks: ReadinessCheck[];
	/** True when any check needs fixing (blocks setup). */
	blocked: boolean;
}

export interface SupportReport {
	text: string;
	generated_at: string;
	includes_order_numbers: boolean;
}

export interface CapacityResult {
	items_per_minute: number;
	ran_at: string;
	items: number;
	seconds: number;
	verdict: string;
}

export interface Onboarding {
	step: 1 | 2 | 3 | 4 | 5;
	completed: boolean;
	connector: string | null;
	answers: Record< string, unknown >;
	shadow_started_at: string | null;
	shadow_ends_at: string | null;
}

export interface ShadowReport {
	info: ShadowInfo;
	rows: {
		order_id: number;
		order_label: string;
		result: 'matches' | 'differs' | 'not_found';
		fields: string[];
		queue_id: number;
	}[];
}

export interface SampleOrder {
	id: number;
	label: string;
	customer: string;
	total: Money;
	created_at: string;
}

/* ---------------------------------------------------------------- misc */

export interface ApiErrorBody {
	code: string;
	message: string;
	data?: {
		status: number;
		class: ErrorClass;
		context?: Record< string, string >;
	};
}

export interface Notice {
	id: string;
	kind: 'info' | 'warning' | 'error' | 'success';
	text: string;
	action_label?: string;
	action_route?: string;
	dismissible: boolean;
}

/** Variables the PHP side passes to the app with wp_add_inline_script (window.estasoftSync). */
export interface BootData {
	restUrl: string;
	nonce: string;
	adminUrl: string;
	pluginUrl: string;
	version: string;
	capabilities: { manage: boolean; configure: boolean };
	storeTimezone: string;
	currency: string;
	currencyDecimals: number;
	locale: string;
	wpAdminColorScheme: string;
	routes: string[];
	notices: Notice[];
	encryptionKeyLine: string | null;
	/** Rows per page of the person's Screen Options (25, 50 or 100). Additive: older boot data has none. */
	perPage?: number;
}

/* ---------------------------------------------------------------- endpoint list */

/**
 * GET    /status                               -> Status
 * GET    /queue?...                            -> Paged<QueueItem>             (X-WP-Total headers too)
 * GET    /queue/attention                      -> AttentionGroup[]
 * GET    /queue/{id}                           -> QueueItemDetail
 * POST   /queue/{id}/retry                     -> QueueItem
 * POST   /queue/{id}/skip      {reason}        -> QueueItem
 * POST   /queue/{id}/restore                   -> QueueItem
 * POST   /queue/retry          RetryRequest    -> { retried: number }
 * POST   /queue/bulk           {ids, action: 'skip'|'retry'|'restore', reason?} -> { done: number }
 * POST   /sync/run                             -> { started: true }
 * POST   /pause      {connection_id?, minutes?}-> ConnectionSummary
 * POST   /resume     {connection_id?}          -> ConnectionSummary
 * POST   /connections/{id}/pause  {minutes?}   -> ConnectionSummary   (same as /pause with connection_id)
 * POST   /connections/{id}/resume              -> ConnectionSummary   (same as /resume with connection_id)
 * GET    /connections                          -> ConnectionSummary[]
 * POST   /connections  ConnectionInput         -> Connection
 * GET    /connections/{id}                     -> Connection
 * POST   /connections/{id}  Partial<ConnectionInput> -> Connection
 * DELETE /connections/{id}                     -> { disconnected: true }
 * POST   /connections/{id}/inbound {enabled}  -> { enabled: false } | { enabled: true, secret, url }  (the secret is shown once)
 * POST   /connections/{id}/oauth/start         -> { url }              (SYNC-CFG-004 for connectors that connect with a key)
 * GET    /connections/{id}/oauth/organisations -> { id, name }[]       (SYNC-CFG-004 for connectors that connect with a key)
 * POST   /connections/{id}/oauth/organisation {id} -> Connection        (chooses the organisation, tests, activates; SYNC-CFG-004 for connectors that connect with a key)
 * GET    /oauth/callback?state=&code=&error=   -> 302 to the admin screen with signin=ok|cancelled|failed and connection=<id>: the one stable redirect address of the site, to register in the vendor's app
 * GET    /connections/{id}/oauth/callback?state=&code=&error= -> same, the older address with the connection id (kept for apps registered with it)
 * GET    /exports?connection_id=               -> ExportsResponse
 * POST   /exports {connection_id}              -> ExportFileInfo       (SYNC-EXP-001, SYNC-EXP-002)
 * GET    /exports/{id}/download?part=          -> the file (attachment); part 1, 2, ... are the extra files in `attachments`
 * DELETE /exports/{id}                         -> { deleted: true }
 * POST   /connections/{id}/test                -> ConnectionTestResult
 * POST   /connections/test     ConnectionInput -> ConnectionTestResult  (before saving)
 * GET    /connections/{id}/pause-history       -> not in Phase 1
 * GET    /catalog                              -> CatalogEntry[]
 * (Mapping, tax-map, stock and reconciliation routes accept an optional connection_id; default: the only or first active connection)
 * GET    /mappings/{type}                      -> MappingSet
 * PUT    /mappings/{type}  {rules, note?}      -> MappingSet (saves the draft)
 * GET    /mappings/{type}/versions/{version}   -> MappingVersion (with rules; to view an older version)
 * POST   /mappings/{type}/publish {note?}     -> MappingSet
 * POST   /mappings/{type}/discard              -> MappingSet
 * POST   /mappings/{type}/rollback {version}   -> MappingSet
 * POST   /mappings/preview     MappingPreviewRequest -> MappingPreview
 * GET    /tax-map                              -> TaxMap
 * PUT    /tax-map     {rows: {country, tax_name, rate_percent, system_code}[]} -> TaxMap
 * GET    /products/unmatched?page=             -> Paged<ProductMatch>
 * GET    /products/search?q=&connection_id=    -> { id, ref, name }[]
 * POST   /products/map  {product_id, external_id|null} -> ProductMatch
 * GET    /stock/rules                          -> StockRules
 * PUT    /stock/rules                          -> StockRules
 * GET    /stock/overview                       -> StockOverview
 * POST   /stock/conflicts/{id}/accept          -> { ok: true }
 * POST   /stock/conflicts/{id}/hold            -> { ok: true }
 * GET    /reconciliation                       -> ReconOverview
 * POST   /reconciliation/run  {window_days}    -> ReconRun
 * POST   /reconciliation/differences/{id}/repair {direction: 'wc_to_system'|'system_to_wc'} -> ReconDiff
 * POST   /reconciliation/differences/{id}/ignore {reason} -> ReconDiff
 * PUT    /reconciliation/settings              -> ReconOverview
 * GET    /invoices?status=                     -> Paged<InvoiceRow>
 * GET    /logs?level=&search=&connection_id=&queue_id=&code=&range=&page=&per_page= -> Paged<LogEntry>  (Accept: text/csv for download, same filters)
 *          range: '1h' | '24h' | '7d' | '30d' | 'all' (default all); code: SYNC-XXX-000; queue_id: a queue item
 * GET    /logs/{id}                            -> LogEntryDetail
 * GET    /readiness                            -> Readiness
 * POST   /readiness/run                        -> Readiness
 * GET    /support-report?order_numbers=0|1     -> SupportReport
 * GET    /settings                             -> Settings
 * PUT    /settings                             -> Settings
 * POST   /tools/export                         -> { filename, json }
 * POST   /tools/import {json, confirm}         -> { preview: string[] } | { applied: true, drafts?: number, skipped?: string[] }
 * POST   /tools/clear-logs                     -> { cleared: number }
 * POST   /tools/delete-data {word}             -> { deleted: true }
 * POST   /notifications/test                    -> { sent_to: string[] }  (error SYNC-* when the mail was not accepted)
 * POST   /capacity-test                        -> CapacityResult
 * GET    /onboarding                           -> Onboarding
 * PUT    /onboarding                           -> Onboarding
 * POST   /go-live {mode: 'shadow'|'live'}      -> Onboarding
 * POST   /shadow/start {days?, connection_id?} -> Onboarding            (days 1 to 30, default 7)
 * GET    /shadow/report                        -> ShadowReport
 * POST   /shadow/end                           -> Onboarding
 * GET    /orders/sample                        -> SampleOrder[]
 * POST   /inbound/{connection}                 -> { accepted: true }   (HMAC signed, no cookie)
 */
export {};
