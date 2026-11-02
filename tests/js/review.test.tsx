/**
 * Fixes from the review of the admin app against the real REST controllers: what the server
 * accepts (queue actions, tracking status), what it sends (details of an error, retention), and
 * how the screens behave when something fails.
 */
import { render } from '@testing-library/react';
import { ApiError, request, setTransport } from '../../src/api/client';
import type { TransportRequest } from '../../src/api/client';
import { NowContext } from '../../src/app/NowContext';
import type { InvoiceRow } from '../../src/api/types';
import { TimeLeft } from '../../src/screens/invoices/Invoices';
import {
	answer,
	expectHeading,
	expectNoA11yViolations,
	failRoute,
	patchJson,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

afterEach( resetApp );

const forGet = ( route: string ) => ( req: TransportRequest ) =>
	req.method === 'GET' && req.route === route;

describe( 'Queue actions follow what the server accepts', () => {
	it( 'has no "Sync again" for a synced or skipped row', async () => {
		const { user } = await renderApp( { route: '/queue' } );
		await screen.findByRole( 'row', { name: /#10490/ } );
		await user.click(
			screen.getByRole( 'button', {
				name: 'More actions for order #10490',
			} )
		);
		await screen.findByRole( 'menuitem', { name: 'Open order #10490' } );
		expect(
			screen.queryByRole( 'menuitem', { name: 'Sync again' } )
		).not.toBeInTheDocument();
	} );

	it( 'turns off the bulk actions that cannot work, and says why', async () => {
		const { user } = await renderApp( { route: '/queue' } );
		await screen.findByRole( 'row', { name: /#10490/ } );
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10490' } )
		);
		const bar = screen.getByRole( 'region', { name: 'Selected orders' } );
		expect(
			within( bar ).getByRole( 'button', { name: 'Retry selected' } )
		).toBeDisabled();
		expect(
			within( bar ).getByRole( 'button', { name: 'Skip selected' } )
		).toBeDisabled();
		expect(
			within( bar ).getByRole( 'button', { name: 'Restore selected' } )
		).toBeDisabled();
		expect(
			within( bar ).getByText(
				'Retry needs at least one failed or retrying order in the selection.'
			)
		).toBeInTheDocument();
		expect(
			within( bar ).getByText(
				'Skip needs at least one queued, retrying or failed order in the selection.'
			)
		).toBeInTheDocument();
		expect(
			within( bar ).getByText(
				'Restore needs at least one skipped order in the selection.'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();

		// Add a failed order: Retry and Skip come on, Restore stays off.
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10491' } )
		);
		expect(
			within( bar ).getByRole( 'button', { name: 'Retry selected' } )
		).toBeEnabled();
		expect(
			within( bar ).getByRole( 'button', { name: 'Skip selected' } )
		).toBeEnabled();
		expect(
			within( bar ).getByRole( 'button', { name: 'Restore selected' } )
		).toBeDisabled();
	} );

	it( 'sends only the orders the action works on and says how many were left alone', async () => {
		const { user, server } = await renderApp( { route: '/queue' } );
		await screen.findByRole( 'row', { name: /#10490/ } );
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10490' } )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10491' } )
		);
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10484' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Retry selected' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Retry 2 orders?',
		} );
		expect(
			within( dialog ).getByText(
				'1 selected order is not failed or retrying, so it is left as it is.'
			)
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Retry 2 orders' } )
		);
		await screen.findByText( /2 orders were sent back to the queue/ );
		const sent = server.requests.find(
			( entry ) => entry.route === '/queue/bulk'
		);
		expect( sent?.body ).toMatchObject( { action: 'retry' } );
		expect( ( sent?.body as { ids: number[] } ).ids ).toHaveLength( 2 );
		expect(
			server.items.find( ( item ) => item.entity_label === '10490' )
				?.state
		).toBe( 'synced' );
	} );

	it( 'reports the number the server did, not the number that was sent', async () => {
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
		await user.click(
			screen.getByRole( 'button', { name: 'Retry selected' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Retry 2 orders?',
		} );
		// Meanwhile one of them is sent by someone else: the server will skip it.
		const other = server.items.find(
			( item ) => item.entity_label === '10485'
		);
		if ( other ) {
			other.state = 'synced';
		}
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Retry 2 orders' } )
		);
		expect(
			await screen.findByText(
				'1 order was sent back to the queue. 1 more could not be sent back, because only a failed or retrying order can be.'
			)
		).toBeInTheDocument();
	} );

	it( 'says so when the server did none of them', async () => {
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
		await user.click(
			screen.getByRole( 'button', { name: 'Retry selected' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Retry 2 orders?',
		} );
		server.items
			.filter( ( item ) =>
				[ '10491', '10485' ].includes( item.entity_label )
			)
			.forEach( ( item ) => {
				item.state = 'synced';
			} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Retry 2 orders' } )
		);
		expect(
			await screen.findByText(
				/No order was sent back to the queue\. Only a failed or retrying order can be retried/
			)
		).toBeInTheDocument();
	} );

	it( 'restores the selected skipped orders and leaves the others', async () => {
		const { user, server } = await renderApp( {
			route: '/queue?state=skipped',
		} );
		await screen.findByRole( 'row', { name: /#10470/ } );
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select order #10470' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Restore selected' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Restore 1 order?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Restore 1 order' } )
		);
		expect(
			await screen.findByText( /1 order was restored\./ )
		).toBeInTheDocument();
		expect(
			server.items.find( ( item ) => item.entity_label === '10470' )
				?.state
		).toBe( 'queued' );
	} );

	it( 'shows an error when Undo is refused, instead of dropping it', async () => {
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
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Skip 1 order' } )
		);
		await screen.findByText( /1 order skipped\./ );
		setTransport(
			failRoute(
				( req ) =>
					req.route === '/queue/bulk' &&
					( req.body as { action?: string } )?.action === 'restore'
			)( server.transport )
		);
		await user.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect(
			await screen.findByText( /Could not reach your site/ )
		).toBeInTheDocument();
	} );
} );

describe( 'Pause notice', () => {
	it( 'shows an error when Resume now is refused', async () => {
		const { user, server } = await renderApp( { route: '/' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Pause syncing' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Pause syncing?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Pause syncing' } )
		);
		const resume = await screen.findByRole( 'button', {
			name: 'Resume now',
		} );
		setTransport(
			failRoute( ( req ) => req.route === '/resume' )( server.transport )
		);
		await user.click( resume );
		expect(
			await screen.findByText( /Could not reach your site/ )
		).toBeInTheDocument();
	} );

	it( 'takes the paused message away when the pause ends some other way', async () => {
		const { user } = await renderApp( { route: '/' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Pause syncing' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Pause syncing?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Pause syncing' } )
		);
		expect(
			await screen.findByText( /Syncing is paused for 1 hour/ )
		).toBeInTheDocument();
		// The dashboard has its own Resume button; using it must clear the old message.
		await user.click(
			await screen.findByRole( 'button', { name: 'Resume syncing' } )
		);
		await waitFor( () =>
			expect(
				screen.queryByText( /Syncing is paused for 1 hour/ )
			).not.toBeInTheDocument()
		);
	} );
} );

describe( 'Dashboard', () => {
	it( 'offers "Check Odoo now" to administrators only', async () => {
		await renderApp( { scenario: 'paused', route: '/' } );
		expect(
			await screen.findByRole( 'button', { name: 'Check Odoo now' } )
		).toBeInTheDocument();
	} );

	it( 'hides it from a manager, who can still resume', async () => {
		await renderApp( {
			scenario: 'paused',
			route: '/',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		const resume = await screen.findByRole( 'button', {
			name: 'Resume syncing',
		} );
		expect( resume ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Check Odoo now' } )
		).not.toBeInTheDocument();
	} );
} );

describe( 'Logs', () => {
	it( 'takes the retention from the settings, not from the range', async () => {
		const { user } = await renderApp( {
			route: '/logs',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await expectHeading( 'Logs' );
		expect(
			await screen.findByText( /Kept for 14 days\./ )
		).toBeInTheDocument();
		await user.selectOptions(
			screen.getByLabelText( 'Range' ),
			'Last 7 days'
		);
		await waitFor( () =>
			expect( window.location.hash ).toContain( 'range=7d' )
		);
		expect( screen.getByText( /Kept for 14 days\./ ) ).toBeInTheDocument();
		expect( screen.queryByText( /Kept for 7 days/ ) ).toBeNull();
	} );
} );

describe( 'Stock tracking status', () => {
	it( 'offers only "do not change" and "completed"', async () => {
		await renderApp( { route: '/stock/tracking' } );
		const select = await screen.findByLabelText( 'Move the order to' );
		const options = within( select )
			.getAllByRole( 'option' )
			.map( ( option ) => option.textContent );
		expect( options ).toEqual( [
			'Completed when delivered',
			'Do not change the status',
		] );
	} );

	it( 'keeps another real status that was saved, labelled with its slug', async () => {
		await renderApp( {
			route: '/stock/tracking',
			wrap: patchJson( forGet( '/stock/rules' ), ( data ) => ( {
				...data,
				delivered_becomes: 'processing',
			} ) ),
		} );
		const select = await screen.findByLabelText( 'Move the order to' );
		expect( select ).toHaveValue( 'processing' );
		expect(
			within( select )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [
			'Completed when delivered',
			'Do not change the status',
			'processing',
		] );
	} );

	it( 'reads a status saved with the wc- prefix as the same status', async () => {
		await renderApp( {
			route: '/stock/tracking',
			wrap: patchJson( forGet( '/stock/rules' ), ( data ) => ( {
				...data,
				delivered_becomes: 'wc-completed',
			} ) ),
		} );
		const select = await screen.findByLabelText( 'Move the order to' );
		expect( select ).toHaveValue( 'completed' );
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
	} );
} );

describe( 'Invoices', () => {
	it( 'counts every invoice by status, not only the first page', async () => {
		await renderApp( { route: '/invoices' } );
		const group = await screen.findByRole( 'group', {
			name: 'Filter by status',
		} );
		// 36 invoices, 25 to a page: the counts must come from the server's totals.
		await waitFor( () =>
			expect(
				within( group ).getByRole( 'button', { name: /All\s*36/ } )
			).toBeInTheDocument()
		);
		expect(
			within( group ).getByRole( 'button', { name: /Reported\s*32/ } )
		).toBeInTheDocument();
		expect(
			within( group ).getByRole( 'button', { name: /Waiting\s*2/ } )
		).toBeInTheDocument();
		expect(
			within( group ).getByRole( 'button', { name: /Rejected\s*1/ } )
		).toBeInTheDocument();
		expect(
			within( group ).getByRole( 'button', { name: /Not required\s*1/ } )
		).toBeInTheDocument();
	} );

	it( 'does not name one country in the note about the reporting time', async () => {
		await renderApp( { route: '/invoices' } );
		expect(
			await screen.findByText(
				/counts down to the reporting time set for this shop/
			)
		).toBeInTheDocument();
		expect( screen.queryByText( /Saudi consumer invoices/ ) ).toBeNull();
		expect(
			screen.getByText( /Rules and dates differ by country and change/ )
		).toBeInTheDocument();
	} );

	it( 'works the time left out of the deadline and the shared clock, so the colour moves on without a request', () => {
		const invoice: InvoiceRow = {
			order_id: 1,
			order_label: '#1',
			number: 'INV/1',
			status: 'waiting',
			authority_status: null,
			issued_at: null,
			due_at: '2026-11-02T20:00:00Z',
			seconds_left: 99999,
			pdf_url: null,
			reject_reason: null,
			total: null,
		};
		const view = ( now: string ) => (
			<NowContext.Provider value={ now }>
				<TimeLeft row={ invoice } />
			</NowContext.Provider>
		);
		const { rerender } = render( view( '2026-11-02T07:40:00Z' ) );
		// 12 h 20 min left: still calm.
		expect( screen.getByText( '12 h 20 min left' ) ).toHaveClass(
			'estasoft-sync-timeleft--neutral'
		);
		rerender( view( '2026-11-02T09:00:00Z' ) );
		// 11 h left: warning.
		expect( screen.getByText( '11 h left' ) ).toHaveClass(
			'estasoft-sync-timeleft--warning'
		);
		rerender( view( '2026-11-02T17:30:00Z' ) );
		// 2 h 30 min left: danger.
		expect( screen.getByText( '2 h 30 min left' ) ).toHaveClass(
			'estasoft-sync-timeleft--danger'
		);
		rerender( view( '2026-11-02T21:00:00Z' ) );
		expect( screen.getByText( 'Late by 1 h' ) ).toHaveClass(
			'estasoft-sync-timeleft--danger'
		);
	} );

	it( 'shows the link to the invoice only when it is a web address', async () => {
		await renderApp( {
			route: '/invoices',
			wrap: patchJson( forGet( '/invoices' ), ( data ) => ( {
				...data,
				items: ( data.items as InvoiceRow[] ).map( ( item ) =>
					item.order_id === 10462
						? { ...item, pdf_url: 'javascript:alert(1)' }
						: item
				),
			} ) ),
		} );
		const rejected = await screen.findByRole( 'row', { name: /#10462/ } );
		expect(
			within( rejected ).queryByRole( 'link', { name: /Open in Odoo/ } )
		).toBeNull();
		expect(
			within( rejected ).getByText( /The buyer’s address is missing/ )
		).toBeInTheDocument();
	} );
} );

describe( 'Reconciliation schedule form', () => {
	it( 'keeps what is being typed when the page loads fresh data, and goes back only on Discard or a save', async () => {
		const { user } = await renderApp( { route: '/reconciliation' } );
		const time = await screen.findByLabelText( 'Runs every night at' );
		const saved = ( time as HTMLSelectElement ).value;
		expect( saved ).not.toBe( '03:00' );
		await user.selectOptions( time, '03:00' );

		// Starting a check makes the page load the overview again (and poll while it runs).
		await user.click(
			screen.getByRole( 'button', { name: 'Run the check now' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Run the check now?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Run the check' } )
		);
		await screen.findByText( 'A check is running.' );
		expect( screen.getByLabelText( 'Runs every night at' ) ).toHaveValue(
			'03:00'
		);

		await user.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect( screen.getByLabelText( 'Runs every night at' ) ).toHaveValue(
			saved
		);

		await user.selectOptions(
			screen.getByLabelText( 'Runs every night at' ),
			'04:00'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		await screen.findByText( 'Schedule saved.' );
		expect( screen.getByLabelText( 'Runs every night at' ) ).toHaveValue(
			'04:00'
		);
	} );

	it( 'does not promise that an ignored difference can be reopened', async () => {
		const { user } = await renderApp( { route: '/reconciliation' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Ignore #10377' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Ignore the difference on order #10377?',
		} );
		expect( within( dialog ).queryByText( /reopen/ ) ).toBeNull();
		expect(
			within( dialog ).getByText(
				/It stays in the list of differences\./
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Settings notifications', () => {
	const recipients = 'layla@cedarstone.example, ops@cedarstone.example';

	it( 'puts the recipients box back on Discard', async () => {
		const { user } = await renderApp( {
			route: '/settings/notifications',
		} );
		const box = await screen.findByLabelText( 'Send emails to' );
		expect( box ).toHaveValue( recipients );
		await user.type( box, ', extra@cedarstone.example' );
		expect( box ).toHaveValue(
			`${ recipients }, extra@cedarstone.example`
		);
		await user.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect( screen.getByLabelText( 'Send emails to' ) ).toHaveValue(
			recipients
		);
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
	} );

	it( 'says which recipients the test email goes to, and waits for a save when they changed', async () => {
		const { user } = await renderApp( {
			route: '/settings/notifications',
		} );
		const box = await screen.findByLabelText( 'Send emails to' );
		expect(
			screen.getByText(
				`Sends a short message to the saved recipients: ${ recipients }.`
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Send a test email' } )
		).toBeEnabled();
		await user.type( box, ', extra@cedarstone.example' );
		expect(
			screen.getByText(
				'Save your changes first. The test goes to the saved recipients, not to what is typed above.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Send a test email' } )
		).toBeDisabled();
	} );
} );

describe( 'Delete all data', () => {
	it( 'warns that connecting again can send orders twice', async () => {
		const { user } = await renderApp( { route: '/settings/tools' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Delete data' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete all plugin data?',
		} );
		expect(
			within( dialog ).getByText(
				/If you connect again afterwards, orders that were already sent can be sent a second time\./
			)
		).toBeInTheDocument();
	} );
} );

async function fillOdoo(
	user: Awaited< ReturnType< typeof renderApp > >[ 'user' ]
) {
	await user.type(
		await screen.findByLabelText( 'Odoo address' ),
		'https://erp.cedarstone.example'
	);
	await user.type( screen.getByLabelText( 'Database' ), 'cedarstone_prod' );
	await user.type(
		screen.getByLabelText( 'Sync user (email)' ),
		'sync-bot@cedarstone.example'
	);
	await user.type( screen.getByLabelText( 'API key' ), 'good-key' );
}

async function toConnectStep(
	user: Awaited< ReturnType< typeof renderApp > >[ 'user' ]
) {
	await screen.findByText( 'Is your store ready?' );
	await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
	await user.click(
		( await screen.findAllByRole( 'button', { name: 'Select Odoo' } ) )[ 0 ]
	);
	await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
}

describe( 'Setup wizard', () => {
	it( 'does not treat a store check that could not run as a pass', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			wrap: failRoute( forGet( '/readiness' ) ),
		} );
		expect(
			await screen.findByText( 'The store check could not run.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();
		expect(
			screen.getByText( /A check that did not run is not a pass/ )
		).toBeInTheDocument();
		await expectNoA11yViolations();

		await user.click(
			screen.getByRole( 'button', { name: 'Continue anyway' } )
		);
		// Focus goes to the heading of the new step, which is also announced.
		const heading = await screen.findByRole( 'heading', {
			level: 2,
			name: 'Connect a system',
		} );
		await waitFor( () => expect( heading ).toHaveFocus() );
		await waitFor( () =>
			expect(
				document.getElementById( 'a11y-speak-polite' )
			).toHaveTextContent( 'Step 2 of 5: Connect a system' )
		);
	} );

	it( 'moves focus to the step heading on every step change', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
		} );
		await screen.findByText( 'Is your store ready?' );
		await user.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		const second = await screen.findByRole( 'heading', {
			level: 2,
			name: 'Connect a system',
		} );
		await waitFor( () => expect( second ).toHaveFocus() );
		await user.click( screen.getByRole( 'button', { name: 'Back' } ) );
		const first = await screen.findByRole( 'heading', {
			level: 2,
			name: 'Check your store',
		} );
		await waitFor( () => expect( first ).toHaveFocus() );
	} );

	it( 'keeps the "Go live now" dialog open with the reason when starting fails', async () => {
		const { user, server } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			wrap: answer(
				( req ) => req.method === 'POST' && req.route === '/go-live',
				409,
				{
					code: 'estasoft_sync_conflict',
					message: 'No connection is ready to go live.',
					data: { status: 409 },
				}
			),
		} );
		await toConnectStep( user );
		await fillOdoo( user );
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
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Go live now' } )
		);
		expect(
			await within( dialog ).findByText(
				'No connection is ready to go live.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'dialog', { name: 'Go live now?' } )
		).toBeInTheDocument();
		expect( server.onboarding.completed ).toBe( false );
	} );

	it( 'shows the wp-config line when saving fails because the encryption key is missing', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			wrap: answer(
				( req ) =>
					req.method === 'POST' && req.route === '/connections',
				409,
				{
					code: 'SYNC-CFG-002',
					message:
						'The encryption key is missing from wp-config.php.',
					data: {
						status: 409,
						details: {
							key_line:
								"define( 'ESTASOFT_SYNC_KEY', 'abc123' );",
						},
					},
				}
			),
		} );
		await toConnectStep( user );
		await fillOdoo( user );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText(
				"define( 'ESTASOFT_SYNC_KEY', 'abc123' );"
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Copy the line' } )
		).toBeInTheDocument();
		expect( screen.getByText( /Nothing was saved\./ ) ).toBeInTheDocument();
	} );

	it( 'lists what the user may not do when the saving test fails', async () => {
		const { user } = await renderApp( {
			scenario: 'first_run',
			route: '/setup',
			wrap: answer(
				( req ) =>
					req.method === 'POST' && req.route === '/connections',
				422,
				{
					code: 'SYNC-AUTH-004',
					message: 'This user cannot create sale orders.',
					data: {
						status: 422,
						details: {
							test: {
								ok: false,
								version: 'Odoo 18 Enterprise',
								company: '',
								warehouses: [],
								product_count: null,
								permissions: { create_sale_orders: false },
								missing_permissions: [ 'create_sale_orders' ],
								error: {
									code: 'SYNC-AUTH-004',
									message:
										'This user cannot create sale orders.',
									data: { status: 422 },
								},
							},
						},
					},
				}
			),
		} );
		await toConnectStep( user );
		await fillOdoo( user );
		await user.click(
			screen.getByRole( 'button', { name: 'Test connection' } )
		);
		expect(
			await screen.findByText(
				'The connection test found this access missing: create_sale_orders.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( /^This user cannot create sale orders\./, {
				selector: 'p',
			} )
		).toBeInTheDocument();
		expect( screen.queryByText( /nothing is lost/i ) ).toBeNull();
	} );
} );

describe( 'Details that come with an error', () => {
	const sendError = ( data: unknown ) =>
		setTransport( async () => ( {
			status: 422,
			headers: { get: () => null },
			text: async () =>
				JSON.stringify( {
					code: 'rest_invalid_param',
					message: 'Refused.',
					data: { status: 422, ...( data as object ) },
				} ),
		} ) );

	it( 'keeps the three documented shapes on the ApiError', async () => {
		sendError( {
			details: {
				errors: [
					{
						rule_id: 'r4',
						field: 'target',
						code: 'target_unknown',
						message: 'Unknown target.',
					},
				],
				test: {
					ok: false,
					version: 'Odoo 18',
					company: '',
					warehouses: [],
					product_count: null,
					permissions: {},
					missing_permissions: [ 'create_sale_orders' ],
					error: null,
				},
				key_line: "define( 'ESTASOFT_SYNC_KEY', 'x' );",
			},
		} );
		const error = await request( '/mappings/orders/publish', {
			method: 'POST',
		} ).catch( ( caught: unknown ) => caught );
		expect( error ).toBeInstanceOf( ApiError );
		const details = ( error as ApiError ).details;
		expect( details.errors ).toEqual( [
			{
				rule_id: 'r4',
				field: 'target',
				code: 'target_unknown',
				message: 'Unknown target.',
			},
		] );
		expect( details.test?.missing_permissions ).toEqual( [
			'create_sale_orders',
		] );
		expect( details.keyLine ).toBe( "define( 'ESTASOFT_SYNC_KEY', 'x' );" );
	} );

	it( 'drops details of another shape instead of trusting them', async () => {
		sendError( {
			details: {
				errors: 'nope',
				test: { ok: 'maybe' },
				key_line: 7,
			},
		} );
		const error = ( await request( '/x', { method: 'POST' } ).catch(
			( caught: unknown ) => caught
		) ) as ApiError;
		expect( error.details ).toEqual( {
			errors: [],
			test: null,
			keyLine: null,
		} );
	} );

	it( 'has empty details when the server sends none', async () => {
		sendError( {} );
		const error = ( await request( '/x', { method: 'POST' } ).catch(
			( caught: unknown ) => caught
		) ) as ApiError;
		expect( error.details.errors ).toEqual( [] );
		expect( error.details.keyLine ).toBeNull();
	} );
} );

describe( 'Mapping', () => {
	const editBillingPhone = async (
		user: Awaited< ReturnType< typeof renderApp > >[ 'user' ]
	) => {
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Edit the Billing phone rule',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Edit rule: Billing phone',
		} );
		await user.clear(
			within( dialog ).getByLabelText( /Default country/ )
		);
		await user.type(
			within( dialog ).getByLabelText( /Default country/ ),
			'SA'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Save rule' } )
		);
	};

	it( 'names each rule the server refuses to publish', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
			wrap: answer(
				( req ) =>
					req.method === 'POST' &&
					req.route === '/mappings/orders/publish',
				422,
				{
					code: 'rest_invalid_param',
					message: 'The mapping cannot be published.',
					data: {
						status: 422,
						details: {
							errors: [
								{
									rule_id: 'r4',
									field: 'transforms[0]',
									code: 'transform_unknown',
									message: 'The country code is not valid.',
								},
							],
						},
					},
				}
			),
		} );
		await editBillingPhone( user );
		await user.click(
			screen.getByRole( 'button', { name: 'Publish as version 8' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Publish version 8?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', {
				name: 'Publish version 8',
			} )
		);
		expect(
			await within( dialog ).findByText(
				'Billing phone: The country code is not valid.'
			)
		).toBeInTheDocument();
		expect(
			within( dialog ).getByText(
				'1 problem to fix before this can go live:'
			)
		).toBeInTheDocument();
		// The dialog stays open, and the edits were saved first so the server judged what is on screen.
		expect(
			screen.getByRole( 'dialog', { name: 'Publish version 8?' } )
		).toBeInTheDocument();
		const calls = server.requests.map(
			( entry ) => `${ entry.method } ${ entry.route }`
		);
		const saved = calls.indexOf( 'PUT /mappings/orders' );
		expect( saved ).toBeGreaterThan( -1 );
		// The dialog's own preview comes after the save, so it judges what is on screen.
		expect( calls.slice( saved + 1 ) ).toContain(
			'POST /mappings/preview'
		);
	} );

	it( 'says the preview does not include unsaved edits, and saves them on request', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
		} );
		await editBillingPhone( user );
		await user.click(
			screen.getByRole( 'button', { name: 'Preview with a real order' } )
		);
		const preview = await screen.findByRole( 'dialog', {
			name: 'Preview with a real order',
		} );
		expect(
			within( preview ).getByText(
				/The edits you have not saved are not in this preview\./
			)
		).toBeInTheDocument();
		await user.click(
			within( preview ).getByRole( 'button', { name: 'Save the draft' } )
		);
		await waitFor( () =>
			expect(
				within( preview ).queryByText(
					/The edits you have not saved are not in this preview\./
				)
			).toBeNull()
		);
		expect(
			server.requests.some(
				( entry ) =>
					entry.method === 'PUT' && entry.route === '/mappings/orders'
			)
		).toBe( true );
	} );

	it( 'shows the rules of an older version from the server', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
		} );
		const versions = (
			await screen.findByRole( 'heading', { name: 'Versions' } )
		).closest( 'section' ) as HTMLElement;
		await user.click(
			within( versions ).getByRole( 'button', { name: 'View version 6' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Version 6',
		} );
		await waitFor( () =>
			expect(
				within( dialog ).getAllByRole( 'listitem' ).length
			).toBeGreaterThan( 0 )
		);
		expect(
			server.requests.some(
				( entry ) =>
					entry.route === '/mappings/orders/versions/6' &&
					entry.method === 'GET'
			)
		).toBe( true );
		expect(
			within( dialog ).queryByText( /kept but not listed here/ )
		).toBeNull();
	} );
} );

