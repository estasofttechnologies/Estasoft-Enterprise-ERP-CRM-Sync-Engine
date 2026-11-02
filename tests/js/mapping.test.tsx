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

describe( 'Mapping', () => {
	it( 'shows the order rules with plain-language descriptions, samples and the live version', async () => {
		await renderApp( { route: '/mapping/orders' } );
		await expectHeading( 'Mapping' );
		expect(
			await screen.findByText(
				/Orders to Odoo sale orders\. Version 7 is live\. No draft\./
			)
		).toBeInTheDocument();
		const nav = screen.getByRole( 'navigation', { name: 'Mapping pages' } );
		expect(
			within( nav )
				.getAllByRole( 'link' )
				.map( ( link ) => link.textContent )
		).toEqual( [
			'Orders',
			'Customers',
			'Products',
			'Refunds',
			'Stock and tracking',
			'Tax',
		] );
		expect(
			within( nav ).getByRole( 'link', { name: 'Orders' } )
		).toHaveAttribute( 'aria-current', 'page' );
		const table = await screen.findByRole( 'table', {
			name: /Rules: Orders/,
		} );
		const orderNumber = within( table ).getByRole( 'row', {
			name: /Order number/,
		} );
		expect(
			within( orderNumber ).getByText( 'Add the prefix WC-' )
		).toBeInTheDocument();
		expect(
			within( orderNumber ).getByText( 'client_order_ref' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect(
				within( orderNumber ).getByText( 'WC-10490' )
			).toBeInTheDocument()
		);
		expect( screen.getByLabelText( 'Connection' ) ).toBeDisabled();
		expect(
			screen.getByText( 'Each connection has its own settings.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Publish as version 8' } )
		).toBeDisabled();
		expect( screen.getByText( '10 fields' ) ).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'edits a rule into a draft, shows what changes, and publishes the next version with a note', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Edit the Billing phone rule',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Edit rule: Billing phone',
		} );
		expect( within( dialog ).getByLabelText( 'Rule' ) ).toHaveValue(
			'phone_e164'
		);
		await expectNoA11yViolations();
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

		const row = screen.getByRole( 'row', { name: /Billing phone/ } );
		expect( within( row ).getByText( 'Changed' ) ).toBeInTheDocument();
		expect( screen.getByText( /Draft: 1 change\./ ) ).toBeInTheDocument();
		expect(
			screen.getByText( /You have unsaved edits/ )
		).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: 'Save draft' } )
		);
		expect(
			await screen.findByText(
				'Draft saved. It is not live until you publish it.'
			)
		).toBeInTheDocument();
		expect(
			server.mappings.orders.draft?.rules.find(
				( rule ) => rule.id === 'r4'
			)?.transforms[ 0 ].args
		).toEqual( { default_country: 'SA' } );

		await user.click(
			screen.getByRole( 'button', { name: 'Publish as version 8' } )
		);
		const publish = await screen.findByRole( 'dialog', {
			name: 'Publish version 8?',
		} );
		expect(
			within( publish ).getByText( 'Billing phone: rule changed' )
		).toBeInTheDocument();
		expect(
			within( publish ).getByText(
				/Items already sent are not touched; items sent from now use version 8\./
			)
		).toBeInTheDocument();
		expect(
			within( publish ).getByRole( 'checkbox', {
				name: 'Preview on the last 10 orders first',
			} )
		).toBeChecked();
		await user.type(
			within( publish ).getByLabelText( 'Note for your team (optional)' ),
			'phone format for Saudi'
		);
		await user.click(
			within( publish ).getByRole( 'button', {
				name: 'Publish version 8',
			} )
		);
		expect(
			await screen.findByText(
				'Version 8 is live. Items sent from now use it.'
			)
		).toBeInTheDocument();
		expect( server.mappings.orders.live?.version ).toBe( 8 );
		expect(
			server.requests.find(
				( request ) => request.route === '/mappings/orders/publish'
			)?.body
		).toEqual( { note: 'phone format for Saudi' } );
		await waitFor( () =>
			expect(
				screen.getByText( /Version 8 is live\. No draft\./ )
			).toBeInTheDocument()
		);
	} );

	it( 'adds a field from the fixed library of rules and removes it again', async () => {
		const { user } = await renderApp( { route: '/mapping/orders' } );
		await user.click(
			await screen.findByRole( 'button', { name: 'Add a field' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Add a field',
		} );
		const rules = within( dialog ).getByLabelText( 'Rule' );
		const options = within( rules )
			.getAllByRole( 'option' )
			.map( ( option ) => option.getAttribute( 'value' ) );
		expect( options ).toEqual( [
			'trim',
			'prefix',
			'suffix',
			'case',
			'phone_e164',
			'iso_country',
			'iso_state',
			'money_minor',
			'date_utc',
			'value_map',
			'if_then',
			'default',
			'required',
			'join',
			'split',
			'tax_lookup',
			'sku_match',
		] );
		await user.selectOptions(
			within( dialog ).getByLabelText( 'WooCommerce field' ),
			'order.note'
		);
		await user.selectOptions( rules, 'prefix' );
		await user.type(
			within( dialog ).getByLabelText( 'Prefix' ),
			'Note: '
		);
		await user.selectOptions(
			within( dialog ).getByLabelText( 'Target in Odoo' ),
			'partner.street'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Add field' } )
		);
		const added = screen.getByRole( 'row', {
			name: /Order note.*partner\.street/,
		} );
		expect( within( added ).getByText( 'New' ) ).toBeInTheDocument();
		expect(
			within( added ).getByText( 'Add the prefix Note:' )
		).toBeInTheDocument();

		await user.click(
			within( added ).getByRole( 'button', {
				name: /Edit the Order note rule/,
			} )
		);
		const edit = await screen.findByRole( 'dialog', {
			name: 'Edit rule: Order note',
		} );
		await user.click(
			within( edit ).getByRole( 'button', { name: 'Remove this field' } )
		);
		await user.click(
			within( edit ).getByRole( 'button', {
				name: 'Yes, remove this field',
			} )
		);
		expect(
			screen.queryByText( 'Add the prefix Note:' )
		).not.toBeInTheDocument();
	} );

	it( 'previews a draft against a real order with plus and minus lines', async () => {
		const { user } = await renderApp( { route: '/mapping/orders' } );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Edit the Billing phone rule',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Edit rule: Billing phone',
		} );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Test on an order' } )
		);
		const preview = await screen.findByRole( 'dialog', {
			name: 'Preview with a real order',
		} );
		expect(
			await within( preview ).findByText( /What changes/ )
		).toBeInTheDocument();
		expect( within( preview ).getByLabelText( 'Order' ) ).toHaveValue(
			'10490'
		);
		expect(
			within( preview ).getByText(
				/Nothing is sent\. Lines marked \+ or − differ from the live version\./
			)
		).toBeInTheDocument();
		await user.click(
			within( preview ).getByRole( 'button', {
				name: 'Open the full preview',
			} )
		);
		expect(
			await within( preview ).findByRole( 'heading', {
				name: 'What Odoo will receive',
			} )
		).toBeInTheDocument();
		await expectNoA11yViolations();
	} );

	it( 'rolls back to an older version after explaining what happens', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
		} );
		const versions = (
			await screen.findByRole( 'heading', { name: 'Versions' } )
		).closest( 'section' ) as HTMLElement;
		expect( within( versions ).getByText( 'Live' ) ).toBeInTheDocument();
		await user.click(
			within( versions ).getByRole( 'button', {
				name: 'Roll back to version 6',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Roll back to version 6?',
		} );
		expect(
			within( dialog ).getByText(
				'Version 6 becomes the live rules again, as a new version 8. Nothing is deleted.'
			)
		).toBeInTheDocument();
		expect(
			within( dialog ).getByText( 'Items already sent stay as they are.' )
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Roll back' } )
		);
		await waitFor( () =>
			expect( server.mappings.orders.live?.version ).toBe( 8 )
		);
	} );

	it( 'discards a saved draft after confirmation', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/orders',
		} );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Edit the Order note rule',
			} )
		);
		const edit = await screen.findByRole( 'dialog' );
		await user.selectOptions(
			within( edit ).getByLabelText( 'Rule' ),
			'prefix'
		);
		await user.type( within( edit ).getByLabelText( 'Prefix' ), 'X' );
		await user.click(
			within( edit ).getByRole( 'button', { name: 'Save rule' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save draft' } )
		);
		await screen.findByText(
			'Draft saved. It is not live until you publish it.'
		);
		await user.click(
			await screen.findByRole( 'button', { name: 'Discard draft' } )
		);
		await user.click(
			within(
				await screen.findByRole( 'dialog', {
					name: 'Discard the draft?',
				} )
			).getByRole( 'button', { name: 'Discard draft' } )
		);
		await waitFor( () =>
			expect( server.mappings.orders.draft ).toBeNull()
		);
	} );

	it( 'shows read-only rules without buttons to a manager', async () => {
		await renderApp( {
			route: '/mapping/orders',
			boot: { capabilities: { manage: true, configure: false } },
		} );
		await screen.findByRole( 'table', { name: /Rules: Orders/ } );
		expect(
			screen.queryByRole( 'button', { name: /Edit the/ } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Add a field' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /Publish as version/ } )
		).not.toBeInTheDocument();
	} );

	it( 'does not ask the preview route (configure only) for sample values, and offers no preview, to a manager', async () => {
		// The preview route answers 403 without the configure capability: a manager would see an empty sample
		// column, failed requests in the console and a dialog that cannot load.
		const asked: string[] = [];
		await renderApp( {
			route: '/mapping/orders',
			boot: { capabilities: { manage: true, configure: false } },
			wrap: ( inner ) => async ( req ) => {
				asked.push( `${ req.method } ${ req.route }` );
				return inner( req );
			},
		} );
		const table = await screen.findByRole( 'table', {
			name: /Rules: Orders/,
		} );
		expect(
			within( table ).queryByRole( 'columnheader', { name: /^Sample/ } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Preview with a real order',
			} )
		).not.toBeInTheDocument();
		expect( asked ).not.toContain( 'POST /mappings/preview' );
	} );

	it( 'still shows the sample column and the preview to someone who may configure', async () => {
		const { user } = await renderApp( { route: '/mapping/orders' } );
		const table = await screen.findByRole( 'table', {
			name: /Rules: Orders/,
		} );
		expect(
			within( table ).getByRole( 'columnheader', { name: /^Sample/ } )
		).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Preview with a real order' } )
		);
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Preview with a real order',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows the stock and tracking rules as two sets with their own versions', async () => {
		await renderApp( { route: '/mapping/stock' } );
		expect(
			await screen.findByRole( 'heading', { name: 'Stock in' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Delivery in' } )
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: /Publish as version 3/ } )
		).toHaveLength( 2 );
	} );
} );

