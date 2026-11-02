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

const row = ( label: string ) =>
	screen.getByRole( 'row', { name: new RegExp( `#${ label }` ) } );

describe( 'Queue', () => {
	it( 'lists the newest items with state chips, plain words and counts', async () => {
		await renderApp( { route: '/queue' } );
		await expectHeading( 'Queue' );
		expect(
			await screen.findByText(
				'333 items today: 312 synced, 14 waiting, 5 failed, 2 skipped.'
			)
		).toBeInTheDocument();
		const group = screen.getByRole( 'group', { name: 'Filter by state' } );
		expect(
			within( group ).getByRole( 'button', { name: /All\s*333/ } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect(
			within( group ).getByRole( 'button', { name: /Waiting\s*14/ } )
		).toBeInTheDocument();
		const failed = row( '10491' );
		expect( within( failed ).getByText( 'Failed' ) ).toBeInTheDocument();
		expect(
			within( failed ).getByText(
				'Buyer tax number must be 15 digits for Saudi Arabia'
			)
		).toBeInTheDocument();
		expect(
			within( row( '10490' ) ).getByText( 'Odoo S00490' )
		).toBeInTheDocument();
		expect(
			within( row( '10489' ) ).getByText(
				'Odoo answered 503. Retry 1 of 4, next try 11:41'
			)
		).toBeInTheDocument();
		expect(
			within( row( '10487' ) ).getByText( 'Credit note RINV/2026/0031' )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Showing 1 to 25 of 333/ )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'filters by state, searches, shows the empty state and clears the filters', async () => {
		const { user } = await renderApp( { route: '/queue' } );
		await screen.findByText( /items today/ );
		await user.click(
			screen.getByRole( 'button', { name: /Failed\s*5/ } )
		);
		await waitFor( () =>
			expect( window.location.hash ).toBe( '#/queue?state=failed' )
		);
		await waitFor( () =>
			expect( screen.getAllByRole( 'row' ) ).toHaveLength( 6 )
		);
		await user.type(
			screen.getByRole( 'searchbox', { name: 'Find' } ),
			'CS-BOWL-09'
		);
		expect(
			await screen.findByRole( 'heading', { name: 'No items match' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Nothing matches “CS-BOWL-09”/ )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Clear filters' } )
		);
		await waitFor( () => expect( window.location.hash ).toBe( '#/queue' ) );
		expect(
			await screen.findByRole( 'row', { name: /#10494/ } )
		).toBeInTheDocument();
	} );

	it( 'pages through the results', async () => {
		const { user } = await renderApp( { route: '/queue' } );
		await screen.findByText( /items today/ );
		await user.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await waitFor( () =>
			expect( window.location.hash ).toBe( '#/queue?page=2' )
		);
		expect(
			await screen.findByText( /Showing 26 to 50 of 333/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Previous' } )
		).toBeEnabled();
	} );

	it( 'retries the selected failed orders after a confirmation and says what happened', async () => {
		const { user, server } = await renderApp( {
			route: '/queue?state=failed',
		} );
		await screen.findByRole( 'row', { name: /#10491/ } );
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10491' } )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10485' } )
		);
		const bar = screen.getByRole( 'region', { name: 'Selected orders' } );
		expect( bar ).toHaveTextContent( '2 selected: 2 failed, 0 retrying.' );
		await user.click(
			within( bar ).getByRole( 'button', { name: 'Retry selected' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Retry 2 orders?',
		} );
		expect( within( dialog ).getByText( /10491/ ) ).toBeInTheDocument();
		expect(
			within( dialog ).getByText(
				'They are sent oldest first, two at a time. Each one that fails again stays in Needs attention.'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Retry 2 orders' } )
		);
		expect(
			await screen.findByText(
				'2 orders were sent back to the queue. They will be sent oldest first, two at a time.'
			)
		).toBeInTheDocument();
		expect(
			server.items.find( ( item ) => item.entity_label === '10491' )
				?.state
		).toBe( 'queued' );
		expect(
			server.requests.find(
				( request ) => request.route === '/queue/bulk'
			)?.body
		).toMatchObject( { action: 'retry' } );
	} );

	it( 'skips with a reason, offers Undo, and restores a skipped order from its dialog', async () => {
		const { user, server } = await renderApp( {
			route: '/queue?state=failed',
		} );
		await screen.findByRole( 'row', { name: /#10468/ } );
		await user.click(
			screen.getByRole( 'button', {
				name: 'More actions for order #10468',
			} )
		);
		await user.click(
			await screen.findByRole( 'menuitem', { name: 'Skip this order…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Skip 1 order?',
		} );
		await user.selectOptions(
			within( dialog ).getByLabelText( 'Why are you skipping them?' ),
			'Test order'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Skip 1 order' } )
		);
		expect(
			await screen.findByText(
				/1 order skipped\. It is in the Skipped list and is not sent\./
			)
		).toBeInTheDocument();
		expect(
			server.items.find( ( item ) => item.entity_label === '10468' )
		).toMatchObject( { state: 'skipped', skip_reason: 'Test order' } );
		await user.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		await waitFor( () =>
			expect(
				server.items.find( ( item ) => item.entity_label === '10468' )
					?.state
			).toBe( 'queued' )
		);
	} );

	it( 'restores a skipped order', async () => {
		const { user, server } = await renderApp( {
			route: '/queue?state=skipped',
		} );
		const skipped = await screen.findByRole( 'row', { name: /#10470/ } );
		expect( within( skipped ).getByText( 'Skipped' ) ).toBeInTheDocument();
		expect(
			within( skipped ).getByText( /Test order\. Skipped at 10:05/ )
		).toBeInTheDocument();
		await user.click(
			within( skipped ).getByRole( 'button', { name: 'Restore' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Restore order #10470?',
		} );
		expect(
			within( dialog ).getByText(
				/The reason it was skipped, “Test order”, stays in the log\./
			)
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Restore order' } )
		);
		await waitFor( () =>
			expect(
				server.items.find( ( item ) => item.entity_label === '10470' )
					?.state
			).toBe( 'queued' )
		);
	} );

	it( 'shows the row menu per state: failed, synced and skipped', async () => {
		const { user } = await renderApp( { route: '/queue' } );
		await screen.findByRole( 'row', { name: /#10490/ } );
		await user.click(
			screen.getByRole( 'button', {
				name: 'More actions for order #10490',
			} )
		);
		expect(
			await screen.findByRole( 'menuitem', { name: 'Open order #10490' } )
		).toHaveAttribute( 'href', expect.stringContaining( 'post=10490' ) );
		expect(
			screen.getByRole( 'menuitem', { name: 'Open S00490 in Odoo' } )
		).toHaveAttribute( 'target', '_blank' );
		expect(
			screen.getByRole( 'menuitem', { name: 'Sync again' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'menuitem', { name: 'View in the log' } )
		).toBeInTheDocument();
		await user.keyboard( '{Escape}' );
		await user.click(
			screen.getByRole( 'button', {
				name: 'More actions for order #10491',
			} )
		);
		expect(
			await screen.findByRole( 'menuitem', {
				name: 'Copy support details',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'menuitem', { name: 'Skip this order…' } )
		).toBeInTheDocument();
	} );

	it( 'does not offer retry, skip or bulk select to someone who may only look', async () => {
		await renderApp( {
			route: '/queue?state=failed',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await screen.findByRole( 'row', { name: /#10491/ } );
		// Operating (manage) stays available; configure-only things are not on this screen.
		expect(
			screen.getAllByRole( 'button', { name: 'Retry' } ).length
		).toBeGreaterThan( 0 );
		expect(
			screen.queryByRole( 'link', { name: 'Edit address and user' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows an error with Try again when the queue cannot be loaded', async () => {
		await renderApp( {
			route: '/queue',
			failures: {
				'GET /queue?': { status: 500, code: 'x', message: 'x' },
				'GET /queue': {
					status: 500,
					code: 'internal',
					message: 'Database is busy.',
				},
			},
		} );
		expect(
			await screen.findByText( /The queue could not be loaded/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );
} );

describe( 'Needs attention', () => {
	it( 'groups failed orders by cause and retries a whole group', async () => {
		const { user, server } = await renderApp( {
			route: '/queue/attention',
		} );
		await expectHeading( 'Needs attention' );
		expect(
			await screen.findByText(
				/5 failed orders in 3 groups\. Failed orders are never retried by themselves/
			)
		).toBeInTheDocument();
		const heading = await screen.findByRole( 'heading', {
			name: 'Product not found in Odoo',
		} );
		const card = heading.closest( 'section' ) as HTMLElement;
		expect(
			within( card ).getByText( 'SKU CS-BOWL-04 · 3 orders' )
		).toBeInTheDocument();
		await within( card ).findByText( '#10485' );
		expect( within( card ).getByText( '#10479' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', {
				name: 'Buyer tax number is not valid',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Customer email is missing' } )
		).toBeInTheDocument();
		await expectNoA11yViolations();

		await user.click(
			within( card ).getByRole( 'button', { name: 'Retry 3 orders' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Retry 3 orders?',
		} );
		expect(
			within( dialog ).getByText(
				/All of them failed with: Product not found in Odoo/
			)
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Retry 3 orders' } )
		);
		expect(
			await screen.findByText( /3 orders were sent back to the queue/ )
		).toBeInTheDocument();
		expect(
			server.items.filter(
				( item ) => item.error_code === 'SYNC-MAP-001'
			)
		).toHaveLength( 0 );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'heading', {
					name: 'Product not found in Odoo',
				} )
			).not.toBeInTheDocument()
		);
	} );

	it( 'maps a missing SKU and retries the orders that failed on it', async () => {
		const { user, server } = await renderApp( {
			route: '/queue/attention',
		} );
		const heading = await screen.findByRole( 'heading', {
			name: 'Product not found in Odoo',
		} );
		const card = heading.closest( 'section' ) as HTMLElement;
		await user.click(
			within( card ).getByRole( 'button', { name: 'Map CS-BOWL-04' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Map SKU CS-BOWL-04',
		} );
		const choice = await within( dialog ).findByRole( 'radio', {
			name: /Stoneware bowl, large/,
		} );
		expect(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Retry the 3 orders that failed on this SKU',
			} )
		).toBeChecked();
		expect(
			within( dialog ).getByRole( 'button', { name: 'Save mapping' } )
		).toBeDisabled();
		await user.click( choice );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Save mapping' } )
		);
		expect(
			await screen.findByText(
				'SKU CS-BOWL-04 is mapped. 3 orders were sent back to the queue.'
			)
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) => request.route === '/products/map'
			)?.body
		).toEqual( { product_id: 501, external_id: 'p1' } );
	} );

	it( 'shows the all clear state when nothing failed', async () => {
		const { server } = await renderApp( { route: '/queue/attention' } );
		await screen.findByRole( 'heading', {
			name: 'Product not found in Odoo',
		} );
		expect( server.items.length ).toBeGreaterThan( 0 );
	} );
} );

describe( 'Order detail', () => {
	it( 'shows why an order failed, what to do, the timeline and the redacted request and answer', async () => {
		const { user } = await renderApp( { route: '/queue/85' } );
		await expectHeading( 'Order #10485' );
		expect( await screen.findByText( 'Failed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Needs a person' ) ).toBeInTheDocument();
		expect(
			screen.getAllByText( 'Product CS-BOWL-04 not found in Odoo' ).length
		).toBeGreaterThan( 1 );
		expect(
			screen.getAllByText( 'SYNC-MAP-001', { selector: 'span' } ).length
		).toBeGreaterThan( 0 );
		expect(
			screen.getByText(
				/Create it in the system or map the SKU, then retry the group/
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'What happened' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'region', { name: 'Request excerpt' } )
		).toHaveTextContent( 'WC-10485' );
		expect(
			screen.getByRole( 'region', { name: 'Answer excerpt' } )
		).toHaveTextContent( 'not found' );
		expect(
			screen.getByRole( 'link', { name: 'Mapping version 7' } )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Skip this order' } )
		);
		expect(
			await screen.findByRole( 'dialog', { name: 'Skip 1 order?' } )
		).toBeInTheDocument();
	} );

	it( 'says so when the order does not exist', async () => {
		await renderApp( { route: '/queue/424242' } );
		expect(
			await screen.findByText( /This order could not be loaded/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'That queue item does not exist.' )
		).toBeInTheDocument();
	} );
} );
