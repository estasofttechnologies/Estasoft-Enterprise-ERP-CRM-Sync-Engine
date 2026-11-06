/* eslint-disable jsx-a11y/no-redundant-roles, jsx-a11y/no-interactive-element-to-noninteractive-role -- explicit table roles keep the table semantics when the cards layout changes display
 * The roles are written out on purpose: the card layout sets display:block on the table parts, which
 * makes some browsers drop the table semantics, and explicit roles keep them. */
/**
 * A table at 783 px and wider, a list of cards at 782 px and below. It is one real `<table>`
 * with explicit ARIA roles, so the card layout (made with CSS) keeps its table semantics, and
 * every cell carries its column name for the card view.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { Key, ReactNode } from 'react';

export interface Column< T > {
	id: string;
	/** Column heading. */
	header: ReactNode;
	/** Plain text for the card view label and screen readers. Defaults to the id. */
	label?: string;
	cell: ( row: T ) => ReactNode;
	/** The first line of the card (no label in card view). */
	primary?: boolean;
	/** Align numbers and actions to the end. */
	align?: 'end';
	/** Take the remaining width in the table view. */
	wide?: boolean;
	/** Heading is visually hidden (a column of action buttons). */
	hideHeader?: boolean;
}

export interface Selection< K > {
	selected: ReadonlySet< K >;
	onToggle: ( key: K ) => void;
	onToggleAll: ( checked: boolean ) => void;
	/** Accessible name for the checkbox of a row. */
	rowLabel: ( key: K ) => string;
}

export interface ResponsiveTableProps< T, K extends Key > {
	/** Describes the table to screen readers. */
	caption: string;
	columns: Column< T >[];
	rows: T[];
	rowKey: ( row: T ) => K;
	selection?: Selection< K >;
	rowClassName?: ( row: T ) => string | undefined;
	className?: string;
}

/**
 * @param props              Columns, rows and optional selection.
 * @param props.caption
 * @param props.columns
 * @param props.rows
 * @param props.rowKey
 * @param props.selection
 * @param props.rowClassName
 * @param props.className
 * @return The table.
 */
export function ResponsiveTable< T, K extends Key >( {
	caption,
	columns,
	rows,
	rowKey,
	selection,
	rowClassName,
	className = '',
}: ResponsiveTableProps< T, K > ) {
	const headerCheckbox = useRef< HTMLInputElement >( null );
	const keys = rows.map( rowKey );
	const selectedOnPage = selection
		? keys.filter( ( key ) => selection.selected.has( key ) ).length
		: 0;
	const allSelected = rows.length > 0 && selectedOnPage === rows.length;

	useEffect( () => {
		if ( headerCheckbox.current ) {
			headerCheckbox.current.indeterminate =
				selectedOnPage > 0 && ! allSelected;
		}
	}, [ selectedOnPage, allSelected ] );

	return (
		<div className={ `estasoft-sync-table-wrap ${ className }`.trim() }>
			<table className="estasoft-sync-table" role="table">
				<caption className="estasoft-sync-sr-only">{ caption }</caption>
				<thead role="rowgroup">
					<tr role="row">
						{ selection ? (
							<th
								role="columnheader"
								scope="col"
								className="estasoft-sync-table__select"
							>
								<input
									ref={ headerCheckbox }
									type="checkbox"
									checked={ allSelected }
									onChange={ ( event ) =>
										selection.onToggleAll(
											event.target.checked
										)
									}
									aria-label={ __(
										'Select all on this page',
										'estasoft-enterprise-erpcrm-sync-engine'
									) }
								/>
							</th>
						) : null }
						{ columns.map( ( column ) => (
							<th
								key={ column.id }
								role="columnheader"
								scope="col"
								className={ [
									column.align ? 'is-end' : '',
									column.wide ? 'is-wide' : '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								{ column.hideHeader ? (
									<span className="estasoft-sync-sr-only">
										{ column.label ?? column.id }
									</span>
								) : (
									column.header
								) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody role="rowgroup">
					{ rows.map( ( row ) => {
						const key = rowKey( row );
						const selected =
							selection?.selected.has( key ) ?? false;
						return (
							<tr
								key={ key }
								role="row"
								className={ [
									selected ? 'is-selected' : '',
									rowClassName?.( row ) ?? '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								{ selection ? (
									<td
										role="cell"
										className="estasoft-sync-table__select"
										data-card-role="select"
									>
										<input
											type="checkbox"
											checked={ selected }
											onChange={ () =>
												selection.onToggle( key )
											}
											aria-label={ selection.rowLabel(
												key
											) }
										/>
									</td>
								) : null }
								{ columns.map( ( column ) => (
									<td
										key={ column.id }
										role="cell"
										data-label={
											column.hideHeader || column.primary
												? undefined
												: ( column.label ??
													( typeof column.header ===
													'string'
														? column.header
														: column.id ) )
										}
										data-card-role={
											column.primary
												? 'primary'
												: column.hideHeader
													? 'actions'
													: undefined
										}
										className={ [
											column.align ? 'is-end' : '',
											column.wide ? 'is-wide' : '',
										]
											.filter( Boolean )
											.join( ' ' ) }
									>
										{ column.cell( row ) }
									</td>
								) ) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
