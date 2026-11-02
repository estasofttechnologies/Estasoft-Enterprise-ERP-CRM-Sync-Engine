import {
	expectHeading,
	expectNoA11yViolations,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

afterEach( resetApp );

describe( 'Connections list', () => {
	it( 'lists the connection, warns that the key runs out, and offers to add another', async () => {
		const { user } = await renderApp( { route: '/connections' } );
		await expectHeading( 'Connections' );
		expect(
			await screen.findByText(
				'1 connection is active, and 1 key needs renewing soon.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/The Odoo key runs out on 14 Nov 2026, in 12 days\./
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'SYNC-AUTH-005' ) ).toBeInTheDocument();
		const row = screen.getByRole( 'row', {
			name: /Cedar & Stone Trading LLC/,
		} );
		expect( within( row ).getByText( 'Connected' ) ).toBeInTheDocument();
		expect( within( row ).getByText( 'ERP' ) ).toBeInTheDocument();
		expect(
			within( row ).getByText(
				'Orders, customers, refunds, stock, tracking, invoices'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Add a connection' } )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Replace the key' } )
		);
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Replace the Odoo key',
			} )
		).toBeInTheDocument();
	} );

	it( 'has no add button for someone who cannot configure', async () => {
		await renderApp( {
			route: '/connections',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await screen.findByRole( 'row', { name: /Cedar & Stone/ } );
		expect(
			screen.queryByRole( 'button', { name: 'Add a connection' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Replace the key' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows the encryption key line with a copy button when the key is missing', async () => {
		// The status carries the flag; the mock does not set it, so the callout stays hidden here.
		await renderApp( { route: '/connections' } );
		await screen.findByRole( 'row', { name: /Cedar & Stone/ } );
		expect( screen.queryByText( 'SYNC-CFG-002' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'Connection detail', () => {
	it( 'never shows the saved key, only that it is stored encrypted', async () => {
		await renderApp( { route: '/connections/12' } );
		await expectHeading( 'Odoo' );
		expect(
			await screen.findByText( 'https://erp.cedarstone.example' )
		).toBeInTheDocument();
		expect( screen.getByText( 'cedarstone_prod' ) ).toBeInTheDocument();
		expect( screen.getByText( /saved encrypted/ ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Found in wp-config.php' )
		).toBeInTheDocument();
		expect( document.body.innerHTML ).not.toMatch(
			/mock-key-not-a-real-secret/
		);
		expect(
			document.querySelectorAll( 'input[type="password"]' )
		).toHaveLength( 0 );
		expect( screen.getByText( '340 ms typical' ) ).toBeInTheDocument();
		expect( screen.getByText( '0 of 5 allowed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Dubai Main' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'replaces the key only after a passing test, and shows a failed test without saving', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/12',
		} );
		await user.click(
			await screen.findByRole( 'button', { name: 'Change the API key' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Replace the Odoo key',
		} );
		const confirm = within( dialog ).getByRole( 'button', {
			name: 'Test and save',
		} );
		expect( confirm ).toBeDisabled();
		await user.type(
			within( dialog ).getByLabelText( 'API key' ),
			'wrong'
		);
		expect( within( dialog ).getByLabelText( 'API key' ) ).toHaveAttribute(
			'type',
			'password'
		);
		await user.click( confirm );
		expect(
			await within( dialog ).findByText(
				'Odoo refused the sign-in. Check the user and the API key.'
			)
		).toBeInTheDocument();
		expect(
			server.requests.some(
				( request ) =>
					request.route === '/connections/12' &&
					request.method === 'POST'
			)
		).toBe( false );
		await user.clear( within( dialog ).getByLabelText( 'API key' ) );
		await user.type(
			within( dialog ).getByLabelText( 'API key' ),
			'a-fresh-key'
		);
		await user.click( confirm );
		expect(
			await screen.findByText( /The new Odoo key is saved, encrypted\./ )
		).toBeInTheDocument();
		const saved = server.requests.find(
			( request ) =>
				request.route === '/connections/12' && request.method === 'POST'
		);
		expect( saved?.body ).toEqual( {
			secrets: { api_key: 'a-fresh-key' },
		} );
	} );

	it( 'saves reliability limits and refuses numbers outside the allowed range next to the field', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/12',
		} );
		const breaker = await screen.findByLabelText(
			'Pause after (failures in a row)'
		);
		expect( breaker ).toHaveValue( 5 );
		await user.clear( breaker );
		await user.type( breaker, '99' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Enter a number from 3 to 20.' )
		).toBeInTheDocument();
		expect( breaker ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( screen.getByText( 'Fix 1 field to save' ) ).toBeInTheDocument();
		await user.clear( breaker );
		await user.type( breaker, '6' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Limits saved.' )
		).toBeInTheDocument();
		const body = server.requests
			.filter(
				( request ) =>
					request.route === '/connections/12' &&
					request.method === 'POST'
			)
			.pop()?.body as { settings: Record< string, unknown > };
		expect( body.settings.breaker_threshold ).toBe( 6 );
		expect( body.settings.retry_delays_seconds ).toEqual( [
			60, 300, 3600, 86400,
		] );
	} );

	it( 'pauses and disconnects after confirmation', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/12',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Pause this connection',
			} )
		);
		const pause = await screen.findByRole( 'dialog', {
			name: 'Pause this connection?',
		} );
		await user.click(
			within( pause ).getByRole( 'button', { name: 'Pause' } )
		);
		await waitFor( () =>
			expect( server.connection?.status ).toBe( 'paused' )
		);

		await user.click(
			await screen.findByRole( 'button', { name: 'Disconnect' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Disconnect Odoo?',
		} );
		expect(
			within( dialog ).getByText(
				/Nothing in your system or in WooCommerce is deleted/
			)
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Disconnect' } )
		);
		await waitFor( () =>
			expect( window.location.hash ).toBe( '#/connections' )
		);
		expect( server.connection ).toBeNull();
	} );

	it( 'shows a manager the connection as text with no way to change it', async () => {
		await renderApp( {
			route: '/connections/12',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		expect(
			await screen.findByText(
				/You can see this connection but not change it/
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Change the API key' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Edit address and user' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Disconnect' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Test connection' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Pause after (failures in a row)' )
		).toBeDisabled();
		expect(
			screen.getByText( 'https://erp.cedarstone.example' )
		).toBeInTheDocument();
	} );

	it( 'explains a paused connection and offers Resume', async () => {
		const { user, server } = await renderApp( {
			route: '/connections/12',
			scenario: 'paused',
		} );
		expect(
			await screen.findByText(
				/did not answer several times in a row, so this connection is paused/
			)
		).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Resume syncing' } )
		);
		await waitFor( () =>
			expect( server.connection?.status ).toBe( 'active' )
		);
	} );
} );

describe( 'Other systems', () => {
	it( 'warns about add-ons from other developers and builds the request address', async () => {
		const { user } = await renderApp( { route: '/connections/other' } );
		await expectHeading( 'Other systems' );
		expect(
			screen.getByText(
				'Add-ons from other developers are not reviewed by Estasoft.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'None installed' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Open a request' } )
		).toBeDisabled();
		await user.type( screen.getByLabelText( 'System name' ), 'Tryton' );
		expect(
			screen
				.getByRole( 'link', { name: 'Open a request' } )
				.getAttribute( 'href' )
		).toContain( 'issues/new?title=System+request%3A+Tryton' );
		expect(
			screen.getByRole( 'link', { name: 'Open a request' } )
		).toHaveAttribute( 'target', '_blank' );
		await expectNoA11yViolations();
	} );
} );
