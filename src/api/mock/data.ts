/**
 * The sample world of board S03: Cedar & Stone Supply, a home goods shop in Dubai (invented),
 * Odoo 18 Enterprise, Monday 2 November 2026, 11:40 store time. Every number comes from here, so
 * the screens agree with each other.
 */
import type {
	AttentionGroup,
	Connection,
	InvoiceRow,
	LogEntry,
	LogEntryDetail,
	MappingRule,
	MappingSet,
	Money,
	QueueItem,
	ReconOverview,
	Readiness,
	SampleOrder,
	Settings,
	StockOverview,
	StockRules,
	TaxMap,
} from '../types';

export const NOW = '2026-11-02T07:40:00Z';
export const TIMEZONE = 'Asia/Dubai';
export const CURRENCY = 'AED';

export const aed = ( minor: number ): Money => ( {
	minor,
	currency: CURRENCY,
} );

const iso = ( hhmm: string, day = '2026-11-02' ): string => {
	// Store time (UTC+4) to UTC.
	const [ h, m ] = hhmm.split( ':' ).map( Number );
	const date = new Date( `${ day }T00:00:00Z` );
	date.setUTCHours( h - 4, m, 0, 0 );
	return date.toISOString().replace( '.000Z', 'Z' );
};
export const at = iso;

export function makeConnection(): Connection {
	return {
		id: 12,
		connector: 'odoo',
		label: 'Cedar & Stone Trading LLC',
		type: 'erp',
		status: 'active',
		version: 'Odoo 18 Enterprise',
		address: 'https://erp.cedarstone.example',
		paused_until: null,
		paused_reason: null,
		last_success_at: iso( '11:39' ),
		last_failure_at: iso( '11:31' ),
		failures_in_a_row: 0,
		typical_response_ms: 340,
		credential_expires_at: '2026-11-14T00:00:00Z',
		capabilities: {
			orders: true,
			customers: true,
			refunds: true,
			stock_in: true,
			tracking_in: true,
			prices_in: false,
			invoice_readback: true,
			reconciliation: true,
			shadow_lookup: true,
			webhooks: false,
		},
		company: 'Cedar & Stone Trading LLC',
		warehouses: [
			{
				id: 'WH1',
				name: 'Dubai Main',
				counts_toward_stock: true,
				countries: [ 'AE' ],
			},
			{
				id: 'WH2',
				name: 'Riyadh 3PL',
				counts_toward_stock: true,
				countries: [ 'SA' ],
			},
		],
		settings: {
			retry_delays_seconds: [ 60, 300, 3600, 86400 ],
			jitter: true,
			breaker_threshold: 5,
			probe_interval_minutes: 2,
			requests_per_second: 4,
			concurrency: 2,
			obey_retry_after: true,
		},
		credentials: [
			{
				name: 'address',
				label: 'Odoo address',
				secret: false,
				required: true,
				hint: 'The web address you use to sign in to Odoo.',
				default: '',
				value: 'https://erp.cedarstone.example',
				has_value: true,
			},
			{
				name: 'database',
				label: 'Database',
				secret: false,
				required: true,
				hint: 'Shown on Odoo’s sign-in page.',
				default: '',
				value: 'cedarstone_prod',
				has_value: true,
			},
			{
				name: 'user',
				label: 'Sync user (email)',
				secret: false,
				required: false,
				hint: '',
				default: '',
				value: 'sync-bot@cedarstone.example',
				has_value: true,
			},
			{
				name: 'api_key',
				label: 'API key',
				secret: true,
				required: true,
				hint: 'Saved encrypted. It is never shown again.',
				default: '',
				value: null,
				has_value: true,
			},
		],
		inbound_enabled: false,
		created_at: '2026-10-20T09:00:00Z',
	};
}

interface ItemSeed {
	id: number;
	label: string;
	event: string;
	state: QueueItem[ 'state' ];
	time: string;
	customer?: string;
	total?: number;
	error_code?: string;
	error_message?: string;
	external_ref?: string;
	skip_reason?: string;
	attempt?: number;
	next?: string;
	entity_type?: QueueItem[ 'entity_type' ];
	mode?: QueueItem[ 'mode' ];
}

