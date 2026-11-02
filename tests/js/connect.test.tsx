/**
 * The connect experience for all systems: choose a system, the five ways to connect, the way back
 * from a sign-in page, reconnecting, and the problems screen.
 */
import { setLeaver } from '../../src/lib/external';
import {
	answer,
	expectHeading,
	expectNoA11yViolations,
	goTo,
	patchJson,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

type Rendered = Awaited< ReturnType< typeof renderApp > >;

const left: string[] = [];

beforeEach( () => {
	left.length = 0;
	setLeaver( ( url ) => {
		left.push( url );
	} );
} );

afterEach( () => {
	setLeaver( null );
	resetApp();
} );

/**
 * Opens "Add a connection", picks a system and goes on to its connect screen.
 * @param user
 * @param name
 * @param product
 */
async function connect(
	user: Rendered[ 'user' ],
	name: string,
	product?: string
) {
	await goToAdd();
	const buttons = await screen.findAllByRole( 'button', {
		name: `Select ${ name }`,
	} );
	await user.click( buttons[ buttons.length - 1 ] );
	if ( product ) {
		await user.click( await screen.findByLabelText( product ) );
	}
	await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
}

/**
 * The alert callout whose text matches (the app also keeps an empty alert region for notices).
 * @param text
 */
async function alertWith( text: RegExp ): Promise< HTMLElement > {
	return waitFor( () => {
		const found = screen
			.getAllByRole( 'alert' )
			.find( ( alert ) => text.test( alert.textContent ?? '' ) );
		if ( ! found ) {
			throw new Error( `No alert says ${ text }` );
		}
		return found;
	} );
}

async function goToAdd() {
	await goTo( '/connections/add' );
	await expectHeading( 'Choose a system' );
}

describe( 'Choose a system', () => {
	it( 'tags every connector that was not tried with a live account, and explains it calmly', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await expectHeading( 'Choose a system' );
		const row = await screen.findByRole( 'row', { name: /Wafeq/ } );
		expect(
			within( row ).getByText( 'Not yet tested with a live account' )
		).toBeInTheDocument();
		await user.click(
			within( row ).getByRole( 'button', {
				name: 'What “not yet tested” means for Wafeq',
			} )
		);
		expect(
			await screen.findByText(
				/built from the public documentation of Wafeq/
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/You can use Shadow mode to check the results before anything is sent/
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'does not let SMACC be selected and gives the reason from the catalog', async () => {
		await renderApp( { route: '/connections/add' } );
		const row = await screen.findByRole( 'row', { name: /SMACC/ } );
		expect(
			within( row ).getByRole( 'button', {
				name: 'SMACC is not available yet',
			} )
		).toBeDisabled();
		expect(
			within( row ).getByText( /SMACC publishes no API yet/ )
		).toBeInTheDocument();
		expect(
			within( row ).getByText(
				/Cannot be selected yet: SMACC has not published an API reference/
			)
		).toBeInTheDocument();
		expect(
			within( row ).queryByText( 'Not yet tested with a live account' )
		).not.toBeInTheDocument();
	} );

	it( 'says why any system the catalog marks as not available cannot be selected', async () => {
		await renderApp( {
			route: '/connections/add',
			wrap: patchJson(
				( req ) => req.route === '/catalog' && req.method === 'GET',
				( data ) =>
					( data as unknown as Record< string, unknown >[] ).map(
						( entry ) =>
							entry.name === 'Pennylane'
								? {
										...entry,
										available: false,
										unavailable_reason:
											'Pennylane requires OAuth for production integrations.',
									}
								: entry
					) as unknown as Record< string, unknown >
			),
		} );
		const row = await screen.findByRole( 'row', { name: /Pennylane/ } );
		expect(
			within( row ).getByRole( 'button', {
				name: 'Pennylane is not available yet',
			} )
		).toBeDisabled();
		expect(
			within( row ).getByText(
				'Cannot be selected yet: Pennylane requires OAuth for production integrations.'
			)
		).toBeInTheDocument();
	} );

	it( 'keeps the heading order when a search finds nothing', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await user.type(
			await screen.findByRole( 'searchbox', { name: 'Find a system' } ),
			'zzzz'
		);
		expect(
			await screen.findByRole( 'heading', {
				level: 2,
				name: 'No system matches',
			} )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'asks which product a row holds and goes on with the product chosen', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		const buttons = await screen.findAllByRole( 'button', {
			name: 'Select Sage Business Cloud Accounting and Sage 50',
		} );
		await user.click( buttons[ buttons.length - 1 ] );
		const group = await screen.findByRole( 'group', {
			name: 'Which product do you use?',
		} );
		const next = screen.getByRole( 'button', { name: 'Continue' } );
		expect( next ).toBeDisabled();
		await user.click(
			within( group ).getByLabelText( 'Sage 50 (desktop program)' )
		);
		expect( next ).toBeEnabled();
		await expectNoA11yViolations();
		await user.click( next );
		// Sage 50 is a file, not a sign-in.
		expect(
			await screen.findByRole( 'heading', {
				name: 'Connect to Sage 50 (desktop program) with an export file',
			} )
		).toBeInTheDocument();
		expect( server.connect.connections ).toHaveLength( 0 );
	} );
} );

describe( 'Connect with a key', () => {
	it( 'draws the form from the connector, lists the steps, and saves only after a passing test', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'Wafeq' );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Where do I find these?',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Create a key and press Enable API. Wafeq shows the key once.'
			)
		).toBeInTheDocument();
		const key = screen.getByLabelText( 'API key' );
		expect( key ).toHaveAttribute( 'type', 'password' );
		expect(
			screen.getByRole( 'button', { name: 'Test connection' } )
		).toBeDisabled();
		// What is sent, and where, with the vendor's terms and privacy policy.
		expect(
			screen.getByRole( 'heading', { name: 'What is sent, and where' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'api.wafeq.example' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Wafeq terms of use/ } )
		).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect(
			screen.getByRole( 'link', { name: /Wafeq privacy policy/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: /Open the Wafeq developer area/,
			} )
		).toHaveAttribute( 'target', '_blank' );
		await expectNoA11yViolations();

		await user.type( key, 'wrong' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText( 'Wafeq refused this key.' )
		).toBeInTheDocument();
		// The test says where it stopped.
		const steps = screen.getByRole( 'list', {
			name: 'The steps of the test',
		} );
		expect(
			within( steps ).getByText( 'Reached the server' )
		).toBeInTheDocument();
		expect(
			within( steps ).getByText( 'Sign-in refused' )
		).toBeInTheDocument();
		expect(
			within( steps ).getByText( 'Permissions: not tried' )
		).toBeInTheDocument();
		expect( screen.getByText( /Nothing was saved\./ ) ).toBeInTheDocument();
		expect( server.connect.connections ).toHaveLength( 0 );
		await expectNoA11yViolations();

		await user.clear( key );
		await user.type( key, 'a-good-key' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test again' } )
		);
		expect(
			await screen.findByText(
				/Connected and saved\. Wafeq, company Cedar & Stone Trading LLC\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( /The key is stored encrypted\./ )
		).toBeInTheDocument();
		const created = server.requests.find(
			( request ) =>
				request.route === '/connections' && request.method === 'POST'
		);
		expect( created?.body ).toEqual( {
			connector: 'wafeq',
			config: {},
			secrets: { api_key: 'a-good-key' },
		} );
		expect(
			screen.queryByDisplayValue( 'a-good-key' )
		).not.toBeInTheDocument();
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Open the connection' } )
			).toBeEnabled()
		);
	} );

	it( 'names a plan without the API, an unreachable server and missing access', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await connect( user, 'Wafeq' );
		const key = await screen.findByLabelText( 'API key' );
		await user.type( key, 'noplan' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		const alert = await alertWith( /Your Wafeq plan/ );
		expect(
			within( alert ).getByText(
				/Your Wafeq plan does not include outside access\./
			)
		).toBeInTheDocument();
		expect(
			within( alert ).getByText( 'SYNC-AUTH-006' )
		).toBeInTheDocument();
		await user.clear( key );
		await user.type( key, 'noaccess' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test again' } )
		);
		expect(
			await screen.findByText( 'Connected, but one thing is missing.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( /cannot do the following: create invoices/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Open the connection' } )
		).toBeDisabled();
	} );

	it( 'asks for a service key and offers the pipeline as a connector option', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await connect( user, 'HubSpot' );
		expect( await screen.findByLabelText( 'Service key' ) ).toHaveAttribute(
			'type',
			'password'
		);
		// The advanced fields sit behind a disclosure.
		const more = screen.getByText( 'More settings' );
		expect( more.closest( 'details' ) ).not.toHaveAttribute( 'open' );
		expect( screen.getByLabelText( 'Pipeline' ) ).toHaveValue( 'default' );
	} );

	it( 'explains a row the vendor gives no way to connect to instead of showing a form', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await goToAdd();
		const row = await screen.findByRole( 'row', {
			name: /Sage 200, Sage 300 and Sage X3/,
		} );
		await user.click(
			within( row ).getByRole( 'button', { name: /^Select/ } )
		);
		await user.click( await screen.findByLabelText( 'Sage X3' ) );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'How you connect to Sage X3',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Test connection' } )
		).not.toBeInTheDocument();
	} );
} );

