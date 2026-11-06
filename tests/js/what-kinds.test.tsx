/**
 * Step 3, What should sync?, for an accounting system (board B04b) and a CRM (board B04c).
 */
import { render } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';
import { setBoot, setTransport } from '../../src/api/client';
import { createMockServer, mockBoot } from '../../src/api/mock/server';
import { makeConnection } from '../../src/api/mock/data';
import type { Connection } from '../../src/api/types';
import {
	readWhat,
	summariseWhat,
	WhatStep,
} from '../../src/screens/setup/WhatStep';
import type { WhatAnswers } from '../../src/screens/setup/WhatStep';
import { axe } from './setup';
import { screen, waitFor, within } from './helpers';

function accounting(): Connection {
	return {
		...makeConnection(),
		id: 31,
		connector: 'xero',
		label: 'Xero',
		type: 'accounting',
		capabilities: {
			orders: true,
			customers: true,
			refunds: true,
			stock_in: false,
			tracking_in: false,
			prices_in: false,
			invoice_readback: true,
		},
		warehouses: [],
	};
}

function crm( pipeline = true ): Connection {
	return {
		...makeConnection(),
		id: 32,
		connector: 'hubspot',
		label: 'HubSpot',
		type: 'crm',
		capabilities: {
			orders: true,
			customers: true,
			refunds: true,
			stock_in: false,
			tracking_in: false,
			prices_in: false,
			invoice_readback: false,
		},
		warehouses: [],
		credentials: pipeline
			? [
					{
						name: 'pipeline',
						label: 'Pipeline',
						secret: false,
						required: false,
						hint: 'The pipeline that deals go to.',
						default: 'default',
						value: 'default',
						has_value: true,
						type: 'select',
						options: {
							default: 'Sales pipeline (default)',
							'online-orders': 'Online orders',
						},
					} as Connection[ 'credentials' ][ number ],
				]
			: [],
	};
}

let latest: WhatAnswers = readWhat( undefined );

function Host( { connection }: { connection: Connection } ) {
	const [ value, setValue ] = useState( readWhat( undefined ) );
	latest = value;
	return (
		<WhatStep
			connection={ connection }
			value={ value }
			onChange={ ( next ) => {
				latest = next;
				setValue( next );
			} }
		/>
	);
}

beforeEach( () => {
	const server = createMockServer();
	setTransport( server.transport );
	setBoot( mockBoot() );
	latest = readWhat( undefined );
} );

afterEach( () => {
	setTransport( null );
	setBoot( null );
} );

describe( 'What should sync to an accounting system', () => {
	it( 'asks what an order becomes and shows the payment table only when payments are chosen', async () => {
		const user = userEvent.setup();
		render( <Host connection={ accounting() } /> );
		expect(
			screen.getByRole( 'heading', { name: 'What should sync to Xero?' } )
		).toBeInTheDocument();
		const create = screen.getByLabelText( 'Create' );
		expect( create ).toHaveValue( 'invoice' );
		expect(
			screen.getByText(
				/Xero issues the invoice\. This plugin does not sign tax invoices\./
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
		await user.selectOptions( create, 'invoice_payment' );
		const table = screen.getByRole( 'table', {
			name: 'Payment methods and the account each is paid into',
		} );
		expect(
			within( table ).getByText( 'Cash on delivery' )
		).toBeInTheDocument();
		await user.type(
			within( table ).getByLabelText(
				'Account in Xero for Cash on delivery'
			),
			'Cash on delivery clearing'
		);
		expect( latest.payment_accounts.cod ).toBe(
			'Cash on delivery clearing'
		);
		expect( latest.accounting_create ).toBe( 'invoice_payment' );
	} );

	it( 'offers only what the system can do and says what is not offered', async () => {
		render( <Host connection={ accounting() } /> );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Save the invoice number and a link on the order',
			} )
		).toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Send refunds as credit notes',
			} )
		).toBeChecked();
		expect(
			screen.queryByRole( 'checkbox', {
				name: 'Update WooCommerce stock',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Not offered for Xero' } )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/An accounting system does not run your warehouse/
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'tax mapping' } )
		).toHaveAttribute( 'href', '#/mapping/tax' );
		expect( await axe( document.body ) ).toHaveNoViolations();
	} );

	it( 'summarises an accounting choice in plain words', () => {
		const what = {
			...readWhat( undefined ),
			accounting_create: 'draft_invoice' as const,
		};
		expect( summariseWhat( what, 'Xero', 'accounting' ).orders ).toBe(
			'Draft invoice, for you to check.'
		);
	} );
} );

