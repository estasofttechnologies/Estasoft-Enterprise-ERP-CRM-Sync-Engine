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

describe( 'Settings', () => {
	it( 'shows General with the privacy promise, and saves a change', async () => {
		const { user, server } = await renderApp( { route: '/settings' } );
		await expectHeading( 'Settings' );
		expect(
			screen.getByText(
				'This plugin has no usage tracking and talks only to the system you connect, and only after you set it up.'
			)
		).toBeInTheDocument();
		const nav = screen.getByRole( 'navigation', {
			name: 'Settings pages',
		} );
		expect(
			within( nav )
				.getAllByRole( 'link' )
				.map( ( link ) => link.textContent )
		).toEqual( [
			'General',
			'Notifications',
			'Performance',
			'Access',
			'Tools',
			'Readiness check',
		] );
		expect( await screen.findByLabelText( 'Show times in' ) ).toHaveValue(
			'store'
		);
		expect(
			screen.getByRole( 'option', { name: 'Store time (Asia/Dubai)' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Keep logs for' ) ).toHaveValue( '14' );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Delete all plugin data when the plugin is deleted',
			} )
		).not.toBeChecked();
		expect( screen.getByText( 'No changes' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.selectOptions(
			screen.getByLabelText( 'Keep logs for' ),
			'30'
		);
		expect( screen.getByText( '1 unsaved change' ) ).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Settings saved.' )
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) =>
					request.route === '/settings' && request.method === 'PUT'
			)?.body
		).toMatchObject( { log_retention_days: 30 } );
	} );

	it( 'shows times in UTC after the setting changes', async () => {
		const { user } = await renderApp( { route: '/settings' } );
		await user.selectOptions(
			await screen.findByLabelText( 'Show times in' ),
			'utc'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		await screen.findByText( 'Settings saved.' );
		await user.click(
			screen.getAllByRole( 'link', { name: 'Dashboard' } )[ 0 ]
		);
		expect(
			await screen.findByText( 'Mon 2 Nov 2026, 07:40' )
		).toBeInTheDocument();
	} );

	it( 'validates the email recipients and sends a test email', async () => {
		const { user } = await renderApp( {
			route: '/settings/notifications',
		} );
		const recipients = await screen.findByLabelText( 'Send emails to' );
		expect( recipients ).toHaveValue(
			'layla@cedarstone.example, ops@cedarstone.example'
		);
		expect(
			screen.getByText(
				'Notices inside WordPress always show. These are emails.'
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.clear( recipients );
		await user.type( recipients, 'not-an-address' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText(
				'“not-an-address” is not an email address.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'Fix 1 field to save' ) ).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect(
			screen.getByRole( 'button', { name: 'Send a test email' } )
		).toBeEnabled();
		await user.click(
			screen.getByRole( 'button', { name: 'Send a test email' } )
		);
		expect(
			await screen.findByText( /Test email sent to 2 recipients\./ )
		).toBeInTheDocument();
	} );

	it( 'shows performance options, the cron line and runs a capacity test', async () => {
		const { user } = await renderApp( { route: '/settings/performance' } );
		expect(
			await screen.findByRole( 'radio', { name: /Standard/ } )
		).toBeChecked();
		expect(
			screen.getByRole( 'region', {
				name: 'Cron line and worker command',
			} )
		).toHaveTextContent( 'wp action-scheduler run --group=estasoft-sync' );
		expect( screen.getByText( 'On site visits' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Items at the same time/ ) ).toHaveValue(
			2
		);
		await expectNoA11yViolations();
		const limit = screen.getByLabelText( /Items at the same time/ );
		await user.clear( limit );
		await user.type( limit, '11' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( 'Enter a number from 1 to 10.' )
		).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: 'Run a capacity test' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Capacity test',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Run the test' } )
		);
		expect(
			await within( dialog ).findByText( '1180' )
		).toBeInTheDocument();
		expect(
			within( dialog ).getByText(
				/more than enough for your busiest hour so far/
			)
		).toBeInTheDocument();
	} );

	it( 'explains who can do what, and what the current account can do', async () => {
		await renderApp( {
			route: '/settings/access',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		expect(
			await screen.findByRole( 'table', {
				name: 'Roles and what they can do',
			} )
		).toBeInTheDocument();
		expect(
			screen.getAllByText( 'estasoft_sync_manage' ).length
		).toBeGreaterThan( 0 );
		expect(
			screen.getByText(
				'Your account can view and operate. It cannot configure.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/The saved API key is never shown to anyone, including administrators/
			)
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'shows settings read-only to a manager', async () => {
		await renderApp( {
			route: '/settings',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		expect(
			await screen.findByText(
				'Only administrators can change settings. You can see them here.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Save changes' } )
		).not.toBeInTheDocument();
	} );
} );

describe( 'Readiness check', () => {
	it( 'lists the checks with words and runs them again', async () => {
		const { user } = await renderApp( { route: '/settings/readiness' } );
		expect(
			await screen.findByText(
				/3 things are worth fixing\. None of them stops you from continuing, but each can cause failed orders later\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Checks: 7 passed, 3 worth fixing, 0 need fixing'
			)
		).toBeInTheDocument();
		expect( screen.getAllByText( 'Passed' ) ).toHaveLength( 7 );
		await expectNoA11yViolations();
		await user.click(
			screen.getByRole( 'button', { name: 'Run the checks again' } )
		);
		await waitFor( () =>
			expect(
				screen.getByText( /Last run/ ).parentElement
			).toHaveTextContent( 'Last run Today 11:40' )
		);
	} );
} );

describe( 'Tools', () => {
	it( 'keeps Delete everything off until DELETE is typed, then deletes', async () => {
		const { user, server } = await renderApp( {
			route: '/settings/tools',
		} );
		await user.click(
			await screen.findByRole( 'button', { name: 'Delete data' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete all plugin data?',
		} );
		const confirm = within( dialog ).getByRole( 'button', {
			name: 'Delete everything',
		} );
		expect( confirm ).toBeDisabled();
		expect(
			within( dialog ).getByRole( 'button', {
				name: 'Export settings first',
			} )
		).toBeInTheDocument();
		await expectNoA11yViolations();
		await user.type(
			within( dialog ).getByLabelText( 'Type DELETE to confirm' ),
			'DELE'
		);
		expect( confirm ).toBeDisabled();
		await user.type(
			within( dialog ).getByLabelText( 'Type DELETE to confirm' ),
			'TE'
		);
		expect( confirm ).toBeEnabled();
		await user.click( confirm );
		expect(
			await screen.findByText(
				'All plugin data was deleted. Syncing has stopped.'
			)
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) => request.route === '/tools/delete-data'
			)?.body
		).toEqual( { word: 'DELETE' } );
	} );

	it( 'does not accept a different case', async () => {
		const { user } = await renderApp( { route: '/settings/tools' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Delete data' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		await user.type(
			within( dialog ).getByLabelText( 'Type DELETE to confirm' ),
			'delete'
		);
		expect(
			within( dialog ).getByRole( 'button', {
				name: 'Delete everything',
			} )
		).toBeDisabled();
	} );

	it( 'clears the logs after confirmation', async () => {
		const { user } = await renderApp( { route: '/settings/tools' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Clear logs' } )
		);
		await user.click(
			within(
				await screen.findByRole( 'dialog', { name: 'Clear the logs?' } )
			).getByRole( 'button', { name: 'Clear logs' } )
		);
		expect(
			await screen.findByText( /Logs cleared: 21300 entries removed\./ )
		).toBeInTheDocument();
	} );

	it( 'imports settings only after showing what will change', async () => {
		const { user, server } = await renderApp( {
			route: '/settings/tools',
		} );
		await screen.findByRole( 'button', { name: 'Choose a file' } );
		const input = document.querySelector(
			'input[type="file"]'
		) as HTMLInputElement;
		const file = new File( [ '{"settings":{}}' ], 'settings.json', {
			type: 'application/json',
		} );
		Object.defineProperty( file, 'text', {
			value: async () => '{"settings":{}}',
		} );
		await user.upload( input, file );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Import settings?',
		} );
		expect(
			within( dialog ).getByText(
				'Limits: Speed limit 4 to 3 per second. Applies now.'
			)
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) => request.route === '/tools/import'
			)?.body
		).toMatchObject( { confirm: false } );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Import' } )
		);
		expect(
			await screen.findByText(
				/Settings imported\. Mapping arrived as drafts/
			)
		).toBeInTheDocument();
		expect(
			server.requests
				.filter( ( request ) => request.route === '/tools/import' )
				.pop()?.body
		).toMatchObject( { confirm: true } );
	} );

	it( 'disables every tool for someone who cannot configure', async () => {
		await renderApp( {
			route: '/settings/tools',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		expect(
			await screen.findByRole( 'button', { name: 'Delete data' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Clear logs' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Export' } )
		).toBeDisabled();
	} );
} );
