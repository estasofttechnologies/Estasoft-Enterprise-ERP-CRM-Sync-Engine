/**
 * Keeps the keyboard where the person was while a request runs.
 */
import { useEffect, useRef } from '@wordpress/element';
import type { RefObject } from 'react';

/**
 * Whether an element can take focus now.
 *
 * @param element The element, or null.
 * @return True when it is on the page and not disabled.
 */
function canFocus( element: HTMLElement | null ): element is HTMLElement {
	return (
		!! element &&
		element.isConnected &&
		! element.hasAttribute( 'disabled' ) &&
		element.getAttribute( 'aria-disabled' ) !== 'true'
	);
}

/**
 * The buttons and fields of a form are switched off while its request runs, and a control that is switched off cannot keep
 * the focus: the browser drops it on the page, and a person who works with the keyboard has to tab in from the top again. When
 * the request is over, this puts the focus back on what had it. If that control is gone or still switched off (the form was
 * replaced by its result, or the button is not needed any more), the focus goes to `fallback` instead, so it is never lost.
 * Focus the person moved elsewhere on purpose is left alone.
 *
 * @param busy     True while the request runs.
 * @param fallback Element (with `tabIndex={ -1 }`) that takes the focus when the control cannot; usually the result.
 */
export function useKeepFocus(
	busy: boolean,
	fallback?: RefObject< HTMLElement | null >
): void {
	const last = useRef< HTMLElement | null >( null );
	const wasBusy = useRef( false );
	useEffect( () => {
		const remember = ( event: FocusEvent ) => {
			if (
				event.target instanceof HTMLElement &&
				event.target !== event.target.ownerDocument.body
			) {
				last.current = event.target;
			}
		};
		document.addEventListener( 'focusin', remember );
		return () => document.removeEventListener( 'focusin', remember );
	}, [] );
	useEffect( () => {
		if ( busy ) {
			wasBusy.current = true;
			return;
		}
		if ( ! wasBusy.current ) {
			return;
		}
		wasBusy.current = false;
		const target = canFocus( last.current )
			? last.current
			: fallback?.current;
		if ( ! target ) {
			return;
		}
		const active = target.ownerDocument.activeElement;
		if ( active && active !== target.ownerDocument.body ) {
			return;
		}
		target.focus();
	}, [ busy, fallback ] );
}
