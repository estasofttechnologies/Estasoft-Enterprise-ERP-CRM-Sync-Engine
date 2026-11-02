/**
 * A tiny hash router. The whole app lives on one wp-admin page, so routes are `#/queue`,
 * `#/connections/12` and so on, with query arguments after `?`.
 */
import { useMemo, useSyncExternalStore } from '@wordpress/element';
import type { AnchorHTMLAttributes, MouseEvent, ReactNode } from 'react';

export interface RouteState {
	/** Path segments without empty parts: `/queue/12` is `['queue', '12']`. */
	segments: string[];
	params: URLSearchParams;
	/** The path with a leading slash, without the query. */
	path: string;
	/** Hash as written, for example `#/queue?state=failed`. */
	hash: string;
}

/**
 * Parses a location hash.
 *
 * @param hash Value of `location.hash`.
 * @return Route state.
 */
export function parseHash( hash: string ): RouteState {
	const cleaned = hash.replace( /^#!?/, '' );
	const [ rawPath, rawQuery = '' ] = cleaned.split( '?' );
	const segments = rawPath
		.split( '/' )
		.filter( Boolean )
		.map( decodeURIComponent );
	return {
		segments,
		params: new URLSearchParams( rawQuery ),
		path: '/' + segments.join( '/' ),
		hash,
	};
}

function subscribe( notify: () => void ): () => void {
	window.addEventListener( 'hashchange', notify );
	return () => window.removeEventListener( 'hashchange', notify );
}

/**
 * @return The current route.
 */
export function useRoute(): RouteState {
	const hash = useSyncExternalStore(
		subscribe,
		() => window.location.hash,
		() => ''
	);
	return useMemo( () => parseHash( hash ), [ hash ] );
}

/**
 * Builds a route string from segments and query arguments. Empty arguments are left out.
 *
 * @param path  Path such as `/queue`.
 * @param query Query arguments.
 * @return Route such as `/queue?state=failed`.
 */
export function routeTo(
	path: string,
	query: Record< string, string | number | undefined | null > = {}
): string {
	const pairs = Object.entries( query )
		.filter(
			( [ , value ] ) =>
				value !== undefined && value !== null && value !== ''
		)
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent( String( value ) ) }`
		);
	return pairs.length > 0 ? `${ path }?${ pairs.join( '&' ) }` : path;
}

/**
 * Goes to a route.
 *
 * @param to      Route such as `/queue/12`.
 * @param replace Replace the history entry instead of adding one.
 */
export function navigate( to: string, replace = false ): void {
	const next = `#${ to.startsWith( '/' ) ? to : `/${ to }` }`;
	if ( replace ) {
		window.history.replaceState( null, '', next );
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
		return;
	}
	window.location.hash = next;
}

export interface LinkProps extends Omit<
	AnchorHTMLAttributes< HTMLAnchorElement >,
	'href'
> {
	to: string;
	children: ReactNode;
}

/**
 * A link to a route inside the app.
 *
 * @param props          Route and anchor attributes.
 * @param props.to
 * @param props.children
 * @param props.onClick
 * @return An anchor.
 */
export function Link( { to, children, onClick, ...rest }: LinkProps ) {
	const handle = ( event: MouseEvent< HTMLAnchorElement > ) => {
		onClick?.( event );
		if (
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey
		) {
			return;
		}
		event.preventDefault();
		navigate( to );
	};
	return (
		<a href={ `#${ to }` } onClick={ handle } { ...rest }>
			{ children }
		</a>
	);
}