describe( 'A product whose way to connect is still to be confirmed', () => {
	it( 'draws the form of its connector, from the sign-in style the server sends, with a calm note above', async () => {
		const field = ( name: string, label: string, over = {} ) => ( {
			name,
			label,
			secret: false,
			required: true,
			hint: '',
			default: '',
			type: 'text',
			options: [],
			group: 'connection',
			...over,
		} );
		const { user } = await renderApp( {
			route: '/connections/add',
			wrap: patchJson(
				( req ) => req.route === '/catalog' && req.method === 'GET',
				( data ) =>
					( data as unknown as Record< string, unknown >[] ).map(
						( entry ) =>
							entry.name === 'Sage 200, Sage 300 and Sage X3'
								? {
										...entry,
										variants: (
											entry.variants as Record<
												string,
												unknown
											>[]
										 ).map( ( variant ) =>
											variant.id === 'sagex3'
												? {
														...variant,
														auth_style: 'basic',
														can_sign_in: false,
														credential_fields: [
															field(
																'address',
																'Web service address',
																{ type: 'url' }
															),
															field(
																'username',
																'Syracuse user'
															),
															field(
																'password',
																'Password',
																{
																	secret: true,
																	type: 'password',
																}
															),
														],
													}
												: variant
										),
									}
								: entry
					) as unknown as Record< string, unknown >
			),
		} );
		await connect( user, 'Sage 200, Sage 300 and Sage X3', 'Sage X3' );
		expect(
			await screen.findByLabelText( 'Web service address' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Password' ) ).toHaveAttribute(
			'type',
			'password'
		);
		expect(
			screen.getByText( /depends on the version you run/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /must be reachable from this website/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Test connection' } )
		).toBeDisabled();
	} );
} );

describe( 'Connect to NetSuite', () => {
	it( 'shows the commands that step 4 points to, with a button that copies them', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await connect( user, 'NetSuite' );
		expect(
			await screen.findByRole( 'region', {
				name: 'Commands that make the key pair',
			} )
		).toHaveTextContent( /openssl ecparam -name prime256v1 -genkey/ );
		expect(
			screen.getByRole( 'button', { name: 'Copy the commands' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Choose PS256 only if you made an RSA key/ )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );
} );

describe( 'Connect with your own app, then sign in', () => {
	it( 'shows the redirect address to copy, saves the app without calling the vendor, then goes to the sign-in page', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'Xero' );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Step 1: create your own Xero app',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'https://cedarstone.example/wp-json/estasoft-sync/v1/oauth/callback'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Copy: Redirect address/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Copy these steps for my accountant',
			} )
		).toBeInTheDocument();
		const secret = screen.getByLabelText( 'Client secret' );
		expect( secret ).toHaveAttribute( 'type', 'password' );
		const submit = screen.getByRole( 'button', {
			name: 'Save and sign in at Xero',
		} );
		expect( submit ).toBeDisabled();
		await expectNoA11yViolations();

		await user.type( screen.getByLabelText( 'Client ID' ), 'my-client' );
		await user.type( secret, 'my-secret' );
		await user.click( submit );
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		expect( left[ 0 ] ).toMatch( /#\/connections\/21\?signin=ok$/ );
		const created = server.requests.find(
			( request ) =>
				request.route === '/connections' && request.method === 'POST'
		);
		expect( created?.body ).toEqual( {
			connector: 'xero',
			config: { client_id: 'my-client' },
			secrets: { client_secret: 'my-secret' },
		} );
		// The vendor was not called: the connection waits for the sign-in.
		expect( server.connect.connections[ 0 ].status ).toBe(
			'needs_reconnect'
		);
		expect(
			server.requests.some( ( request ) =>
				request.route.endsWith( '/test' )
			)
		).toBe( false );
		expect(
			server.requests.some(
				( request ) => request.route === '/connections/test'
			)
		).toBe( false );
	} );

	it( 'asks for the data centre of Zoho before it signs in', async () => {
		const { user } = await renderApp( { route: '/connections/add' } );
		await connect( user, 'Zoho Books' );
		const centre = await screen.findByLabelText( 'Data centre' );
		expect( centre ).toHaveValue( 'com' );
		expect(
			within( centre ).getByRole( 'option', { name: 'zoho.sa' } )
		).toBeInTheDocument();
	} );

	it( 'takes the person through the organisation and the test after a good sign-in', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections/21?signin=ok',
		} );
		expect(
			await screen.findByRole( 'heading', { name: /Signed in at Xero/ } )
		).toBeInTheDocument();
		const select = await screen.findByLabelText( 'Organisation' );
		expect( within( select ).getAllByRole( 'option' ) ).toHaveLength( 3 );
		await user.selectOptions( select, 'org-2' );
		await user.click(
			screen.getByRole( 'button', { name: 'Use this organisation' } )
		);
		expect(
			await screen.findByText( 'Connected to Xero.' )
		).toBeInTheDocument();
		const steps = screen.getByRole( 'list', {
			name: 'The steps of the test',
		} );
		expect(
			within( steps ).getByText( 'Can read items and create orders' )
		).toBeInTheDocument();
		expect(
			server.connect.connections.find( ( c ) => c.id === 21 )?.status
		).toBe( 'active' );
		expect(
			server.connect.connections.find( ( c ) => c.id === 21 )?.company
		).toBe( 'Cedar & Stone Riyadh' );
		await expectNoA11yViolations();
	} );

	it( 'says a cancelled sign-in connected nothing, and tries again', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/21?signin=cancelled',
		} );
		expect(
			await screen.findByText(
				'Sign-in was cancelled. Nothing was connected.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'SYNC-AUTH-003' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
	} );

	it( 'says a failed sign-in could not be finished and points to the log', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections/21?signin=failed',
		} );
		expect(
			await screen.findByText(
				/The sign-in with Xero could not be finished\. Nothing was connected\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Open the log' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'shows the access that was not approved and offers to sign in again', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections',
		} );
		await screen.findByRole( 'row', { name: /Xero/ } );
		// Give the stored app a secret that comes back partly approved.
		await user.click(
			await screen.findByRole( 'button', { name: 'Open Xero' } )
		);
		await expectHeading( 'Xero' );
		await user.click(
			screen.getByRole( 'button', { name: 'Change the client secret' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Replace the Xero secret',
		} );
		await user.type(
			within( dialog ).getByLabelText( 'Client secret' ),
			'noaccess'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Save and sign in' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		expect(
			server.connect.connections.find( ( c ) => c.id === 21 )
		).toBeDefined();
		// The organisation was chosen before, so the server has already activated the connection and
		// nothing is asked again: the test runs at once and finds the access that was not approved.
		await goTo( '/connections/21?signin=ok' );
		expect(
			await screen.findByText(
				/Xero did not give access to everything we need\. We could not: create invoices\./
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'SYNC-AUTH-004' ) ).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: 'Sign in again' } ).length
		).toBeGreaterThan( 0 );
	} );
} );

