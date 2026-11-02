import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import type { ReactNode } from 'react';
import { Icon } from './Icon';

export interface RowMenuItem {
	label: string;
	onClick?: () => void;
	/** Opens a link instead (WooCommerce order screen, the system). */
	href?: string;
	external?: boolean;
	/** Draw a rule above. */
	separated?: boolean;
	icon?: ReactNode;
}

/**
 * The three dots at the end of a row. Each item is a real button or link with a 44 px target.
 *
 * @param props       Label for the trigger and the items.
 * @param props.label
 * @param props.items
 * @return The menu.
 */
export function RowMenu( {
	label,
	items,
}: {
	label: string;
	items: RowMenuItem[];
} ) {
	return (
		<DropdownMenu
			className="estasoft-sync-rowmenu"
			label={ label }
			icon={ <Icon name="more" size={ 20 } /> }
			popoverProps={ {
				placement: 'bottom-end',
				className: 'estasoft-sync-popover',
			} }
			toggleProps={ {
				className:
					'estasoft-sync-btn estasoft-sync-btn--sm estasoft-sync-btn--icon',
			} }
		>
			{ ( { onClose } ) => (
				<MenuGroup>
					{ items.map( ( item ) =>
						item.href ? (
							<a
								key={ item.label }
								role="menuitem"
								className={ `components-button components-menu-item__button estasoft-sync-menuitem${ item.separated ? ' has-rule' : '' }` }
								href={ item.href }
								target={ item.external ? '_blank' : undefined }
								rel={
									item.external
										? 'noopener noreferrer'
										: undefined
								}
								onClick={ onClose }
							>
								<span className="components-menu-item__item">
									{ item.label }
								</span>
							</a>
						) : (
							<MenuItem
								key={ item.label }
								className={ `estasoft-sync-menuitem${ item.separated ? ' has-rule' : '' }` }
								onClick={ () => {
									onClose();
									item.onClick?.();
								} }
							>
								{ item.label }
							</MenuItem>
						)
					) }
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}
