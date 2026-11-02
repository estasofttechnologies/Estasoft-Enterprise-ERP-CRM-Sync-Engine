import { CATALOG } from '../../src/api/mock/catalog';
import { mockBoot } from '../../src/api/mock/server';
import type { ConnectorField } from '../../src/api/local-types';
import type { ConnectionTestResult } from '../../src/api/types';
import {
	defaultValues,
	entryForConnector,
	hasRequired,
	isConnectedNow,
	isSelectable,
	needsVariant,
	readSignIn,
	redirectAddress,
	resolveSystem,
	secretWord,
	shapeOf,
	splitInput,
	stepsAsText,
	testChecks,
	validateValues,
} from '../../src/lib/connect';
import { formatFileSize } from '../../src/lib/format';
import { leaveTo, setLeaver } from '../../src/lib/external';

const field = ( over: Partial< ConnectorField > ): ConnectorField => ( {
	name: 'x',
	label: 'X',
	secret: false,
	required: true,
	hint: '',
	default: '',
	...over,
} );

const row = ( name: string ) => {
	const found = CATALOG.find( ( entry ) => entry.name === name );
	if ( ! found ) {
		throw new Error( name );
	}
	return found;
};

describe( 'ways to connect', () => {
	it( 'knows the five shapes and nothing else', () => {
		expect( shapeOf( 'key' ) ).toBe( 'key' );
		expect( shapeOf( 'own_app' ) ).toBe( 'own_app' );
		expect( shapeOf( 'cloud_app' ) ).toBe( 'cloud_app' );
		expect( shapeOf( 'server_login' ) ).toBe( 'server_login' );
		expect( shapeOf( 'export_file' ) ).toBe( 'export_file' );
		expect( shapeOf( 'none' ) ).toBeNull();
		expect( shapeOf( 'unknown' ) ).toBeNull();
		expect( shapeOf( undefined ) ).toBeNull();
	} );

	it( 'does not let a row with no way to connect, or switched off, be selected', () => {
		expect( isSelectable( row( 'SMACC' ) ) ).toBe( false );
		expect( isSelectable( row( 'Xero' ) ) ).toBe( true );
		expect( isSelectable( { ...row( 'Xero' ), available: false } ) ).toBe(
			false
		);
	} );

	it( 'takes the way to connect from the product that was chosen', () => {
		const sage = row( 'Sage Business Cloud Accounting and Sage 50' );
		expect( needsVariant( sage, null ) ).toBe( true );
		expect( needsVariant( sage, 'sage50-export' ) ).toBe( false );
		expect( needsVariant( row( 'Xero' ), null ) ).toBe( false );
		const file = resolveSystem( sage, 'sage50-export' );
		expect( file.connector ).toBe( 'sage50-export' );
		expect( file.connection_method ).toBe( 'export_file' );
		expect( file.hosts ).toEqual( [] );
		const sign = resolveSystem( sage, 'sage-accounting' );
		expect( sign.connection_method ).toBe( 'own_app' );
		// With no product chosen the row stays as it is.
		expect( resolveSystem( sage, null ) ).toBe( sage );
	} );

	it( 'uses the spec’s method for a product when the catalog does not say', () => {
		const bare = {
			...row( 'Sage 200, Sage 300 and Sage X3' ),
			variants: [ { id: 'sage300', label: 'Sage 300' } ],
		};
		expect( resolveSystem( bare, 'sage300' ).connection_method ).toBe(
			'server_login'
		);
	} );

	it( 'finds a row, or a product of a row, from a connector id', () => {
		expect( entryForConnector( CATALOG, 'xero' )?.name ).toBe( 'Xero' );
		expect(
			entryForConnector( CATALOG, 'sage300' )?.connection_method
		).toBe( 'server_login' );
		expect( entryForConnector( CATALOG, 'nope' ) ).toBeUndefined();
		expect( entryForConnector( undefined, 'xero' ) ).toBeUndefined();
	} );

	it( 'says a connection is usable unless it waits for a sign-in or was removed', () => {
		expect( isConnectedNow( { status: 'active' } ) ).toBe( true );
		expect( isConnectedNow( { status: 'paused' } ) ).toBe( true );
		expect( isConnectedNow( { status: 'needs_reconnect' } ) ).toBe( false );
		expect( isConnectedNow( { status: 'disconnected' } ) ).toBe( false );
		expect( isConnectedNow( null ) ).toBe( false );
	} );
} );

