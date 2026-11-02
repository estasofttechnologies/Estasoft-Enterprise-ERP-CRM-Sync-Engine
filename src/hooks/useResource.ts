/**
 * Loads data from the REST API with loading, error and refresh state.
 *
 * Optional polling: the interval doubles after each failure (up to five minutes), stops while the
 * browser tab is hidden and catches up at once when the tab shows again.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export interface ResourceOptions {
	/** Poll interval in milliseconds. No polling when omitted. */
	poll?: number;
	/** Set false to skip loading (for example while a prerequisite is missing). */
	enabled?: boolean;
	/** Keep showing the previous data while new data loads after a dependency changed. */
	keepPrevious?: boolean;
}

export interface Resource< T > {
	data: T | undefined;
	error: unknown;
	/** True while the first load runs (no data yet). */
	loading: boolean;
	/** True while a reload runs and data is already on screen. */
	refreshing: boolean;
	reload: () => Promise< void >;
	/** Replaces the data without a request (after a mutation that returns the new state). */
	setData: ( next: T | ( ( previous: T | undefined ) => T ) ) => void;
}

interface State< T > {
	data: T | undefined;
	error: unknown;
	loading: boolean;
	refreshing: boolean;
}

const MAX_BACKOFF_MS = 5 * 60 * 1000;

/**
 * @param fetcher Function that loads the data; receives an AbortSignal.
 * @param deps    Reload when one of these changes.
 * @param options Polling and enablement.
 * @return The resource state and helpers.
 */
export function useResource< T >(
	fetcher: ( signal: AbortSignal ) => Promise< T >,
	deps: readonly unknown[],
	options: ResourceOptions = {}
): Resource< T > {
	const { poll, enabled = true, keepPrevious = false } = options;
	const fetchRef = useRef( fetcher );
	fetchRef.current = fetcher;
	const [ state, setState ] = useState< State< T > >( {
		data: undefined,
		error: undefined,
		loading: enabled,
		refreshing: false,
	} );
	const sequence = useRef( 0 );
	const controller = useRef< AbortController | null >( null );
	const timer = useRef< ReturnType< typeof setTimeout > | null >( null );
	const failures = useRef( 0 );
	const waitingForVisible = useRef( false );
	const pollRef = useRef( poll );
	pollRef.current = poll;

	const clearTimer = useCallback( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
			timer.current = null;
		}
	}, [] );

	const run = useCallback(
		async ( background: boolean ): Promise< void > => {
			controller.current?.abort();
			const abort = new AbortController();
			controller.current = abort;
			sequence.current += 1;
			const id = sequence.current;
			setState( ( previous ) => ( {
				...previous,
				loading: previous.data === undefined && ! background,
				refreshing: previous.data !== undefined,
			} ) );
			try {
				const data = await fetchRef.current( abort.signal );
				if ( id !== sequence.current ) {
					return;
				}
				failures.current = 0;
				setState( {
					data,
					error: undefined,
					loading: false,
					refreshing: false,
				} );
			} catch ( error ) {
				if ( abort.signal.aborted || id !== sequence.current ) {
					return;
				}
				failures.current += 1;
				setState( ( previous ) => ( {
					...previous,
					error,
					loading: false,
					refreshing: false,
				} ) );
			}
			if ( id !== sequence.current || ! pollRef.current ) {
				return;
			}
			const delay = Math.min(
				pollRef.current * Math.pow( 2, failures.current ),
				MAX_BACKOFF_MS
			);
			clearTimer();
			timer.current = setTimeout( () => {
				timer.current = null;
				if ( typeof document !== 'undefined' && document.hidden ) {
					waitingForVisible.current = true;
					return;
				}
				void run( true );
			}, delay );
		},
		[ clearTimer ]
	);

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		if ( ! keepPrevious ) {
			setState( {
				data: undefined,
				error: undefined,
				loading: true,
				refreshing: false,
			} );
		}
		failures.current = 0;
		void run( false );
		const onVisible = () => {
			if ( ! document.hidden && waitingForVisible.current ) {
				waitingForVisible.current = false;
				void run( true );
			}
		};
		document.addEventListener( 'visibilitychange', onVisible );
		return () => {
			document.removeEventListener( 'visibilitychange', onVisible );
			clearTimer();
			controller.current?.abort();
			sequence.current += 1;
		};
		// The caller's dependency list decides when to reload.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ enabled, run, clearTimer, ...deps ] );

	const reload = useCallback( () => run( true ), [ run ] );

	const setData = useCallback(
		( next: T | ( ( previous: T | undefined ) => T ) ) => {
			setState( ( previous ) => ( {
				...previous,
				data:
					typeof next === 'function'
						? ( next as ( p: T | undefined ) => T )( previous.data )
						: next,
			} ) );
		},
		[]
	);

	return { ...state, reload, setData };
}
