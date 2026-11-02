/**
 * An in-memory stand-in for the REST API, with the sample world of board S03. It is used by the
 * development build (`ESTASOFT_MOCK=1`) and by the tests. It is never imported by the production bundle.
 */
import type { Transport, TransportRequest, TransportResponse } from '../client';
import type {
	AttentionGroup,
	BootData,
	CatalogEntry,
	Connection,
	ConnectionTestResult,
	DashboardMode,
	ItemState,
	LogEntryDetail,
	MappingRule,
	MappingType,
	Onboarding,
	QueueItem,
	QueueItemDetail,
	ReconOverview,
	Readiness,
	Settings,
	Status,
	StockOverview,
	StockRules,
	TaxMap,
} from '../types';
import { CATALOG } from './catalog';
import {
	aed,
	CURRENCY,
	makeAllLogs,
	makeAttention,
	makeConnection,
	makeEvents,
	makeHourly,
	makeInvoices,
	makeMappings,
	makeQueue,
	makeReadiness,
	makeRecon,
	makeSettings,
	makeStock,
	makeTaxMap,
	NOW,
	SAMPLE_ORDERS,
	sourceOrder,
	TIMEZONE,
} from './data';

export type Scenario = 'normal' | 'first_run' | 'paused' | 'shadow';

export interface MockOptions {
	scenario?: Scenario;
	/** Milliseconds each answer is delayed (0 in tests). */
	latency?: number;
	/** Make routes fail: map from "METHOD /route" prefix to an error code and status. */
	failures?: Record<
		string,
		{
			status: number;
			code: string;
			message: string;
			/** Extra structure for `data.details` (rule problems, the failed test, the wp-config line). */
			details?: Record< string, unknown >;
		}
	>;
}

interface Json {
	[ key: string ]: unknown;
}

interface Answer {
	status: number;
	body: unknown;
	headers?: Record< string, string >;
}

const ok = (
	body: unknown,
	headers: Record< string, string > = {}
): Answer => ( { status: 200, body, headers } );
const fail = (
	status: number,
	code: string,
	message: string,
	extra: Json = {}
): Answer => ( {
	status,
	body: { code, message, data: { status, ...extra } },
} );

function setPath(
	target: Record< string, unknown >,
	path: string,
	value: unknown
): void {
	const parts = path.replace( /\[\]/g, '.0' ).split( '.' );
	let current: Record< string, unknown > = target;
	parts.forEach( ( part, index ) => {
		if ( index === parts.length - 1 ) {
			current[ part ] = value;
			return;
		}
		if ( typeof current[ part ] !== 'object' || current[ part ] === null ) {
			current[ part ] = /^\d+$/.test( parts[ index + 1 ] ) ? [] : {};
		}
		current = current[ part ] as Record< string, unknown >;
	} );
}

function readPath( source: unknown, path: string ): unknown {
	const parts = path.replace( /\[\]/g, '.0' ).split( '.' );
	let current: unknown = source;
	for ( const part of parts ) {
		if ( current === null || typeof current !== 'object' ) {
			return undefined;
		}
		current = ( current as Record< string, unknown > )[ part ];
	}
	return current;
}

function applyRule(
	rule: MappingRule,
	source: Record< string, unknown >
): unknown {
	const paths =
		typeof rule.source === 'string'
			? [ rule.source ]
			: Array.isArray( rule.source )
				? rule.source
				: [];
	let value: unknown =
		typeof rule.source === 'object' && ! Array.isArray( rule.source )
			? rule.source.const
			: paths.length === 1
				? readPath( source, paths[ 0 ] )
				: paths.map( ( path ) => readPath( source, path ) );
	for ( const transform of rule.transforms ) {
		const args = transform.args ?? {};
		const text = ( v: unknown ) =>
			v === undefined || v === null ? '' : String( v );
		switch ( transform.type ) {
			case 'join':
				value = ( Array.isArray( value ) ? value : [ value ] )
					.map( text )
					.join( text( args.separator ) );
				break;
			case 'trim':
				value = text( value ).trim();
				break;
			case 'prefix':
				value = `${ text( args.value ) }${ text( value ) }`;
				break;
			case 'suffix':
				value = `${ text( value ) }${ text( args.value ) }`;
				break;
			case 'case':
				value =
					args.mode === 'upper'
						? text( value ).toUpperCase()
						: text( value ).toLowerCase();
				break;
			case 'phone_e164':
				value = `+971${ text( value ).replace( /\D/g, '' ).replace( /^0/, '' ) }`;
				break;
			case 'iso_state':
				value = text( value ) === 'Dubai' ? 'Dubai (AE-DU)' : value;
				break;
			case 'value_map': {
				const map = ( args.map ?? {} ) as Record< string, string >;
				value = map[ text( value ) ] ?? value;
				break;
			}
			case 'default':
				value = text( value ) === '' ? args.value : value;
				break;
			case 'money_minor':
				value = typeof value === 'number' ? value / 100 : value;
				break;
			default:
				break;
		}
	}
	return value;
}

/**
 * Creates a fresh mock server.
 *
 * @param options Scenario and failure injection.
 * @return The transport for the REST client, plus the state for assertions.
 */