describe( 'Reconnecting a sign-in that was set up before', () => {
	it( 'does not ask for the organisation again, and tests at once', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections',
		} );
		const row = await screen.findByRole( 'row', { name: /Xero/ } );
		await user.click(
			within( row ).getByRole( 'button', { name: 'Reconnect Xero' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Reconnect Xero',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Continue to Xero' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		await goTo( left[ 0 ].slice( left[ 0 ].indexOf( '#' ) + 1 ) );
		expect(
			await screen.findByText( 'Connected to Xero.' )
		).toBeInTheDocument();
		expect(
			screen.queryByLabelText( 'Organisation' )
		).not.toBeInTheDocument();
	} );

	it( 'goes on without a sign-in when the connector says the connection is already active', async () => {
		const { user } = await renderApp( {
			route: '/connections/add',
			wrap: patchJson(
				( req ) =>
					req.route === '/connections' && req.method === 'POST',
				( data ) => ( { ...data, status: 'active' } )
			),
		} );
		await connect( user, 'Xero' );
		await user.type( await screen.findByLabelText( 'Client ID' ), 'id' );
		await user.type( screen.getByLabelText( 'Client secret' ), 'secret' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save and sign in at Xero' } )
		);
		expect(
			await screen.findByText( /Connected and saved\./ )
		).toBeInTheDocument();
		expect( left ).toHaveLength( 0 );
	} );
} );

