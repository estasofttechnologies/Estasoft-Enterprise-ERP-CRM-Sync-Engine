import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { InvoiceRow, InvoiceStatus } from '../../api/types';
import { Link, navigate, routeTo, useRoute } from '../../app/router';
import { useNow } from '../../app/NowContext';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Callout } from '../../components/Callout';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { Money } from '../../components/Money';
import { PageHeader } from '../../components/PageHeader';
import { Pager } from '../../components/Pager';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SegmentedFilter } from '../../components/SegmentedFilter';
import { StatusBadge } from '../../components/StatusBadge';
import type { Tone } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import { formatTimeLeft, timeLeftTone } from '../../lib/format';
import { safeHttpUrl } from '../../lib/url';

type Filter = 'all' | InvoiceStatus;

const STATUSES: InvoiceStatus[] = [
	'waiting',
	'rejected',
	'reported',
	'not_required',
];

function statusBadge( row: InvoiceRow ) {
	const tones: Record< InvoiceStatus, Tone > = {
		waiting: 'warning',
		rejected: 'danger',
		reported: 'success',
		not_required: 'neutral',
	};
	const labels: Record< InvoiceStatus, string > = {
		waiting: __( 'Waiting to be reported', 'estasoft-erp-crm-sync-engine' ),
		rejected: __( 'Rejected', 'estasoft-erp-crm-sync-engine' ),
		reported: __( 'Reported', 'estasoft-erp-crm-sync-engine' ),
		not_required: __( 'Not required', 'estasoft-erp-crm-sync-engine' ),
	};
	return (
		<StatusBadge tone={ tones[ row.status ] }>
			{ labels[ row.status ] }
		</StatusBadge>
	);
}

/**
 * Time left to report one invoice, worked out from `due_at` and the shared clock, so the colour
 * changes at 12 h and 3 h while the page stays open. `seconds_left` is only used when the server
 * sent no deadline.
 *
 * @param props     Row.
 * @param props.row
 * @return The cell.
 */
export function TimeLeft( { row }: { row: InvoiceRow } ) {
	const now = useNow();
	if ( row.status === 'reported' || row.status === 'not_required' ) {
		return <>—</>;
	}
	const left = row.due_at
		? Math.round( ( Date.parse( row.due_at ) - Date.parse( now ) ) / 1000 )
		: row.seconds_left;
	if ( left === null || Number.isNaN( left ) ) {
		return <>—</>;
	}
	return (
		<span
			className={ `estasoft-sync-timeleft estasoft-sync-timeleft--${ timeLeftTone( left ) }` }
		>
			{ formatTimeLeft( left ) }
		</span>
	);
}

/**
 * Invoices: what the ERP and the tax authority say about each invoice. The ERP creates, signs
 * and sends them; this page only shows the answer.
 *
 * @return The screen.
 */
