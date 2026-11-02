/**
 * Development entry for the mock layer. Imported only when `process.env.ESTASOFT_MOCK` is "1" at
 * build time. Never part of the production bundle.
 */
import '@wordpress/components/build-style/style.css';
import { setBoot, setTransport } from '../client';
import type { Scenario } from './server';
import { createMockServer, mockBoot } from './server';

/**
 * Replaces the REST transport with the in-memory server and fills in boot data.
 * The scenario comes from the address (`?scenario=paused`), so every dashboard state can be opened.
 */
export function installMock(): void {
	const params = new URLSearchParams( window.location.search );
	const scenario = ( params.get( 'scenario' ) ?? 'normal' ) as Scenario;
	const server = createMockServer( { scenario, latency: 250 } );
	setTransport( server.transport );
	setBoot(
		mockBoot( {
			...( window.estasoftSync ?? {} ),
			capabilities: {
				manage: true,
				configure: params.get( 'role' ) !== 'manager',
			},
		} )
	);
}
