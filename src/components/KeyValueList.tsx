import type { ReactNode } from 'react';

export interface KeyValueItem {
	label: string;
	value: ReactNode;
}

/**
 * Label and value pairs as a description list.
 *
 * @param props         Items.
 * @param props.items
 * @param props.stacked
 * @return The list.
 */
export function KeyValueList( {
	items,
	stacked = false,
}: {
	items: KeyValueItem[];
	stacked?: boolean;
} ) {
	return (
		<dl
			className={ `estasoft-sync-kv${ stacked ? ' estasoft-sync-kv--stack' : '' }` }
		>
			{ items.map( ( item ) => (
				<div key={ item.label } className="estasoft-sync-kv__row">
					<dt>{ item.label }</dt>
					<dd>{ item.value }</dd>
				</div>
			) ) }
		</dl>
	);
}
