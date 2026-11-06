import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '../app/router';
import type { Section, SectionId } from '../app/sections';
import { Icon } from './Icon';

export interface SectionsMenuProps {
	sections: Section[];
	current: SectionId | null;
	/** Count tags by section (waiting items on Queue). */
	counts?: Partial< Record< SectionId, number > >;
}

/**
 * At 782 px and below the nine tabs collapse into one button that opens this list. Each row is 48 px high.
 *
 * @param props          Sections and the current one.
 * @param props.sections
 * @param props.current
 * @param props.counts
 * @return The button and the list.
 */
export function SectionsMenu( {
	sections,
	current,
	counts = {},
}: SectionsMenuProps ) {
	const [ open, setOpen ] = useState( false );
	const listId = useId();
	const button = useRef< HTMLButtonElement >( null );
	const currentLabel =
		sections.find( ( section ) => section.id === current )?.label ??
		__( 'Menu', 'estasoft-enterprise-erpcrm-sync-engine' );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const onKey = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				setOpen( false );
				button.current?.focus();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ open ] );

	return (
		<div className="estasoft-sync-sections">
			<button
				ref={ button }
				type="button"
				className="estasoft-sync-sections__button"
				aria-expanded={ open }
				aria-controls={ listId }
				aria-label={ sprintf(
					/* translators: %s: name of the current section */
					__(
						'Sections, now %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					currentLabel
				) }
				onClick={ () => setOpen( ! open ) }
			>
				<span>{ currentLabel }</span>
				<Icon name="chevron-down" />
			</button>
			{ open ? (
				<nav
					id={ listId }
					className="estasoft-sync-sections__list"
					aria-label={ __(
						'ESTASOFT SYNC sections',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				>
					{ sections.map( ( section ) => (
						<Link
							key={ section.id }
							to={ section.to }
							aria-current={
								section.id === current ? 'page' : undefined
							}
							onClick={ () => setOpen( false ) }
						>
							<span>{ section.label }</span>
							{ counts[ section.id ] ? (
								<span className="estasoft-sync-tag">
									{ counts[ section.id ] }
								</span>
							) : null }
						</Link>
					) ) }
				</nav>
			) : null }
		</div>
	);
}
