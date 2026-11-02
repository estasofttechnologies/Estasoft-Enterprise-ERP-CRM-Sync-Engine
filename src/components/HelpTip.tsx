import { Popover } from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Icon } from './Icon';

/**
 * A small help button that opens a short explanation. Esc closes it and focus stays on the button.
 *
 * @param props          Label for the button and the explanation.
 * @param props.label
 * @param props.children
 * @return The button and its popover.
 */
export function HelpTip( {
	label,
	children,
}: {
	label?: string;
	children: ReactNode;
} ) {
	const [ open, setOpen ] = useState( false );
	const id = useId();
	return (
		<span className="estasoft-sync-helptip">
			<button
				type="button"
				className="estasoft-sync-helptip__button"
				aria-expanded={ open }
				aria-controls={ open ? id : undefined }
				aria-label={
					label ??
					__( 'What is this?', 'estasoft-erp-crm-sync-engine' )
				}
				onClick={ () => setOpen( ! open ) }
			>
				<Icon name="info" />
			</button>
			{ open ? (
				<Popover
					placement="bottom-start"
					onClose={ () => setOpen( false ) }
					focusOnMount={ false }
					className="estasoft-sync-popover"
				>
					<div
						className="estasoft-sync-helptip__body"
						id={ id }
						role="note"
					>
						{ children }
					</div>
				</Popover>
			) : null }
		</span>
	);
}
