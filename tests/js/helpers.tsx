import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setBoot, setTransport } from '../../src/api/client';
import type { Transport, TransportRequest } from '../../src/api/client';
import type { MockOptions } from '../../src/api/mock/server';
import { createMockServer, mockBoot } from '../../src/api/mock/server';
import type { BootData } from '../../src/api/types';
import { App } from '../../src/app/App';
import { axe } from './setup';

export interface SetupOptions extends MockOptions {
	route?: string;
	boot?: Partial< BootData >;
	/** Puts something around the mock server's transport (see `patchJson` and `failRoute`). */
	wrap?: Wrap;
}

export type Wrap = ( inner: Transport ) => Transport;

type Json = Record< string, unknown >;

/**
 * Changes the JSON the mock server answers with for some requests, to reach a state the sample
 * world does not have (a link with a bad scheme, a saved status the page does not know).
 *
 * @param match  Which requests to change.
 * @param change Makes the new body from the old one.
 * @return A wrapper for `renderApp({ wrap })`.
 */
export function patchJson(
	match: ( req: TransportRequest ) => boolean,
	change: ( data: Json ) => Json
): Wrap {
	return ( inner ) => async ( req ) => {
		const response = await inner( req );
		if ( ! match( req ) ) {
			return response;
		}
		const text = JSON.stringify(
			change( JSON.parse( await response.text() ) as Json )
		);
		return { ...response, text: async () => text };
	};
}

/**
 * Answers some requests with a fixed status and body, for an error the sample world cannot give.
 *
 * @param match  Which requests to answer.
 * @param status HTTP status.
 * @param body   JSON body.
 * @return A wrapper for `renderApp({ wrap })`.
 */
export function answer(
	match: ( req: TransportRequest ) => boolean,
	status: number,
	body: unknown
): Wrap {
	return ( inner ) => async ( req ) => {
		if ( ! match( req ) ) {
			return inner( req );
		}
		const text = JSON.stringify( body );
		return {
			status,
			headers: { get: () => null },
			text: async () => text,
		};
	};
}

/**
 * Makes some requests fail the way a lost connection does.
 *
 * @param match Which requests to break.
 * @return A wrapper for `renderApp({ wrap })`, also usable as `setTransport( failRoute( … )( inner ) )`.
 */
export function failRoute( match: ( req: TransportRequest ) => boolean ): Wrap {
	return ( inner ) => async ( req ) => {
		if ( match( req ) ) {
			throw new Error( 'offline' );
		}
		return inner( req );
	};
}

/**
 * Renders the whole app against the in-memory server, at a route.
 *
 * @param options Scenario, route, capabilities and injected failures.
 * @return Testing helpers and the server for assertions.
 */
export async function renderApp( options: SetupOptions = {} ) {
	const server = createMockServer( options );
	const boot = mockBoot( options.boot );
	setTransport(
		options.wrap ? options.wrap( server.transport ) : server.transport
	);
	setBoot( boot );
	window.location.hash = options.route ? `#${ options.route }` : '';
	const user = userEvent.setup();
	const view = render( <App boot={ boot } wrap={ false } /> );
	// Let the first requests settle inside act(), so later updates do not warn.
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	return { server, user, boot, ...view };
}

/**
 * Waits until the main heading (h1) of the screen has this text.
 * @param name
 */
export async function expectHeading( name: string | RegExp ) {
	return waitFor( () =>
		expect(
			screen.getByRole( 'heading', { level: 1, name } )
		).toBeInTheDocument()
	);
}

/**
 * Goes to another route in the running app.
 * @param route
 */
export async function goTo( route: string ) {
	await act( async () => {
		window.location.hash = `#${ route }`;
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
	} );
}

/** Runs axe on the whole document body (dialogs render outside the app root). */
export async function expectNoA11yViolations() {
	expect( await axe( document.body ) ).toHaveNoViolations();
}

export function resetApp() {
	setTransport( null );
	setBoot( null );
	window.location.hash = '';
}

export { screen, waitFor, within, act };
