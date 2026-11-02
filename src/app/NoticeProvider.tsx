/**
 * Messages at the top of the page ("3 orders were sent back to the queue"). They stay until
 * dismissed and are read out by screen readers through persistent live regions.
 */
import {
	createContext,
	useCallback,
	useContext,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import type { ReactNode } from 'react';
import { Notice } from '@wordpress/components';
import { navigate } from './router';

export type NoticeKind = 'info' | 'success' | 'warning' | 'error';

export interface AppNotice {
	id: string;
	kind: NoticeKind;
	text: string;
	actionLabel?: string;
	/** Route to open when the action is pressed. */
	actionRoute?: string;
	onAction?: () => void;
	dismissible?: boolean;
}

export type NoticeInput = Omit< AppNotice, 'id' > & { id?: string };

interface NoticeApi {
	notices: AppNotice[];
	notify: ( notice: NoticeInput ) => string;
	dismiss: ( id: string ) => void;
	clear: () => void;
}

const NoticeContext = createContext< NoticeApi | null >( null );

/**
 * @return Functions to show and dismiss notices.
 * @throws {Error} When used outside the provider.
 */
export function useNotices(): NoticeApi {
	const value = useContext( NoticeContext );
	if ( ! value ) {
		throw new Error( 'useNotices() needs <NoticeProvider>.' );
	}
	return value;
}

/**
 * @param props          Children and notices that the page itself supplies (boot data).
 * @param props.children
 * @param props.initial
 * @return Provider.
 */
export function NoticeProvider( {
	children,
	initial = [],
}: {
	children: ReactNode;
	initial?: AppNotice[];
} ) {
	const [ notices, setNotices ] = useState< AppNotice[] >( initial );
	const counter = useRef( 0 );

	const notify = useCallback( ( input: NoticeInput ) => {
		counter.current += 1;
		const id = input.id ?? `n${ counter.current }`;
		setNotices( ( current ) => [
			...current.filter( ( n ) => n.id !== id ),
			{ dismissible: true, ...input, id },
		] );
		return id;
	}, [] );
	const dismiss = useCallback(
		( id: string ) =>
			setNotices( ( current ) => current.filter( ( n ) => n.id !== id ) ),
		[]
	);
	const clear = useCallback( () => setNotices( [] ), [] );

	const value = useMemo(
		() => ( { notices, notify, dismiss, clear } ),
		[ notices, notify, dismiss, clear ]
	);
	return (
		<NoticeContext.Provider value={ value }>
			{ children }
		</NoticeContext.Provider>
	);
}

/**
 * Renders the notices. Two persistent regions: errors are announced at once, the rest politely.
 *
 * @return The notice area.
 */
export function NoticeArea() {
	const { notices, dismiss } = useNotices();
	const render = ( notice: AppNotice ) => (
		<Notice
			key={ notice.id }
			status={ notice.kind }
			isDismissible={ notice.dismissible !== false }
			onRemove={ () => dismiss( notice.id ) }
			className={ `estasoft-sync-notice estasoft-sync-notice--${ notice.kind }` }
			politeness={ notice.kind === 'error' ? 'assertive' : 'polite' }
			spokenMessage={ null }
			actions={
				notice.actionLabel
					? [
							{
								label: notice.actionLabel,
								onClick: () => {
									if ( notice.onAction ) {
										notice.onAction();
									}
									if ( notice.actionRoute ) {
										navigate( notice.actionRoute );
									}
								},
								variant: 'link' as const,
							},
						]
					: []
			}
		>
			{ notice.text }
		</Notice>
	);
	return (
		<div className="estasoft-sync-notices">
			<div role="alert">
				{ notices.filter( ( n ) => n.kind === 'error' ).map( render ) }
			</div>
			<div role="status" aria-live="polite">
				{ notices.filter( ( n ) => n.kind !== 'error' ).map( render ) }
			</div>
		</div>
	);
}