describe( 'A sign-in that comes back without a connection', () => {
	it( 'says nothing was connected, on the list, and can be closed', async () => {
		const { user } = await renderApp( {
			route: '/connections?signin=failed',
		} );
		expect(
			await screen.findByText(
				/The sign-in could not be finished\. Nothing was connected\./
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click( screen.getByRole( 'button', { name: 'Close' } ) );
		await waitFor( () =>
			expect( window.location.hash ).toBe( '#/connections' )
		);
		expect(
			screen.queryByText( /The sign-in could not be finished/ )
		).not.toBeInTheDocument();
	} );
} );

describe( 'Connect a cloud app', () => {
	it( 'is for administrators: copyable steps, tenant fields, a test, and the company question', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'Microsoft Dynamics 365 Business Central' );
		expect(
			await screen.findByText(
				/Parts of this are done by administrators/
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Copy the steps for my administrators',
			} )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Environment' ) ).toHaveValue(
			'Production'
		);
		await user.type( screen.getByLabelText( 'Tenant ID' ), 'tenant-1' );
		await user.type( screen.getByLabelText( 'Client ID' ), 'client-1' );
		await user.type( screen.getByLabelText( 'Client secret' ), 'noapp' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		const alert = await alertWith( /does not know this app yet/ );
		expect(
			within( alert ).getByText(
				/Microsoft Dynamics 365 Business Central does not know this app yet\./
			)
		).toBeInTheDocument();
		expect(
			within( alert ).getByText( 'SYNC-AUTH-007' )
		).toBeInTheDocument();
		const steps = screen.getByRole( 'list', {
			name: 'The steps of the test',
		} );
		expect(
			within( steps ).getByText(
				'Microsoft Dynamics 365 Business Central does not know this app yet'
			)
		).toBeInTheDocument();
		expect( server.connect.connections ).toHaveLength( 0 );

		const secret = screen.getByLabelText( 'Client secret' );
		await user.clear( secret );
		await user.type( secret, 'a-secret' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test again' } )
		);
		expect(
			await screen.findByText( /Connected and saved\./ )
		).toBeInTheDocument();
		const company = await screen.findByLabelText( 'Company' );
		await user.selectOptions( company, 'org-2' );
		await user.click(
			screen.getByRole( 'button', { name: 'Use this company' } )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Open the connection' } )
			).toBeEnabled()
		);
		expect( server.connect.connections[ 0 ].company ).toBe(
			'Cedar & Stone Riyadh'
		);
	} );
} );

