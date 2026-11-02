import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type {
	EntityType,
	ItemState,
	QueueItem,
	QueueQuery,
} from '../../api/types';
import { useApp } from '../../app/AppContext';
import { navigate, routeTo, useRoute, Link } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { SelectField, TextField } from '../../components/Fields';
import { useFormat } from '../../components/FormatContext';
import { PageHeader } from '../../components/PageHeader';
import { PauseDialog } from '../../components/PauseDialog';
import { Pager } from '../../components/Pager';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import type { Column } from '../../components/ResponsiveTable';
import { RowMenu } from '../../components/RowMenu';
import type { RowMenuItem } from '../../components/RowMenu';
import { SegmentedFilter } from '../../components/SegmentedFilter';
import { ItemStateChip } from '../../components/StateChip';
import { copyText } from '../../components/CopyButton';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { useResource } from '../../hooks/useResource';
import { useNotices } from '../../app/NoticeProvider';
import {
	eventLabel,
	errorText,
	ordersPhrase,
	stateLabel,
	whatHappened,
} from '../../lib/text';
import {
	actionable,
	RestoreDialog,
	RestoreManyDialog,
	RetryDialog,
	SkipDialog,
	unavailableReason,
	useQueueActions,
} from './actions';
import { safeHttpUrl } from '../../lib/url';

type Filter = 'all' | 'waiting' | 'failed' | 'synced' | 'skipped';
type DayFilter = NonNullable< QueueQuery[ 'day' ] >;

const POLL_MS = 15000;

function toQuery( filter: Filter ): Pick< QueueQuery, 'state' > {
	return filter === 'all' ? {} : { state: filter };
}

/**
 * Support details for one item, as text to paste into a support request.
 *
 * @param item Queue item.
 * @return Text without names or emails.
 */
export function supportText( item: QueueItem ): string {
	return [
		`Order #${ item.entity_label } (${ item.event })`,
		`State: ${ stateLabel( item.state ) }`,
		item.error_code ? `Code: ${ item.error_code }` : '',
		item.error_message ? `Message: ${ item.error_message }` : '',
		`Attempt: ${ item.attempt } of ${ item.max_attempts }`,
		`Queue item: ${ item.id }`,
		`Updated: ${ item.updated_at }`,
	]
		.filter( Boolean )
		.join( '\n' );
}

/**
 * Builds the menu for a queue row (C04).
 *
 * @param item               Queue item.
 * @param handlers           What the entries do.
 * @param handlers.skip
 * @param handlers.restore
 * @param handlers.copy
 * @param handlers.system
 * @param handlers.canManage
 * @return Menu entries.
 */
export function rowMenuItems(
	item: QueueItem,
	handlers: {
		skip: () => void;
		restore: () => void;
		copy: () => void;
		system: string;
		canManage: boolean;
	}
): RowMenuItem[] {
	const label = `#${ item.entity_label }`;
	const items: RowMenuItem[] = [];
	const editUrl = safeHttpUrl( item.order_edit_url );
	const externalUrl = safeHttpUrl( item.external_url );
	if ( editUrl ) {
		items.push( {
			label: sprintf(
				/* translators: %s: order number */ __(
					'Open order %s',
					'estasoft-erp-crm-sync-engine'
				),
				label
			),
			href: editUrl,
		} );
	} else {
		items.push( {
			label: sprintf(
				/* translators: %s: order number */ __(
					'Open details of %s',
					'estasoft-erp-crm-sync-engine'
				),
				label
			),
			onClick: () => navigate( `/queue/${ item.id }` ),
		} );
	}
	if ( item.state === 'synced' && externalUrl && item.external_ref ) {
		items.push( {
			label: sprintf(
				/* translators: 1: number in the system such as S00490, 2: system name */ __(
					'Open %1$s in %2$s',
					'estasoft-erp-crm-sync-engine'
				),
				item.external_ref,
				handlers.system
			),
			href: externalUrl,
			external: true,
		} );
	}
	if ( handlers.canManage && item.state === 'skipped' ) {
		items.push( {
			label: __( 'Restore…', 'estasoft-erp-crm-sync-engine' ),
			onClick: handlers.restore,
		} );
	}
	items.push( {
		label: __( 'View in the log', 'estasoft-erp-crm-sync-engine' ),
		onClick: () => navigate( routeTo( '/logs', { search: label } ) ),
	} );
	if ( item.state === 'failed' || item.state === 'retrying' ) {
		items.push( {
			label: __( 'Copy support details', 'estasoft-erp-crm-sync-engine' ),
			onClick: handlers.copy,
		} );
	}
	if (
		handlers.canManage &&
		( item.state === 'failed' ||
			item.state === 'retrying' ||
			item.state === 'queued' )
	) {
		items.push( {
			label: __( 'Skip this order…', 'estasoft-erp-crm-sync-engine' ),
			onClick: handlers.skip,
			separated: true,
		} );
	}
	return items;
}

