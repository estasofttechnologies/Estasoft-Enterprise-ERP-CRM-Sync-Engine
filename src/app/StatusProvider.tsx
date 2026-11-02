/**
 * Polls GET /status and shares it with the app bar, the sections menu and the dashboard.
 */
import {
	createContext,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import type { ReactNode } from 'react';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api/endpoints';
import type { Status } from '../api/types';
import { useResource } from '../hooks/useResource';
import { systemName } from '../lib/text';
import { useNotices } from './NoticeProvider';
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

/** How often the shared clock moves on between polls, in milliseconds. */
export const CLOCK_TICK_MS = 60000;

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
	const { dismiss } = useNotices();

	// The pause message ("Syncing is paused ...") is stale as soon as the pause ends, whoever ended it.
	const mode = resource.data?.mode;
	useEffect( () => {
		if ( mode !== undefined && mode !== 'paused' ) {
			dismiss( 'paused' );
		}
	}, [ mode, dismiss ] );

	// A clock that follows the server's time between polls, so deadlines (time left to report an
	// invoice) keep moving without a request.
	const base = useMemo(
		() =>
			resource.data
				? {
						server: Date.parse( resource.data.now ),
						local: Date.now(),
					}
				: null,
		[ resource.data ]
	);
	const [ tick, setTick ] = useState( 0 );
	useEffect( () => {
		const id = window.setInterval(
			() => setTick( ( count ) => count + 1 ),
			CLOCK_TICK_MS
		);
		return () => window.clearInterval( id );
	}, [] );
	const now = useMemo(
		() =>
			new Date(
				base
					? // Whole ticks only, so the clock moves a minute at a time, like the numbers shown from it.
						base.server +
							Math.floor(
								( Date.now() - base.local ) / CLOCK_TICK_MS
							) *
								CLOCK_TICK_MS
					: Date.now()
			).toISOString(),
		// `tick` is what moves the clock on.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ base, tick ]
	);

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
			<NowContext.Provider value={ now }>
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
