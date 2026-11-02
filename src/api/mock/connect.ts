/**
 * The mock side of the connect experience: connections for systems other than Odoo, the sign-in
 * routes, the organisation picker and the export files. It keeps its own state next to the main
 * mock server (`server.ts`), which asks it first and falls through when a route is not its own.
 *
 * Magic values for the connect forms (any field, secret or not): `wrong` is refused (AUTH-001),
 * `expired` is past its end date (AUTH-005), `noplan` has no API in its plan (AUTH-006), `noapp`
 * is an app the system does not know (AUTH-007), `badcert` is an untrusted certificate (NET-003),
 * `nodb` is a database that does not exist (CFG-003), `noaccess` is signed in with some access
 * missing (AUTH-004), and a value containing `down` cannot be reached (NET-001). For a sign-in app,
 * a client secret of `cancel` comes back cancelled and `fail` comes back failed.
 */
import { ERROR_CATALOG } from '../errors';
import type {
	ExportFileInfo,
	ExportList,
	OAuthOrganisation,
	SystemEntry,
} from '../local-types';
import type {
	Connection,
	ConnectionStatus,
	ConnectionSummary,
	ConnectionTestResult,
} from '../types';
import type { TransportRequest } from '../client';
import { entryForConnector, shapeOf } from '../../lib/connect';
import { CATALOG } from './catalog';
import { makeConnection, NOW } from './data';

export interface MockAnswer {
	status: number;
	body: unknown;
	headers?: Record< string, string >;
}

/** A route of the mock: method, pattern, and a handler that answers or returns undefined to pass. */
export type MockRoute = [
	string,
	RegExp,
	(
		match: RegExpMatchArray,
		req: TransportRequest
	) => MockAnswer | undefined,
];

interface Stored {
	connection: Connection;
	entry: SystemEntry;
	config: Record< string, string >;
	secrets: Record< string, string >;
	signedIn: boolean;
}

const ok = ( body: unknown ): MockAnswer => ( { status: 200, body } );
const fail = (
	status: number,
	code: string,
	message: string,
	extra: Record< string, unknown > = {}
): MockAnswer => ( {
	status,
	body: {
		code,
		message,
		data: { status, class: 'needs_person', ...extra },
	},
} );

const body = ( req: TransportRequest ) =>
	( req.body ?? {} ) as Record< string, unknown >;

function say( code: string, system: string ): string {
	return ( ERROR_CATALOG[ code ]?.message ?? '' ).replace(
		/\{system\}/g,
		system
	);
}

function capabilitiesFor( entry: SystemEntry ): Record< string, boolean > {
	const method = entry.connection_method;
	if ( method === 'export_file' ) {
		return {
			orders: true,
			customers: false,
			refunds: true,
			stock_in: false,
			tracking_in: false,
			prices_in: false,
			invoice_readback: false,
			reconciliation: false,
			webhooks: false,
			shadow_lookup: false,
		};
	}
	if ( entry.type === 'crm' ) {
		return {
			orders: true,
			customers: true,
			refunds: true,
			stock_in: false,
			tracking_in: false,
			prices_in: false,
			invoice_readback: false,
			reconciliation: false,
			webhooks: false,
			shadow_lookup: false,
		};
	}
	if ( entry.type === 'accounting' ) {
		return {
			orders: true,
			customers: true,
			refunds: true,
			stock_in: false,
			tracking_in: false,
			prices_in: false,
			invoice_readback: true,
			reconciliation: true,
			webhooks: false,
			shadow_lookup: true,
		};
	}
	return {
		orders: true,
		customers: true,
		refunds: true,
		stock_in: true,
		tracking_in: false,
		prices_in: false,
		invoice_readback: true,
		reconciliation: true,
		webhooks: false,
		shadow_lookup: true,
	};
}

/**
 * The organisations to choose between after signing in.
 * @param entry
 */