/**
 * The queue: filters, search, bulk select, pagination and the row menu.
 *
 * @return The screen.
 */
export function QueueList() {
	const route = useRoute();
	const { canManage, boot } = useApp();
	const { status, reload: reloadStatus } = useStatus();
	const { notify } = useNotices();
	const cfg = useFormat();
	const system = useSystemName();

	const filter = (
		[ 'waiting', 'failed', 'synced', 'skipped' ].includes(
			route.params.get( 'state' ) ?? ''
		)
			? route.params.get( 'state' )
			: 'all'
	) as Filter;
	const type = ( route.params.get( 'type' ) ?? '' ) as EntityType | '';
	const day = ( route.params.get( 'day' ) ?? 'today' ) as DayFilter;
	const page = Math.max( 1, Number( route.params.get( 'page' ) ?? 1 ) || 1 );
	const urlSearch = route.params.get( 'q' ) ?? '';
	const [ search, setSearch ] = useState( urlSearch );
	const settledSearch = useDebouncedValue( search, 300 );

	const update = (
		changes: Record< string, string | number | undefined >
	) => {
		const next: Record< string, string | number | undefined > = {
			state: filter === 'all' ? undefined : filter,
			type: type || undefined,
			day: day === 'today' ? undefined : day,
			q: settledSearch || undefined,
			page: page > 1 ? page : undefined,
			...changes,
		};
		navigate( routeTo( '/queue', next ), true );
	};

	// Keep the address in step with the search box once typing settles.
	useEffect( () => {
		if ( settledSearch !== urlSearch ) {
			navigate(
				routeTo( '/queue', {
					state: filter === 'all' ? undefined : filter,
					type: type || undefined,
					day: day === 'today' ? undefined : day,
					q: settledSearch || undefined,
				} ),
				true
			);
		}
		// Only the settled search text should trigger this.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ settledSearch ] );

	const query: QueueQuery = {
		...toQuery( filter ),
		entity_type: type || undefined,
		day,
		search: urlSearch || undefined,
		page,
		// The rows per page the person chose under Screen Options (the server snaps it to 25, 50 or 100).
		per_page:
			( [ 25, 50, 100 ] as const ).find(
				( size ) => size === boot.perPage
			) ?? 25,
	};
	const list = useResource(
		( signal ) => api.queue.list( query, signal ),
		[ filter, type, day, urlSearch, page ],
		{ poll: POLL_MS, keepPrevious: true }
	);
	const [ selected, setSelected ] = useState< ReadonlySet< number > >(
		new Set()
	);
	const [ pauseOpen, setPauseOpen ] = useState( false );
	const [ dialog, setDialog ] = useState<
		| { kind: 'retry' | 'skip'; items: QueueItem[]; leftOut: number }
		| { kind: 'restore'; item: QueueItem }
		| { kind: 'restoreMany'; items: QueueItem[]; leftOut: number }
		| null
	>( null );
	const actions = useQueueActions( () => {
		setSelected( new Set() );
		void list.reload();
	} );

	// Selection belongs to the page that is on screen.
	useEffect(
		() => setSelected( new Set() ),
		[ filter, type, day, urlSearch, page ]
	);

	const syncNow = useAsyncAction( async () => {
		await api.sync.run();
		notify( {
			kind: 'success',
			text: __(
				'Sync started. Waiting orders are being sent now, oldest first.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
		await Promise.all( [ list.reload(), reloadStatus() ] );
	} );

	const counts = status?.by_state;
	const items = useMemo( () => list.data?.items ?? [], [ list.data?.items ] );
	const byId = useMemo(
		() => new Map( items.map( ( item ) => [ item.id, item ] ) ),
		[ items ]
	);
	const selectedItems = [ ...selected ]
		.map( ( id ) => byId.get( id ) )
		.filter( ( item ): item is QueueItem => Boolean( item ) );
	const failedSelected = selectedItems.filter(
		( item ) => item.state === 'failed'
	).length;
	const retryingSelected = selectedItems.filter(
		( item ) => item.state === 'retrying'
	).length;
	// Only the states the server accepts count for each action.
	const canRetry = actionable( selectedItems, 'retry' );
	const canSkip = actionable( selectedItems, 'skip' );
	const canRestore = actionable( selectedItems, 'restore' );

	const summary = counts
		? sprintf(
				/* translators: 1: total items today, 2: synced, 3: waiting, 4: failed, 5: skipped */
				_n(
					'%1$d item today: %2$d synced, %3$d waiting, %4$d failed, %5$d skipped.',
					'%1$d items today: %2$d synced, %3$d waiting, %4$d failed, %5$d skipped.',
					Object.values( counts ).reduce( ( sum, n ) => sum + n, 0 ),
					'estasoft-erp-crm-sync-engine'
				),
				Object.values( counts ).reduce( ( sum, n ) => sum + n, 0 ),
				counts.synced,
				status?.waiting ?? 0,
				counts.failed,
				counts.skipped
			)
		: undefined;

	const columns: Column< QueueItem >[] = [
		{
			id: 'order',
			header: __( 'Order', 'estasoft-erp-crm-sync-engine' ),
			label: __( 'Order', 'estasoft-erp-crm-sync-engine' ),
			primary: true,
			cell: ( item ) => (
				<>
					<Link
						to={ `/queue/${ item.id }` }
						className="estasoft-sync-strong estasoft-sync-link"
					>
						#{ item.entity_label }
					</Link>
					<span className="estasoft-sync-muted estasoft-sync-block">
						{ eventLabel( item.event, item.entity_type ) }
					</span>
				</>
			),
		},
		{
			id: 'state',
			header: __( 'State', 'estasoft-erp-crm-sync-engine' ),
			cell: ( item ) => (
				<ItemStateChip
					state={ item.state }
					skipReason={ item.skip_reason }
				/>
			),
		},
		{
			id: 'what',
			header: __( 'What happened', 'estasoft-erp-crm-sync-engine' ),
			wide: true,
			cell: ( item ) => whatHappened( item, system, cfg ),
		},
		{
			id: 'updated',
			header: __( 'Updated', 'estasoft-erp-crm-sync-engine' ),
			cell: ( item ) => <DateTime value={ item.updated_at } />,
		},
		{
			id: 'actions',
			header: __( 'Actions', 'estasoft-erp-crm-sync-engine' ),
			hideHeader: true,
			align: 'end',
			cell: ( item ) => (
				<span className="estasoft-sync-rowactions">
					{ canManage && item.state === 'failed' ? (
						<Btn
							small
							onClick={ () =>
								void actions
									.retryItems( [ item.id ] )
									.catch( ( error ) =>
										notify( {
											kind: 'error',
											text: errorText( error ),
										} )
									)
							}
						>
							{ __( 'Retry', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					) : null }
					{ canManage && item.state === 'retrying' ? (
						<Btn
							small
							onClick={ () =>
								void actions
									.retryItems( [ item.id ] )
									.catch( ( error ) =>
										notify( {
											kind: 'error',
											text: errorText( error ),
										} )
									)
							}
						>
							{ __(
								'Retry now',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null }
					{ canManage && item.state === 'skipped' ? (
						<Btn
							small
							onClick={ () =>
								setDialog( { kind: 'restore', item } )
							}
						>
							{ __( 'Restore', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					) : null }
					<RowMenu
						label={ sprintf(
							/* translators: %s: order number */ __(
								'More actions for order #%s',
								'estasoft-erp-crm-sync-engine'
							),
							item.entity_label
						) }
						items={ rowMenuItems( item, {
							system,
							canManage,
							skip: () =>
								setDialog( {
									kind: 'skip',
									items: [ item ],
									leftOut: 0,
								} ),
							restore: () =>
								setDialog( { kind: 'restore', item } ),
							copy: () => {
								void copyText( supportText( item ) ).then(
									( ok ) =>
										notify(
											ok
												? {
														kind: 'success',
														text: __(
															'Support details copied.',
															'estasoft-erp-crm-sync-engine'
														),
													}
												: {
														kind: 'warning',
														text: __(
															'Copy failed. Open the order and select the details by hand.',
															'estasoft-erp-crm-sync-engine'
														),
													}
										)
								);
							},
						} ) }
					/>
				</span>
			),
		},
	];

	const failedTotal = counts?.failed ?? 0;
	const noFilters =
		filter === 'all' && ! type && day === 'today' && ! urlSearch;

	return (
		<>
			<PageHeader
				title={ __( 'Queue', 'estasoft-erp-crm-sync-engine' ) }
				description={ summary }
				actions={
					canManage ? (
						<>
							<Btn
								icon="paused"
								onClick={ () => setPauseOpen( true ) }
							>
								{ __(
									'Pause syncing',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
							<Btn
								variant="primary"
								icon="refresh"
								busy={ syncNow.busy }
								onClick={ () => void syncNow.run() }
							>
								{ __(
									'Sync now',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						</>
					) : null
				}
			/>
			{ syncNow.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( syncNow.error ) }
				</Callout>
			) : null }
			{ failedTotal > 0 ? (
				<p>
					<Link to="/queue/attention" className="estasoft-sync-link">
						{ sprintf(
							/* translators: %s: "5 orders" */ __(
								'Review %s that need attention, grouped by cause',
								'estasoft-erp-crm-sync-engine'
							),
							ordersPhrase( failedTotal )
						) }
					</Link>
				</p>
			) : null }
			<SegmentedFilter< Filter >
				label={ __(
					'Filter by state',
					'estasoft-erp-crm-sync-engine'
				) }
				value={ filter }
				onChange={ ( value ) =>
					update( {
						state: value === 'all' ? undefined : value,
						page: undefined,
					} )
				}
				options={ [
					{
						value: 'all',
						label: __( 'All', 'estasoft-erp-crm-sync-engine' ),
						count: counts
							? Object.values( counts ).reduce(
									( sum, n ) => sum + n,
									0
								)
							: undefined,
					},
					{
						value: 'waiting',
						label: __( 'Waiting', 'estasoft-erp-crm-sync-engine' ),
						count: status?.waiting,
					},
					{
						value: 'failed',
						label: __( 'Failed', 'estasoft-erp-crm-sync-engine' ),
						count: counts?.failed,
					},
					{
						value: 'synced',
						label: __( 'Synced', 'estasoft-erp-crm-sync-engine' ),
						count: counts?.synced,
					},
					{
						value: 'skipped',
						label: __( 'Skipped', 'estasoft-erp-crm-sync-engine' ),
						count: counts?.skipped,
					},
				] }
			/>
			<div className="estasoft-sync-filters" role="search">
				<TextField
					label={ __( 'Find', 'estasoft-erp-crm-sync-engine' ) }
					type="search"
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Order number, SKU or email',
						'estasoft-erp-crm-sync-engine'
					) }
					spellCheck={ false }
				/>
				<SelectField
					label={ __( 'Type', 'estasoft-erp-crm-sync-engine' ) }
					value={ type }
					onChange={ ( value ) =>
						update( { type: value || undefined, page: undefined } )
					}
					options={ [
						{
							label: __(
								'All types',
								'estasoft-erp-crm-sync-engine'
							),
							value: '',
						},
						{
							label: __(
								'Orders',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'order',
						},
						{
							label: __(
								'Refunds',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'refund',
						},
						{
							label: __(
								'Customers',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'customer',
						},
						{
							label: __(
								'Stock',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'stock',
						},
					] }
				/>
				<SelectField
					label={ __( 'Day', 'estasoft-erp-crm-sync-engine' ) }
					value={ day }
					onChange={ ( value ) =>
						update( {
							day: value === 'today' ? undefined : value,
							page: undefined,
						} )
					}
					options={ [
						{
							label: __(
								'Today',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'today',
						},
						{
							label: __(
								'Yesterday',
								'estasoft-erp-crm-sync-engine'
							),
							value: 'yesterday',
						},
						{
							label: __(
								'Last 7 days',
								'estasoft-erp-crm-sync-engine'
							),
							value: '7d',
						},
						{
							label: __(
								'Last 30 days',
								'estasoft-erp-crm-sync-engine'
							),
							value: '30d',
						},
					] }
				/>
			</div>

			{ canManage && selected.size > 0 ? (
				<div
					className="estasoft-sync-bulkbar"
					role="region"
					aria-label={ __(
						'Selected orders',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					<p aria-live="polite">
						{ sprintf(
							/* translators: 1: number selected, 2: number failed, 3: number retrying */
							__(
								'%1$d selected: %2$d failed, %3$d retrying.',
								'estasoft-erp-crm-sync-engine'
							),
							selected.size,
							failedSelected,
							retryingSelected
						) }
						{ failedTotal > failedSelected ? (
							<>
								{ ' ' }
								<Btn
									variant="link"
									onClick={ () =>
										update( {
											state: 'failed',
											page: undefined,
										} )
									}
								>
									{ sprintf(
										/* translators: %d: number of failed orders */ __(
											'Show all %d failed',
											'estasoft-erp-crm-sync-engine'
										),
										failedTotal
									) }
								</Btn>
							</>
						) : null }
					</p>
					<div className="estasoft-sync-bulkbar__buttons">
						<Btn
							small
							disabled={ canRetry.length === 0 }
							aria-describedby="estasoft-sync-bulk-reasons"
							onClick={ () =>
								setDialog( {
									kind: 'retry',
									items: canRetry,
									leftOut:
										selectedItems.length - canRetry.length,
								} )
							}
						>
							{ __(
								'Retry selected',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
						<Btn
							small
							disabled={ canSkip.length === 0 }
							aria-describedby="estasoft-sync-bulk-reasons"
							onClick={ () =>
								setDialog( {
									kind: 'skip',
									items: canSkip,
									leftOut:
										selectedItems.length - canSkip.length,
								} )
							}
						>
							{ __(
								'Skip selected',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
						<Btn
							small
							disabled={ canRestore.length === 0 }
							aria-describedby="estasoft-sync-bulk-reasons"
							onClick={ () =>
								setDialog( {
									kind: 'restoreMany',
									items: canRestore,
									leftOut:
										selectedItems.length -
										canRestore.length,
								} )
							}
						>
							{ __(
								'Restore selected',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
						<Btn
							small
							variant="tertiary"
							onClick={ () => setSelected( new Set() ) }
						>
							{ __( 'Clear', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					</div>
					{ canRetry.length === 0 ||
					canSkip.length === 0 ||
					canRestore.length === 0 ? (
						<p
							id="estasoft-sync-bulk-reasons"
							className="estasoft-sync-muted estasoft-sync-bulkbar__reasons"
						>
							{ [
								canRetry.length === 0
									? unavailableReason( 'retry' )
									: '',
								canSkip.length === 0
									? unavailableReason( 'skip' )
									: '',
								canRestore.length === 0
									? unavailableReason( 'restore' )
									: '',
							]
								.filter( Boolean )
								.map( ( reason ) => (
									<span
										key={ reason }
										className="estasoft-sync-block"
									>
										{ reason }
									</span>
								) ) }
						</p>
					) : null }
				</div>
			) : null }

			{ list.loading ? (
				<LoadingState
					label={ __(
						'Loading the queue',
						'estasoft-erp-crm-sync-engine'
					) }
					rows={ 6 }
				/>
			) : null }
			{ list.error && ! list.data ? (
				<ErrorState
					error={ list.error }
					onRetry={ () => void list.reload() }
					title={ __(
						'The queue could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ list.data && items.length === 0 ? (
				noFilters ? (
					<EmptyState
						as="h2"
						title={ __(
							'Nothing is waiting',
							'estasoft-erp-crm-sync-engine'
						) }
						action={
							<Btn small onClick={ () => navigate( '/logs' ) }>
								{ __(
									'Open the log',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						}
					>
						{ __(
							'New orders appear here and are sent as soon as the next scheduled task runs, usually within a minute.',
							'estasoft-erp-crm-sync-engine'
						) }
					</EmptyState>
				) : (
					<EmptyState
						as="h2"
						title={ __(
							'No items match',
							'estasoft-erp-crm-sync-engine'
						) }
						action={
							<Btn
								small
								onClick={ () => {
									setSearch( '' );
									navigate( '/queue', true );
								} }
							>
								{ __(
									'Clear filters',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						}
					>
						{ urlSearch
							? sprintf(
									/* translators: %s: the text searched for */ __(
										'Nothing matches “%s” for this view. Try another day or clear the filters.',
										'estasoft-erp-crm-sync-engine'
									),
									urlSearch
								)
							: __(
									'Nothing matches these filters. Try another day or clear the filters.',
									'estasoft-erp-crm-sync-engine'
								) }
					</EmptyState>
				)
			) : null }
			{ list.data && items.length > 0 ? (
				<>
					<ResponsiveTable< QueueItem, number >
						caption={ __(
							'Queue items',
							'estasoft-erp-crm-sync-engine'
						) }
						columns={ columns }
						rows={ items }
						rowKey={ ( item ) => item.id }
						selection={
							canManage
								? {
										selected,
										onToggle: ( id ) => {
											const next = new Set( selected );
											if ( next.has( id ) ) {
												next.delete( id );
											} else {
												next.add( id );
											}
											setSelected( next );
										},
										onToggleAll: ( checked ) =>
											setSelected(
												checked
													? new Set(
															items.map(
																( item ) =>
																	item.id
															)
														)
													: new Set()
											),
										rowLabel: ( id ) =>
											sprintf(
												/* translators: %s: order number */ __(
													'Select order #%s',
													'estasoft-erp-crm-sync-engine'
												),
												byId.get( id )?.entity_label ??
													String( id )
											),
									}
								: undefined
						}
					/>
					<Pager
						page={ list.data.page }
						totalPages={ list.data.total_pages }
						total={ list.data.total }
						perPage={ list.data.per_page }
						shown={ items.length }
						onPage={ ( next ) =>
							update( { page: next > 1 ? next : undefined } )
						}
					/>
				</>
			) : null }

			{ pauseOpen ? (
				<PauseDialog
					onClose={ () => setPauseOpen( false ) }
					onDone={ () =>
						void Promise.all( [ list.reload(), reloadStatus() ] )
					}
				/>
			) : null }
			{ dialog?.kind === 'retry' ? (
				<RetryDialog
					items={ dialog.items }
					leftOut={ dialog.leftOut }
					onClose={ () => setDialog( null ) }
					onConfirm={ () =>
						actions.retryItems(
							dialog.items.map( ( item ) => item.id )
						)
					}
				/>
			) : null }
			{ dialog?.kind === 'skip' ? (
				<SkipDialog
					items={ dialog.items }
					leftOut={ dialog.leftOut }
					system={ system }
					onClose={ () => setDialog( null ) }
					onConfirm={ ( reason ) =>
						actions.skipItems(
							dialog.items.map( ( item ) => item.id ),
							reason
						)
					}
				/>
			) : null }
			{ dialog?.kind === 'restoreMany' ? (
				<RestoreManyDialog
					items={ dialog.items }
					leftOut={ dialog.leftOut }
					onClose={ () => setDialog( null ) }
					onConfirm={ () =>
						actions.restoreItems(
							dialog.items.map( ( item ) => item.id )
						)
					}
				/>
			) : null }
			{ dialog?.kind === 'restore' ? (
				<RestoreDialog
					item={ dialog.item }
					onClose={ () => setDialog( null ) }
					onConfirm={ () =>
						actions.restoreItems( [ dialog.item.id ] )
					}
				/>
			) : null }
		</>
	);
}

export type { ItemState };
