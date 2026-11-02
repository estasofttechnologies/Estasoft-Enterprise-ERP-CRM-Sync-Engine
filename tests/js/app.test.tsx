import { act } from '@testing-library/react';
import {
	expectHeading,
	expectNoA11yViolations,
	goTo,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

afterEach( resetApp );

describe( 'app frame', () => {
	it( 'shows the brand, nine tabs and the connection state, and marks the current tab', async () => {
		await renderApp();
		await expectHeading( 'Dashboard' );
		const nav = screen.getByRole( 'navigation', {
			name: 'ESTASOFT SYNC sections',
		} );
		const labels = within( nav )
			.getAllByRole( 'link' )
			.map( ( link ) => link.textContent );
		expect( labels ).toEqual( [
			'Dashboard',
			'Queue',
			'Invoices',
			'Connections',
			'Mapping',
			'Stock',
			'Reconciliation',
			'Logs',
			'Settings',
		] );
		expect(
			within( nav ).getByRole( 'link', { name: 'Dashboard' } )
		).toHaveAttribute( 'aria-current', 'page' );
		expect(
			await screen.findByText( 'Odoo connected' )
		).toBeInTheDocument();
		expect( screen.getByText( 'ESTASOFT SYNC' ) ).toBeInTheDocument();
	} );

	it( 'moves between screens with the hash router and moves focus to the heading', async () => {
		const { user } = await renderApp();
		await expectHeading( 'Dashboard' );
		await user.click( screen.getByRole( 'link', { name: 'Queue' } ) );
		await expectHeading( 'Queue' );
		expect( window.location.hash ).toBe( '#/queue' );
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { level: 1, name: 'Queue' } )
			).toHaveFocus()
		);
		await goTo( '/logs' );
		await expectHeading( 'Logs' );
	} );

	it( 'collapses the tabs into a sections menu that opens, lists nine rows and closes with Escape', async () => {
		const { user } = await renderApp();
		await expectHeading( 'Dashboard' );
		const button = screen.getByRole( 'button', {
			name: 'Sections, now Dashboard',
		} );
		expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		await user.click( button );
		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		const lists = screen.getAllByRole( 'navigation', {
			name: 'ESTASOFT SYNC sections',
		} );
		const menu = lists[ lists.length - 1 ];
		expect( within( menu ).getAllByRole( 'link' ) ).toHaveLength( 9 );
		expect(
			within( menu ).getByRole( 'link', { name: /Queue/ } )
		).toHaveTextContent( '14' );
		await user.keyboard( '{Escape}' );
		expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( button ).toHaveFocus();
	} );

	it( 'shows notices from the page and lets the person dismiss them', async () => {
		const { user } = await renderApp( {
			boot: {
				notices: [
					{
						id: 'welcome',
						kind: 'success',
						text: 'ESTASOFT SYNC is active.',
						dismissible: true,
					},
				],
			},
		} );
		expect(
			await screen.findByText( 'ESTASOFT SYNC is active.' )
		).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: /Close|Dismiss/i } )
		);
		expect(
			screen.queryByText( 'ESTASOFT SYNC is active.' )
		).not.toBeInTheDocument();
	} );

	it( 'explains the missing permission to someone without the view-and-operate capability', async () => {
		await renderApp( {
			boot: { capabilities: { manage: false, configure: false } },
		} );
		expect(
			await screen.findByText( /does not have access to ESTASOFT SYNC/ )
		).toBeInTheDocument();
	} );

	it( 'has no accessibility violations on the frame', async () => {
		await renderApp();
		await expectHeading( 'Dashboard' );
		await screen.findByText( 'Orders synced per hour' );
		await expectNoA11yViolations();
	} );

	it( 'falls back to the dashboard for an unknown route', async () => {
		await renderApp( { route: '/nothing-here' } );
		await expectHeading( 'Dashboard' );
		expect(
			screen.getByRole( 'heading', { level: 1, name: 'Dashboard' } )
		).toBeVisible();
		await act( async () => undefined );
	} );
} );
