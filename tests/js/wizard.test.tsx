import {
	expectHeading,
	expectNoA11yViolations,
	patchJson,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

afterEach( resetApp );

async function fillOdoo(
	user: Awaited< ReturnType< typeof renderApp > >[ 'user' ],
	key: string,
	extra: { address?: string; database?: string } = {}
) {
	await user.type(
		await screen.findByLabelText( 'Odoo address' ),
		extra.address ?? 'https://erp.cedarstone.example'
	);
	await user.type(
		screen.getByLabelText( 'Database' ),
		extra.database ?? 'cedarstone_prod'
	);
	await user.type(
		screen.getByLabelText( 'Sync user (email)' ),
		'sync-bot@cedarstone.example'
	);
	await user.type( screen.getByLabelText( 'API key' ), key );
}

describe( 'Setup wizard', () => {
	it( 'runs the store checks by itself the first time, and never calls an empty result a pass', async () => {
		// The server has no result yet: an empty list, no time, and the checks count as blocking.
		let runs = 0;
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			wrap: ( inner ) => {
				const never = patchJson(
					( req ) =>
						req.method === 'GET' && req.route === '/readiness',
					( data ) => ( {
						...data,
						ran_at: null,
						checks: [],
						blocked: true,
					} )
				)( inner );
				return async ( req ) => {
					if (
						req.method === 'POST' &&
						req.route === '/readiness/run'
					) {
						runs += 1;
					}
					return never( req );
				};
			},
		} );
		await expectHeading( 'Set up ESTASOFT SYNC' );
		expect(
			await screen.findByText( /worth fixing, 7 passed/ )
		).toBeInTheDocument();
		expect( runs ).toBe( 1 );
		expect( screen.queryByText( 'Everything passed.' ) ).toBeNull();
		expect(
			await screen.findByRole( 'button', { name: 'Continue' } )
		).toBeEnabled();
		expect( user ).toBeDefined();
	} );

	it( 'starts at step 1 with the store checks, and lets Continue through when nothing needs fixing', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await expectHeading( 'Set up ESTASOFT SYNC' );
		const steps = screen.getByRole( 'list', { name: 'Setup steps' } );
		expect( within( steps ).getAllByRole( 'listitem' ) ).toHaveLength( 5 );
		expect(
			within( steps )
				.getByText( /Check your store/ )
				.closest( 'li' )
		).toHaveAttribute( 'aria-current', 'step' );
		expect(
			await screen.findByText( 'Is your store ready?' )
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'3 things are worth fixing, 7 passed. None of them stops you from continuing.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( '6 products have no SKU' )
		).toBeInTheDocument();
		expect( screen.getAllByText( 'Worth fixing' ).length ).toBe( 3 );
		expect( screen.getByText( 'Step 1 of 5' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( server.onboarding.step ).toBe( 2 ) );
	} );

	it( 'keeps Continue off while a check needs fixing', async () => {
		const { server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		// The mock readiness never blocks; this asserts the enabled state it reports.
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeEnabled();
		expect( server.onboarding.step ).toBe( 1 );
	} );

	it( 'chooses a system: 34 systems, filters with counts, live search, suggestions and no-result state', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		const filters = await screen.findByRole( 'group', {
			name: 'Filter systems',
		} );
		expect(
			within( filters ).getByRole( 'button', { name: /All\s*34/ } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect(
			within( filters ).getByRole( 'button', { name: /ERP\s*11/ } )
		).toBeInTheDocument();
		expect(
			within( filters ).getByRole( 'button', { name: /Accounting\s*17/ } )
		).toBeInTheDocument();
		expect(
			within( filters ).getByRole( 'button', { name: /CRM\s*6/ } )
		).toBeInTheDocument();
		expect(
			within( filters ).getByRole( 'button', { name: /In the UAE/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', {
				name: 'Suggested for a store in the UAE',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Accounting (17)' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', {
				name: 'ERP and business suites (11)',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'SMACC is not available yet' } )
		).toBeDisabled();
		expect( screen.getAllByText( 'Paste a key' ).length ).toBeGreaterThan(
			5
		);
		await expectNoA11yViolations();

		await user.click(
			within( filters ).getByRole( 'button', { name: /CRM\s*6/ } )
		);
		expect(
			screen.queryByRole( 'heading', { name: /Accounting/ } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'CRM (6)' } )
		).toBeInTheDocument();
		await user.click(
			within( filters ).getByRole( 'button', { name: /All\s*34/ } )
		);
		await user.type(
			screen.getByRole( 'searchbox', { name: 'Find a system' } ),
			'tryton'
		);
		expect(
			await screen.findByRole( 'heading', { name: 'No system matches' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Nothing matches “tryton”/ )
		).toBeInTheDocument();
		await user.clear(
			screen.getByRole( 'searchbox', { name: 'Find a system' } )
		);
		await user.type(
			screen.getByRole( 'searchbox', { name: 'Find a system' } ),
			'xero'
		);
		expect(
			screen.getAllByRole( 'button', { name: 'Select Xero' } ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'explains a product with no documented way to connect instead of pretending', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.type(
			await screen.findByRole( 'searchbox', { name: 'Find a system' } ),
			'sage 200'
		);
		await user.click(
			screen.getAllByRole( 'button', {
				name: 'Select Sage 200, Sage 300 and Sage X3',
			} )[ 0 ]
		);
		// Which product comes first: Continue stays off until one is chosen.
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
		await user.click( await screen.findByLabelText( 'Sage X3' ) );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'How you connect to Sage X3',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
		await expectNoA11yViolations();
	} );

	it( 'resumes where the person left off, from the server', async () => {
		const { server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		expect( server.onboarding.step ).toBe( 1 );
	} );

	it( 'shows a refused sign-in, saves nothing, and keeps Continue off until a test passes', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			(
				await screen.findAllByRole( 'button', { name: 'Select Odoo' } )
			)[ 0 ]
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Where do I find these?',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Odoo Online needs the Custom plan/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Test connection' } )
		).toBeDisabled();
		await fillOdoo( user, 'wrong' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText( 'Odoo refused this user or key.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Odoo refused the sign-in\. Check the user and the API key\./
			)
		).toBeInTheDocument();
		expect( screen.getByText( /Nothing was saved\./ ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Sync user (email)' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
		expect( server.connection ).toBeNull();
		expect(
			server.requests.some(
				( request ) =>
					request.route === '/connections' &&
					request.method === 'POST'
			)
		).toBe( false );
		await expectNoA11yViolations();
	} );

	it( 'reports a missing permission as partly connected and does not save', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			(
				await screen.findAllByRole( 'button', { name: 'Select Odoo' } )
			)[ 0 ]
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await fillOdoo( user, 'noaccess' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText( 'Connected, but one thing is missing.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( /cannot do the following: create sale orders/ )
		).toBeInTheDocument();
		expect( screen.getAllByText( 'SYNC-AUTH-004' ).length ).toBeGreaterThan(
			0
		);
		expect(
			screen.getByRole( 'button', { name: 'Test again' } )
		).toBeEnabled();
		expect( server.connection ).toBeNull();
	} );

	it( 'names the address, database and certificate problems', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			(
				await screen.findAllByRole( 'button', { name: 'Select Odoo' } )
			)[ 0 ]
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await fillOdoo( user, 'good-key', {
			address: 'https://down.example',
			database: 'cedarstone_prod',
		} );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			( await screen.findAllByText( /Check the address\./ ) ).length
		).toBeGreaterThan( 0 );
		expect( screen.getAllByText( 'SYNC-NET-001' ).length ).toBeGreaterThan(
			0
		);
	} );

	it( 'connects, then walks through what to sync, the preview and shadow mode by default', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			(
				await screen.findAllByRole( 'button', { name: 'Select Odoo' } )
			)[ 0 ]
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await fillOdoo( user, 'good-key' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText(
				/Connected and saved\. Odoo 18 Enterprise, company Cedar & Stone Trading LLC\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/2 warehouses found \(Dubai Main, Riyadh 3PL\)\. The key is stored encrypted\./
			)
		).toBeInTheDocument();
		// The key is not kept in the form once saved.
		expect(
			screen.queryByDisplayValue( 'good-key' )
		).not.toBeInTheDocument();
		const created = server.requests.find(
			( request ) =>
				request.route === '/connections' && request.method === 'POST'
		);
		expect( created?.body ).toEqual( {
			connector: 'odoo',
			config: {
				address: 'https://erp.cedarstone.example',
				database: 'cedarstone_prod',
				user: 'sync-bot@cedarstone.example',
			},
			secrets: { api_key: 'good-key' },
		} );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );

		// Step 3: capability driven.
		expect(
			await screen.findByRole( 'heading', {
				name: 'What should sync to Odoo?',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: 'Send orders' } )
		).toBeChecked();
		expect( screen.getByLabelText( 'Send when' ) ).toHaveValue(
			'paid_or_cod'
		);
		expect(
			screen.getByRole( 'checkbox', { name: 'Update WooCommerce stock' } )
		).toBeChecked();
		expect(
			screen.queryByRole( 'checkbox', {
				name: 'Update WooCommerce prices',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Not offered for Odoo' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Prices stay in WooCommerce.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', {
				name: 'When both sides change something',
			} )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.selectOptions(
			screen.getByLabelText( 'Documents in Odoo' ),
			'order'
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( server.onboarding.step ).toBe( 4 ) );
		expect(
			( server.onboarding.answers.what as Record< string, unknown > )
				.documents
		).toBe( 'order' );

		// Step 4: preview an order.
		expect(
			await screen.findByRole( 'heading', {
				name: 'The WooCommerce order',
			} )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'heading', {
				name: 'What Odoo will receive',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Preview only. Nothing is sent.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'region', {
				name: 'The request that would be sent',
			} )
		).toHaveTextContent( 'WC-10490' );
		expect(
			screen.getByRole( 'region', {
				name: 'The request that would be sent',
			} )
		).toHaveTextContent( '+971501234567' );
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Looks right, continue' } )
		);

		// Step 5: shadow mode is the default, and nothing was sent before this point.
		expect(
			await screen.findByRole( 'heading', { name: 'Ready to start' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'radio', {
				name: /Start in shadow mode for 7 days \(recommended\)/,
			} )
		).toBeChecked();
		expect(
			screen.getByText( 'Sale order only.', { exact: false } )
		).toBeInTheDocument();
		expect( server.items.every( ( item ) => item.mode === 'live' ) ).toBe(
			true
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Start shadow mode' } )
		);
		await waitFor( () => expect( window.location.hash ).toBe( '#/' ) );
		expect( server.onboarding.shadow_started_at ).toBeTruthy();
		expect(
			await screen.findByText( /Shadow mode started\. Nothing is sent\./ )
		).toBeInTheDocument();
	} );

	it( 'asks for confirmation before going live straight away', async () => {
		const { user, server } = await renderApp( {
			route: '/setup',
			scenario: 'first_run',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			(
				await screen.findAllByRole( 'button', { name: 'Select Odoo' } )
			)[ 0 ]
		);
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await fillOdoo( user, 'good-key' );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		await screen.findByText( /Connected and saved/ );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await user.click(
			await screen.findByRole( 'button', { name: 'Continue' } )
		);
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Looks right, continue',
			} )
		);
		await user.click(
			await screen.findByRole( 'radio', { name: /Go live now/ } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Go live now' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Go live now?',
		} );
		expect( server.onboarding.completed ).toBe( false );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Go live now' } )
		);
		await waitFor( () =>
			expect( server.onboarding.completed ).toBe( true )
		);
		expect( server.onboarding.shadow_started_at ).toBeNull();
	} );

	it( 'is for administrators only', async () => {
		await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		expect(
			await screen.findByText(
				/Only an administrator can set up the connection/
			)
		).toBeInTheDocument();
	} );
} );