describe( 'Connect with a server sign-in', () => {
	it( 'warns that the server must be reachable and shows where a failed test stopped', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'SAP Business One' );
		expect(
			await screen.findByText(
				/server must be reachable from this website/
			)
		).toBeInTheDocument();
		await user.type(
			screen.getByLabelText( 'Service Layer address' ),
			'https://sap-down.example:50000'
		);
		await user.type(
			screen.getByLabelText( 'Company database' ),
			'CEDARSTONE'
		);
		await user.type( screen.getByLabelText( 'User' ), 'SYNC_BOT' );
		await user.type( screen.getByLabelText( 'Password' ), 'pw' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		const alert = await alertWith( /Couldn’t reach SAP/ );
		expect(
			within( alert ).getByText( /Couldn’t reach SAP Business One/ )
		).toBeInTheDocument();
		expect(
			within( alert ).getByText( 'SYNC-NET-001' )
		).toBeInTheDocument();
		const steps = screen.getByRole( 'list', {
			name: 'The steps of the test',
		} );
		expect(
			within( steps ).getByText( 'Could not reach the server' )
		).toBeInTheDocument();
		expect(
			within( steps ).getByText( 'Certificate: not tried' )
		).toBeInTheDocument();
		expect( server.connect.connections ).toHaveLength( 0 );
		await expectNoA11yViolations();

		const address = screen.getByLabelText( 'Service Layer address' );
		await user.clear( address );
		await user.type( address, 'sap.example' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test again' } )
		);
		expect(
			await screen.findByText(
				'Enter a web address that starts with https://.'
			)
		).toBeInTheDocument();
		await user.clear( address );
		await user.type( address, 'https://sap-badcert.example:50000' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test again' } )
		);
		expect(
			await screen.findByText( 'Certificate not trusted' )
		).toBeInTheDocument();
		expect( screen.getAllByText( 'SYNC-NET-003' ).length ).toBeGreaterThan(
			0
		);
	} );

	it( 'sends the password as a secret and the rest as settings', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'SAP Business One' );
		await user.type(
			await screen.findByLabelText( 'Service Layer address' ),
			'https://sap.example:50000'
		);
		await user.type(
			screen.getByLabelText( 'Company database' ),
			'CEDARSTONE'
		);
		await user.type( screen.getByLabelText( 'User' ), 'SYNC_BOT' );
		await user.type( screen.getByLabelText( 'Password' ), 'pw' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		await screen.findByText( /Connected and saved\./ );
		const created = server.requests.find(
			( request ) =>
				request.route === '/connections' && request.method === 'POST'
		);
		expect( created?.body ).toEqual( {
			connector: 'sap-business-one',
			config: {
				address: 'https://sap.example:50000',
				company_db: 'CEDARSTONE',
				user: 'SYNC_BOT',
				certificate: 'public',
				ca_certificate: '',
			},
			secrets: { password: 'pw' },
		} );
	} );
} );