describe( 'Tax mapping', () => {
	it( 'warns about a rate without a code, then saves a choice without drafts or versions', async () => {
		const { user, server } = await renderApp( { route: '/mapping/tax' } );
		expect(
			await screen.findByText(
				/Changes on this tab apply as soon as you save, and are written to the log\. Unlike order and customer rules, tax codes have no drafts or versions\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/1 WooCommerce tax rate has no code\. Orders that use Oman Standard VAT 5% will fail with “Tax code not mapped” until you choose one\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Required for business buyers, 15 digits' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toBeDisabled();
		await expectNoA11yViolations();
		await user.selectOptions(
			screen.getByLabelText( 'Tax code for Oman Standard VAT 5%' ),
			'VAT 5% (OM)'
		);
		expect( screen.getByText( '1 unsaved change' ) ).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect(
			await screen.findByText( /Tax mapping saved\./ )
		).toBeInTheDocument();
		expect(
			server.requests.find(
				( request ) =>
					request.route === '/tax-map' && request.method === 'PUT'
			)
		).toBeTruthy();
		await waitFor( () =>
			expect(
				screen.queryByText( /has no code\./ )
			).not.toBeInTheDocument()
		);
	} );
} );

describe( 'Products and SKU matching', () => {
	it( 'lists the products without a match and maps a SKU through a search of the system', async () => {
		const { user, server } = await renderApp( {
			route: '/mapping/products',
		} );
		expect(
			await screen.findByText( 'Products without a match (7)' )
		).toBeInTheDocument();
		const missing = screen.getByRole( 'row', {
			name: /Stoneware bowl, large/,
		} );
		expect(
			within( missing ).getByText( 'SKU not found in Odoo' )
		).toBeInTheDocument();
		expect(
			within( missing ).getByText( '3 orders are waiting on it' )
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'row', { name: /Olive wood spoon set/ } )
			).getByText( 'No SKU' )
		).toBeInTheDocument();
		expect(
			within(
				screen.getByRole( 'row', { name: /Olive wood spoon set/ } )
			).getByRole( 'link', { name: 'Edit product' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'post=510&action=edit' )
		);
		await expectNoA11yViolations();
		await user.click(
			within( missing ).getByRole( 'button', {
				name: 'Map this SKU CS-BOWL-04',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Map SKU CS-BOWL-04',
		} );
		await user.click(
			await within( dialog ).findByRole( 'radio', {
				name: /Stoneware bowl, medium/,
			} )
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Save mapping' } )
		);
		await waitFor( () =>
			expect(
				server.requests.some(
					( request ) => request.route === '/products/map'
				)
			).toBe( true )
		);
		expect(
			await screen.findByText( 'Products without a match (6)' )
		).toBeInTheDocument();
	} );
} );
