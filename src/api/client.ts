/**
 * REST client for `estasoft-sync/v1`.
 *
 * All calls go through one function, `request()`, on top of a replaceable transport. The default
 * transport uses `@wordpress/api-fetch` (cookie sign-in plus the REST nonce). The mock layer and
 * the tests replace the transport with an in-memory server.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import type { ErrorClass } from './errors';
import { ERROR_CATALOG } from './errors';
import type { ApiErrorBody, BootData, Paged } from './types';
import { readBootData } from './boot';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

export type QueryValue = string | number | boolean | null | undefined;

export interface TransportRequest {
	route: string;
	query: Record< string, string >;
	method: HttpMethod;
	body?: unknown;
	headers: Record< string, string >;
	signal?: AbortSignal;
}

export interface TransportResponse {
	status: number;
	headers: { get: ( name: string ) => string | null };
	text: () => Promise< string >;
}

export type Transport = (
	request: TransportRequest
) => Promise< TransportResponse >;

export interface RequestOptions {
	method?: HttpMethod;
	query?: Record< string, QueryValue >;
	body?: unknown;
	signal?: AbortSignal;
	/** Override the generated Idempotency-Key, or pass false to send none. */
	idempotencyKey?: string | false;
	/** Return the response text instead of parsing JSON (CSV download). */
	text?: boolean;
	accept?: string;
}

/** An error answer from the server, or a failure to reach it. */
export class ApiError extends Error {
	public readonly code: string;

	public readonly status: number;

	public readonly errorClass: ErrorClass | null;

	public readonly context: Record< string, string >;

	public readonly fieldErrors: Record< string, string >;

	/**
	 * @param code        Error code (`SYNC-...` or a WordPress REST code).
	 * @param message     Message for the person, already translated by the server when it came from there.
	 * @param status      HTTP status, 0 when the server could not be reached.
	 * @param errorClass  Class from the error catalog, if known.
	 * @param context     Placeholder values the server sent with the error.
	 * @param fieldErrors Messages by field name, for validation errors.
	 */
	public constructor(
		code: string,
		message: string,
		status: number,
		errorClass: ErrorClass | null = null,
		context: Record< string, string > = {},
		fieldErrors: Record< string, string > = {}
	) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.errorClass = errorClass;
		this.context = context;
		this.fieldErrors = fieldErrors;
	}
}

let boot: BootData | null = null;

/**
 * Returns the boot data, reading it from the page on first use.
 *
 * @return The boot data.
 */
export function getBoot(): BootData {
	if ( ! boot ) {
		boot = readBootData();
	}
	return boot;
}

/**
 * Replaces the boot data (tests and the mock host page).
 *
 * @param next New boot data, or null to read it from the page again.
 */
export function setBoot( next: BootData | null ): void {
	boot = next;
}

/**
 * Joins the REST base address, a route and a query string. Handles sites with plain permalinks,
 * where the base already contains a query (`?rest_route=`).
 *
 * @param base  Namespace address, for example `https://x.test/wp-json/estasoft-sync/v1/`.
 * @param route Route such as `/queue/12`.
 * @param query Query arguments.
 * @return The full address.
 */
export function buildUrl(
	base: string,
	route: string,
	query: Record< string, string >
): string {
	const joined = `${ base.replace( /\/+$/, '' ) }/${ route.replace( /^\/+/, '' ) }`;
	const pairs = Object.entries( query ).map(
		( [ key, value ] ) =>
			`${ encodeURIComponent( key ) }=${ encodeURIComponent( value ) }`
	);
	if ( pairs.length === 0 ) {
		return joined;
	}
	return `${ joined }${ joined.includes( '?' ) ? '&' : '?' }${ pairs.join( '&' ) }`;
}

function isResponseLike( value: unknown ): value is TransportResponse {
	return (
		typeof value === 'object' &&
		value !== null &&
		'status' in value &&
		'headers' in value &&
		typeof ( value as { text?: unknown } ).text === 'function'
	);
}

let nonceInstalled: string | null = null;

const defaultTransport: Transport = async ( req ) => {
	const current = getBoot();
	if ( current.nonce && nonceInstalled !== current.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( current.nonce ) );
		nonceInstalled = current.nonce;
	}
	const url = buildUrl( current.restUrl, req.route, req.query );
	try {
		const options = {
			url,
			method: req.method,
			data: req.body,
			headers: req.headers,
			// With false, api-fetch hands back the Response (we need the headers and the error body).
			parse: false,
			signal: req.signal,
		};
		const response = await (
			apiFetch as unknown as ( o: typeof options ) => Promise< unknown >
		 )( options );
		return response as TransportResponse;
	} catch ( error ) {
		// With parse: false, api-fetch rejects with the Response itself for HTTP errors.
		if ( isResponseLike( error ) ) {
			return error;
		}
		throw error;
	}
};

let transport: Transport = defaultTransport;

/**
 * Replaces the transport (mock server, tests).
 *
 * @param next New transport, or null to restore the api-fetch transport.
 */
export function setTransport( next: Transport | null ): void {
	transport = next ?? defaultTransport;
}

/**
 * Creates a random Idempotency-Key.
 *
 * @return A UUID-like string.
 */
export function newIdempotencyKey(): string {
	const cryptoObject: Crypto | undefined =
		typeof crypto !== 'undefined' ? crypto : undefined;
	if ( cryptoObject && typeof cryptoObject.randomUUID === 'function' ) {
		return cryptoObject.randomUUID();
	}
	return `k${ Date.now().toString( 36 ) }${ Math.random().toString( 36 ).slice( 2, 12 ) }`;
}