describe( 'redirect address', () => {
	it( 'is the stable callback of this site’s REST address', () => {
		expect( redirectAddress( mockBoot() ) ).toBe(
			'https://cedarstone.example/wp-json/estasoft-sync/v1/oauth/callback'
		);
		// Plain permalinks keep the route in the query.
		expect(
			redirectAddress(
				mockBoot( {
					restUrl: 'https://x.example/?rest_route=/estasoft-sync/v1/',
				} )
			)
		).toBe(
			'https://x.example/?rest_route=/estasoft-sync/v1/oauth/callback'
		);
	} );
} );

describe( 'form values', () => {
	const fields = [
		field( { name: 'address', type: 'url' } ),
		field( { name: 'user', required: false } ),
		field( { name: 'api_key', secret: true } ),
		field( {
			name: 'region',
			type: 'select',
			options: { eu: 'EU', us: 'US' },
		} ),
		field( {
			name: 'redirect_uri',
			type: 'display',
			default: 'https://r.example',
		} ),
		field( { name: 'cert', type: 'textarea', required: false } ),
	];

	it( 'starts with defaults, the first option of a required select, and nothing for display fields', () => {
		expect( defaultValues( fields ) ).toEqual( {
			address: '',
			user: '',
			api_key: '',
			region: 'eu',
			cert: '',
		} );
	} );

	it( 'splits secrets from settings, leaves out an empty secret, and never sends a display field', () => {
		expect(
			splitInput( fields, {
				address: ' https://a.example ',
				user: '',
				api_key: ' k ',
				region: 'us',
				cert: '  -----BEGIN-----\nabc  ',
			} )
		).toEqual( {
			config: {
				address: 'https://a.example',
				user: '',
				region: 'us',
				cert: '  -----BEGIN-----\nabc  ',
			},
			secrets: { api_key: 'k' },
		} );
		expect( splitInput( fields, { api_key: '   ' } ).secrets ).toEqual(
			{}
		);
	} );

	it( 'asks for required fields and a real web address', () => {
		expect(
			hasRequired( fields, { address: 'a', api_key: 'b', region: 'eu' } )
		).toBe( true );
		expect( hasRequired( fields, { address: 'a', region: 'eu' } ) ).toBe(
			false
		);
		const errors = validateValues( fields, {
			address: 'sap.example',
			api_key: '',
			region: 'eu',
		} );
		expect( errors.address ).toBe(
			'Enter a web address that starts with https://.'
		);
		expect( errors.api_key ).toBe( 'X is needed.' );
		expect( errors.user ).toBeNull();
		expect(
			validateValues( fields, {
				address: 'https://sap.example:50000',
				api_key: 'k',
				region: 'eu',
			} ).address
		).toBeNull();
		expect(
			validateValues( fields, {
				address: 'javascript:alert(1)',
				api_key: 'k',
				region: 'eu',
			} ).address
		).not.toBeNull();
	} );

	it( 'calls a secret a key, a secret or a password as the connector names it', () => {
		expect(
			secretWord( [ field( { name: 'api_key', secret: true } ) ] )
		).toBe( 'key' );
		expect(
			secretWord( [ field( { name: 'client_secret', secret: true } ) ] )
		).toBe( 'secret' );
		expect(
			secretWord( [ field( { name: 'password', secret: true } ) ] )
		).toBe( 'password' );
		expect( secretWord( [] ) ).toBe( 'key' );
	} );

	it( 'numbers the steps in the text a person copies', () => {
		expect( stepsAsText( 'Xero', [ 'Open it.', 'Paste it.' ] ) ).toBe(
			'Steps to connect Xero to a WooCommerce store:\n1. Open it.\n2. Paste it.'
		);
	} );
} );