export function Invoices() {
	const route = useRoute();
	const system = useSystemName();
	const { status } = useStatus();
	const filter = (
		[ 'waiting', 'rejected', 'reported', 'not_required' ].includes(
			route.params.get( 'status' ) ?? ''
		)
			? route.params.get( 'status' )
			: 'all'
	) as Filter;
	const page = Math.max( 1, Number( route.params.get( 'page' ) ?? 1 ) || 1 );
	const supported = status?.connection
		? status.connection.capabilities.invoice_readback !== false
		: true;
	const list = useResource(
		( signal ) =>
			api.invoices.list(
				{ status: filter === 'all' ? undefined : filter, page },
				signal
			),
		[ filter, page ],
		{ enabled: supported, keepPrevious: true }
	);
	// The counts on the filter come from the server, one query per status asking for a single row:
	// the total is what counts, so the numbers do not depend on which page is on screen.
	const counted = useResource(
		async ( signal ) => {
			const [ everything, ...byStatus ] = await Promise.all( [
				api.invoices.list( { per_page: 1 }, signal ),
				...STATUSES.map( ( name ) =>
					api.invoices.list( { status: name, per_page: 1 }, signal )
				),
			] );
			return {
				all: everything.total,
				by: Object.fromEntries(
					STATUSES.map( ( name, index ) => [
						name,
						byStatus[ index ].total,
					] )
				) as Record< InvoiceStatus, number >,
			};
		},
		[],
		{ enabled: supported, keepPrevious: true }
	);
	const counts: Partial< Record< InvoiceStatus, number > > =
		counted.data?.by ?? {};
	const rows = list.data?.items ?? [];

	return (
		<>
			<PageHeader
				title={ __( 'Invoices', 'estasoft-erp-crm-sync-engine' ) }
				description={ sprintf(
					/* translators: 1: system name */ __(
						'What %1$s and the tax authority say about each invoice. %1$s creates, signs and sends them. This page only shows the answer.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			/>
			<Callout tone="info">
				{ sprintf(
					/* translators: 1: system name */
					__(
						'The time left counts down to the reporting time set for this shop. %1$s, or the provider you use, creates, signs and reports the invoice. This page only shows what they say. Rules and dates differ by country and change; ask your accountant which apply to you.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			</Callout>
			{ ! supported ? (
				<EmptyState
					as="h2"
					title={ __(
						'Not available for this system',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					{ sprintf(
						/* translators: %s: system name */ __(
							'%s does not report invoice status back to the plugin, so there is nothing to show here.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				</EmptyState>
			) : (
				<>
					<SegmentedFilter< Filter >
						label={ __(
							'Filter by status',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ filter }
						onChange={ ( value ) =>
							navigate(
								routeTo( '/invoices', {
									status: value === 'all' ? undefined : value,
								} ),
								true
							)
						}
						options={ [
							{
								value: 'all',
								label: __(
									'All',
									'estasoft-erp-crm-sync-engine'
								),
								count: counted.data?.all,
							},
							{
								value: 'waiting',
								label: __(
									'Waiting',
									'estasoft-erp-crm-sync-engine'
								),
								count: counts.waiting,
							},
							{
								value: 'rejected',
								label: __(
									'Rejected',
									'estasoft-erp-crm-sync-engine'
								),
								count: counts.rejected,
							},
							{
								value: 'reported',
								label: __(
									'Reported',
									'estasoft-erp-crm-sync-engine'
								),
								count: counts.reported,
							},
							{
								value: 'not_required',
								label: __(
									'Not required',
									'estasoft-erp-crm-sync-engine'
								),
								count: counts.not_required,
							},
						] }
					/>
					{ list.loading ? (
						<LoadingState
							rows={ 4 }
							label={ __(
								'Loading invoices',
								'estasoft-erp-crm-sync-engine'
							) }
						/>
					) : null }
					{ list.error && ! list.data ? (
						<ErrorState
							error={ list.error }
							onRetry={ () => void list.reload() }
						/>
					) : null }
					{ list.data && rows.length === 0 ? (
						<EmptyState
							as="h2"
							title={ __(
								'No invoices to show',
								'estasoft-erp-crm-sync-engine'
							) }
						>
							{ filter === 'all'
								? sprintf(
										/* translators: %s: system name */ __(
											'Invoices appear here once %s has issued one for a synced order.',
											'estasoft-erp-crm-sync-engine'
										),
										system
									)
								: __(
										'No invoice has this status in the last 7 days.',
										'estasoft-erp-crm-sync-engine'
									) }
						</EmptyState>
					) : null }
					{ rows.length > 0 ? (
						<>
							<ResponsiveTable< InvoiceRow, number >
								caption={ __(
									'Invoices',
									'estasoft-erp-crm-sync-engine'
								) }
								rows={ rows }
								rowKey={ ( row ) => row.order_id }
								columns={ [
									{
										id: 'order',
										header: __(
											'Order',
											'estasoft-erp-crm-sync-engine'
										),
										primary: true,
										cell: ( row ) => (
											<>
												<Link
													to={ routeTo( '/queue', {
														q: row.order_label,
													} ) }
													className="estasoft-sync-strong estasoft-sync-link"
												>
													{ row.order_label }
												</Link>
												{ row.total ? (
													<span className="estasoft-sync-muted estasoft-sync-block">
														<Money
															value={ row.total }
														/>
													</span>
												) : null }
											</>
										),
									},
									{
										id: 'invoice',
										header: sprintf(
											/* translators: %s: system name */ __(
												'%s invoice',
												'estasoft-erp-crm-sync-engine'
											),
											system
										),
										label: __(
											'Invoice',
											'estasoft-erp-crm-sync-engine'
										),
										cell: ( row ) => row.number ?? '—',
									},
									{
										id: 'authority',
										header: __(
											'Tax authority',
											'estasoft-erp-crm-sync-engine'
										),
										cell: ( row ) => statusBadge( row ),
									},
									{
										id: 'left',
										header: __(
											'Time left',
											'estasoft-erp-crm-sync-engine'
										),
										cell: ( row ) => (
											<TimeLeft row={ row } />
										),
									},
									{
										id: 'detail',
										header: __(
											'Detail',
											'estasoft-erp-crm-sync-engine'
										),
										wide: true,
										cell: ( row ) => (
											<>
												{ row.status === 'rejected' &&
												row.reject_reason
													? row.reject_reason
													: null }
												{ row.status === 'waiting' &&
												row.issued_at ? (
													<>
														{ sprintf(
															/* translators: %s: system name */ __(
																'Issued by %s',
																'estasoft-erp-crm-sync-engine'
															),
															system
														) }{ ' ' }
														<DateTime
															value={
																row.issued_at
															}
															kind="smart"
														/>
													</>
												) : null }
												{ row.status === 'reported' ? (
													<>
														{ __(
															'Reported',
															'estasoft-erp-crm-sync-engine'
														) }{ ' ' }
														<DateTime
															value={
																row.issued_at
															}
															kind="smart"
														/>
													</>
												) : null }
												{ row.status === 'not_required'
													? ( row.authority_status ??
														__(
															'No report to the tax authority is needed for this sale.',
															'estasoft-erp-crm-sync-engine'
														) )
													: null }
												{ safeHttpUrl( row.pdf_url ) ? (
													<>
														{ ' ' }
														<a
															href={
																safeHttpUrl(
																	row.pdf_url
																) ?? undefined
															}
															target="_blank"
															rel="noopener noreferrer"
															className="estasoft-sync-link"
														>
															{ sprintf(
																/* translators: %s: system name */ __(
																	'Open in %s',
																	'estasoft-erp-crm-sync-engine'
																),
																system
															) }
														</a>
													</>
												) : null }
											</>
										),
									},
								] }
							/>
							<Pager
								page={ list.data?.page ?? 1 }
								totalPages={ list.data?.total_pages ?? 1 }
								total={ list.data?.total ?? rows.length }
								perPage={ list.data?.per_page ?? 25 }
								shown={ rows.length }
								scope={ __(
									'in the last 7 days',
									'estasoft-erp-crm-sync-engine'
								) }
								onPage={ ( next ) =>
									navigate(
										routeTo( '/invoices', {
											status:
												filter === 'all'
													? undefined
													: filter,
											page: next > 1 ? next : undefined,
										} ),
										true
									)
								}
							/>
						</>
					) : null }
				</>
			) }
		</>
	);
}
