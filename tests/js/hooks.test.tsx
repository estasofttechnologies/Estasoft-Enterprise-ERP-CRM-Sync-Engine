import { act, render, screen } from '@testing-library/react';
import { useResource } from '../../src/hooks/useResource';

function Probe( {
	fetcher,
	poll,
}: {
	fetcher: () => Promise< number >;
	poll?: number;
} ) {
	const resource = useResource( fetcher, [], { poll } );
	return (
		<div>
			<span data-testid="state">
				{ resource.loading
					? 'loading'
					: resource.error
						? 'error'
						: String( resource.data ) }
			</span>
			<button type="button" onClick={ () => void resource.reload() }>
				reload
			</button>
		</div>
	);
}

describe( 'useResource', () => {
	afterEach( () => {
		jest.useRealTimers();
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			value: false,
		} );
	} );

	it( 'goes from loading to data', async () => {
		render( <Probe fetcher={ async () => 7 } /> );
		expect( screen.getByTestId( 'state' ) ).toHaveTextContent( 'loading' );
		expect( await screen.findByText( '7' ) ).toBeInTheDocument();
	} );

	it( 'shows the error and recovers on reload', async () => {
		let calls = 0;
		render(
			<Probe
				fetcher={ async () => {
					calls += 1;
					if ( calls === 1 ) {
						throw new Error( 'down' );
					}
					return 9;
				} }
			/>
		);
		expect( await screen.findByText( 'error' ) ).toBeInTheDocument();
		await act( async () =>
			screen.getByRole( 'button', { name: 'reload' } ).click()
		);
		expect( await screen.findByText( '9' ) ).toBeInTheDocument();
	} );

	it( 'polls, backs off after a failure, and waits while the tab is hidden', async () => {
		jest.useFakeTimers();
		let calls = 0;
		let fail = false;
		render(
			<Probe
				poll={ 1000 }
				fetcher={ async () => {
					calls += 1;
					if ( fail ) {
						throw new Error( 'down' );
					}
					return calls;
				} }
			/>
		);
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 0 );
		} );
		expect( calls ).toBe( 1 );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 1000 );
		} );
		expect( calls ).toBe( 2 );

		// One failure: the next wait doubles (2 s), so nothing happens after 1 s.
		fail = true;
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 1000 );
		} );
		expect( calls ).toBe( 3 );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 1000 );
		} );
		expect( calls ).toBe( 3 );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 1000 );
		} );
		expect( calls ).toBe( 4 );

		// Hidden tab: the timer fires but no request is made until the tab shows again.
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			value: true,
		} );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 10000 );
		} );
		expect( calls ).toBe( 4 );
		fail = false;
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			value: false,
		} );
		await act( async () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
			await jest.advanceTimersByTimeAsync( 0 );
		} );
		expect( calls ).toBe( 5 );
	} );

	it( 'ignores an answer that arrives after a newer request', async () => {
		const resolvers: ( ( value: number ) => void )[] = [];
		render(
			<Probe
				fetcher={ () =>
					new Promise< number >( ( resolve ) =>
						resolvers.push( resolve )
					)
				}
			/>
		);
		await act( async () =>
			screen.getByRole( 'button', { name: 'reload' } ).click()
		);
		await act( async () => {
			resolvers[ 1 ]( 2 );
		} );
		await act( async () => {
			resolvers[ 0 ]( 1 );
		} );
		expect( screen.getByTestId( 'state' ) ).toHaveTextContent( '2' );
	} );
} );