describe( 'Connect with an export file', () => {
	it( 'has no credentials, says nothing is sent anywhere, and saves the choices that shape the file', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/add',
		} );
		await connect( user, 'TallyPrime' );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Connect to TallyPrime with an export file',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/So the plugin sends nothing to it|the plugin sends nothing to it/
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Nothing is sent to any website. The file is made on your own website and stays there until you download it.'
			)
		).toBeInTheDocument();
		expect(
			document.querySelectorAll( 'input[type="password"]' )
		).toHaveLength( 0 );
		expect(
			screen.queryByRole( 'button', { name: 'Test connection' } )
		).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Tax' ) ).toHaveValue( 'uae_vat' );
		const save = screen.getByRole( 'button', {
			name: 'Save and continue',
		} );
		expect( save ).toBeDisabled();
		await user.type(
			screen.getByLabelText( 'Company name in TallyPrime' ),
			'Cedar & Stone Trading LLC'
		);
		await expectNoA11yViolations();
		await user.click( save );
		expect(
			await screen.findByText(
				/Saved\. Files for TallyPrime are made on the Exports screen\./
			)
		).toBeInTheDocument();
		const created = server.requests.find(
			( request ) =>
				request.route === '/connections' && request.method === 'POST'
		);
		expect( created?.body ).toEqual( {
			connector: 'tallyprime',
			config: {
				company_name: 'Cedar & Stone Trading LLC',
				tax: 'uae_vat',
				customers: 'one',
				ledger_sales: 'Online sales',
				ledger_shipping: 'Shipping income',
			},
			secrets: {},
		} );
		expect(
			screen.getByRole( 'link', { name: 'Open the Exports screen' } )
		).toHaveAttribute( 'href', '#/connections/21/exports' );
	} );
} );

