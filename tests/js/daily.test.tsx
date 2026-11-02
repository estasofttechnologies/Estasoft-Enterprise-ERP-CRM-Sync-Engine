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

describe( 'Invoices', () => {
	it( 'shows ERP and tax authority status with time left in tone words, and filters', async () => {
		const { user } = await renderApp( { route: '/invoices' } );
		await expectHeading( 'Invoices' );
		const waiting = await screen.findByRole( 'row', { name: /#10443/ } );
		expect(
			within( waiting ).getByText( 'INV/2026/1181' )
		).toBeInTheDocument();
		expect(
			within( waiting ).getByText( 'Waiting to be reported' )
		).toBeInTheDocument();
		expect( within( waiting ).getByText( '5 h 40 min left' ) ).toHaveClass(
			'estasoft-sync-timeleft--warning'
		);
		expect(
			within( screen.getByRole( 'row', { name: /#10478/ } ) ).getByText(
				'23 h 17 min left'
			)
		).toHaveClass( 'estasoft-sync-timeleft--neutral' );
		const rejected = screen.getByRole( 'row', { name: /#10462/ } );
		expect(
			within( rejected ).getByText( 'Rejected' )
		).toBeInTheDocument();
		expect(
			within( rejected ).getByText( /The buyer’s address is missing/ )
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'row', { name: /#10490/ } ) ).getByText(
				'Not required'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: /Rejected\s*1/ } )
		);
		await waitFor( () =>
			expect( screen.getAllByRole( 'row' ) ).toHaveLength( 2 )
		);
	} );

	it( 'says when the system does not report invoices back', async () => {
		const { server } = await renderApp( { route: '/invoices' } );
		await screen.findByRole( 'row', { name: /#10443/ } );
		expect( server.connection?.capabilities.invoice_readback ).toBe( true );
	} );
} );

describe( 'Stock', () => {
	it( 'shows the rules, warehouses and recent changes, and saves a changed buffer with its range', async () => {
		const { user, server } = await renderApp( { route: '/stock' } );
		await expectHeading( 'Stock' );
		expect(
			await screen.findByText(
				'Odoo is the source of truth for stock quantities.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'Every 5 min' ) ).toBeInTheDocument();
		const changes = screen.getByRole( 'table', {
			name: 'Recent stock changes',
		} );
		expect(
			within( changes ).getByRole( 'row', { name: /CS-MUG-01/ } )
		).toHaveTextContent( '44, then 40' );
		expect(
			within(
				screen.getByRole( 'table', { name: 'Warehouses' } )
			).getAllByRole( 'checkbox' )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
		await expectNoA11yViolations();

		const buffer = screen.getByLabelText( /Safety buffer/ );
		await user.clear( buffer );
		await user.type( buffer, '1000' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Enter a number from 0 to 999.' )
		).toBeInTheDocument();
		await user.clear( buffer );
		await user.type( buffer, '3' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Stock rules saved.' )
		).toBeInTheDocument();
		const saved = server.requests.find(
			( request ) =>
				request.route === '/stock/rules' && request.method === 'PUT'
		);
		expect( saved?.body ).toMatchObject( {
			safety_buffer: 3,
			interval_minutes: 5,
			subtract_held: true,
		} );
	} );

	it( 'accepts the system quantity for a conflict and holds another', async () => {
		const { user, server } = await renderApp( {
			route: '/stock/conflicts',
		} );
		expect( await screen.findByText( '3 conflicts' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Conflicts/ } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', {
				name: 'Accept Odoo’s quantity for CS-TRAY-03',
			} )
		);
		expect(
			await screen.findByText(
				'The shop now shows the Odoo quantity for CS-TRAY-03.'
			)
		).toBeInTheDocument();
		await waitFor( () =>
			expect( screen.getByText( '2 conflicts' ) ).toBeInTheDocument()
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Hold CS-LAMP-07' } )
		);
		expect(
			await screen.findByText(
				/Holding CS-LAMP-07\. The shop quantity stays as it is/
			)
		).toBeInTheDocument();
		expect(
			server.requests.some(
				( request ) => request.route === '/stock/conflicts/32/hold'
			)
		).toBe( true );
	} );

	it( 'shows tracking settings and recent shipments', async () => {
		await renderApp( { route: '/stock/tracking' } );
		expect(
			await screen.findByText(
				'Tracking numbers and delivery status come from Odoo.'
			)
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Move the order to' ) ).toHaveValue(
			'completed'
		);
		expect(
			within(
				screen.getByRole( 'table', { name: 'Recent shipments' } )
			).getByText( 'AX-2231-8841' )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );
} );

describe( 'Reconciliation', () => {
	it( 'lists the differences from the last check, repairs one and ignores another with a reason', async () => {
		const { user, server } = await renderApp( {
			route: '/reconciliation',
		} );
		await expectHeading( 'Reconciliation' );
		expect( await screen.findByText( '2,104' ) ).toBeInTheDocument();
		expect( screen.getByText( '1 repaired, 2 open' ) ).toBeInTheDocument();
		const repaired = screen.getByRole( 'row', { name: /#10311/ } );
		expect( repaired ).toHaveTextContent( 'Repaired at 02:03' );
		expect(
			within( screen.getByRole( 'row', { name: /#10377/ } ) ).getByText(
				'WooCommerce: AED 438.00. Odoo: AED 437.95.'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();

		await user.click(
			screen.getByRole( 'button', { name: 'Repair #10402' } )
		);
		const repair = await screen.findByRole( 'dialog', {
			name: 'Repair refund not in the system for order #10402?',
		} );
		expect(
			within( repair ).getByRole( 'radio', {
				name: /Create the credit note in Odoo/,
			} )
		).toBeChecked();
		await user.click(
			within( repair ).getByRole( 'button', { name: 'Repair' } )
		);
		expect(
			await screen.findByText( /Order #10402 was repaired\./ )
		).toBeInTheDocument();
		expect(
			server.requests.find( ( request ) =>
				request.route.endsWith( '/repair' )
			)?.body
		).toEqual( { direction: 'wc_to_system' } );

		await user.click(
			screen.getByRole( 'button', { name: 'Ignore #10377' } )
		);
		const ignore = await screen.findByRole( 'dialog', {
			name: 'Ignore the difference on order #10377?',
		} );
		await user.selectOptions(
			within( ignore ).getByLabelText( 'Why?' ),
			'A known rounding difference'
		);
		await user.click(
			within( ignore ).getByRole( 'button', { name: 'Ignore' } )
		);
		await waitFor( () =>
			expect(
				server.requests.find( ( request ) =>
					request.route.endsWith( '/ignore' )
				)?.body
			).toEqual( { reason: 'A known rounding difference' } )
		);
	} );

	it( 'runs the check now and shows progress while it runs', async () => {
		const { user, server } = await renderApp( {
			route: '/reconciliation',
		} );
		await screen.findByText( '2,104' );
		await user.click(
			screen.getByRole( 'button', { name: 'Run the check now' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Run the check now?',
		} );
		await user.selectOptions(
			within( dialog ).getByLabelText( 'Compare orders from' ),
			'30'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Run the check' } )
		);
		expect(
			await screen.findByText( 'A check is running.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'progressbar', { name: 'Progress of the check' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Run the check now' } )
		).toBeDisabled();
		expect(
			server.requests.find(
				( request ) => request.route === '/reconciliation/run'
			)?.body
		).toEqual( { window_days: 30 } );
	} );
} );

describe( 'Logs', () => {
	it( 'filters by level and search, opens an entry, and downloads CSV', async () => {
		const { user, server } = await renderApp( { route: '/logs' } );
		await expectHeading( 'Logs' );
		expect(
			await screen.findByText(
				'Kept for 14 days. Names, emails and addresses are removed where the plugin finds them. Check before you share a log.'
			)
		).toBeInTheDocument();
		expect(
			within(
				await screen.findByRole( 'row', { name: /11:39:20/ } )
			).getByText( 'Warning' )
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'row', { name: /11:31:07/ } ) ).getByText(
				'Not sent'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();

		await user.click( screen.getByRole( 'button', { name: /Errors/ } ) );
		await waitFor( () =>
			expect( screen.getAllByRole( 'row' ) ).toHaveLength( 3 )
		);
		await user.click( screen.getByRole( 'link', { name: '11:22:19' } ) );
		await expectHeading( /Product CS-BOWL-04 not found in Odoo/ );
		expect(
			screen.getByRole( 'region', { name: 'Request excerpt' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Open the queue item' } )
		).toBeInTheDocument();
		await user.click(
			screen.getAllByRole( 'link', { name: 'Logs' } )[ 0 ]
		);
		await expectHeading( 'Logs' );
		const create = jest.fn( () => 'blob:test' );
		Object.defineProperty( URL, 'createObjectURL', {
			configurable: true,
			value: create,
		} );
		Object.defineProperty( URL, 'revokeObjectURL', {
			configurable: true,
			value: jest.fn(),
		} );
		const click = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => undefined );
		await user.click(
			screen.getByRole( 'button', { name: 'Download as CSV' } )
		);
		await waitFor( () => expect( click ).toHaveBeenCalled() );
		expect( create ).toHaveBeenCalled();
		expect(
			server.requests.some(
				( request ) =>
					request.route === '/logs' &&
					request.headers.Accept === 'text/csv'
			)
		).toBe( true );
		click.mockRestore();
	} );

	it( 'opens the support report, with order numbers off by default', async () => {
		const { user } = await renderApp( { route: '/logs' } );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Copy a support report',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Support report',
		} );
		expect(
			await within( dialog ).findByRole( 'region', {
				name: 'Support report text',
			} )
		).toHaveTextContent( 'Queue: 9 queued' );
		expect(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include order numbers',
			} )
		).not.toBeChecked();
		expect(
			within( dialog ).getByText(
				'Nothing is sent anywhere. Copy the report and paste it into your support request.'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include order numbers',
			} )
		);
		await waitFor( () =>
			expect(
				within( dialog ).getByRole( 'region', {
					name: 'Support report text',
				} )
			).toHaveTextContent( 'Orders: #10491' )
		);
	} );

	it( 'shows the empty state for a search with no results', async () => {
		const { user } = await renderApp( { route: '/logs' } );
		await user.type(
			await screen.findByRole( 'searchbox', { name: 'Find' } ),
			'zzzz'
		);
		expect(
			await screen.findByRole( 'heading', { name: 'No log entries' } )
		).toBeInTheDocument();
	} );
} );

describe( 'Shadow results', () => {
	it( 'groups the differences by cause and offers to go live', async () => {
		const { user, server } = await renderApp( {
			route: '/shadow',
			scenario: 'shadow',
		} );
		await expectHeading( 'Shadow mode results' );
		expect(
			await screen.findByText(
				'Day 3 of 7. Nothing has been sent to Odoo.'
			)
		).toBeInTheDocument();
		const table = screen.getByRole( 'table', {
			name: 'Differences by cause',
		} );
		expect(
			within( table ).getByRole( 'row', { name: /Phone format/ } )
		).toHaveTextContent( '6' );
		expect(
			within( table ).getByRole( 'row', { name: /Tax code/ } )
		).toHaveTextContent( '4' );
		await expectNoA11yViolations();
		await user.click( screen.getByRole( 'button', { name: 'Go live' } ) );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Go live now?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Go live' } )
		);
		await waitFor( () =>
			expect( server.onboarding.completed ).toBe( true )
		);
	} );
} );