function item( seed: ItemSeed ): QueueItem {
	return {
		id: seed.id,
		connection_id: 12,
		entity_type: seed.entity_type ?? 'order',
		entity_id: String( seed.id + 5000 ),
		entity_label: seed.label,
		event: seed.event,
		state: seed.state,
		mode: seed.mode ?? 'live',
		attempt: seed.attempt ?? ( seed.state === 'queued' ? 0 : 1 ),
		max_attempts: 5,
		next_attempt_at: seed.next ? iso( seed.next ) : null,
		error_code: seed.error_code ?? null,
		error_message: seed.error_message ?? null,
		external_id: seed.external_ref ? String( seed.id ) : null,
		external_ref: seed.external_ref ?? null,
		external_url: seed.external_ref
			? `https://erp.cedarstone.example/odoo/sales/${ seed.id }`
			: null,
		skip_reason: seed.skip_reason ?? null,
		customer_name: seed.customer ?? null,
		total: seed.total ? aed( seed.total ) : null,
		waiting_for_earlier: false,
		created_at: iso( seed.time ),
		updated_at: iso( seed.time ),
		finished_at: [ 'synced', 'skipped', 'failed' ].includes( seed.state )
			? iso( seed.time )
			: null,
		order_edit_url: `https://cedarstone.example/wp-admin/post.php?post=${ seed.label }&action=edit`,
	};
}

/**
 * 333 items today: 312 synced, 14 waiting (9 queued, 2 syncing, 3 retrying), 5 failed, 2 skipped.
 */
export function makeQueue(): QueueItem[] {
	const named: ItemSeed[] = [
		{
			id: 94,
			label: '10494',
			event: 'created',
			state: 'queued',
			time: '11:40',
		},
		{
			id: 93,
			label: '10493',
			event: 'created',
			state: 'queued',
			time: '11:40',
		},
		{
			id: 92,
			label: '10492',
			event: 'paid',
			state: 'syncing',
			time: '11:40',
			attempt: 1,
		},
		{
			id: 91,
			label: '10491',
			event: 'created',
			state: 'failed',
			time: '11:31',
			customer: 'Faisal Al-Otaibi',
			total: 52300,
			error_code: 'SYNC-VAL-001',
			error_message:
				'Buyer tax number must be 15 digits for Saudi Arabia',
		},
		{
			id: 90,
			label: '10490',
			event: 'created',
			state: 'synced',
			time: '11:39',
			customer: 'Noor Al-Mansouri',
			total: 48615,
			external_ref: 'S00490',
		},
		{
			id: 89,
			label: '10489',
			event: 'created',
			state: 'retrying',
			time: '11:39',
			error_code: 'SYNC-NET-002',
			error_message: 'Odoo answered 503',
			next: '11:41',
		},
		{
			id: 88,
			label: '10488',
			event: 'created',
			state: 'retrying',
			time: '11:38',
			error_code: 'SYNC-RATE-001',
			error_message: 'Odoo asked us to slow down (429)',
			next: '11:41',
		},
		{
			id: 87,
			label: '10487',
			event: 'refund',
			state: 'synced',
			time: '11:36',
			external_ref: 'RINV/2026/0031',
			entity_type: 'refund',
		},
		{
			id: 86,
			label: '10486',
			event: 'created',
			state: 'synced',
			time: '11:34',
			customer: 'Layla K.',
			total: 21200,
			external_ref: 'S00486',
		},
		{
			id: 85,
			label: '10485',
			event: 'created',
			state: 'failed',
			time: '11:22',
			customer: 'Noor Al-Mansouri',
			total: 43800,
			error_code: 'SYNC-MAP-001',
			error_message: 'Product CS-BOWL-04 not found in Odoo',
		},
		{
			id: 84,
			label: '10484',
			event: 'paid',
			state: 'retrying',
			time: '11:30',
			error_code: 'SYNC-NET-001',
			error_message: 'Timed out after 30 s',
			next: '12:30',
			attempt: 3,
		},
		{
			id: 79,
			label: '10479',
			event: 'created',
			state: 'failed',
			time: '10:58',
			customer: 'Hessa Al-Ketbi',
			total: 21900,
			error_code: 'SYNC-MAP-001',
			error_message: 'Product CS-BOWL-04 not found in Odoo',
		},
		{
			id: 74,
			label: '10474',
			event: 'created',
			state: 'failed',
			time: '10:41',
			customer: 'Omar Farouk',
			total: 87600,
			error_code: 'SYNC-MAP-001',
			error_message: 'Product CS-BOWL-04 not found in Odoo',
		},
		{
			id: 68,
			label: '10468',
			event: 'created',
			state: 'failed',
			time: '10:12',
			customer: 'Guest checkout',
			total: 15000,
			error_code: 'SYNC-MAP-003',
			error_message: 'Customer email is missing on order #10468',
		},
		{
			id: 70,
			label: '10470',
			event: 'created',
			state: 'skipped',
			time: '10:05',
			skip_reason: 'Test order',
		},
		{
			id: 66,
			label: '10466',
			event: 'created',
			state: 'skipped',
			time: '09:50',
			skip_reason: 'Duplicate of another order',
		},
	];
	const items = named.map( item );
	// Fill up to the counts of the sample world.
	for ( let n = 0; n < 7; n++ ) {
		items.push(
			item( {
				id: 95 + n,
				label: String( 10495 + n ),
				event: 'created',
				state: 'queued',
				time: '11:40',
			} )
		);
	}
	items.push(
		item( {
			id: 102,
			label: '10502',
			event: 'paid',
			state: 'syncing',
			time: '11:40',
			attempt: 1,
		} )
	);
	for ( let n = 0; n < 309; n++ ) {
		const minutes = 9 * 60 + ( n % 150 );
		const label = String( 10100 + n );
		items.push(
			item( {
				id: 1000 + n,
				label,
				event: 'created',
				state: 'synced',
				time: `${ String( Math.floor( minutes / 60 ) ).padStart( 2, '0' ) }:${ String( minutes % 60 ).padStart( 2, '0' ) }`,
				customer: `Customer ${ n + 1 }`,
				total: 10000 + n * 7,
				external_ref: `S${ String( 100 + n ).padStart( 5, '0' ) }`,
			} )
		);
	}
	return items;
}

