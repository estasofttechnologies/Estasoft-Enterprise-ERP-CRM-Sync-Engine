/**
 * Entry point of the admin app (script handle `estasoft-sync-admin`).
 */
import { createRoot } from '@wordpress/element';
import { getBoot } from './api/client';
import { App } from './app/App';
import './styles/index.scss';

declare const process: { env: { ESTASOFT_MOCK?: string } };

/**
 * Starts the app in the element PHP printed.
 */
async function start(): Promise< void > {
	if ( process.env.ESTASOFT_MOCK === '1' ) {
		// Development only. The build replaces the constant, so this branch (and the mock server
		// behind it) is removed from the production bundle.
		// `eager` keeps it in the entry chunk: the style rule of @wordpress/scripts needs a named chunk.
		const { installMock } = await import(
			/* webpackMode: "eager" */ './api/mock'
		);
		installMock();
	}
	const container = document.getElementById( 'estasoft-sync-root' );
	if ( ! container ) {
		return;
	}
	const wrapped = container.closest( '.wrap' ) !== null;
	createRoot( container ).render(
		<App boot={ getBoot() } wrap={ ! wrapped } />
	);
}

void start();