describe( 'Links from the connected system', () => {
	const detail = ( url: string | null ) =>
		patchJson(
			( req ) =>
				req.method === 'GET' && /^\/queue\/90$/.test( req.route ),
			( data ) => ( {
				...data,
				external_url: url,
				external_ref: 'S00490',
				order_edit_url: url,
			} )
		);

	it( 'turns a web address into a link', async () => {
		await renderApp( {
			route: '/queue/90',
			wrap: detail( 'https://erp.cedarstone.example/orders/490' ),
		} );
		expect(
			await screen.findByRole( 'link', { name: 'S00490' } )
		).toHaveAttribute(
			'href',
			'https://erp.cedarstone.example/orders/490'
		);
	} );

	it( 'shows the reference as plain text when the address is not http or https', async () => {
		await renderApp( {
			route: '/queue/90',
			wrap: detail( 'javascript:alert(1)' ),
		} );
		await screen.findByText( 'S00490' );
		expect( screen.queryByRole( 'link', { name: 'S00490' } ) ).toBeNull();
		expect(
			screen.queryByRole( 'link', { name: /Open order #10490/ } )
		).toBeNull();
		expect( document.querySelector( 'a[href^="javascript:"]' ) ).toBeNull();
	} );
} );

describe( 'Settings for a manager', () => {
	it( 'shows times in UTC when the setting says so, although a manager cannot change it', async () => {
		await renderApp( {
			route: '/',
			boot: { capabilities: { manage: true, configure: false } },
			wrap: patchJson( forGet( '/settings' ), ( data ) => ( {
				...data,
				display_time: 'utc',
			} ) ),
		} );
		await expectHeading( 'Dashboard' );
		// The sample "now" is 11:40 in Dubai, 07:40 UTC.
		expect(
			await screen.findByText( /Mon 2 Nov 2026, 07:40/ )
		).toBeInTheDocument();
	} );
} );

describe( 'Plural forms that follow their own count', () => {
	it( 'says "1 order is waiting" when syncing is paused with one order in the queue', async () => {
		await renderApp( {
			scenario: 'paused',
			route: '/',
			wrap: patchJson( forGet( '/status' ), ( data ) => ( {
				...data,
				waiting: 1,
			} ) ),
		} );
		expect(
			await screen.findByText( /1 order is waiting in the queue/ )
		).toBeInTheDocument();
	} );

	it( 'counts groups by their own number on Needs attention', async () => {
		await renderApp( { route: '/queue/attention' } );
		expect(
			await screen.findByText( /5 failed orders in 3 groups\./ )
		).toBeInTheDocument();
	} );
} );

describe( 'Connection picker on Mapping', () => {
	it( 'sends the chosen connection with the mapping requests, and nothing from other screens', async () => {
		const seen: Record< string, string >[] = [];
		await renderApp( {
			route: '/mapping/orders?connection=13',
			wrap: ( inner ) => async ( req ) => {
				if ( req.route.startsWith( '/mappings' ) ) {
					seen.push( req.query );
				}
				return inner( req );
			},
		} );
		await screen.findByRole( 'button', {
			name: 'Edit the Billing phone rule',
		} );
		expect( seen.length ).toBeGreaterThan( 0 );
		expect( seen.every( ( query ) => query.connection_id === '13' ) ).toBe(
			true
		);
	} );

	it( 'sends none when no connection was picked', async () => {
		const seen: Record< string, string >[] = [];
		await renderApp( {
			route: '/mapping/orders',
			wrap: ( inner ) => async ( req ) => {
				if ( req.route.startsWith( '/mappings' ) ) {
					seen.push( req.query );
				}
				return inner( req );
			},
		} );
		await screen.findByRole( 'button', {
			name: 'Edit the Billing phone rule',
		} );
		expect(
			seen.every( ( query ) => query.connection_id === undefined )
		).toBe( true );
	} );
} );