export const HOURLY = [ 14, 9, 7, 5, 8, 11, 19, 28, 41, 63, 71, 36 ];

export function makeHourly() {
	return HOURLY.map( ( synced, index ) => ( {
		hour: new Date( Date.UTC( 2026, 10, 1, 20 + index ) )
			.toISOString()
			.replace( '.000Z', 'Z' ),
		synced,
		failed: index === 9 || index === 11 ? ( index === 9 ? 3 : 2 ) : 0,
	} ) );
}

export function makeEvents(): LogEntry[] {
	return [
		{
			id: 9001,
			ts: iso( '11:39' ),
			level: 'info',
			connection_id: 12,
			queue_id: 90,
			entity_ref: 'Order #10490',
			code: null,
			message: 'Order #10490 synced',
			http_status: 200,
			duration_ms: 540,
		},
		{
			id: 9002,
			ts: iso( '11:39' ),
			level: 'warning',
			connection_id: 12,
			queue_id: 89,
			entity_ref: 'Order #10489',
			code: 'SYNC-NET-002',
			message:
				'Order #10489 retrying. Odoo answered 503. Retry 1 of 4 at 11:41',
			http_status: 503,
			duration_ms: 2100,
		},
		{
			id: 9003,
			ts: iso( '11:38' ),
			level: 'warning',
			connection_id: 12,
			queue_id: 88,
			entity_ref: 'Order #10488',
			code: 'SYNC-RATE-001',
			message: 'Order #10488 retrying. Odoo asked us to slow down (429)',
			http_status: 429,
			duration_ms: 180,
		},
		{
			id: 9004,
			ts: iso( '11:31' ),
			level: 'error',
			connection_id: 12,
			queue_id: 91,
			entity_ref: 'Order #10491',
			code: 'SYNC-VAL-001',
			message:
				'Order #10491 failed. Buyer tax number must be 15 digits for Saudi Arabia',
			http_status: null,
			duration_ms: 4,
		},
		{
			id: 9005,
			ts: iso( '11:22' ),
			level: 'error',
			connection_id: 12,
			queue_id: 85,
			entity_ref: 'Order #10485',
			code: 'SYNC-MAP-001',
			message:
				'Order #10485 failed. Product CS-BOWL-04 not found in Odoo',
			http_status: 200,
			duration_ms: 840,
		},
	];
}

