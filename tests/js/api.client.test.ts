import apiFetch from '@wordpress/api-fetch';
import {
	ApiError,
	buildUrl,
	request,
	requestPaged,
	setBoot,
	setTransport,
} from '../../src/api/client';
import { mockBoot } from '../../src/api/mock/server';

interface FakeResponse {
	status: number;
	headers: { get: ( name: string ) => string | null };
	text: () => Promise< string >;
}

function respond(
	status: number,
	body: unknown,
	headers: Record< string, string > = {}
): FakeResponse {
	return {
		status,
		headers: { get: ( name: string ) => headers[ name ] ?? null },
		text: async () =>
			typeof body === 'string' ? body : JSON.stringify( body ),
	};
}

describe( 'REST client', () => {
	const seen: Record< string, unknown >[] = [];
	beforeEach( () => {
		seen.length = 0;
		setTransport( null );
		setBoot( mockBoot() );
	} );
	afterEach( () => {
		setTransport( null );
		setBoot( null );
	} );

	it( 'builds addresses for pretty permalinks and for plain permalinks', () => {
		expect(
			buildUrl( 'https://x.test/wp-json/estasoft-sync/v1/', '/queue', {
				page: '2',
			} )
		).toBe( 'https://x.test/wp-json/estasoft-sync/v1/queue?page=2' );
		expect(
			buildUrl(
				'https://x.test/index.php?rest_route=/estasoft-sync/v1/',
				'/queue/5',
				{ page: '2' }
			)
		).toBe(
			'https://x.test/index.php?rest_route=/estasoft-sync/v1/queue/5&page=2'
		);
		expect(
			buildUrl( 'https://x.test/wp-json/estasoft-sync/v1', 'status', {} )
		).toBe( 'https://x.test/wp-json/estasoft-sync/v1/status' );
	} );

	it( 'sends the nonce, an Idempotency-Key on writes only, and parses the JSON answer', async () => {
		apiFetch.setFetchHandler( async ( options ) => {
			seen.push( options as unknown as Record< string, unknown > );
			return respond( 200, { ok: true } ) as unknown as Response;
		} );
		await expect( request( '/status' ) ).resolves.toEqual( { ok: true } );
		await request( '/queue/1/retry', { method: 'POST' } );
		const read = seen[ 0 ].headers as Record< string, string >;
		const write = seen[ 1 ].headers as Record< string, string >;
		expect( read[ 'Idempotency-Key' ] ).toBeUndefined();
		expect( write[ 'Idempotency-Key' ] ).toMatch( /.{8,}/ );
		expect( write[ 'X-WP-Nonce' ] ).toBe( 'mock-nonce' );
		expect( seen[ 0 ].parse ).toBe( false );
	} );

	it( 'keeps a caller supplied Idempotency-Key and can send none', async () => {
		apiFetch.setFetchHandler( async ( options ) => {
			seen.push( options as unknown as Record< string, unknown > );
			return respond( 200, {} ) as unknown as Response;
		} );
		await request( '/x', { method: 'POST', idempotencyKey: 'abc' } );
		await request( '/x', { method: 'POST', idempotencyKey: false } );
		expect(
			( seen[ 0 ].headers as Record< string, string > )[
				'Idempotency-Key'
			]
		).toBe( 'abc' );
		expect(
			( seen[ 1 ].headers as Record< string, string > )[
				'Idempotency-Key'
			]
		).toBeUndefined();
	} );

	it( 'maps an error body to ApiError with the catalog class and the server message', async () => {
		setTransport( async () =>
			respond( 422, {
				code: 'SYNC-MAP-001',
				message: 'Product CS-BOWL-04 was not found in Odoo.',
				data: { status: 422, context: { sku: 'CS-BOWL-04' } },
			} )
		);
		const error = ( await request( '/queue' ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( error ).toBeInstanceOf( ApiError );
		expect( error.code ).toBe( 'SYNC-MAP-001' );
		expect( error.errorClass ).toBe( 'needs_person' );
		expect( error.status ).toBe( 422 );
		expect( error.message ).toContain( 'CS-BOWL-04' );
		expect( error.context.sku ).toBe( 'CS-BOWL-04' );
	} );

	it( 'reads field errors from WordPress validation answers', async () => {
		setTransport( async () =>
			respond( 400, {
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): x',
				data: {
					status: 400,
					params: {
						global_concurrency: 'Enter a number from 1 to 10.',
					},
				},
			} )
		);
		const error = ( await request( '/settings', { method: 'PUT' } ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( error.fieldErrors.global_concurrency ).toBe(
			'Enter a number from 1 to 10.'
		);
	} );

	it( 'gives a plain message for answers that are not JSON, for 403 and for lost connections', async () => {
		setTransport( async () => respond( 502, '<html>Bad gateway</html>' ) );
		const bad = ( await request( '/status' ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( bad.code ).toBe( 'http_502' );
		expect( bad.message ).toMatch( /went wrong/i );

		setTransport( async () => respond( 403, '' ) );
		const denied = ( await request( '/status' ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( denied.message ).toMatch( /permission/i );

		setTransport( async () => {
			throw new TypeError( 'Failed to fetch' );
		} );
		const offline = ( await request( '/status' ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( offline.code ).toBe( 'client_offline' );
		expect( offline.status ).toBe( 0 );
	} );

	it( 'explains an expired nonce', async () => {
		setTransport( async () =>
			respond( 403, {
				code: 'rest_cookie_invalid_nonce',
				message: 'Cookie check failed',
			} )
		);
		const error = ( await request( '/status' ).catch(
			( caught ) => caught
		) ) as ApiError;
		expect( error.message ).toMatch( /Reload the page/ );
	} );

	it( 'lets an abort through without wrapping it', async () => {
		setTransport( async () => {
			throw new DOMException( 'Aborted', 'AbortError' );
		} );
		await expect( request( '/status' ) ).rejects.toMatchObject( {
			name: 'AbortError',
		} );
	} );

	it( 'fills pagination from the headers when the body is a plain list', async () => {
		setTransport( async () =>
			respond( 200, [ { id: 1 }, { id: 2 } ], {
				'X-WP-Total': '57',
				'X-WP-TotalPages': '3',
			} )
		);
		const page = await requestPaged< { id: number } >( '/queue', {
			query: { page: 2, per_page: 25 },
		} );
		expect( page ).toMatchObject( {
			total: 57,
			total_pages: 3,
			page: 2,
			per_page: 25,
		} );
		expect( page.items ).toHaveLength( 2 );
	} );

	it( 'returns text for a CSV download and drops empty query values', async () => {
		setTransport( async ( req ) => {
			seen.push( req as unknown as Record< string, unknown > );
			return respond( 200, 'a,b\n1,2' );
		} );
		await expect(
			request< string >( '/logs', {
				text: true,
				accept: 'text/csv',
				query: { level: undefined, search: '', page: 1 },
			} )
		).resolves.toBe( 'a,b\n1,2' );
		expect( seen[ 0 ].query ).toEqual( { page: '1' } );
		expect( ( seen[ 0 ].headers as Record< string, string > ).Accept ).toBe(
			'text/csv'
		);
	} );
} );