describe( 'the checks of a test', () => {
	const good = {
		ok: true,
		version: 'v',
		company: 'c',
		warehouses: [],
		product_count: null,
		permissions: {},
		missing_permissions: [],
		error: null,
	} as ConnectionTestResult;
	const failed = ( code: string ): ConnectionTestResult => ( {
		...good,
		ok: false,
		error: { code, message: 'm' },
	} );
	const states = ( checks: { id: string; state: string }[] ) =>
		checks.map( ( check ) => `${ check.id }:${ check.state }` );

	it( 'passes every check, leaving out the app check for anything but a cloud app', () => {
		expect( states( testChecks( 'key', good, '', 'Odoo' ) ) ).toEqual( [
			'reach:passed',
			'certificate:passed',
			'sign_in:passed',
			'plan:passed',
			'permissions:passed',
		] );
		expect(
			testChecks( 'cloud_app', good, '', 'BC' ).map( ( c ) => c.id )
		).toContain( 'app' );
	} );

	it( 'stops at the first failure and marks the rest as not tried', () => {
		expect(
			states( testChecks( 'key', failed( 'SYNC-NET-003' ), '', 'Odoo' ) )
		).toEqual( [
			'reach:passed',
			'certificate:failed',
			'sign_in:not_tried',
			'plan:not_tried',
			'permissions:not_tried',
		] );
		expect(
			testChecks( 'key', failed( 'SYNC-NET-001' ), '', 'Odoo' )[ 0 ].state
		).toBe( 'failed' );
		expect(
			testChecks( 'cloud_app', failed( 'SYNC-AUTH-007' ), '', 'BC' ).find(
				( c ) => c.id === 'app'
			)?.state
		).toBe( 'failed' );
	} );

	it( 'marks missing access on the last check, and reads a failure that came as an error', () => {
		const partial = { ...good, missing_permissions: [ 'create invoices' ] };
		expect( states( testChecks( 'key', partial, '', 'Odoo' ) ).pop() ).toBe(
			'permissions:failed'
		);
		expect(
			testChecks( 'key', null, 'SYNC-AUTH-001', 'Odoo' ).find(
				( c ) => c.id === 'sign_in'
			)?.state
		).toBe( 'failed' );
		expect( testChecks( 'key', null, '', 'Odoo' ) ).toEqual( [] );
		expect( testChecks( 'key', failed( '' ), '', 'Odoo' ) ).toEqual( [] );
	} );
} );

describe( 'the way back from a sign-in page', () => {
	it( 'reads the result and the connection from the address', () => {
		expect(
			readSignIn( new URLSearchParams( 'signin=ok' ), [
				'connections',
				'21',
			] )
		).toEqual( { result: 'ok', connectionId: 21 } );
		expect(
			readSignIn( new URLSearchParams( 'signin=failed&connection=7' ), [
				'connections',
			] )
		).toEqual( { result: 'failed', connectionId: 7 } );
		expect(
			readSignIn( new URLSearchParams( 'signin=cancelled' ), [ 'setup' ] )
		).toEqual( { result: 'cancelled', connectionId: null } );
	} );

	it( 'ignores anything that is not one of the three results', () => {
		expect(
			readSignIn( new URLSearchParams( 'signin=maybe' ), [
				'connections',
				'3',
			] ).result
		).toBeNull();
		expect(
			readSignIn( new URLSearchParams( '' ), [ 'connections', 'add' ] )
		).toEqual( {
			result: null,
			connectionId: null,
		} );
	} );
} );

describe( 'leaving for a sign-in page', () => {
	afterEach( () => setLeaver( null ) );

	it( 'goes to a web address and to nothing else', () => {
		const seen: string[] = [];
		setLeaver( ( url ) => seen.push( url ) );
		expect( leaveTo( 'https://login.example/authorize?x=1' ) ).toBe( true );
		expect( leaveTo( 'javascript:alert(1)' ) ).toBe( false );
		expect( leaveTo( 'data:text/html,hi' ) ).toBe( false );
		expect( leaveTo( '' ) ).toBe( false );
		expect( seen ).toEqual( [ 'https://login.example/authorize?x=1' ] );
	} );
} );

describe( 'file size', () => {
	it( 'uses bytes, kilobytes and megabytes', () => {
		expect( formatFileSize( 812 ) ).toBe( '812 B' );
		expect( formatFileSize( 4300 ) ).toBe( '4.2 KB' );
		expect( formatFileSize( 48213 ) ).toBe( '47 KB' );
		expect( formatFileSize( 1363149 ) ).toBe( '1.3 MB' );
	} );
} );
