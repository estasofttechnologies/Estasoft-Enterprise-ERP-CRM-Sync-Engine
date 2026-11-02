/**
 * Runs an async action (a button press) with busy and error state, and ignores double presses.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export interface AsyncAction< Args extends unknown[], R > {
	run: ( ...args: Args ) => Promise< R | undefined >;
	busy: boolean;
	error: unknown;
	clearError: () => void;
}

/**
 * @param action Function to run. Its thrown errors are kept in `error`.
 * @return Handlers and state.
 */
export function useAsyncAction< Args extends unknown[], R >(
	action: ( ...args: Args ) => Promise< R >
): AsyncAction< Args, R > {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( undefined );
	const actionRef = useRef( action );
	actionRef.current = action;
	const busyRef = useRef( false );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const run = useCallback(
		async ( ...args: Args ): Promise< R | undefined > => {
			if ( busyRef.current ) {
				return undefined;
			}
			busyRef.current = true;
			setBusy( true );
			setError( undefined );
			try {
				return await actionRef.current( ...args );
			} catch ( caught ) {
				if ( mounted.current ) {
					setError( caught );
				}
				return undefined;
			} finally {
				busyRef.current = false;
				if ( mounted.current ) {
					setBusy( false );
				}
			}
		},
		[]
	);

	const clearError = useCallback( () => setError( undefined ), [] );

	return { run, busy, error, clearError };
}