describe( 'What should sync to a CRM', () => {
	it( 'asks about contacts, deals and consent, and says what a CRM does not hold', async () => {
		const user = userEvent.setup();
		render( <Host connection={ crm() } /> );
		expect(
			screen.getByRole( 'heading', {
				name: 'What should sync to HubSpot?',
			} )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Create in HubSpot' ) ).toHaveValue(
			'contact_company'
		);
		expect( screen.getByLabelText( 'Show each order as' ) ).toHaveValue(
			'deal_per_order'
		);
		const consent = screen.getByRole( 'checkbox', {
			name: 'Respect marketing consent',
		} );
		expect( consent ).toBeChecked();
		await user.click( consent );
		expect( latest.crm_marketing_consent ).toBe( false );
		expect(
			screen.getByRole( 'heading', { name: 'Not offered for HubSpot' } )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/A CRM does not hold inventory or issue tax invoices/
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', {
				name: 'Update WooCommerce stock',
			} )
		).not.toBeInTheDocument();
		const stages = screen.getByRole( 'table', {
			name: 'WooCommerce order status and the deal stage for it',
		} );
		await user.type(
			within( stages ).getByLabelText(
				'HubSpot deal stage for Completed'
			),
			'Fulfilled'
		);
		expect( latest.crm_stages.completed ).toBe( 'Fulfilled' );
		expect( await axe( document.body ) ).toHaveNoViolations();
	} );

	it( 'saves the pipeline through the connector’s own option', async () => {
		const user = userEvent.setup();
		const server = createMockServer();
		setTransport( server.transport );
		render( <Host connection={ crm() } /> );
		const pipeline = screen.getByLabelText( 'Show deals in' );
		expect( pipeline ).toHaveValue( 'default' );
		await user.selectOptions( pipeline, 'online-orders' );
		expect( latest.crm_pipeline ).toBe( 'online-orders' );
		await waitFor( () =>
			expect(
				server.requests.some(
					( request ) =>
						request.method === 'POST' &&
						request.route === '/connections/32'
				)
			).toBe( true )
		);
		const sent = server.requests.find(
			( request ) => request.route === '/connections/32'
		);
		expect( sent?.body ).toEqual( {
			config: { pipeline: 'online-orders' },
		} );
	} );

	it( 'says deals go to the default pipeline when the connector offers no choice', () => {
		render( <Host connection={ crm( false ) } /> );
		expect(
			screen.getByText( 'Deals go to the default pipeline of HubSpot.' )
		).toBeInTheDocument();
		expect(
			screen.queryByLabelText( 'Show deals in' )
		).not.toBeInTheDocument();
	} );
} );

describe( 'what the choices change today', () => {
	it.each( [
		[ 'an accounting system', accounting() ],
		[ 'a CRM', crm() ],
		[ 'an ERP', { ...makeConnection(), type: 'erp' as const } ],
	] )(
		'says plainly which choices are applied for %s',
		( _name, connection ) => {
			render( <Host connection={ connection } /> );
			expect(
				screen.getByText( /What these choices do today/ )
			).toBeInTheDocument();
			expect(
				screen.getByText( /do not change anything yet/ )
			).toBeInTheDocument();
		}
	);
} );

describe( 'stored answers', () => {
	it( 'keeps old answers working and ignores a table that holds something else than text', () => {
		const read = readWhat( {
			documents: 'order',
			payment_accounts: { cod: 'Cash', bank: 5 },
			crm_stages: 'nope',
		} );
		expect( read.documents ).toBe( 'order' );
		expect( read.payment_accounts ).toEqual( { cod: 'Cash' } );
		expect( read.crm_stages ).toEqual( {} );
		expect( read.accounting_create ).toBe( 'invoice' );
	} );
} );