export function makeAllLogs(): LogEntryDetail[] {
	const rows: [
		number,
		string,
		LogEntry[ 'level' ],
		string,
		string | null,
		string | null,
		number | null,
		number | null,
		number | null,
	][] = [
		[
			1,
			'11:40:12',
			'info',
			'Sent order to Odoo',
			null,
			'#10492',
			200,
			610,
			92,
		],
		[
			2,
			'11:39:48',
			'info',
			'Order synced as S00490',
			null,
			'#10490',
			200,
			540,
			90,
		],
		[
			3,
			'11:39:20',
			'warning',
			'Odoo answered 503. Retry 1 of 4 at 11:41',
			'SYNC-NET-002',
			'#10489',
			503,
			2100,
			89,
		],
		[
			4,
			'11:38:55',
			'warning',
			'Odoo asked us to slow down. Waiting 120 seconds',
			'SYNC-RATE-001',
			'#10488',
			429,
			180,
			88,
		],
		[
			5,
			'11:36:30',
			'info',
			'Credit note RINV/2026/0031 created',
			null,
			'#10487',
			200,
			720,
			87,
		],
		[
			6,
			'11:31:07',
			'error',
			'Buyer tax number must be 15 digits for Saudi Arabia',
			'SYNC-VAL-001',
			'#10491',
			null,
			4,
			91,
		],
		[
			7,
			'11:30:41',
			'warning',
			'Timed out after 30 seconds. Retry 3 of 4 at 12:30',
			'SYNC-NET-001',
			'#10484',
			null,
			30000,
			84,
		],
		[
			8,
			'11:22:19',
			'error',
			'Product CS-BOWL-04 not found in Odoo',
			'SYNC-MAP-001',
			'#10485',
			200,
			840,
			85,
		],
		[
			9,
			'11:22:01',
			'info',
			'Stock sync finished: 1,204 checked, 18 changed',
			null,
			'Stock',
			200,
			4800,
			null,
		],
	];
	return rows.map(
		( [ id, time, level, message, code, ref, http, ms, queue ] ) => ( {
			id,
			ts: iso( time.slice( 0, 5 ) ).replace(
				':00Z',
				`:${ time.slice( 6, 8 ) }Z`
			),
			level,
			connection_id: 12,
			queue_id: queue,
			entity_ref: ref,
			code,
			message,
			http_status: http,
			duration_ms: ms,
			request_excerpt: queue
				? 'POST /jsonrpc sale.order.create\nreference: "WC-' +
					String( ref ).replace( '#', '' ) +
					'"\npartner: "****"\nlines: 2'
				: null,
			response_excerpt: queue
				? http === 200
					? 'HTTP 200\nerror.message: "' + message + '"'
					: 'HTTP ' + String( http )
				: null,
		} )
	);
}

export function makeAttention( items: QueueItem[] ): AttentionGroup[] {
	const failed = items.filter( ( entry ) => entry.state === 'failed' );
	const groups = new Map< string, AttentionGroup >();
	for ( const entry of failed ) {
		const cause =
			entry.error_code === 'SYNC-MAP-001'
				? 'SKU CS-BOWL-04'
				: entry.error_code === 'SYNC-VAL-001'
					? 'Saudi Arabia'
					: entry.error_code === 'SYNC-MAP-003'
						? 'Guest checkout'
						: '';
		const key = `${ entry.error_code }|${ cause }`;
		const existing = groups.get( key );
		if ( existing ) {
			existing.count += 1;
			existing.item_ids.push( entry.id );
			if ( entry.updated_at < existing.oldest_at ) {
				existing.oldest_at = entry.updated_at;
			}
		} else {
			groups.set( key, {
				error_code: entry.error_code ?? '',
				cause,
				count: 1,
				oldest_at: entry.updated_at,
				item_ids: [ entry.id ],
			} );
		}
	}
	return [ ...groups.values() ].sort( ( a, b ) => b.count - a.count );
}

export const SAMPLE_ORDERS: SampleOrder[] = [
	{
		id: 10490,
		label: '#10490',
		customer: 'Noor Al-Mansouri',
		total: aed( 48615 ),
		created_at: iso( '11:21' ),
	},
	{
		id: 10486,
		label: '#10486',
		customer: 'Layla K.',
		total: aed( 21200 ),
		created_at: iso( '11:12' ),
	},
];

export function sourceOrder( orderId: number ): Record< string, unknown > {
	const noor = orderId === 10490;
	return {
		order: {
			id: orderId,
			number: String( orderId ),
			status: 'processing',
			currency: 'AED',
			total: noor ? 48615 : 21200,
			subtotal: noor ? 43800 : 20190,
			tax_total: noor ? 2315 : 1010,
			shipping_total: noor ? 2500 : 0,
			note: 'Gift wrap please',
			client_ref: `WC-${ orderId }`,
			payment: {
				method_id: 'cod',
				method_title: 'Cash on delivery',
				is_cod: true,
			},
		},
		customer: {
			email: noor ? 'noor@example.com' : 'layla@example.com',
			is_guest: false,
		},
		billing: {
			first_name: noor ? 'Noor' : 'Layla',
			last_name: noor ? 'Al-Mansouri' : 'K.',
			phone: '050 123 4567',
			state: 'Dubai',
			country: 'AE',
			city: 'Dubai',
			address_1: 'Sheikh Zayed Road',
		},
		lines: noor
			? [
					{
						sku: 'CS-BOWL-02',
						name: 'Stoneware bowl set',
						quantity: 2,
						unit_price: 21900,
					},
				]
			: [
					{
						sku: 'CS-MUG-01',
						name: 'Ceramic mug, sand',
						quantity: 4,
						unit_price: 5047,
					},
				],
	};
}