function organisationsFor( entry: SystemEntry ): OAuthOrganisation[] {
	if (
		[
			'xero',
			'quickbooks',
			'business-central',
			'sage-accounting',
		].includes( entry.connector ?? '' )
	) {
		return [
			{ id: 'org-1', name: 'Cedar & Stone Trading LLC' },
			{ id: 'org-2', name: 'Cedar & Stone Riyadh' },
		];
	}
	return [ { id: 'org-1', name: 'Cedar & Stone Trading LLC' } ];
}

function result(
	entry: SystemEntry,
	over: Partial< ConnectionTestResult > = {}
): ConnectionTestResult {
	const erp = entry.type === 'erp';
	return {
		ok: true,
		version: erp ? `${ entry.name } 10.0` : entry.name,
		company: 'Cedar & Stone Trading LLC',
		warehouses: erp
			? [
					{ id: 'WH1', name: 'Dubai Main' },
					{ id: 'WH2', name: 'Riyadh 3PL' },
				]
			: [],
		product_count: erp ? 1204 : null,
		permissions: { read_items: true, create_orders: true },
		missing_permissions: [],
		error: null,
		...over,
	};
}

function failure( entry: SystemEntry, code: string ): ConnectionTestResult {
	return result( entry, {
		ok: false,
		version: '',
		company: '',
		warehouses: [],
		product_count: null,
		permissions: {},
		error: {
			code,
			message: say( code, entry.name ),
			data: { status: 400, class: 'needs_person' },
		},
	} );
}

/**
 * Runs the test the way the server's checks run (reach, certificate, sign in, plan, app, permissions).
 *
 * @param entry   The system.
 * @param config  Non-secret values.
 * @param secrets Secret values.
 * @return The answer.
 */
export function runTest(
	entry: SystemEntry,
	config: Record< string, string >,
	secrets: Record< string, string >
): ConnectionTestResult {
	const plain = Object.values( config ).join( ' ' );
	const all = `${ plain } ${ Object.values( secrets ).join( ' ' ) }`;
	if ( plain.includes( 'down' ) ) {
		return failure( entry, 'SYNC-NET-001' );
	}
	if ( all.includes( 'badcert' ) ) {
		return failure( entry, 'SYNC-NET-003' );
	}
	if ( all.includes( 'nodb' ) ) {
		return failure( entry, 'SYNC-CFG-003' );
	}
	if ( all.includes( 'wrong' ) ) {
		return failure( entry, 'SYNC-AUTH-001' );
	}
	if ( all.includes( 'expired' ) ) {
		return failure( entry, 'SYNC-AUTH-005' );
	}
	if ( all.includes( 'noplan' ) ) {
		return failure( entry, 'SYNC-AUTH-006' );
	}
	if ( all.includes( 'noapp' ) ) {
		return failure( entry, 'SYNC-AUTH-007' );
	}
	if ( all.includes( 'noaccess' ) ) {
		return result( entry, {
			permissions: { read_items: true, create_orders: false },
			missing_permissions: [ 'create invoices' ],
		} );
	}
	return result( entry );
}

function build(
	id: number,
	entry: SystemEntry,
	config: Record< string, string >,
	secrets: Record< string, string >,
	status: ConnectionStatus,
	tested: ConnectionTestResult | null
): Connection {
	const base = makeConnection();
	const fields = entry.credential_fields ?? [];
	return {
		...base,
		id,
		connector: entry.connector ?? '',
		label: entry.name,
		type: entry.type,
		status,
		version: tested?.version ?? entry.name,
		address: config.address ?? entry.hosts?.[ 0 ] ?? '',
		last_success_at: status === 'active' ? NOW : null,
		last_failure_at: null,
		typical_response_ms: status === 'active' ? 420 : null,
		credential_expires_at: null,
		capabilities: capabilitiesFor( entry ),
		company: tested?.company ?? '',
		warehouses: ( tested?.warehouses ?? [] ).map( ( warehouse ) => ( {
			...warehouse,
			counts_toward_stock: true,
			countries: [],
		} ) ),
		credentials: fields.map( ( field ) => ( {
			...field,
			value: field.secret
				? null
				: ( config[ field.name ] ?? field.default ?? '' ),
			has_value: field.secret
				? Boolean( secrets[ field.name ] )
				: Boolean( config[ field.name ] ?? field.default ),
		} ) ),
		created_at: NOW,
	};
}

