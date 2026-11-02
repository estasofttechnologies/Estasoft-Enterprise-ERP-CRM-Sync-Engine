import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { Btn } from './Btn';

/**
 * Copies text to the clipboard. Uses the async clipboard API where the page allows it and a
 * hidden textarea otherwise (wp-admin on plain http).
 *
 * @param text Text to copy.
 * @return True when the copy worked.
 */
export async function copyText( text: string ): Promise< boolean > {
	try {
		if (
			typeof navigator !== 'undefined' &&
			navigator.clipboard &&
			window.isSecureContext
		) {
			await navigator.clipboard.writeText( text );
			return true;
		}
	} catch {
		// Fall through to the textarea method.
	}
	try {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.insetInlineStart = '-9999px';
		document.body.appendChild( area );
		area.select();
		const ok = document.execCommand( 'copy' );
		document.body.removeChild( area );
		return ok;
	} catch {
		return false;
	}
}

export interface CopyButtonProps {
	text: string | ( () => string );
	label: string;
	copiedLabel?: string;
	small?: boolean;
	variant?: 'primary' | 'secondary' | 'tertiary';
}

/**
 * A button that copies text and says so.
 *
 * @param props             Text and labels.
 * @param props.text
 * @param props.label
 * @param props.copiedLabel
 * @param props.small
 * @param props.variant
 * @return The button.
 */
export function CopyButton( {
	text,
	label,
	copiedLabel,
	small = true,
	variant = 'secondary',
}: CopyButtonProps ) {
	const [ state, setState ] = useState< 'idle' | 'copied' | 'failed' >(
		'idle'
	);
	const timer = useRef< ReturnType< typeof setTimeout > | null >( null );
	useEffect(
		() => () => {
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
		},
		[]
	);
	const press = async () => {
		const ok = await copyText( typeof text === 'function' ? text() : text );
		setState( ok ? 'copied' : 'failed' );
		speak(
			ok
				? ( copiedLabel ??
						__( 'Copied', 'estasoft-erp-crm-sync-engine' ) )
				: __(
						'Copy failed. Select the text and copy it by hand.',
						'estasoft-erp-crm-sync-engine'
					),
			'polite'
		);
		if ( timer.current ) {
			clearTimeout( timer.current );
		}
		timer.current = setTimeout( () => setState( 'idle' ), 2500 );
	};
	let shown = label;
	if ( state === 'copied' ) {
		shown = copiedLabel ?? __( 'Copied', 'estasoft-erp-crm-sync-engine' );
	} else if ( state === 'failed' ) {
		shown = __( 'Copy failed', 'estasoft-erp-crm-sync-engine' );
	}
	return (
		<Btn
			small={ small }
			variant={ variant }
			icon={ state === 'copied' ? 'check' : 'copy' }
			onClick={ () => void press() }
		>
			{ shown }
		</Btn>
	);
}
