import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setBoot, setTransport } from '../../src/api/client';
import type { MockOptions } from '../../src/api/mock/server';
import { createMockServer, mockBoot } from '../../src/api/mock/server';
import type { BootData } from '../../src/api/types';
import { App } from '../../src/app/App';
import { axe } from './setup';

export interface SetupOptions extends MockOptions {
	route?: string;
	boot?: Partial< BootData >;
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
	setTransport( server.transport );
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