function rule(
	id: string,
	target: string,
	source: string | string[],
	transforms: MappingRule[ 'transforms' ] = [],
	extra: Partial< MappingRule > = {}
): MappingRule {
	return { id, target, source, transforms, required: false, ...extra };
}

export const SOURCE_FIELDS = [
	{
		path: 'order.number',
		label: 'Order number',
		type: 'string',
		group: 'Order',
	},
	{ path: 'order.note', label: 'Order note', type: 'string', group: 'Order' },
	{
		path: 'order.shipping_total',
		label: 'Shipping total',
		type: 'money',
		group: 'Order',
	},
	{
		path: 'order.payment.method_id',
		label: 'Payment method',
		type: 'string',
		group: 'Order',
	},
	{
		path: 'customer.email',
		label: 'Billing email',
		type: 'string',
		group: 'Customer',
	},
	{
		path: 'billing.first_name',
		label: 'Billing first name',
		type: 'string',
		group: 'Billing',
	},
	{
		path: 'billing.last_name',
		label: 'Billing last name',
		type: 'string',
		group: 'Billing',
	},
	{
		path: 'billing.phone',
		label: 'Billing phone',
		type: 'string',
		group: 'Billing',
	},
	{
		path: 'billing.state',
		label: 'Billing state',
		type: 'string',
		group: 'Billing',
	},
	{
		path: 'billing.country',
		label: 'Billing country',
		type: 'string',
		group: 'Billing',
	},
	{
		path: 'order.buyer_tax_number',
		label: 'Buyer tax number',
		type: 'string',
		group: 'Order',
	},
	{ path: 'lines[].sku', label: 'Line SKU', type: 'string', group: 'Lines' },
	{
		path: 'lines[].unit_price',
		label: 'Line price',
		type: 'money',
		group: 'Lines',
	},
];

const TARGET_FIELDS = [
	{
		path: 'client_order_ref',
		label: 'client_order_ref',
		type: 'char',
		required: true,
		group: 'Sale order',
	},
	{
		path: 'partner.email',
		label: 'partner.email',
		type: 'char',
		required: true,
		group: 'Partner',
	},
	{
		path: 'partner.name',
		label: 'partner.name',
		type: 'char',
		required: true,
		group: 'Partner',
	},
	{
		path: 'partner.phone',
		label: 'partner.phone',
		type: 'char',
		required: false,
		group: 'Partner',
	},
	{
		path: 'partner.mobile',
		label: 'partner.mobile',
		type: 'char',
		required: false,
		group: 'Partner',
	},
	{
		path: 'partner.street',
		label: 'partner.street',
		type: 'char',
		required: false,
		group: 'Partner',
	},
	{
		path: 'partner.state_id',
		label: 'partner.state_id',
		type: 'many2one',
		required: false,
		group: 'Partner',
	},
	{
		path: 'partner.vat',
		label: 'partner.vat',
		type: 'char',
		required: false,
		group: 'Partner',
	},
	{
		path: 'order_line[].product',
		label: 'order_line.product',
		type: 'many2one',
		required: true,
		group: 'Lines',
	},
	{
		path: 'order_line[].price_unit',
		label: 'order_line.price_unit',
		type: 'float',
		required: true,
		group: 'Lines',
	},
	{
		path: 'payment_term',
		label: 'payment_term',
		type: 'many2one',
		required: false,
		group: 'Sale order',
	},
	{
		path: 'note',
		label: 'note',
		type: 'text',
		required: false,
		group: 'Sale order',
	},
];

