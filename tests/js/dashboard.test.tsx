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

describe( 'Dashboard', () => {
	it( 'shows the sample world: numbers, attention groups, chart table, events and the connection', async () => {
		await renderApp();
		await expectHeading( 'Dashboard' );
		expect(
			screen.getByText( 'Mon 2 Nov 2026, 11:40' )
		).toBeInTheDocument();
		const synced = await screen.findByRole( 'region', {
			name: 'Synced today',
		} );
		expect( within( synced ).getByText( '312' ) ).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Waiting' } )
			).getByText( '9 queued, 2 syncing, 3 retrying' )
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Needs attention' } )
			).getByText( '5' )
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Oldest waiting' } )
			).getByText( /Order #\d+/ )
		).toBeInTheDocument();

		expect(
			await screen.findByText(
				'3 orders failed: product CS-BOWL-04 not found in Odoo'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'1 order failed: buyer tax number is not valid for Saudi Arabia'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( '1 order failed: customer email is missing' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Scheduled tasks depend on site visits' )
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				/1 invoice was rejected by the tax authority/
			)
		).toBeInTheDocument();
		expect(
			await screen.findByText( /The last check found 2 differences/ )
		).toBeInTheDocument();

		// The chart has a text description and a table alternative.
		expect(
			screen.getByText( /Today: 312 orders synced/ )
		).toBeInTheDocument();
		const table = screen.getByRole( 'table', {
			name: 'Orders synced and failed in each hour today',
		} );
		expect( within( table ).getAllByRole( 'row' ) ).toHaveLength( 13 );
		expect(
			within( table )
				.getByRole( 'rowheader', { name: '10:00' } )
				.closest( 'tr' )
		).toHaveTextContent( '71' );

		expect(
			screen.getByText(
				'Order #10489 retrying. Odoo answered 503. Retry 1 of 4 at 11:41'
			)
		).toBeInTheDocument();
		const connection = screen
			.getByRole( 'heading', { name: 'Connection' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( connection ).getByText( 'Odoo 18 Enterprise' )
		).toBeInTheDocument();
		expect(
			within( connection ).getByText( '340 ms typical' )
		).toBeInTheDocument();
		const meters = screen
			.getByRole( 'heading', { name: 'Queue right now' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( meters ).getByText( 'Failed' ).nextSibling
		).toBeTruthy();
		await expectNoA11yViolations();
	} );

	it( 'shows a loading state while the status loads, then an error with Try again', async () => {
		const { user } = await renderApp( {
			latency: 30,
			failures: {
				'GET /status': {
					status: 500,
					code: 'internal',
					message: 'The server is having a bad day.',
				},
			},
		} );
		expect(
			screen.getByText( 'Loading the dashboard' )
		).toBeInTheDocument();
		expect(
			await screen.findByText( /The dashboard could not be loaded/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /The server is having a bad day/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeEnabled();
		await expectNoA11yViolations();
		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
	} );

	it( 'welcomes a new store and offers to start setup', async () => {
		const { user } = await renderApp( { scenario: 'first_run' } );
		expect(
			await screen.findByRole( 'heading', {
				level: 1,
				name: 'Welcome to ESTASOFT SYNC',
			} )
		).toBeInTheDocument();
		expect( screen.getByText( 'Not connected' ) ).toBeInTheDocument();
		const steps = screen
			.getByRole( 'heading', { name: 'What setup does' } )
			.closest( 'section' ) as HTMLElement;
		expect( within( steps ).getAllByRole( 'listitem' ) ).toHaveLength( 5 );
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Start setup' } )
		);
		await waitFor( () => expect( window.location.hash ).toBe( '#/setup' ) );
	} );

	it( 'shows the paused state when the system stops answering, with the next check time', async () => {
		const { user, server } = await renderApp( { scenario: 'paused' } );
		expect(
			await screen.findByText(
				/Odoo has not answered since 11:48\. Syncing is paused\. Customers can still check out\./
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'Odoo unreachable' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /The next automatic check is at 12:07/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Check Odoo now' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'What happens next' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Resume syncing' } )
		);
		expect(
			await screen.findByText( /Syncing resumed/ )
		).toBeInTheDocument();
		expect(
			server.requests.some( ( request ) => request.route === '/resume' )
		).toBe( true );
	} );

	it( 'shows shadow mode: counts of the comparison and four things to check', async () => {
		const { user } = await renderApp( { scenario: 'shadow' } );
		expect(
			await screen.findByText(
				/Shadow mode, day 3 of 7\. Nothing is sent to Odoo\./
			)
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Orders compared' } )
			).getByText( '912' )
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'region', { name: 'Match' } ) ).getByText(
				'886'
			)
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Differ' } )
			).getByText( '14' )
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'region', { name: 'Not in Odoo yet' } )
			).getByText( '12' )
		).toBeInTheDocument();
		expect(
			await screen.findByText( 'Review 14 differences' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Go live when you are ready' )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'End shadow mode' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'End shadow mode?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'End shadow mode' } )
		);
		expect(
			await screen.findByText( /Shadow mode ended/ )
		).toBeInTheDocument();
	} );

	it( 'pauses syncing for an hour and says when it resumes', async () => {
		const { user, server } = await renderApp();
		await screen.findByText( 'Orders synced per hour' );
		await user.click(
			screen.getByRole( 'button', { name: 'Pause syncing' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Pause syncing?',
		} );
		expect(
			within( dialog ).getByRole( 'radio', { name: 'For 1 hour' } )
		).toBeChecked();
		await expectNoA11yViolations();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Pause syncing' } )
		);
		expect(
			await screen.findByText(
				'Syncing is paused for 1 hour. Orders wait in the queue. It resumes at 12:40.'
			)
		).toBeInTheDocument();
		const call = server.requests.find(
			( request ) => request.route === '/pause'
		);
		expect( call?.body ).toMatchObject( { minutes: 60 } );
		expect( call?.headers[ 'Idempotency-Key' ] ).toBeTruthy();
	} );

	it( 'starts a sync with Sync now and confirms it', async () => {
		const { user, server } = await renderApp();
		await screen.findByText( 'Orders synced per hour' );
		await user.click( screen.getByRole( 'button', { name: 'Sync now' } ) );
		expect( await screen.findByText( /Sync started/ ) ).toBeInTheDocument();
		expect(
			server.requests.some(
				( request ) =>
					request.route === '/sync/run' && request.method === 'POST'
			)
		).toBe( true );
	} );

	it( 'hides Pause and Sync now from someone who cannot operate, but never hides the numbers', async () => {
		await renderApp( {
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await screen.findByText( 'Orders synced per hour' );
		expect(
			screen.getByRole( 'button', { name: 'Pause syncing' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Test connection' } )
		).not.toBeInTheDocument();
	} );
} );
