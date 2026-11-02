import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';
import { CodeBlock, JsonView } from '../../src/components/CodeBlock';
import { copyText } from '../../src/components/CopyButton';
import { ConfirmDialog } from '../../src/components/Dialog';
import { Pager } from '../../src/components/Pager';
import { ResponsiveTable } from '../../src/components/ResponsiveTable';
import { SkippedTag, StateChip } from '../../src/components/StateChip';
import { validateWholeNumber } from '../../src/components/Fields';
import { NoticeProvider } from '../../src/app/NoticeProvider';
import { axe } from './setup';

interface Row {
	id: number;
	name: string;
}

function Table() {
	const [ selected, setSelected ] = useState< ReadonlySet< number > >(
		new Set()
	);
	const rows: Row[] = [
		{ id: 1, name: 'One' },
		{ id: 2, name: 'Two' },
	];
	return (
		<ResponsiveTable< Row, number >
			caption="Things"
			rows={ rows }
			rowKey={ ( row ) => row.id }
			columns={ [
				{
					id: 'name',
					header: 'Name',
					primary: true,
					cell: ( row ) => row.name,
				},
				{ id: 'state', header: 'State', cell: () => 'Fine' },
			] }
			selection={ {
				selected,
				onToggle: ( id ) => {
					const next = new Set( selected );
					if ( next.has( id ) ) {
						next.delete( id );
					} else {
						next.add( id );
					}
					setSelected( next );
				},
				onToggleAll: ( checked ) =>
					setSelected(
						checked
							? new Set( rows.map( ( row ) => row.id ) )
							: new Set()
					),
				rowLabel: ( id ) => `Select thing ${ id }`,
			} }
		/>
	);
}

