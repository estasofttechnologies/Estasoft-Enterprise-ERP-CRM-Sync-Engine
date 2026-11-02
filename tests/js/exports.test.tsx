/**
 * The Exports screen (board D06) and its link on the dashboard.
 */
import {
	answer,
	expectHeading,
	expectNoA11yViolations,
	renderApp,
	resetApp,
	screen,
	waitFor,
	within,
} from './helpers';

const saved: { name: string; size: number }[] = [];

beforeEach( () => {
	saved.length = 0;
	// jsdom has no object URLs: record what was saved instead.
	URL.createObjectURL = ( blob: Blob | MediaSource ) => {
		saved.push( { name: '', size: ( blob as Blob ).size } );
		return 'blob:mock';
	};
	URL.revokeObjectURL = () => undefined;
	jest.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation(
		function click( this: HTMLAnchorElement ) {
			saved[ saved.length - 1 ].name = this.download;
		}
	);
} );

afterEach( () => {
	jest.restoreAllMocks();
	resetApp();
} );

describe( 'Exports screen', () => {
	it( 'shows what is waiting, the files, the orders left out and why, and how to import', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
		} );
		await expectHeading( 'Exports' );
		expect(
			await screen.findByText(
				/Everything since the last export: 9 orders\./
			)
		).toBeInTheDocument();
		const table = screen.getByRole( 'table', { name: 'Previous exports' } );
		expect(
			within( table ).getByText(
				'cedar-stone-2026-11-02-1140-7f3a9c41.xml'
			)
		).toBeInTheDocument();
		expect( within( table ).getByText( '38 orders' ) ).toBeInTheDocument();
		expect(
			within( table ).getByText( '3 orders left out' )
		).toBeInTheDocument();
		// The orders left out, with the reason (SYNC-EXP-002).
		const left = screen.getByRole( 'table', {
			name: 'Orders left out of the export',
		} );
		expect( within( left ).getByText( '#10497' ) ).toBeInTheDocument();
		expect(
			within( left ).getAllByText( /Payment method Tabby has no ledger/ )
		).toHaveLength( 3 );
		expect( screen.getByText( 'SYNC-EXP-002' ) ).toBeInTheDocument();
		// The import guide of the format.
		expect(
			screen.getByRole( 'heading', { name: 'Import in TallyPrime' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Press Alt\+O \(Import\)/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Exported', {
				selector: '.estasoft-sync-chip, .estasoft-sync-chip *',
			} )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'makes a file, says how many orders it holds, and lists the ones it had to leave out', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Create export file now',
			} )
		);
		expect(
			await screen.findByText( /is ready, with 8 orders\./, {
				selector: 'p',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/1 order were left out of this file|1 order was left out of this file|were left out of this file/
			)
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) =>
					request.route === '/exports' && request.method === 'POST'
			)?.body
		).toEqual( { connection_id: 22 } );
		// A second file has nothing left out and the waiting number is back to the one that stayed.
		await waitFor( () =>
			expect(
				screen.getByText(
					/Everything since the last export: 1 order\./
				)
			).toBeInTheDocument()
		);
	} );

	it( 'says nothing is waiting and keeps the button off', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
			wrap: ( inner ) => async ( req ) => {
				const response = await inner( req );
				if ( req.route !== '/exports' || req.method !== 'GET' ) {
					return response;
				}
				const data = JSON.parse( await response.text() );
				const text = JSON.stringify( { ...data, open_batch: null } );
				return { ...response, text: async () => text };
			},
		} );
		expect(
			await screen.findByText( /No orders are waiting for a file/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Create export file now' } )
		).toBeDisabled();
	} );

	it( 'shows SYNC-EXP-001 with a way to try again, and keeps the orders queued', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
			failures: {
				'POST /exports': {
					status: 500,
					code: 'SYNC-EXP-001',
					message:
						'The export file could not be written to the uploads folder.',
				},
			},
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Create export file now',
			} )
		);
		expect(
			await screen.findByText(
				/The export file could not be written to the uploads folder\./
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'SYNC-EXP-001' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Nothing is lost: the orders stay Queued\./ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Create again' } )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'downloads a file byte for byte and says so', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Download cedar-stone-2026-11-01-1800-1b8e0d52.xml',
			} )
		);
		await waitFor( () => expect( saved ).toHaveLength( 1 ) );
		expect( saved[ 0 ].name ).toBe(
			'cedar-stone-2026-11-01-1800-1b8e0d52.xml'
		);
		expect( saved[ 0 ].size ).toBeGreaterThan( 20 );
	} );

	it( 'downloads the list that goes with a file', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Download Masters list for cedar-stone-2026-11-02-1140-7f3a9c41.xml',
			} )
		);
		await waitFor( () => expect( saved ).toHaveLength( 1 ) );
		expect( saved[ 0 ].name ).toBe( 'masters-7f3a9c41.csv' );
		expect(
			server.requests.some(
				( request ) =>
					request.route.endsWith( '/download' ) &&
					request.query.part === '1'
			)
		).toBe( true );
	} );

	it( 'shows SYNC-EXP-002 when every order was left out, with what to do', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
			failures: {
				'POST /exports': {
					status: 422,
					code: 'SYNC-EXP-002',
					message:
						'9 orders were left out of the export: Payment method Tabby has no ledger in TallyPrime yet.',
				},
			},
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Create export file now',
			} )
		);
		expect(
			await screen.findByText( /9 orders were left out of the export/ )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Map the missing ledger or code, then export again/
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Nothing is lost: the orders stay Queued/ )
		).not.toBeInTheDocument();
	} );

	it( 'shows a failed download instead of nothing', async () => {
		const { user } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
			wrap: answer( ( req ) => req.route.endsWith( '/download' ), 403, {
				code: 'rest_forbidden',
				message: 'You do not have permission to do this.',
				data: { status: 403 },
			} ),
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Download cedar-stone-2026-10-31-1800-c04477aa.xml',
			} )
		);
		expect(
			await screen.findByText( 'You do not have permission to do this.' )
		).toBeInTheDocument();
		expect( saved ).toHaveLength( 0 );
	} );

	it( 'asks before it deletes one file, and before it deletes them all', async () => {
		const { user, server } = await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Delete cedar-stone-2026-10-31-1800-c04477aa.xml',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete this export file?',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Delete' } )
		);
		expect(
			await screen.findByText( '1 export file was deleted.' )
		).toBeInTheDocument();
		expect( server.connect.exports.get( 22 )?.files ).toHaveLength( 2 );

		await user.click(
			screen.getByRole( 'button', {
				name: 'Delete all export files now',
			} )
		);
		const all = await screen.findByRole( 'dialog', {
			name: 'Delete all export files?',
		} );
		await user.click(
			within( all ).getByRole( 'button', { name: 'Delete' } )
		);
		expect(
			await screen.findByText( '2 export files were deleted.' )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'heading', { name: 'No exports yet' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Nothing is sent anywhere\./ )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'lets a manager download but not create or delete', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections/22/exports',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await screen.findByRole( 'table', { name: 'Previous exports' } );
		expect(
			screen.getByRole( 'button', { name: 'Create export file now' } )
		).toBeDisabled();
		expect(
			screen.getByText( 'Only an administrator can create a file.' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /^Delete / } )
		).not.toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: /^Download / } ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'is on the connection page, with no Test connection for a connection that uses files', async () => {
		await renderApp( {
			scenario: 'systems',
			route: '/connections/22',
		} );
		await expectHeading( 'TallyPrime' );
		expect(
			await screen.findByRole( 'button', {
				name: 'Open the Exports screen',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Test connection' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText( 'Keeping orders safe' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /Change the/ } )
		).not.toBeInTheDocument();
		await expectNoA11yViolations();
	} );
} );

describe( 'Dashboard link', () => {
	it( 'shows the waiting orders and a way into Exports when a connection uses files', async () => {
		const { user } = await renderApp( { scenario: 'systems', route: '/' } );
		const card = await screen.findByRole( 'region', {
			name: 'Export files',
		} );
		expect(
			await within( card ).findByText(
				'9 orders waiting for the next file.'
			)
		).toBeInTheDocument();
		await user.click(
			within( card ).getByRole( 'button', { name: 'Open Exports' } )
		);
		await waitFor( () =>
			expect( window.location.hash ).toBe( '#/connections/22/exports' )
		);
	} );

	it( 'shows no card when no connection uses files', async () => {
		await renderApp( { route: '/' } );
		await screen.findByRole( 'heading', { level: 1, name: 'Dashboard' } );
		expect(
			screen.queryByRole( 'heading', { name: 'Export files' } )
		).not.toBeInTheDocument();
	} );
} );
