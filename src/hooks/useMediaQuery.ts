import { useSyncExternalStore } from '@wordpress/element';

/**
 * Whether a CSS media query matches, kept up to date.
 *
 * @param query Media query such as `(max-width: 782px)`.
 * @return True when it matches.
 */
export function useMediaQuery( query: string ): boolean {
	return useSyncExternalStore(
		( notify ) => {
			if ( typeof window === 'undefined' || ! window.matchMedia ) {
				return () => undefined;
			}
			const list = window.matchMedia( query );
			list.addEventListener( 'change', notify );
			return () => list.removeEventListener( 'change', notify );
		},
		() =>
			typeof window !== 'undefined' && window.matchMedia
				? window.matchMedia( query ).matches
				: false,
		() => false
	);
}

/** True at 782px and below, where tabs collapse into the sections menu and tables become cards. */
export function useIsNarrow(): boolean {
	return useMediaQuery( '(max-width: 782px)' );
}