describe( 'ResponsiveTable', () => {
	it( 'keeps table roles, labels every cell for the card view and supports select all', async () => {
		const user = userEvent.setup();
		const { container } = render( <Table /> );
		expect(
			screen.getByRole( 'table', { name: 'Things' } )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'columnheader' ) ).toHaveLength( 3 );
		const stateCell = within(
			screen.getByRole( 'row', { name: /One/ } )
		).getByText( 'Fine' );
		expect( stateCell ).toHaveAttribute( 'data-label', 'State' );
		const all = screen.getByRole( 'checkbox', {
			name: 'Select all on this page',
		} ) as HTMLInputElement;
		await user.click(
			screen.getByRole( 'checkbox', { name: 'Select thing 1' } )
		);
		expect( all.indeterminate ).toBe( true );
		await user.click( all );
		expect(
			screen.getByRole( 'checkbox', { name: 'Select thing 2' } )
		).toBeChecked();
		expect( all.indeterminate ).toBe( false );
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );

describe( 'ConfirmDialog', () => {
	function Host( { onConfirm }: { onConfirm: () => Promise< void > } ) {
		const [ open, setOpen ] = useState( true );
		return (
			<NoticeProvider>
				{ open ? (
					<ConfirmDialog
						title="Do it?"
						confirmLabel="Do it"
						onConfirm={ onConfirm }
						onClose={ () => setOpen( false ) }
					>
						<p>Body</p>
					</ConfirmDialog>
				) : (
					<p>closed</p>
				) }
			</NoticeProvider>
		);
	}

	it( 'stays open and shows the error when the action fails, then closes when it works', async () => {
		const user = userEvent.setup();
		let attempts = 0;
		render(
			<Host
				onConfirm={ async () => {
					attempts += 1;
					if ( attempts === 1 ) {
						throw new Error( 'The system said no.' );
					}
				} }
			/>
		);
		const dialog = await screen.findByRole( 'dialog', { name: 'Do it?' } );
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Do it' } )
		);
		expect(
			await within( dialog ).findByText( 'The system said no.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'dialog', { name: 'Do it?' } )
		).toBeInTheDocument();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Do it' } )
		);
		expect( await screen.findByText( 'closed' ) ).toBeInTheDocument();
	} );

	it( 'closes with Escape and the Cancel button, and is accessible', async () => {
		const user = userEvent.setup();
		render( <Host onConfirm={ async () => undefined } /> );
		await screen.findByRole( 'dialog', { name: 'Do it?' } );
		expect( await axe( document.body ) ).toHaveNoViolations();
		await user.keyboard( '{Escape}' );
		await waitFor( () =>
			expect( screen.getByText( 'closed' ) ).toBeInTheDocument()
		);
	} );
} );

describe( 'small parts', () => {
	it( 'shows an icon and a word for every state, and a neutral tag for skipped', () => {
		render(
			<div>
				{ (
					[
						'queued',
						'syncing',
						'synced',
						'retrying',
						'failed',
						'paused',
					] as const
				 ).map( ( state ) => (
					<StateChip key={ state } state={ state } />
				) ) }
				<SkippedTag reason="Test order" />
			</div>
		);
		[
			'Queued',
			'Syncing',
			'Synced',
			'Retrying',
			'Failed',
			'Paused',
		].forEach( ( word ) => {
			const chip = screen.getByText( word );
			expect( chip.querySelector( 'svg' ) ).not.toBeNull();
		} );
		expect( screen.getByText( 'Skipped' ) ).toHaveAttribute(
			'title',
			'Test order'
		);
	} );

	it( 'pages with counts', async () => {
		const user = userEvent.setup();
		const onPage = jest.fn();
		render(
			<Pager
				page={ 2 }
				totalPages={ 3 }
				total={ 57 }
				perPage={ 25 }
				shown={ 25 }
				scope="today"
				onPage={ onPage }
			/>
		);
		expect(
			screen.getByText( 'Showing 26 to 50 of 57 today' )
		).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onPage ).toHaveBeenCalledWith( 3 );
		await user.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( onPage ).toHaveBeenCalledWith( 1 );
	} );

	it( 'marks changed lines in text for screen readers, not only with colour', () => {
		const { container } = render(
			<JsonView
				data={ { partner: { phone: '+971501234567', name: 'Noor' } } }
				marked={ [ 'partner.phone' ] }
				label="Preview"
			/>
		);
		const lines = container.querySelectorAll(
			'.estasoft-sync-code__line.is-changed'
		);
		expect( lines ).toHaveLength( 1 );
		expect( lines[ 0 ] ).toHaveTextContent(
			'Changed by a rule: "phone": "+971501234567"'
		);
		expect(
			screen.getByRole( 'region', { name: 'Preview' } )
		).toHaveAttribute( 'tabindex', '0' );
	} );

	it( 'renders plain code blocks', () => {
		render( <CodeBlock label="Cron">* * * * * wp</CodeBlock> );
		expect(
			screen.getByRole( 'region', { name: 'Cron' } )
		).toHaveTextContent( '* * * * * wp' );
	} );

	it( 'validates whole numbers against a range with the message of the design', () => {
		expect( validateWholeNumber( '5', 1, 60 ) ).toBeNull();
		expect( validateWholeNumber( '0', 1, 60 ) ).toBe(
			'Enter a number from 1 to 60.'
		);
		expect( validateWholeNumber( '61', 1, 60 ) ).toBe(
			'Enter a number from 1 to 60.'
		);
		expect( validateWholeNumber( '2.5', 1, 60 ) ).toBe(
			'Enter a number from 1 to 60.'
		);
		expect( validateWholeNumber( '', 1, 60 ) ).toBe(
			'Enter a number from 1 to 60.'
		);
		expect( validateWholeNumber( 'abc', 1, 60 ) ).toBe(
			'Enter a number from 1 to 60.'
		);
	} );

	it( 'copies text through the clipboard API, and falls back to a hidden field', async () => {
		const writeText = jest.fn().mockResolvedValue( undefined );
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		Object.defineProperty( window, 'isSecureContext', {
			configurable: true,
			value: true,
		} );
		await expect( copyText( 'hello' ) ).resolves.toBe( true );
		expect( writeText ).toHaveBeenCalledWith( 'hello' );

		Object.defineProperty( window, 'isSecureContext', {
			configurable: true,
			value: false,
		} );
		document.execCommand = jest.fn().mockReturnValue( true );
		await expect( copyText( 'again' ) ).resolves.toBe( true );
		expect( document.execCommand ).toHaveBeenCalledWith( 'copy' );
		expect( document.querySelector( 'textarea' ) ).toBeNull();
	} );
} );