export function createMockServer( options: MockOptions = {} ) {
	const scenario: Scenario = options.scenario ?? 'normal';
	let items: QueueItem[] = makeQueue();
	let connection: Connection | null =
		scenario === 'first_run' ? null : makeConnection();
	const logs: LogEntryDetail[] = makeAllLogs();
	const mappings = makeMappings();
	let taxMap: TaxMap = makeTaxMap();
	const stock = makeStock();
	let stockRules: StockRules = stock.rules;
	const stockOverview: StockOverview = stock.overview;
	let recon: ReconOverview = makeRecon();
	let settings: Settings = makeSettings();
	let readiness: Readiness = makeReadiness();
	let catalog: CatalogEntry[] = CATALOG;
	const unmatched = [
		{
			product_id: 501,
			sku: 'CS-BOWL-04',
			name: 'Stoneware bowl, large',
			status: 'unmatched' as const,
			external_id: null,
			external_ref: null,
			external_name: null,
			orders_blocked: 3,
		},
		...[
			'Olive wood spoon set',
			'Linen napkins, set of 4',
			'Brass candle holder',
			'Stone coaster',
			'Wool throw, sand',
			'Ceramic planter',
		].map( ( name, index ) => ( {
			product_id: 510 + index,
			sku: '',
			name,
			status: 'no_sku' as const,
			external_id: null,
			external_ref: null,
			external_name: null,
			orders_blocked: 0,
		} ) ),
	];
	let onboarding: Onboarding = {
		step: 1,
		completed: scenario !== 'first_run',
		connector: scenario === 'first_run' ? null : 'odoo',
		answers: {},
		shadow_started_at:
			scenario === 'shadow' ? '2026-10-30T07:00:00Z' : null,
		shadow_ends_at: scenario === 'shadow' ? '2026-11-06T07:00:00Z' : null,
	};
	if ( connection && scenario === 'paused' ) {
		connection = {
			...connection,
			status: 'paused_circuit',
			paused_reason: 'circuit',
			paused_until: '2026-11-02T08:07:00Z',
			last_success_at: '2026-11-02T07:48:00Z',
			failures_in_a_row: 5,
		};
	}
	let paused = scenario === 'paused';
	const requests: {
		method: string;
		route: string;
		body: unknown;
		headers: Record< string, string >;
	}[] = [];

	const counts = (): Record< ItemState, number > => {
		const out: Record< ItemState, number > = {
			queued: 0,
			syncing: 0,
			synced: 0,
			retrying: 0,
			failed: 0,
			skipped: 0,
		};
		items.forEach( ( entry ) => {
			out[ entry.state ] += 1;
		} );
		return out;
	};

	const mode = (): DashboardMode =>
		scenario === 'first_run' && ! onboarding.completed
			? 'first_run'
			: scenario === 'shadow' && onboarding.shadow_started_at
				? 'shadow'
				: paused
					? 'paused'
					: 'normal';

	const status = (): Status => {
		const by = counts();
		const waiting = by.queued + by.syncing + by.retrying;
		const oldest = items
			.filter( ( entry ) =>
				[ 'queued', 'syncing', 'retrying' ].includes( entry.state )
			)
			.sort( ( a, b ) =>
				a.created_at.localeCompare( b.created_at )
			)[ 0 ];
		const current = mode();
		return {
			mode: current,
			now: NOW,
			store_timezone: TIMEZONE,
			currency: CURRENCY,
			synced_today: by.synced,
			waiting,
			needs_attention: by.failed,
			skipped: by.skipped,
			oldest_waiting_at: oldest?.created_at ?? null,
			oldest_waiting_order: oldest ? `#${ oldest.entity_label }` : null,
			by_state: by,
			hourly: makeHourly(),
			events: makeEvents(),
			connection: connection
				? ( {
						...connection,
						credentials: undefined,
						warehouses: undefined,
						settings: undefined,
						company: undefined,
						inbound_enabled: undefined,
						created_at: undefined,
					} as unknown as Status[ 'connection' ] )
				: null,
			shadow:
				current === 'shadow'
					? {
							started_at: '2026-10-30T07:00:00Z',
							ends_at: '2026-11-06T07:00:00Z',
							day: 3,
							of_days: 7,
							items: 912,
							matches: 886,
							differs: 14,
							not_found: 12,
						}
					: null,
			encryption_key_missing: false,
			cron_on_visits: true,
		};
	};

	const matches = ( entry: QueueItem, search: string ): boolean => {
		const needle = search.toLowerCase();
		return [
			entry.entity_label,
			entry.customer_name,
			entry.error_message,
			entry.external_ref,
			entry.error_code,
		].some( ( field ) => field?.toLowerCase().includes( needle ) );
	};

	const page = < T >(
		list: T[],
		query: Record< string, string >,
		defaultPerPage = 25
	): Answer => {
		const perPage =
			Number( query.per_page ?? defaultPerPage ) || defaultPerPage;
		const current = Math.max( 1, Number( query.page ?? 1 ) || 1 );
		const totalPages = Math.max( 1, Math.ceil( list.length / perPage ) );
		return ok(
			{
				items: list.slice(
					( current - 1 ) * perPage,
					current * perPage
				),
				total: list.length,
				total_pages: totalPages,
				page: current,
				per_page: perPage,
			},
			{
				'X-WP-Total': String( list.length ),
				'X-WP-TotalPages': String( totalPages ),
			}
		);
	};

	// Like the server: an action only changes items in a state that allows it, and says how many it did.
	const FROM: Record< 'retry' | 'skip' | 'restore', string[] > = {
		retry: [ 'failed', 'retrying' ],
		skip: [ 'queued', 'retrying', 'failed' ],
		restore: [ 'skipped' ],
	};
	const touch = (
		ids: number[],
		change: ( entry: QueueItem ) => QueueItem,
		action: 'retry' | 'skip' | 'restore'
	): number => {
		let done = 0;
		items = items.map( ( entry ) => {
			if (
				ids.includes( entry.id ) &&
				FROM[ action ].includes( entry.state )
			) {
				done += 1;
				return change( entry );
			}
			return entry;
		} );
		return done;
	};
	const refuse = (
		id: number,
		action: 'retry' | 'skip' | 'restore'
	): Answer | null => {
		const entry = items.find( ( candidate ) => candidate.id === id );
		if ( ! entry ) {
			return fail(
				404,
				'rest_not_found',
				'That queue item does not exist.'
			);
		}
		if ( FROM[ action ].includes( entry.state ) ) {
			return null;
		}
		return action === 'retry'
			? fail(
					409,
					'estasoft_sync_cannot_retry',
					'Only failed or retrying orders can be retried.'
				)
			: action === 'skip'
				? fail(
						409,
						'estasoft_sync_cannot_skip',
						'This item has already finished and cannot be skipped.'
					)
				: fail(
						409,
						'estasoft_sync_cannot_restore',
						'Only skipped orders can be restored.'
					);
	};
	const requeue = ( entry: QueueItem ): QueueItem => ( {
		...entry,
		state: 'queued',
		error_code: null,
		error_message: null,
		next_attempt_at: null,
		attempt: 0,
		updated_at: NOW,
		finished_at: null,
	} );
	const skip =
		( reason: string ) =>
		( entry: QueueItem ): QueueItem => ( {
			...entry,
			state: 'skipped',
			skip_reason: reason,
			updated_at: NOW,
			finished_at: NOW,
		} );

	const testInput = ( input: Json ): ConnectionTestResult => {
		const config = ( input.config ?? {} ) as Record< string, string >;
		const secrets = ( input.secrets ?? {} ) as Record< string, string >;
		const base: ConnectionTestResult = {
			ok: true,
			version: 'Odoo 18 Enterprise',
			company: 'Cedar & Stone Trading LLC',
			warehouses: [
				{ id: 'WH1', name: 'Dubai Main' },
				{ id: 'WH2', name: 'Riyadh 3PL' },
			],
			product_count: 1204,
			permissions: {
				read_products: true,
				create_customers: true,
				create_sale_orders: true,
			},
			missing_permissions: [],
			error: null,
		};
		const error = (
			code: string,
			message: string
		): ConnectionTestResult => ( {
			...base,
			ok: false,
			version: '',
			company: '',
			warehouses: [],
			product_count: null,
			permissions: {},
			error: {
				code,
				message,
				data: { status: 400, class: 'needs_person' },
			},
		} );
		if ( secrets.api_key === 'wrong' ) {
			return error(
				'SYNC-AUTH-001',
				'Odoo refused the sign-in. Check the user and the API key.'
			);
		}
		if ( ( config.address ?? '' ).includes( 'down' ) ) {
			return error(
				'SYNC-NET-001',
				'Couldn’t reach Odoo, or it didn’t answer within 30 seconds. Check the address.'
			);
		}
		if ( config.database === 'nodb' ) {
			return error(
				'SYNC-CFG-003',
				'Odoo has no database called “nodb”. Check the name on Odoo’s sign-in page.'
			);
		}
		if ( secrets.api_key === 'noaccess' ) {
			return {
				...base,
				permissions: { ...base.permissions, create_sale_orders: false },
				missing_permissions: [ 'create sale orders' ],
			};
		}
		return base;
	};

	const previewFor = (
		type: MappingType,
		orderId: number,
		useDraft: boolean
	) => {
		const set = mappings[ type ];
		const rules =
			( useDraft && set.draft ? set.draft : set.live )?.rules ?? [];
		const source = sourceOrder( orderId );
		const target: Record< string, unknown > = {};
		const changed: string[] = [];
		const errors: {
			code: string;
			message: string;
			data?: { status: number; class: 'needs_person' };
		}[] = [];
		rules.forEach( ( rule ) => {
			const value = applyRule( rule, source );
			setPath( target, rule.target, value );
			if (
				rule.transforms.some(
					( transform ) =>
						transform.type !== 'trim' && transform.type !== 'join'
				)
			) {
				changed.push( rule.target.replace( /\[\]/g, '[0]' ) );
			}
			if ( rule.required && ( value === undefined || value === '' ) ) {
				errors.push( {
					code: rule.error_code ?? 'SYNC-PRV-001',
					message: `Required field ${ rule.target } is empty.`,
				} );
			}
		} );
		const sku = readPath( source, 'lines[].sku' );
		const lookups = [
			{
				label: `SKU ${ String( sku ) }`,
				found: sku !== 'CS-LAMP-07',
				detail:
					sku === 'CS-LAMP-07'
						? 'Not found in Odoo'
						: 'Found in Odoo',
			},
		];
		if ( sku === 'CS-LAMP-07' ) {
			errors.push( {
				code: 'SYNC-MAP-001',
				message: 'Product CS-LAMP-07 was not found in Odoo.',
				data: { status: 422, class: 'needs_person' },
			} );
		}
		return {
			source,
			target,
			changed_paths: changed,
			rule_notes: changed
				.slice( 0, 2 )
				.map( ( path ) => ( { path, note: 'rule' } ) ),
			lookups,
			errors,
		};
	};

	type Route = [
		string,
		RegExp,
		( match: RegExpMatchArray, req: TransportRequest ) => Answer,
	];
	const body = ( req: TransportRequest ): Json => ( req.body ?? {} ) as Json;

	const routes: Route[] = [
		[ 'GET', /^\/status$/, () => ok( status() ) ],
		[
			'GET',
			/^\/queue$/,
			( _m, req ) => {
				const q = req.query;
				let list = items.filter( () => q.day !== 'yesterday' );
				if ( q.state === 'waiting' ) {
					list = list.filter( ( entry ) =>
						[ 'queued', 'syncing', 'retrying' ].includes(
							entry.state
						)
					);
				} else if ( q.state && q.state !== 'all' ) {
					list = list.filter( ( entry ) => entry.state === q.state );
				}
				if ( q.entity_type ) {
					list = list.filter(
						( entry ) => entry.entity_type === q.entity_type
					);
				}
				if ( q.error_code ) {
					list = list.filter(
						( entry ) => entry.error_code === q.error_code
					);
				}
				if ( q.search ) {
					list = list.filter( ( entry ) =>
						matches( entry, q.search )
					);
				}
				list = [ ...list ].sort(
					( a, b ) =>
						b.updated_at.localeCompare( a.updated_at ) ||
						b.id - a.id
				);
				return page( list, q );
			},
		],
		[
			'GET',
			/^\/queue\/attention$/,
			() => ok( makeAttention( items ) as AttentionGroup[] ),
		],
		[
			'GET',
			/^\/queue\/(\d+)$/,
			( m ) => {
				const entry = items.find(
					( candidate ) => candidate.id === Number( m[ 1 ] )
				);
				if ( ! entry ) {
					return fail(
						404,
						'rest_not_found',
						'That queue item does not exist.'
					);
				}
				const detail: QueueItemDetail = {
					...entry,
					idempotency_key:
						'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
					partition_key: `order:${ entry.entity_id }`,
					mapping_version: 7,
					timeline: [
						{
							at: entry.created_at,
							level: 'info',
							code: null,
							message: 'Order placed in WooCommerce',
						},
						{
							at: entry.created_at,
							level: 'info',
							code: null,
							message: 'Added to the queue',
						},
						...( entry.error_code
							? [
									{
										at: entry.updated_at,
										level: 'error' as const,
										code: entry.error_code,
										message:
											entry.error_message ?? 'Failed',
									},
								]
							: [] ),
					],
					request_excerpt:
						'POST /jsonrpc sale.order.create\nclient_order_ref: "WC-' +
						entry.entity_label +
						'"\npartner: matched by email, 1 found',
					response_excerpt: entry.error_message
						? '{ "error": { "code": 200, "message": "' +
							entry.error_message +
							'" } }'
						: null,
					log_id: 8,
					siblings: [
						{
							id: entry.id,
							event: entry.event,
							state: entry.state,
							error_code: entry.error_code,
							created_at: entry.created_at,
						},
					],
				};
				return ok( detail );
			},
		],
		[
			'POST',
			/^\/queue\/(\d+)\/retry$/,
			( m ) => {
				const refused = refuse( Number( m[ 1 ] ), 'retry' );
				if ( refused ) {
					return refused;
				}
				touch( [ Number( m[ 1 ] ) ], requeue, 'retry' );
				return ok(
					items.find( ( entry ) => entry.id === Number( m[ 1 ] ) )
				);
			},
		],
		[
			'POST',
			/^\/queue\/(\d+)\/skip$/,
			( m, req ) => {
				const refused = refuse( Number( m[ 1 ] ), 'skip' );
				if ( refused ) {
					return refused;
				}
				touch(
					[ Number( m[ 1 ] ) ],
					skip( String( body( req ).reason ?? '' ) ),
					'skip'
				);
				return ok(
					items.find( ( entry ) => entry.id === Number( m[ 1 ] ) )
				);
			},
		],
		[
			'POST',
			/^\/queue\/(\d+)\/restore$/,
			( m ) => {
				const refused = refuse( Number( m[ 1 ] ), 'restore' );
				if ( refused ) {
					return refused;
				}
				touch( [ Number( m[ 1 ] ) ], requeue, 'restore' );
				return ok(
					items.find( ( entry ) => entry.id === Number( m[ 1 ] ) )
				);
			},
		],
		[
			'POST',
			/^\/queue\/retry$/,
			( _m, req ) => {
				const input = body( req ) as {
					ids?: number[];
					error_code?: string;
					cause?: string;
				};
				const ids =
					input.ids ??
					items
						.filter(
							( entry ) =>
								entry.state === 'failed' &&
								entry.error_code === input.error_code
						)
						.map( ( entry ) => entry.id );
				return ok( { retried: touch( ids, requeue, 'retry' ) } );
			},
		],
		[
			'POST',
			/^\/queue\/bulk$/,
			( _m, req ) => {
				const input = body( req ) as {
					ids: number[];
					action: 'skip' | 'retry' | 'restore';
					reason?: string;
				};
				const change =
					input.action === 'skip'
						? skip( input.reason ?? '' )
						: requeue;
				return ok( { done: touch( input.ids, change, input.action ) } );
			},
		],
		[ 'POST', /^\/sync\/run$/, () => ok( { started: true } ) ],
		[
			'POST',
			/^\/pause$/,
			( _m, req ) => {
				paused = true;
				const minutes = Number( body( req ).minutes ?? 0 );
				connection = connection
					? {
							...connection,
							status: 'paused',
							paused_reason: 'person',
							paused_until: minutes
								? new Date(
										new Date( NOW ).getTime() +
											minutes * 60000
									)
										.toISOString()
										.replace( '.000Z', 'Z' )
								: null,
						}
					: connection;
				return ok( connection );
			},
		],
		[
			'POST',
			/^\/resume$/,
			() => {
				paused = false;
				connection = connection
					? {
							...connection,
							status: 'active',
							paused_reason: null,
							paused_until: null,
							failures_in_a_row: 0,
						}
					: connection;
				return ok( connection );
			},
		],
		[
			'GET',
			/^\/connections$/,
			() => ok( connection ? [ status().connection ] : [] ),
		],
		[
			'POST',
			/^\/connections\/test$/,
			( _m, req ) => ok( testInput( body( req ) ) ),
		],
		[
			'POST',
			/^\/connections$/,
			( _m, req ) => {
				const input = body( req ) as {
					config?: Record< string, string >;
				};
				connection = {
					...makeConnection(),
					address:
						input.config?.address ??
						'https://erp.cedarstone.example',
					credential_expires_at: null,
				};
				return ok( connection );
			},
		],
		[
			'GET',
			/^\/connections\/(\d+)$/,
			() =>
				connection
					? ok( connection )
					: fail( 404, 'rest_not_found', 'No such connection.' ),
		],
		[
			'POST',
			/^\/connections\/(\d+)\/test$/,
			() => ok( testInput( { config: {}, secrets: {} } ) ),
		],
		[
			'POST',
			/^\/connections\/(\d+)$/,
			( _m, req ) => {
				if ( ! connection ) {
					return fail( 404, 'rest_not_found', 'No such connection.' );
				}
				const input = body( req ) as {
					settings?: Partial< Connection[ 'settings' ] >;
					config?: Record< string, string >;
					secrets?: Record< string, string >;
				};
				connection = {
					...connection,
					settings: {
						...connection.settings,
						...( input.settings ?? {} ),
					},
					credential_expires_at: input.secrets
						? '2027-02-02T00:00:00Z'
						: connection.credential_expires_at,
					credentials: connection.credentials.map( ( field ) =>
						input.config && field.name in input.config
							? { ...field, value: input.config[ field.name ] }
							: field
					),
				};
				return ok( connection );
			},
		],
		[
			'DELETE',
			/^\/connections\/(\d+)$/,
			() => {
				connection = null;
				return ok( { disconnected: true } );
			},
		],
		[ 'GET', /^\/catalog$/, () => ok( catalog ) ],
		[
			'GET',
			/^\/mappings\/(\w+)$/,
			( m ) =>
				mappings[ m[ 1 ] ]
					? ok( mappings[ m[ 1 ] ] )
					: fail( 404, 'rest_not_found', 'Unknown mapping type.' ),
		],
		[
			'GET',
			/^\/mappings\/(\w+)\/versions\/(\d+)$/,
			( m ) => {
				const set = mappings[ m[ 1 ] ];
				const number = Number( m[ 2 ] );
				const meta = set?.versions.find(
					( version ) => version.version === number
				);
				if ( ! set || ! meta ) {
					return fail(
						404,
						'rest_not_found',
						'That version does not exist.'
					);
				}
				// Older versions keep their rules; the sample world gives them the live rules.
				const rules =
					set.live?.version === number
						? set.live.rules
						: ( set.live?.rules ?? [] ).slice( 0, -1 );
				return ok( { ...meta, rules } );
			},
		],
		[
			'PUT',
			/^\/mappings\/(\w+)$/,
			( m, req ) => {
				const set = mappings[ m[ 1 ] ];
				const input = body( req ) as {
					rules: MappingRule[];
					note?: string;
				};
				const draftVersion =
					Math.max(
						...set.versions.map( ( version ) => version.version )
					) + 1;
				set.draft = {
					version: draftVersion,
					status: 'draft',
					note: input.note ?? null,
					created_by: 'Layla Haddad',
					created_at: NOW,
					published_at: null,
					rules: input.rules,
				};
				return ok( set );
			},
		],
		[
			'POST',
			/^\/mappings\/(\w+)\/publish$/,
			( m, req ) => {
				const set = mappings[ m[ 1 ] ];
				if ( ! set.draft ) {
					return fail(
						409,
						'SYNC-CFG-001',
						'There is no draft to publish.'
					);
				}
				const published = {
					...set.draft,
					status: 'live' as const,
					note:
						( body( req ).note as string | undefined ) ??
						set.draft.note,
					published_at: NOW,
				};
				const previous = set.live
					? [ { ...set.live, status: 'retired' as const } ]
					: [];
				const { rules: _liveRules, ...liveMeta } = published;
				set.versions = [
					liveMeta,
					...set.versions.map( ( version ) =>
						version.status === 'live'
							? { ...version, status: 'retired' as const }
							: version
					),
				];
				set.live = published;
				set.draft = null;
				void previous;
				return ok( set );
			},
		],
		[
			'POST',
			/^\/mappings\/(\w+)\/discard$/,
			( m ) => {
				mappings[ m[ 1 ] ].draft = null;
				return ok( mappings[ m[ 1 ] ] );
			},
		],
		[
			'POST',
			/^\/mappings\/(\w+)\/rollback$/,
			( m, req ) => {
				const set = mappings[ m[ 1 ] ];
				const version = Number( body( req ).version );
				const next =
					Math.max(
						...set.versions.map( ( entry ) => entry.version )
					) + 1;
				const meta = {
					version: next,
					status: 'live' as const,
					note: `Rolled back to version ${ version }`,
					created_by: 'Layla Haddad',
					created_at: NOW,
					published_at: NOW,
				};
				set.versions = [
					meta,
					...set.versions.map( ( entry ) =>
						entry.status === 'live'
							? { ...entry, status: 'retired' as const }
							: entry
					),
				];
				set.live = { ...meta, rules: set.live?.rules ?? [] };
				return ok( set );
			},
		],
		[
			'POST',
			/^\/mappings\/preview$/,
			( _m, req ) => {
				const input = body( req ) as {
					type: MappingType;
					order_id: number;
					use_draft: boolean;
				};
				return ok(
					previewFor( input.type, input.order_id, input.use_draft )
				);
			},
		],
		[ 'GET', /^\/tax-map$/, () => ok( taxMap ) ],
		[
			'PUT',
			/^\/tax-map$/,
			( _m, req ) => {
				const rows =
					( body( req ).rows as {
						country: string;
						tax_name: string;
						rate_percent: number;
						system_code: string | null;
					}[] ) ?? [];
				taxMap = {
					...taxMap,
					rows: taxMap.rows.map( ( row ) => ( {
						...row,
						system_code:
							rows.find(
								( r ) =>
									r.country === row.country &&
									r.tax_name === row.tax_name &&
									r.rate_percent === row.rate_percent
							)?.system_code ?? row.system_code,
					} ) ),
				};
				return ok( taxMap );
			},
		],
		[
			'GET',
			/^\/products\/unmatched$/,
			( _m, req ) => page( unmatched, req.query ),
		],
		[
			'GET',
			/^\/products\/search$/,
			( _m, req ) => {
				const all = [
					{
						id: 'p1',
						ref: 'CS-BOWL-04L',
						name: 'Stoneware bowl, large',
					},
					{
						id: 'p2',
						ref: 'CS-BOWL-04M',
						name: 'Stoneware bowl, medium',
					},
				];
				const q = ( req.query.q ?? '' ).toLowerCase();
				return ok(
					all.filter(
						( hit ) =>
							hit.name.toLowerCase().includes( q ) ||
							hit.ref.toLowerCase().includes( q ) ||
							q.includes( 'cs-bowl' ) ||
							q.includes( 'bowl' )
					)
				);
			},
		],
		[
			'POST',
			/^\/products\/map$/,
			( _m, req ) => {
				const input = body( req ) as {
					product_id: number;
					external_id: string | null;
				};
				const index = unmatched.findIndex(
					( product ) => product.product_id === input.product_id
				);
				const found = unmatched[ index ];
				if ( index >= 0 ) {
					unmatched.splice( index, 1 );
				}
				return ok( {
					...found,
					status: 'override',
					external_id: input.external_id,
				} );
			},
		],
		[ 'GET', /^\/stock\/rules$/, () => ok( stockRules ) ],
		[
			'PUT',
			/^\/stock\/rules$/,
			( _m, req ) => {
				const input = body( req ) as Partial< StockRules > & {
					warehouses?: { id: string; counts_toward_stock: boolean }[];
				};
				stockRules = {
					...stockRules,
					...input,
					warehouses: stockRules.warehouses.map( ( warehouse ) => ( {
						...warehouse,
						counts_toward_stock:
							input.warehouses?.find(
								( w ) => w.id === warehouse.id
							)?.counts_toward_stock ??
							warehouse.counts_toward_stock,
					} ) ),
					system_wins: true,
				};
				return ok( stockRules );
			},
		],
		[ 'GET', /^\/stock\/overview$/, () => ok( stockOverview ) ],
		[
			'POST',
			/^\/stock\/conflicts\/(\d+)\/(accept|hold)$/,
			( m ) => {
				if ( m[ 2 ] === 'accept' ) {
					stockOverview.conflicts = stockOverview.conflicts.filter(
						( conflict ) => conflict.id !== Number( m[ 1 ] )
					);
				}
				return ok( { ok: true } );
			},
		],
		[ 'GET', /^\/reconciliation$/, () => ok( recon ) ],
		[
			'POST',
			/^\/reconciliation\/run$/,
			( _m, req ) => {
				const run = {
					id: 6,
					started_at: NOW,
					finished_at: null,
					window_days: Number( body( req ).window_days ?? 7 ),
					compared: 0,
					matched: 0,
					differences: 0,
					repaired: 0,
					status: 'running' as const,
				};
				recon = { ...recon, runs: [ run, ...recon.runs ] };
				return ok( run );
			},
		],
		[
			'POST',
			/^\/reconciliation\/differences\/(\d+)\/repair$/,
			( m ) => {
				recon = {
					...recon,
					diffs: recon.diffs.map( ( diff ) =>
						diff.id === Number( m[ 1 ] )
							? {
									...diff,
									status: 'repaired' as const,
									acted_by: 'Layla Haddad',
									acted_at: NOW,
								}
							: diff
					),
				};
				return ok(
					recon.diffs.find( ( diff ) => diff.id === Number( m[ 1 ] ) )
				);
			},
		],
		[
			'POST',
			/^\/reconciliation\/differences\/(\d+)\/ignore$/,
			( m, req ) => {
				recon = {
					...recon,
					diffs: recon.diffs.map( ( diff ) =>
						diff.id === Number( m[ 1 ] )
							? {
									...diff,
									status: 'ignored' as const,
									note: String( body( req ).reason ?? '' ),
									acted_by: 'Layla Haddad',
									acted_at: NOW,
								}
							: diff
					),
				};
				return ok(
					recon.diffs.find( ( diff ) => diff.id === Number( m[ 1 ] ) )
				);
			},
		],
		[
			'PUT',
			/^\/reconciliation\/settings$/,
			( _m, req ) => {
				recon = {
					...recon,
					...( body( req ) as Partial< ReconOverview > ),
				};
				return ok( recon );
			},
		],
		[
			'GET',
			/^\/invoices$/,
			( _m, req ) => {
				const all = makeInvoices();
				return page(
					req.query.status
						? all.filter(
								( row ) => row.status === req.query.status
							)
						: all,
					req.query
				);
			},
		],
		[
			'GET',
			/^\/logs$/,
			( _m, req ) => {
				const q = req.query;
				// The real route validates `range` against this list and answers 400 otherwise.
				if (
					q.range !== undefined &&
					! [ '1h', '24h', '7d', '30d', 'all' ].includes( q.range )
				) {
					return fail(
						400,
						'rest_invalid_param',
						'Invalid parameter(s): range'
					);
				}
				let list = logs.filter(
					( entry ) =>
						( q.level ? entry.level === q.level : true ) &&
						( q.search
							? (
									entry.message +
									( entry.entity_ref ?? '' ) +
									( entry.code ?? '' )
								)
									.toLowerCase()
									.includes( q.search.toLowerCase() )
							: true )
				);
				list = [ ...list ].sort( ( a, b ) =>
					b.ts.localeCompare( a.ts )
				);
				if ( req.headers.Accept === 'text/csv' ) {
					return {
						status: 200,
						body: [
							'time,level,code,message',
							...list.map(
								( entry ) =>
									`${ entry.ts },${ entry.level },${ entry.code ?? '' },"${ entry.message }"`
							),
						].join( '\n' ),
						headers: { 'Content-Type': 'text/csv' },
					};
				}
				return page( list, q );
			},
		],
		[
			'GET',
			/^\/logs\/(\d+)$/,
			( m ) => {
				const entry = logs.find(
					( candidate ) => candidate.id === Number( m[ 1 ] )
				);
				return entry
					? ok( entry )
					: fail(
							404,
							'rest_not_found',
							'That log entry does not exist.'
						);
			},
		],
		[ 'GET', /^\/readiness$/, () => ok( readiness ) ],
		[
			'POST',
			/^\/readiness\/run$/,
			() => {
				readiness = { ...readiness, ran_at: NOW };
				return ok( readiness );
			},
		],
		[
			'GET',
			/^\/support-report$/,
			( _m, req ) =>
				ok( {
					text: `Estasoft Enterprise ERP/CRM Sync Engine 0.1.0\nWordPress 6.x, WooCommerce 10.x, PHP 8.3, HPOS on\nQueue: 9 queued, 2 syncing, 3 retrying, 5 failed\n${ req.query.order_numbers === '1' ? 'Orders: #10491, #10485, #10468\n' : '' }Last 20 errors (no names, emails or addresses): ...`,
					generated_at: NOW,
					includes_order_numbers: req.query.order_numbers === '1',
				} ),
		],
		[ 'GET', /^\/settings$/, () => ok( settings ) ],
		[
			'PUT',
			/^\/settings$/,
			( _m, req ) => {
				const input = body( req ) as Partial< Settings >;
				if (
					input.global_concurrency !== undefined &&
					( input.global_concurrency < 1 ||
						input.global_concurrency > 10 )
				) {
					return fail(
						400,
						'rest_invalid_param',
						'Invalid parameter(s): global_concurrency',
						{
							params: {
								global_concurrency:
									'Enter a number from 1 to 10.',
							},
						}
					);
				}
				settings = { ...settings, ...input };
				return ok( settings );
			},
		],
		[
			'POST',
			/^\/tools\/export$/,
			() =>
				ok( {
					filename: 'estasoft-sync-settings.json',
					json: JSON.stringify( {
						settings,
						mappings: Object.keys( mappings ),
					} ),
				} ),
		],
		[
			'POST',
			/^\/tools\/import$/,
			( _m, req ) => {
				const input = body( req ) as { json: string; confirm: boolean };
				try {
					JSON.parse( input.json );
				} catch {
					return fail(
						400,
						'rest_invalid_param',
						'The file is not a settings export.'
					);
				}
				return input.confirm
					? ok( { applied: true } )
					: ok( {
							preview: [
								'Mapping, orders: New draft version 8. Not live.',
								'Limits: Speed limit 4 to 3 per second. Applies now.',
							],
						} );
			},
		],
		[ 'POST', /^\/tools\/clear-logs$/, () => ok( { cleared: 21300 } ) ],
		[
			'POST',
			/^\/tools\/delete-data$/,
			( _m, req ) =>
				body( req ).word === 'DELETE'
					? ok( { deleted: true } )
					: fail(
							400,
							'rest_invalid_param',
							'Type DELETE to confirm.'
						),
		],
		[
			'POST',
			/^\/capacity-test$/,
			() =>
				ok( {
					items_per_minute: 1180,
					ran_at: NOW,
					items: 500,
					seconds: 25,
					verdict:
						'At this speed the Standard runtime is more than enough for your busiest hour so far (71 orders).',
				} ),
		],
		[ 'GET', /^\/onboarding$/, () => ok( onboarding ) ],
		[
			'PUT',
			/^\/onboarding$/,
			( _m, req ) => {
				const input = body( req ) as Partial< Onboarding >;
				onboarding = {
					...onboarding,
					...input,
					answers: {
						...onboarding.answers,
						...( input.answers ?? {} ),
					},
				};
				return ok( onboarding );
			},
		],
		[
			'POST',
			/^\/go-live$/,
			( _m, req ) => {
				const live = body( req ).mode === 'live';
				onboarding = {
					...onboarding,
					completed: true,
					shadow_started_at: live ? null : NOW,
					shadow_ends_at: live ? null : '2026-11-09T07:40:00Z',
				};
				return ok( onboarding );
			},
		],
		[
			'GET',
			/^\/shadow\/report$/,
			() =>
				ok( {
					info: {
						started_at: '2026-10-30T07:00:00Z',
						ends_at: '2026-11-06T07:00:00Z',
						day: 3,
						of_days: 7,
						items: 912,
						matches: 886,
						differs: 14,
						not_found: 12,
					},
					rows: [
						...[ 10480, 10481, 10482, 10483, 10484, 10485 ].map(
							( n ) => ( {
								order_id: n,
								order_label: `#${ n }`,
								result: 'differs' as const,
								fields: [ 'Phone format' ],
								queue_id: n - 10400,
							} )
						),
						...[ 10470, 10471, 10472, 10473 ].map( ( n ) => ( {
							order_id: n,
							order_label: `#${ n }`,
							result: 'differs' as const,
							fields: [ 'Tax code' ],
							queue_id: n - 10400,
						} ) ),
						{
							order_id: 10460,
							order_label: '#10460',
							result: 'not_found' as const,
							fields: [],
							queue_id: 60,
						},
					],
				} ),
		],
		[
			'POST',
			/^\/shadow\/end$/,
			() => {
				onboarding = {
					...onboarding,
					shadow_started_at: null,
					shadow_ends_at: null,
				};
				return ok( onboarding );
			},
		],
		[ 'GET', /^\/orders\/sample$/, () => ok( SAMPLE_ORDERS ) ],
		[
			'POST',
			/^\/notifications\/test$/,
			() => ok( { sent_to: settings.notifications.recipients } ),
		],
	];

	const transport: Transport = async (
		req
	): Promise< TransportResponse > => {
		requests.push( {
			method: req.method,
			route: req.route,
			body: req.body,
			headers: req.headers,
		} );
		if ( options.latency ) {
			await new Promise( ( resolve ) =>
				setTimeout( resolve, options.latency )
			);
		}
		if ( req.signal?.aborted ) {
			throw new DOMException( 'Aborted', 'AbortError' );
		}
		const key = `${ req.method } ${ req.route }`;
		const injected = Object.entries( options.failures ?? {} ).find(
			( [ prefix ] ) => key.startsWith( prefix )
		);
		let answer: Answer | undefined;
		if ( injected ) {
			answer = fail(
				injected[ 1 ].status,
				injected[ 1 ].code,
				injected[ 1 ].message,
				injected[ 1 ].details ? { details: injected[ 1 ].details } : {}
			);
		} else {
			const route = req.route.replace( /\/+$/, '' ) || '/';
			for ( const [ method, pattern, handler ] of routes ) {
				const match =
					method === req.method ? route.match( pattern ) : null;
				if ( match ) {
					answer = handler( match, req );
					break;
				}
			}
		}
		const final =
			answer ??
			fail(
				404,
				'rest_no_route',
				'No route was found matching the URL and request method.'
			);
		const text =
			typeof final.body === 'string'
				? final.body
				: final.body === undefined
					? ''
					: JSON.stringify( final.body );
		const headers = final.headers ?? {};
		return {
			status: final.status,
			headers: {
				get: ( name: string ) =>
					headers[ name ] ?? headers[ name.toLowerCase() ] ?? null,
			},
			text: async () => text,
		};
	};

	return {
		transport,
		requests,
		get items() {
			return items;
		},
		get connection() {
			return connection;
		},
		get onboarding() {
			return onboarding;
		},
		mappings,
		aed,
		setCatalog: ( next: CatalogEntry[] ) => {
			catalog = next;
		},
	};
}

export type MockServer = ReturnType< typeof createMockServer >;

/**
 * Boot data for the mock host page and for tests.
 *
 * @param overrides Changes to the defaults.
 * @return A complete boot object.
 */
export function mockBoot( overrides: Partial< BootData > = {} ): BootData {
	return {
		restUrl: 'https://cedarstone.example/wp-json/estasoft-sync/v1/',
		nonce: 'mock-nonce',
		adminUrl: 'https://cedarstone.example/wp-admin/',
		pluginUrl:
			'https://cedarstone.example/wp-content/plugins/estasoft-erp-crm-sync-engine/',
		version: '0.1.0',
		capabilities: { manage: true, configure: true },
		storeTimezone: TIMEZONE,
		currency: CURRENCY,
		currencyDecimals: 2,
		locale: 'en_GB',
		wpAdminColorScheme: 'fresh',
		routes: [],
		notices: [],
		encryptionKeyLine:
			"define( 'ESTASOFT_SYNC_KEY', 'mock-key-not-a-real-secret' );",
		...overrides,
	};
}