describe( 'Reconnect and expiry', () => {
	it( 'shows Needs reconnect in the list with a Reconnect button, and restarts the sign-in from a dialog', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections',
		} );
		const row = await screen.findByRole( 'row', { name: /Xero/ } );
		expect(
			within( row ).getByText( 'Needs reconnect' )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			within( row ).getByRole( 'button', { name: 'Reconnect Xero' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Reconnect Xero',
		} );
		expect(
			within( dialog ).getByText( /opens its own sign-in page/ )
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Continue to Xero' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		expect( left[ 0 ] ).toContain( 'signin=ok' );
	} );

	it( 'shows the reconnect warning on the detail page with the way to find out more', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/21',
		} );
		expect(
			await screen.findByText(
				/The sign-in to Xero has expired\. Reconnect\./
			)
		).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Reconnect' } ) );
		expect(
			await screen.findByRole( 'dialog', { name: 'Reconnect Xero' } )
		).toBeInTheDocument();
	} );

	it( 'replaces the secret of a cloud app only after a passing test', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections/24',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Change the client secret',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Replace the Microsoft Dynamics 365 Business Central secret',
		} );
		const confirm = within( dialog ).getByRole( 'button', {
			name: 'Test and save',
		} );
		expect( confirm ).toBeDisabled();
		const field = within( dialog ).getByLabelText( 'Client secret' );
		expect( field ).toHaveAttribute( 'type', 'password' );
		await user.type( field, 'wrong' );
		await user.click( confirm );
		expect(
			await within( dialog ).findByText( /refused the sign-in/ )
		).toBeInTheDocument();
		expect(
			server.requests.some(
				( request ) =>
					request.route === '/connections/24' &&
					request.method === 'POST'
			)
		).toBe( false );
		await user.clear( field );
		await user.type( field, 'a-new-secret' );
		await user.click( confirm );
		expect(
			await screen.findByText(
				/The new Microsoft Dynamics 365 Business Central secret is saved, encrypted\./
			)
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) =>
					request.route === '/connections/24' &&
					request.method === 'POST'
			)?.body
		).toEqual( { secrets: { client_secret: 'a-new-secret' } } );
	} );

	it( 'replaces the key of a pasted-key system and the password of a server sign-in', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/23',
		} );
		await user.click(
			await screen.findByRole( 'button', { name: 'Change the API key' } )
		);
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Replace the Wafeq key',
			} )
		).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		await goTo( '/connections/25' );
		await user.click(
			await screen.findByRole( 'button', { name: 'Change the password' } )
		);
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Replace the SAP Business One password',
			} )
		).toBeInTheDocument();
	} );

	it( 'names what runs out in the warning: the secret of a cloud app, the sign-in of an own app', async () => {
		const soon = ( id: number ) =>
			patchJson(
				( req ) => req.route === '/connections' && req.method === 'GET',
				( data ) =>
					( data as unknown as { id: number }[] ).map(
						( connection ) =>
							connection.id === id
								? {
										...connection,
										credential_expires_at:
											'2026-11-10T00:00:00Z',
									}
								: connection
					) as unknown as Record< string, unknown >
			);
		const first = await renderApp( {
			scenario: 'systems',
			route: '/connections',
			wrap: soon( 24 ),
		} );
		expect(
			await screen.findByText(
				/The Microsoft Dynamics 365 Business Central secret runs out on 10 Nov 2026, in 8 days\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Make a new secret in Microsoft Dynamics 365 Business Central and paste it here/
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Replace the secret' } )
		).toBeInTheDocument();
		first.unmount();
		resetApp();
		await renderApp( {
			scenario: 'systems',
			route: '/connections',
			wrap: soon( 21 ),
		} );
		expect(
			await screen.findByText(
				/The Xero sign-in runs out on 10 Nov 2026, in 8 days\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Sign in again' } )
		).toBeInTheDocument();
	} );

	it( 'lets a person who cannot configure see the state but not reconnect', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		const row = await screen.findByRole( 'row', { name: /Xero/ } );
		expect(
			within( row ).getByText( 'Needs reconnect' )
		).toBeInTheDocument();
		expect(
			within( row ).queryByRole( 'button', { name: /^Reconnect/ } )
		).not.toBeInTheDocument();
	} );
} );

