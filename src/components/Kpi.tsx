import type { ReactNode } from 'react';
import { formatNumber } from '../lib/format';
import { useFormat } from './FormatContext';

export interface KpiProps {
	label: string;
	value: ReactNode;
	hint: string;
}

/**
 * A number card. Numbers are written with the locale's separators (`2,104`).
 *
 * @param props       Label, value and a line of explanation.
 * @param props.label
 * @param props.value
 * @param props.hint
 * @return The card.
 */
export function Kpi( { label, value, hint }: KpiProps ) {
	const cfg = useFormat();
	return (
		<section
			className="estasoft-sync-card estasoft-sync-kpi"
			aria-label={ label }
		>
			<span className="estasoft-sync-label">{ label }</span>
			<span className="estasoft-sync-kpi__n">
				{ typeof value === 'number'
					? formatNumber( value, cfg.locale )
					: value }
			</span>
			<span className="estasoft-sync-muted estasoft-sync-small">
				{ hint }
			</span>
		</section>
	);
}

/**
 * A responsive row of KPI cards.
 *
 * @param props       The cards.
 * @param props.items
 * @return The grid.
 */
export function KpiGrid( { items }: { items: KpiProps[] } ) {
	return (
		<div className="estasoft-sync-grid estasoft-sync-kpis">
			{ items.map( ( item ) => (
				<Kpi key={ item.label } { ...item } />
			) ) }
		</div>
	);
}
