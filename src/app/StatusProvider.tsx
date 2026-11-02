/**
 * Polls GET /status and shares it with the app bar, the sections menu and the dashboard.
 */
import {
	createContext,
	useContext,
	useEffect,
	useMemo,
	useRef,
} from '@wordpress/element';
import type { ReactNode } from 'react';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api/endpoints';
import type { Status } from '../api/types';
import { useResource } from '../hooks/useResource';
import { systemName } from '../lib/text';
import { NowContext } from './NowContext';

export interface StatusApi {
	status: Status | undefined;
	error: unknown;
	loading: boolean;
	reload: () => Promise< void >;
}

const StatusContext = createContext< StatusApi | null >( null );

/**
 * @return The shared status.
 * @throws {Error} When used outside the provider.
 */
export function useStatus(): StatusApi {
	const value = useContext( StatusContext );
	if ( ! value ) {
		throw new Error( 'useStatus() needs <StatusProvider>.' );
	}
	return value;
}

/** Poll interval for the status, in milliseconds. */
export const STATUS_POLL_MS = 30000;

/**
 * @param props          Children.
 * @param props.children
 * @return Provider.
 */
export function StatusProvider( { children }: { children: ReactNode } ) {
	const resource = useResource( ( signal ) => api.status.get( signal ), [], {
		poll: STATUS_POLL_MS,
		keepPrevious: true,
	} );
	const previous = useRef< Status | undefined >( undefined );

	// Tell screen readers, politely, when the numbers that matter change between polls.
	useEffect( () => {
		const next = resource.data;
		const before = previous.current;
		previous.current = next;
		if ( ! next || ! before ) {
			return;
		}
		if (
			before.waiting !== next.waiting ||
			before.needs_attention !== next.needs_attention
		) {
			speak(
				sprintf(
					/* translators: 1: number of items waiting, 2: number of orders that need attention */
					__(
						'Queue updated: %1$d waiting, %2$d need attention.',
						'estasoft-erp-crm-sync-engine'
					),
					next.waiting,
					next.needs_attention
				),
				'polite'
			);
		}
	}, [ resource.data ] );

	const value = useMemo(
		() => ( {
			status: resource.data,
			error: resource.error,
			loading: resource.loading,
			reload: resource.reload,
		} ),
		[ resource.data, resource.error, resource.loading, resource.reload ]
	);
	return (
		<StatusContext.Provider value={ value }>
			<NowContext.Provider
				value={ resource.data?.now ?? new Date().toISOString() }
			>
				{ children }
			</NowContext.Provider>
		</StatusContext.Provider>
	);
}

/**
 * Short name of the connected system ("Odoo"), or "your system" when none is connected yet.
 *
 * @return Name.
 */
export function useSystemName(): string {
	const { status } = useStatus();
	return systemName( status?.connection );
}