const ORDER_RULES: MappingRule[] = [
	rule(
		'r1',
		'client_order_ref',
		'order.number',
		[ { type: 'prefix', args: { value: 'WC-' } } ],
		{ required: true }
	),
	rule( 'r2', 'partner.email', 'customer.email', [ { type: 'trim' } ], {
		required: true,
		error_code: 'SYNC-MAP-003',
	} ),
	rule(
		'r3',
		'partner.name',
		[ 'billing.first_name', 'billing.last_name' ],
		[ { type: 'join', args: { separator: ' ' } }, { type: 'trim' } ],
		{ required: true }
	),
	rule( 'r4', 'partner.phone', 'billing.phone', [
		{ type: 'phone_e164', args: { default_country: 'AE' } },
	] ),
	rule( 'r5', 'partner.state_id', 'billing.state', [
		{ type: 'iso_state' },
	] ),
	rule( 'r6', 'partner.vat', 'order.buyer_tax_number', [ { type: 'trim' } ] ),
	rule(
		'r7',
		'order_line[].product',
		'lines[].sku',
		[ { type: 'sku_match' } ],
		{ required: true, error_code: 'SYNC-MAP-001' }
	),
	rule( 'r8', 'order_line[].price_unit', 'lines[].unit_price', [
		{ type: 'money_minor' },
	] ),
	rule( 'r9', 'payment_term', 'order.payment.method_id', [
		{ type: 'value_map', args: { map: { cod: 'Pay on delivery' } } },
	] ),
	rule( 'r10', 'note', 'order.note', [ { type: 'trim' } ] ),
];

export function makeMappings(): Record< string, MappingSet > {
	const version = (
		n: number,
		status: 'live' | 'retired' | 'draft',
		day: string,
		rules: MappingRule[] = []
	) => ( {
		version: n,
		status,
		note: null,
		created_by: 'Layla Haddad',
		created_at: `${ day }T10:05:00Z`,
		published_at: status === 'draft' ? null : `${ day }T10:05:00Z`,
		rules,
	} );
	const set = (
		type: MappingSet[ 'type' ],
		rules: MappingRule[],
		liveVersion: number
	): MappingSet => ( {
		type,
		versioned: true,
		live: version( liveVersion, 'live', '2026-10-28', rules ),
		draft: null,
		versions: Array.from( { length: liveVersion }, ( _unused, index ) => {
			const n = liveVersion - index;
			const { rules: _rules, ...rest } = version(
				n,
				n === liveVersion ? 'live' : 'retired',
				n === liveVersion ? '2026-10-28' : '2026-10-24'
			);
			return rest;
		} ),
		target_fields: TARGET_FIELDS,
		source_fields: SOURCE_FIELDS,
	} );
	return {
		orders: set( 'orders', ORDER_RULES, 7 ),
		customers: set(
			'customers',
			[
				rule(
					'c1',
					'partner.email',
					'customer.email',
					[ { type: 'case', args: { mode: 'lower' } } ],
					{ required: true }
				),
				rule(
					'c2',
					'partner.name',
					[ 'billing.first_name', 'billing.last_name' ],
					[ { type: 'join', args: { separator: ' ' } } ]
				),
				rule( 'c3', 'partner.phone', 'billing.phone', [
					{ type: 'phone_e164', args: { default_country: 'AE' } },
				] ),
			],
			3
		),
		refunds: set(
			'refunds',
			[ rule( 'f1', 'note', 'order.note', [ { type: 'trim' } ] ) ],
			2
		),
		stock: set(
			'stock',
			[
				rule( 's1', 'partner.name', 'order.number', [
					{ type: 'trim' },
				] ),
			],
			2
		),
		tracking: set(
			'tracking',
			[ rule( 't1', 'note', 'order.note', [ { type: 'trim' } ] ) ],
			2
		),
		products: set( 'products', [], 1 ),
	};
}

export function makeTaxMap(): TaxMap {
	return {
		rows: [
			{
				country: 'United Arab Emirates',
				tax_name: 'Standard VAT',
				rate_percent: 5,
				system_code: 'VAT 5% (AE)',
				orders_30d: 612,
			},
			{
				country: 'United Arab Emirates',
				tax_name: 'Zero-rated export',
				rate_percent: 0,
				system_code: 'VAT 0% (AE export)',
				orders_30d: 14,
			},
			{
				country: 'Saudi Arabia',
				tax_name: 'Standard VAT',
				rate_percent: 15,
				system_code: 'VAT 15% (SA)',
				orders_30d: 188,
			},
			{
				country: 'Oman',
				tax_name: 'Standard VAT',
				rate_percent: 5,
				system_code: null,
				orders_30d: 3,
			},
		],
		system_codes: [
			{ code: 'VAT 5% (AE)', label: 'VAT 5% (AE)', rate_percent: 5 },
			{
				code: 'VAT 0% (AE export)',
				label: 'VAT 0% (AE export)',
				rate_percent: 0,
			},
			{ code: 'VAT 15% (SA)', label: 'VAT 15% (SA)', rate_percent: 15 },
			{ code: 'VAT 5% (OM)', label: 'VAT 5% (OM)', rate_percent: 5 },
		],
		buyer_tax_rules: [
			{
				country: 'Saudi Arabia',
				digits: 15,
				required_for_business: true,
			},
			{
				country: 'United Arab Emirates',
				digits: null,
				required_for_business: false,
			},
		],
	};
}