const summary = ( connection: Connection ): ConnectionSummary => {
	const {
		credentials: _credentials,
		warehouses: _warehouses,
		settings: _settings,
		company: _company,
		inbound_enabled: _inbound,
		created_at: _created,
		...rest
	} = connection;
	return rest;
};

const FILE_BODY =
	'<?xml version="1.0" encoding="UTF-8"?>\n<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER></ENVELOPE>\n';

export type ConnectMockOptions = {
	/** Seed the sample systems: a signed-out Xero, a TallyPrime export, Wafeq, Business Central and SAP. */
	systems: boolean;
};

/**
 * Creates the mock state and routes of the connect experience.
 *
 * @param options What to seed.
 * @return The routes, the summaries for the list, and the state for assertions.
 */
export function createConnectMock( options: ConnectMockOptions ) {
	const stored = new Map< number, Stored >();
	let nextId = 21;
	const exportsByConnection = new Map< number, ExportList >();
	let leftOutNext = options.systems;
	let fileCounter = 3;

	const entryOf = ( connector: string ): SystemEntry | undefined =>
		entryForConnector( CATALOG, connector );

	const add = (
		entry: SystemEntry,
		config: Record< string, string >,
		secrets: Record< string, string >,
		status: ConnectionStatus,
		signedIn: boolean,
		wantedId?: number
	): Stored => {
		const id = wantedId ?? nextId++;
		const shape = shapeOf( entry.connection_method );
		const tested =
			status === 'active' && shape !== 'export_file'
				? runTest( entry, config, secrets )
				: null;
		const item: Stored = {
			connection: build( id, entry, config, secrets, status, tested ),
			entry,
			config,
			secrets,
			signedIn,
		};
		stored.set( id, item );
		if ( shape === 'export_file' ) {
			exportsByConnection.set( id, { files: [], open_batch: null } );
		}
		return item;
	};

	if ( options.systems ) {
		const xero = entryOf( 'xero' );
		const tally = entryOf( 'tallyprime' );
		const wafeq = entryOf( 'wafeq' );
		const bc = entryOf( 'business-central' );
		const sap = entryOf( 'sap-business-one' );
		if ( xero && tally && wafeq && bc && sap ) {
			const signedOut = add(
				xero,
				{ client_id: 'xero-client-1', organisation: 'org-1' },
				{ client_secret: 'secret' },
				'needs_reconnect',
				true,
				21
			);
			signedOut.connection.company = 'Cedar & Stone Trading LLC';
			signedOut.connection.last_success_at = '2026-11-01T14:10:00Z';
			const file = add(
				tally,
				{ company_name: 'Cedar & Stone Trading LLC', tax: 'uae_vat' },
				{},
				'active',
				true,
				22
			);
			file.connection.last_success_at = NOW;
			exportsByConnection.set( 22, {
				open_batch: {
					connection_id: 22,
					orders: 9,
					since: '2026-11-02T06:10:00Z',
				},
				files: [
					{
						id: 'f3',
						attachments: [
							{
								name: 'Masters list',
								filename: 'masters-7f3a9c41.csv',
								size_bytes: 2140,
							},
						],
						connection_id: 22,
						filename: 'cedar-stone-2026-11-02-1140-7f3a9c41.xml',
						size_bytes: 48213,
						orders: 38,
						created_at: '2026-11-02T07:40:00Z',
						expires_at: '2026-12-02T07:40:00Z',
						created_by: 'Layla Haddad',
						format_label: 'TallyPrime XML',
						left_out: [
							{
								order: '#10497',
								reason: 'Payment method Tabby has no ledger in TallyPrime yet.',
							},
							{
								order: '#10499',
								reason: 'Payment method Tabby has no ledger in TallyPrime yet.',
							},
							{
								order: '#10503',
								reason: 'Payment method Tabby has no ledger in TallyPrime yet.',
							},
						],
					},
					{
						id: 'f2',
						attachments: [
							{
								name: 'Masters list',
								filename: 'masters-1b8e0d52.csv',
								size_bytes: 2140,
							},
						],
						connection_id: 22,
						filename: 'cedar-stone-2026-11-01-1800-1b8e0d52.xml',
						size_bytes: 52840,
						orders: 41,
						created_at: '2026-11-01T14:00:00Z',
						expires_at: '2026-12-01T14:00:00Z',
						created_by: 'Layla Haddad',
						format_label: 'TallyPrime XML',
						left_out: [],
					},
					{
						id: 'f1',
						attachments: [
							{
								name: 'Masters list',
								filename: 'masters-c04477aa.csv',
								size_bytes: 2140,
							},
						],
						connection_id: 22,
						filename: 'cedar-stone-2026-10-31-1800-c04477aa.xml',
						size_bytes: 57102,
						orders: 44,
						created_at: '2026-10-31T14:00:00Z',
						expires_at: '2026-11-30T14:00:00Z',
						created_by: 'Layla Haddad',
						format_label: 'TallyPrime XML',
						left_out: [],
					},
				],
			} );
			add( wafeq, {}, { api_key: 'wafeq-key' }, 'active', true, 23 );
			const central = add(
				bc,
				{
					tenant_id: 'tenant-1',
					client_id: 'client-1',
					environment: 'Production',
				},
				{ client_secret: 'secret' },
				'active',
				true,
				24
			);
			central.connection.credential_expires_at = '2027-01-14T00:00:00Z';
			add(
				sap,
				{
					address: 'https://sap.cedarstone.example:50000',
					company_db: 'CEDARSTONE_LIVE',
					user: 'SYNC_BOT',
					certificate: 'public',
				},
				{ password: 'pw' },
				'active',
				true,
				25
			);
			nextId = 26;
		}
	}

	const find = ( match: RegExpMatchArray ): Stored | undefined =>
		stored.get( Number( match[ 1 ] ) );

	const routes: MockRoute[] = [
		[
			'POST',
			/^\/connections\/test$/,
			( _m, req ) => {
				const input = body( req );
				const entry = entryOf( String( input.connector ) );
				if ( ! entry || input.connector === 'odoo' ) {
					return undefined;
				}
				return ok(
					runTest(
						entry,
						( input.config ?? {} ) as Record< string, string >,
						( input.secrets ?? {} ) as Record< string, string >
					)
				);
			},
		],
		[
			'POST',
			/^\/connections$/,
			( _m, req ) => {
				const input = body( req );
				const entry = entryOf( String( input.connector ) );
				if ( ! entry || input.connector === 'odoo' ) {
					return undefined;
				}
				const config = ( input.config ?? {} ) as Record<
					string,
					string
				>;
				const secrets = ( input.secrets ?? {} ) as Record<
					string,
					string
				>;
				const shape = shapeOf( entry.connection_method );
				if ( shape === 'own_app' ) {
					// A sign-in system is saved without calling the vendor; it waits for the sign-in.
					return ok(
						add( entry, config, secrets, 'needs_reconnect', false )
							.connection
					);
				}
				if ( shape !== 'export_file' ) {
					const tested = runTest( entry, config, secrets );
					if ( ! tested.ok ) {
						return fail(
							400,
							tested.error?.code ?? 'SYNC-AUTH-001',
							tested.error?.message ?? '',
							{ details: { test: tested } }
						);
					}
					if ( tested.missing_permissions.length > 0 ) {
						return fail(
							400,
							'SYNC-AUTH-004',
							say( 'SYNC-AUTH-004', entry.name ),
							{ details: { test: tested } }
						);
					}
				}
				return ok(
					add( entry, config, secrets, 'active', true ).connection
				);
			},
		],
		[
			'GET',
			/^\/connections\/(\d+)$/,
			( m ) => {
				const item = find( m );
				return item ? ok( item.connection ) : undefined;
			},
		],
		[
			'POST',
			/^\/connections\/(\d+)\/test$/,
			( m ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				const shape = shapeOf( item.entry.connection_method );
				if ( shape === 'own_app' && ! item.signedIn ) {
					return ok( failure( item.entry, 'SYNC-AUTH-002' ) );
				}
				const tested = runTest( item.entry, item.config, item.secrets );
				if ( tested.ok && tested.missing_permissions.length === 0 ) {
					item.connection = {
						...build(
							item.connection.id,
							item.entry,
							item.config,
							item.secrets,
							'active',
							tested
						),
						company: item.connection.company || tested.company,
						credential_expires_at:
							item.connection.credential_expires_at,
					};
				}
				return ok( tested );
			},
		],
		[
			'POST',
			/^\/connections\/(\d+)$/,
			( m, req ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				const input = body( req ) as {
					config?: Record< string, string >;
					secrets?: Record< string, string >;
					settings?: Partial< Connection[ 'settings' ] >;
				};
				item.config = { ...item.config, ...( input.config ?? {} ) };
				Object.entries( input.secrets ?? {} ).forEach(
					( [ name, value ] ) => {
						if ( value ) {
							item.secrets[ name ] = value;
						}
					}
				);
				const keepStatus = item.connection.status;
				item.connection = {
					...build(
						item.connection.id,
						item.entry,
						item.config,
						item.secrets,
						keepStatus,
						null
					),
					company: item.connection.company,
					version: item.connection.version,
					last_success_at: item.connection.last_success_at,
					settings: {
						...item.connection.settings,
						...( input.settings ?? {} ),
					},
					credential_expires_at: input.secrets
						? '2027-02-02T00:00:00Z'
						: item.connection.credential_expires_at,
				};
				return ok( item.connection );
			},
		],
		[
			'DELETE',
			/^\/connections\/(\d+)$/,
			( m ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				stored.delete( item.connection.id );
				return ok( { disconnected: true } );
			},
		],
		[
			'POST',
			/^\/connections\/(\d+)\/oauth\/start$/,
			( m ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				if ( shapeOf( item.entry.connection_method ) !== 'own_app' ) {
					return fail(
						400,
						'SYNC-CFG-004',
						say( 'SYNC-CFG-004', item.entry.name )
					);
				}
				const secret = item.secrets.client_secret ?? '';
				const outcome = secret.includes( 'cancel' )
					? 'cancelled'
					: secret.includes( 'fail' )
						? 'failed'
						: 'ok';
				// The server activates a connection whose organisation was chosen before when the browser comes
				// back, so by the time the page loads there is nothing to ask again.
				if ( outcome === 'ok' && item.config.organisation ) {
					item.connection = { ...item.connection, status: 'active' };
				}
				const here =
					typeof window !== 'undefined'
						? window.location.href.split( '#' )[ 0 ]
						: 'https://cedarstone.example/wp-admin/admin.php?page=estasoft-sync';
				return ok( {
					url: `${ here }#/connections/${ item.connection.id }?signin=${ outcome }`,
				} );
			},
		],
		[
			'GET',
			/^\/connections\/(\d+)\/oauth\/organisations$/,
			( m ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				const shape = shapeOf( item.entry.connection_method );
				if ( shape !== 'own_app' && shape !== 'cloud_app' ) {
					return fail(
						400,
						'SYNC-CFG-004',
						say( 'SYNC-CFG-004', item.entry.name )
					);
				}
				// The browser only asks after it came back from the vendor's page.
				item.signedIn = true;
				return ok( organisationsFor( item.entry ) );
			},
		],
		[
			'POST',
			/^\/connections\/(\d+)\/oauth\/organisation$/,
			( m, req ) => {
				const item = find( m );
				if ( ! item ) {
					return undefined;
				}
				const wanted = String( body( req ).id ?? '' );
				const organisation = organisationsFor( item.entry ).find(
					( candidate ) => candidate.id === wanted
				);
				if ( ! organisation ) {
					return fail(
						400,
						'rest_invalid_param',
						'That organisation is not one of the choices.'
					);
				}
				item.config.organisation = organisation.id;
				item.connection = {
					...item.connection,
					company: organisation.name,
				};
				return ok( item.connection );
			},
		],
		[
			'GET',
			/^\/exports$/,
			( _m, req ) => {
				const id = Number( req.query.connection_id );
				const list = exportsByConnection.get( id );
				return list ? ok( list ) : undefined;
			},
		],
		[
			'POST',
			/^\/exports$/,
			( _m, req ) => {
				const id = Number( body( req ).connection_id );
				const list = exportsByConnection.get( id );
				const item = stored.get( id );
				if ( ! list || ! item ) {
					return undefined;
				}
				const waiting = list.open_batch?.orders ?? 0;
				const leftOut = leftOutNext
					? [
							{
								order: '#10507',
								reason: 'Payment method Tabby has no ledger in TallyPrime yet.',
							},
						]
					: [];
				leftOutNext = false;
				fileCounter += 1;
				const file: ExportFileInfo = {
					id: `f${ fileCounter }`,
					connection_id: id,
					filename: `cedar-stone-2026-11-02-${ 1200 + fileCounter }-a${ fileCounter }c3e9.xml`,
					size_bytes: 1200 * Math.max( 1, waiting - leftOut.length ),
					orders: Math.max( 0, waiting - leftOut.length ),
					created_at: NOW,
					expires_at: '2026-12-02T07:40:00Z',
					created_by: 'Layla Haddad',
					format_label: 'TallyPrime XML',
					left_out: leftOut,
					attachments: [
						{
							name: 'Masters list',
							filename: `masters-a${ fileCounter }c3e9.csv`,
							size_bytes: 980,
						},
					],
				};
				list.files = [ file, ...list.files ];
				list.open_batch =
					leftOut.length > 0
						? {
								connection_id: id,
								orders: leftOut.length,
								since: NOW,
							}
						: null;
				return ok( file );
			},
		],
		[
			'GET',
			/^\/exports\/(\w+)\/download$/,
			( m, req ) => {
				const known = [ ...exportsByConnection.values() ].some(
					( list ) =>
						list.files.some(
							( file ) => String( file.id ) === m[ 1 ]
						)
				);
				return known
					? {
							status: 200,
							body: req.query.part
								? 'Ledger,Used for\nOnline sales,Sales\n'
								: FILE_BODY,
						}
					: fail( 404, 'rest_not_found', 'No such export file.' );
			},
		],
		[
			'DELETE',
			/^\/exports\/(\w+)$/,
			( m ) => {
				let found = false;
				exportsByConnection.forEach( ( list ) => {
					const kept = list.files.filter(
						( file ) => String( file.id ) !== m[ 1 ]
					);
					if ( kept.length !== list.files.length ) {
						found = true;
						list.files = kept;
					}
				} );
				return found
					? ok( { deleted: true } )
					: fail( 404, 'rest_not_found', 'No such export file.' );
			},
		],
	];

	return {
		routes,
		summaries: (): ConnectionSummary[] =>
			[ ...stored.values() ].map( ( item ) =>
				summary( item.connection )
			),
		get connections() {
			return [ ...stored.values() ].map( ( item ) => item.connection );
		},
		get exports() {
			return exportsByConnection;
		},
		/**
		 * Whether the sign-in came back and the browser asked for the organisations.
		 * @param id
		 */
		signedIn: ( id: number ): boolean =>
			stored.get( id )?.signedIn ?? false,
	};
}

export type ConnectMock = ReturnType< typeof createConnectMock >;
