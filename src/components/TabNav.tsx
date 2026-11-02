import { Link } from '../app/router';

export interface TabNavItem {
	label: string;
	to: string;
	current: boolean;
	count?: number;
}

/**
 * A row of links for the pages of one section (Orders, Customers, ... under Mapping). Links, not
 * ARIA tabs: each one is a page of its own.
 *
 * @param props       Items and a label.
 * @param props.items
 * @param props.label
 * @return The navigation.
 */
export function TabNav( {
	items,
	label,
}: {
	items: TabNavItem[];
	label: string;
} ) {
	return (
		<nav className="estasoft-sync-subtabs" aria-label={ label }>
			{ items.map( ( item ) => (
				<Link
					key={ item.to }
					to={ item.to }
					className="estasoft-sync-subtab"
					aria-current={ item.current ? 'page' : undefined }
				>
					{ item.label }
					{ item.count ? (
						<span className="estasoft-sync-segment__count">
							{ item.count }
						</span>
					) : null }
				</Link>
			) ) }
		</nav>
	);
}
