import { __, sprintf } from '@wordpress/i18n';
import { Btn } from './Btn';

export interface PagerProps {
	page: number;
	totalPages: number;
	total: number;
	perPage: number;
	/** Items on the current page. */
	shown: number;
	/** Describes the whole set, for example "today". */
	scope?: string;
	onPage: ( page: number ) => void;
}

/**
 * Counts on the left, Previous and Next on the right.
 *
 * @param props            Paging numbers and handler.
 * @param props.page
 * @param props.totalPages
 * @param props.total
 * @param props.perPage
 * @param props.shown
 * @param props.scope
 * @param props.onPage
 * @return The pager.
 */
export function Pager( {
	page,
	totalPages,
	total,
	perPage,
	shown,
	scope,
	onPage,
}: PagerProps ) {
	const first = total === 0 ? 0 : ( page - 1 ) * perPage + 1;
	const last = ( page - 1 ) * perPage + shown;
	const summary = scope
		? sprintf(
				/* translators: 1: first item number, 2: last item number, 3: total number, 4: scope such as "today" */
				__(
					'Showing %1$d to %2$d of %3$d %4$s',
					'estasoft-erp-crm-sync-engine'
				),
				first,
				last,
				total,
				scope
			)
		: sprintf(
				/* translators: 1: first item number, 2: last item number, 3: total number */
				__(
					'Showing %1$d to %2$d of %3$d',
					'estasoft-erp-crm-sync-engine'
				),
				first,
				last,
				total
			);
	return (
		<nav
			className="estasoft-sync-pager"
			aria-label={ __( 'Pages', 'estasoft-erp-crm-sync-engine' ) }
		>
			<p className="estasoft-sync-muted" aria-live="polite">
				{ summary }
			</p>
			<div className="estasoft-sync-pager__buttons">
				<Btn
					small
					disabled={ page <= 1 }
					onClick={ () => onPage( page - 1 ) }
				>
					{ __( 'Previous', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
				<Btn
					small
					disabled={ page >= totalPages }
					onClick={ () => onPage( page + 1 ) }
				>
					{ __( 'Next', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			</div>
		</nav>
	);
}