export function makeStock(): { rules: StockRules; overview: StockOverview } {
	const warehouses = makeConnection().warehouses;
	return {
		rules: {
			enabled: true,
			interval_minutes: 5,
			safety_buffer: 2,
			subtract_held: true,
			system_wins: true,
			warehouses,
			tracking_enabled: true,
			delivered_becomes: 'completed',
			cod_payment_on_delivery: true,
		},
		overview: {
			last_run_at: iso( '11:35' ),
			matched: 1204,
			unmatched: 7,
			changes: [
				{
					id: 1,
					sku: 'CS-MUG-01',
					product_name: 'Ceramic mug, sand',
					system_quantity: 42,
					shop_before: 44,
					shop_after: 40,
					at: iso( '11:35' ),
				},
				{
					id: 2,
					sku: 'CS-BOWL-02',
					product_name: 'Stoneware bowl set',
					system_quantity: 9,
					shop_before: 11,
					shop_after: 7,
					at: iso( '11:35' ),
				},
				{
					id: 3,
					sku: 'CS-TRAY-03',
					product_name: 'Olive wood tray',
					system_quantity: 2,
					shop_before: 2,
					shop_after: 0,
					at: iso( '11:30' ),
				},
				{
					id: 4,
					sku: 'CS-LAMP-07',
					product_name: 'Brass table lamp',
					system_quantity: 15,
					shop_before: 15,
					shop_after: 13,
					at: iso( '11:30' ),
				},
			],
			conflicts: [
				{
					id: 31,
					sku: 'CS-TRAY-03',
					product_name: 'Olive wood tray',
					system_quantity: 2,
					sold_unsynced: 3,
					detected_at: iso( '11:35' ),
					code: 'SYNC-STK-001',
				},
				{
					id: 32,
					sku: 'CS-LAMP-07',
					product_name: 'Brass table lamp',
					system_quantity: 0,
					sold_unsynced: 2,
					detected_at: iso( '11:35' ),
					code: 'SYNC-STK-001',
				},
				{
					id: 33,
					sku: 'CS-MUG-09',
					product_name: 'Ceramic mug, blue',
					system_quantity: -3,
					sold_unsynced: 0,
					detected_at: iso( '11:35' ),
					code: 'SYNC-STK-001',
				},
			],
			tracking_recent: [
				{
					order_id: 10481,
					order_label: '#10481',
					carrier: 'Aramex',
					number: 'AX-2231-8841',
					at: iso( '11:35' ),
				},
				{
					order_id: 10479,
					order_label: '#10479',
					carrier: 'DHL Express',
					number: 'DH-7719-0042',
					at: iso( '10:58' ),
				},
				{
					order_id: 10471,
					order_label: '#10471',
					carrier: 'Aramex',
					number: 'AX-2231-8802',
					at: iso( '09:20' ),
				},
			],
		},
	};
}

export function makeRecon(): ReconOverview {
	return {
		last_run: {
			id: 5,
			started_at: iso( '02:00' ),
			finished_at: iso( '02:03' ),
			window_days: 7,
			compared: 2104,
			matched: 2101,
			differences: 3,
			repaired: 1,
			status: 'done',
		},
		runs: [
			{
				id: 5,
				started_at: iso( '02:00' ),
				finished_at: iso( '02:03' ),
				window_days: 7,
				compared: 2104,
				matched: 2101,
				differences: 3,
				repaired: 1,
				status: 'done',
			},
		],
		diffs: [
			{
				id: 1,
				run_id: 5,
				order_id: 10311,
				order_label: '#10311',
				type: 'missing_in_system',
				wc_value: 'paid, AED 612.00',
				system_value: 'no sale order',
				status: 'repaired',
				note: null,
				acted_by: 'system',
				acted_at: iso( '02:03' ),
			},
			{
				id: 2,
				run_id: 5,
				order_id: 10377,
				order_label: '#10377',
				type: 'total_differs',
				wc_value: 'AED 438.00',
				system_value: 'AED 437.95',
				status: 'open',
				note: null,
				acted_by: null,
				acted_at: null,
			},
			{
				id: 3,
				run_id: 5,
				order_id: 10402,
				order_label: '#10402',
				type: 'refund_missing',
				wc_value: 'refunded AED 219.00',
				system_value: 'sale order with no credit note',
				status: 'open',
				note: null,
				acted_by: null,
				acted_at: null,
			},
		],
		schedule_time: '02:00',
		window_days: 7,
		tolerance_minor: 1,
		auto_repair_missing: true,
	};
}