function cleanQuery(
	query: Record< string, QueryValue > | undefined
): Record< string, string > {
	const out: Record< string, string > = {};
	if ( ! query ) {
		return out;
	}
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value === undefined || value === null || value === '' ) {
			continue;
		}
		out[ key ] =
			typeof value === 'boolean'
				? value
					? '1'
					: '0'
				: String( value );
	}
	return out;
}

function toApiError( status: number, raw: string ): ApiError {
	let body: Partial< ApiErrorBody > & { data?: Record< string, unknown > } =
		{};
	try {
		body = JSON.parse( raw ) as typeof body;
	} catch {
		// Not JSON (a PHP notice or a proxy page). Fall through to the generic message.
	}
	const code = typeof body.code === 'string' ? body.code : `http_${ status }`;
	const data = ( body.data ?? {} ) as {
		class?: ErrorClass;
		context?: Record< string, string >;
		params?: Record< string, string >;
		fields?: Record< string, string >;
	};
	const info = ERROR_CATALOG[ code ];
	const errorClass = data.class ?? info?.class ?? null;
	let message = typeof body.message === 'string' ? body.message : '';
	if ( ! message ) {
		if ( status === 401 || status === 403 ) {
			message = __(
				'You do not have permission to do this.',
				'estasoft-erp-crm-sync-engine'
			);
		} else if ( status === 404 ) {
			message = __(
				'That could not be found. It may have been removed.',
				'estasoft-erp-crm-sync-engine'
			);
		} else {
			message = __(
				'Something went wrong on the server. Try again in a moment.',
				'estasoft-erp-crm-sync-engine'
			);
		}
	}
	if ( code === 'rest_cookie_invalid_nonce' ) {
		message = __(
			'Your sign-in has expired. Reload the page and try again.',
			'estasoft-erp-crm-sync-engine'
		);
	}
	return new ApiError(
		code,
		message,
		status,
		errorClass,
		data.context ?? {},
		data.fields ?? data.params ?? {}
	);
}

/**
 * Sends one request and returns the parsed body.
 *
 * @param  route   Route under the namespace, for example `/queue`.
 * @param  options Method, query, body and the rest.
 * @return The response body.
 * @throws {ApiError} When the server answers with an error status or cannot be reached.
 */
export async function request< T >(
	route: string,
	options: RequestOptions = {}
): Promise< T > {
	const response = await send( route, options );
	return parseBody< T >( response, options );
}

async function send(
	route: string,
	options: RequestOptions
): Promise< TransportResponse > {
	const method = options.method ?? 'GET';
	const headers: Record< string, string > = {
		Accept: options.accept ?? 'application/json',
	};
	if ( method !== 'GET' && options.idempotencyKey !== false ) {
		headers[ 'Idempotency-Key' ] =
			options.idempotencyKey ?? newIdempotencyKey();
	}
	let response: TransportResponse;
	try {
		response = await transport( {
			route,
			query: cleanQuery( options.query ),
			method,
			body: options.body,
			headers,
			signal: options.signal,
		} );
	} catch ( error ) {
		if ( error instanceof DOMException && error.name === 'AbortError' ) {
			throw error;
		}
		throw new ApiError(
			'client_offline',
			__(
				'Could not reach your site. Check your connection and try again.',
				'estasoft-erp-crm-sync-engine'
			),
			0
		);
	}
	if ( response.status >= 400 ) {
		throw toApiError( response.status, await response.text() );
	}
	return response;
}

async function parseBody< T >(
	response: TransportResponse,
	options: RequestOptions
): Promise< T > {
	const text = await response.text();
	if ( options.text ) {
		return text as unknown as T;
	}
	if ( text === '' ) {
		return undefined as unknown as T;
	}
	try {
		return JSON.parse( text ) as T;
	} catch {
		throw new ApiError(
			'client_bad_response',
			__(
				'The server sent an answer this page could not read. Reload and try again.',
				'estasoft-erp-crm-sync-engine'
			),
			response.status
		);
	}
}

/**
 * Sends a request for a page of results and fills in the pagination from the headers.
 *
 * The body may be `Paged<T>` or a plain array; `X-WP-Total` and `X-WP-TotalPages` fill the gaps.
 *
 * @param  route   Route such as `/queue`.
 * @param  options Request options (query holds `page` and `per_page`).
 * @return A page of results.
 * @throws {ApiError} When the request fails.
 */
export async function requestPaged< T >(
	route: string,
	options: RequestOptions = {}
): Promise< Paged< T > > {
	const response = await send( route, options );
	const body = await parseBody< Paged< T > | T[] >( response, {} );
	const headerTotal = Number( response.headers.get( 'X-WP-Total' ) );
	const headerPages = Number( response.headers.get( 'X-WP-TotalPages' ) );
	const page = Number( options.query?.page ?? 1 ) || 1;
	const perPage = Number( options.query?.per_page ?? 25 ) || 25;
	if ( Array.isArray( body ) ) {
		const total =
			Number.isFinite( headerTotal ) && headerTotal > 0
				? headerTotal
				: body.length;
		return {
			items: body,
			total,
			total_pages:
				Number.isFinite( headerPages ) && headerPages > 0
					? headerPages
					: Math.max( 1, Math.ceil( total / perPage ) ),
			page,
			per_page: perPage,
		};
	}
	return {
		...body,
		total:
			body.total ??
			( Number.isFinite( headerTotal )
				? headerTotal
				: body.items.length ),
		total_pages:
			body.total_pages ??
			( Number.isFinite( headerPages ) ? headerPages : 1 ),
		page: body.page ?? page,
		per_page: body.per_page ?? perPage,
	};
}