describe( 'In the setup wizard', () => {
	async function toConnect(
		user: Rendered[ 'user' ],
		name: string,
		product?: string
	) {
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		const buttons = await screen.findAllByRole( 'button', {
			name: `Select ${ name }`,
		} );
		await user.click( buttons[ buttons.length - 1 ] );
		if ( product ) {
			await user.click( await screen.findByLabelText( product ) );
		}
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	}

	it( 'carries a system that is not Odoo through to the questions that fit it', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await toConnect( user, 'Wafeq' );
		await user.type( await screen.findByLabelText( 'API key' ), 'a-key' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		await screen.findByText( /Connected and saved\./ );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		// An accounting system gets the accounting questions, not the ERP ones.
		expect(
			await screen.findByRole( 'heading', {
				name: 'What should sync to Wafeq?',
			} )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Create' ) ).toHaveValue( 'invoice' );
		expect(
			screen.queryByLabelText( 'Send when' )
		).not.toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( server.onboarding.step ).toBe( 4 ) );
		expect(
			( server.onboarding.answers.what as Record< string, unknown > )
				.accounting_create
		).toBe( 'invoice' );
		expect( server.onboarding.connector ).toBe( 'wafeq' );
	} );

	it( 'keeps the product that was chosen, and the connector id with it', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await toConnect(
			user,
			'Sage Business Cloud Accounting and Sage 50',
			'Sage 50 (desktop program)'
		);
		await screen.findByRole( 'heading', { name: /with an export file/ } );
		await waitFor( () =>
			expect( server.onboarding.answers.variant ).toBe( 'sage50-export' )
		);
		expect( server.onboarding.connector ).toBe( 'sage50-export' );
		expect( server.onboarding.answers.system ).toBe(
			'Sage Business Cloud Accounting and Sage 50'
		);
	} );

	it( 'keeps Continue off for a sign-in app until it is signed in', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await toConnect( user, 'Xero' );
		await user.type( await screen.findByLabelText( 'Client ID' ), 'id' );
		await user.type( screen.getByLabelText( 'Client secret' ), 'secret' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save and sign in at Xero' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		expect( server.connect.connections[ 0 ].status ).toBe(
			'needs_reconnect'
		);
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
		// Pressing the button again signs in with the saved connection: it does not make a second one.
		await user.type( screen.getByLabelText( 'Client secret' ), 'secret-2' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save and sign in at Xero' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 2 ) );
		expect( server.connect.connections ).toHaveLength( 1 );
	} );
} );

describe( 'Back from a sign-in during setup', () => {
	it( 'offers the way back into the setup guide when the guide is not finished', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		const buttons = await screen.findAllByRole( 'button', {
			name: 'Select Xero',
		} );
		await user.click( buttons[ buttons.length - 1 ] );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.type( await screen.findByLabelText( 'Client ID' ), 'id' );
		await user.type( screen.getByLabelText( 'Client secret' ), 'secret' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save and sign in at Xero' } )
		);
		await waitFor( () => expect( left ).toHaveLength( 1 ) );
		const id = server.connect.connections[ 0 ].id;
		await goTo( `/connections/${ id }?signin=ok&connection=${ id }` );
		await user.selectOptions(
			await screen.findByLabelText( 'Organisation' ),
			'org-1'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Use this organisation' } )
		);
		const link = await screen.findByRole( 'link', {
			name: 'Continue setup',
		} );
		expect( link ).toHaveAttribute( 'href', '#/setup' );
		// Back in the guide, the connection is found again and Continue is on.
		await user.click( link );
		expect(
			await screen.findByText( /Connected and saved\. Xero/ )
		).toBeInTheDocument();
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Continue' } )
			).toBeEnabled()
		);
	} );
} );

describe( 'Connection problems', () => {
	it( 'lists what each problem looks like, what the plugin does and what to do', async () => {
		await renderApp( { route: '/connections/problems' } );
		await expectHeading( 'Connection problems' );
		for ( const code of [
			'SYNC-AUTH-001',
			'SYNC-AUTH-002',
			'SYNC-AUTH-003',
			'SYNC-AUTH-004',
			'SYNC-AUTH-005',
			'SYNC-AUTH-006',
			'SYNC-AUTH-007',
		] ) {
			expect( screen.getByText( code ) ).toBeInTheDocument();
		}
		expect( screen.getAllByText( 'What you do' ).length ).toBeGreaterThan(
			6
		);
		await expectNoA11yViolations();
	} );
} );

describe( 'Errors the screens must not hide', () => {
	it( 'keeps the form and shows the reason when saving a sign-in app fails', async () => {
		const { user } = await renderApp( {
			route: '/connections/add',
			wrap: answer(
				( req ) =>
					req.route === '/connections' && req.method === 'POST',
				500,
				{
					code: 'SYNC-CFG-002',
					message:
						'The encryption key is missing from wp-config.php.',
					data: {
						status: 500,
						class: 'needs_person',
						details: { key_line: "define( 'X', 'y' );" },
					},
				}
			),
		} );
		await connect( user, 'Xero' );
		await user.type( await screen.findByLabelText( 'Client ID' ), 'a' );
		await user.type( screen.getByLabelText( 'Client secret' ), 'b' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save and sign in at Xero' } )
		);
		expect(
			await screen.findByText(
				'The encryption key is missing from wp-config.php.'
			)
		).toBeInTheDocument();
		expect( left ).toHaveLength( 0 );
		expect( screen.getByLabelText( 'Client ID' ) ).toHaveValue( 'a' );
	} );
} );