export function makeInvoices(): InvoiceRow[] {
	const row = (
		order: number,
		number: string,
		status: InvoiceRow[ 'status' ],
		left: number | null,
		extra: Partial< InvoiceRow > = {}
	): InvoiceRow => ( {
		order_id: order,
		order_label: `#${ order }`,
		number,
		status,
		authority_status: null,
		issued_at: null,
		due_at: null,
		seconds_left: left,
		pdf_url: null,
		reject_reason: null,
		total: aed( 10000 + ( order % 1000 ) ),
		...extra,
	} );
	return [
		row( 10443, 'INV/2026/1181', 'waiting', 20400, {
			issued_at: iso( '17:20', '2026-11-01' ),
		} ),
		row( 10462, 'INV/2026/1190', 'rejected', 32700, {
			reject_reason:
				'The buyer’s address is missing. Add it in Odoo, then ask Odoo to report it again.',
			pdf_url: 'https://erp.cedarstone.example/invoices/1190',
		} ),
		row( 10478, 'INV/2026/1198', 'waiting', 83820, {
			issued_at: iso( '10:57' ),
		} ),
		row( 10470, 'INV/2026/1193', 'reported', null, {
			issued_at: iso( '09:14' ),
		} ),
		row( 10477, 'INV/2026/1197', 'reported', null, {
			issued_at: iso( '10:50' ),
		} ),
		row( 10490, 'INV/2026/1201', 'not_required', null, {
			authority_status:
				'Consumer sales are outside UAE e-invoicing for now.',
		} ),
	];
}

export function makeReadiness(): Readiness {
	const check = (
		id: string,
		title: string,
		status: Readiness[ 'checks' ][ number ][ 'status' ],
		detail: string,
		link: string | null = null,
		label: string | null = null
	) => ( { id, title, status, detail, link, link_label: label } );
	return {
		ran_at: iso( '11:12' ),
		blocked: false,
		checks: [
			check(
				'hpos',
				'Orders use High-Performance Order Storage',
				'passed',
				'Compatible. The plugin reads and writes orders through WooCommerce, never through raw tables.'
			),
			check(
				'cron_running',
				'Background tasks are running',
				'passed',
				'14 waiting, the oldest for 4 minutes. This is healthy.'
			),
			check(
				'cron_visits',
				'Scheduled tasks depend on site visits',
				'worth_fixing',
				'Scheduled tasks only run when someone visits the site. A real cron job keeps timing steady.',
				'/settings/performance',
				'How to set it up'
			),
			check(
				'dup_sku',
				'No duplicate SKUs',
				'passed',
				'1,204 products checked.'
			),
			check(
				'no_sku',
				'6 products have no SKU',
				'worth_fixing',
				'Your system matches products by SKU, so these 6 cannot sync until they have one.',
				'/mapping/products',
				'View the 6 products'
			),
			check(
				'state',
				'2 customers have a free-text state',
				'worth_fixing',
				'Your system needs a state it can match, such as Dubai (AE-DU).'
			),
			check(
				'currency',
				'Currency decimals match',
				'passed',
				'AED uses 2 decimals in WooCommerce and in Odoo.'
			),
			check(
				'charset',
				'The database stores Arabic and other scripts',
				'passed',
				'The database setting that keeps names and addresses intact is in place.'
			),
			check(
				'key',
				'The encryption key is in wp-config.php',
				'passed',
				'Saved credentials cannot be read from a copy of the database.'
			),
			check(
				'php',
				'PHP 8.1 or newer',
				'passed',
				'This site runs PHP 8.3.'
			),
		],
	};
}

export function makeSettings(): Settings {
	return {
		display_time: 'store',
		log_retention_days: 14,
		log_excerpt_bytes: 2048,
		redaction: true,
		payload_cap_bytes: 8192,
		delete_on_uninstall: false,
		runtime_profile: 'standard',
		global_concurrency: 2,
		backlog_threshold: 200,
		admin_bar: true,
		notifications: {
			recipients: [
				'layla@cedarstone.example',
				'ops@cedarstone.example',
			],
			on_paused: true,
			daily_digest: true,
			digest_time: '08:00',
			on_backlog: false,
			on_signin_expired: true,
		},
	};
}
