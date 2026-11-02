import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { AttentionGroup, QueueItem } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { Link, navigate, routeTo } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { useFormat } from '../../components/FormatContext';
import { PageHeader } from '../../components/PageHeader';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { StatusBadge } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import { causeValue, groupRemedy, groupTitle } from '../../lib/attention';
import { formatMoney } from '../../lib/format';
import { groupsPhrase, ordersPhrase } from '../../lib/text';
import { MapSkuDialog } from '../mapping/MapSkuDialog';
import { RetryDialog, SkipDialog, useQueueActions } from './actions';
import { safeHttpUrl } from '../../lib/url';

/**
 * The failed orders, grouped by error code and cause. Failed orders are never retried by
 * themselves; fix the cause once, then retry the whole group.
 *
 * @return The screen.
 */
export function NeedsAttention() {
	const { canManage, canConfigure } = useApp();
	const { status } = useStatus();
	const system = useSystemName();
	const groups = useResource(
		( signal ) => api.queue.attention( signal ),
		[],
		{ keepPrevious: true }
	);
	const [ dialog, setDialog ] = useState<
		| { kind: 'retry' | 'skip'; group: AttentionGroup; items: QueueItem[] }
		| { kind: 'map'; group: AttentionGroup }
		| null
	>( null );
	const actions = useQueueActions( () => {
		setDialog( null );
		void groups.reload();
	} );
	const list = groups.data ?? [];
	const total = list.reduce( ( sum, group ) => sum + group.count, 0 );
	const skipped = status?.skipped ?? 0;

	return (
		<>
			<PageHeader
				title={ __(
					'Needs attention',
					'estasoft-erp-crm-sync-engine'
				) }
				breadcrumb={ [
					{
						label: __( 'Queue', 'estasoft-erp-crm-sync-engine' ),
						to: '/queue',
					},
					{
						label: __(
							'Needs attention',
							'estasoft-erp-crm-sync-engine'
						),
					},
				] }
				description={
					groups.data
						? sprintf(
								/* translators: 1: number of failed orders, 2: number of groups */
								_n(
									'%1$d failed order in %2$s. Failed orders are never retried by themselves, because retrying cannot fix them.',
									'%1$d failed orders in %2$s. Failed orders are never retried by themselves, because retrying cannot fix them.',
									total,
									'estasoft-erp-crm-sync-engine'
								),
								total,
								groupsPhrase( list.length )
							)
						: undefined
				}
				actions={
					<Btn
						onClick={ () =>
							navigate(
								routeTo( '/queue', { state: 'skipped' } )
							)
						}
					>
						{ sprintf(
							/* translators: %d: number of skipped orders */ __(
								'View skipped (%d)',
								'estasoft-erp-crm-sync-engine'
							),
							skipped
						) }
					</Btn>
				}
			/>
			<p className="estasoft-sync-muted">
				{ __(
					'Fix the cause once, then retry the whole group. Orders that are skipped stay in the log and are not sent.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			{ groups.loading ? (
				<LoadingState
					rows={ 3 }
					label={ __(
						'Loading failed orders',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ groups.error && ! groups.data ? (
				<ErrorState
					error={ groups.error }
					onRetry={ () => void groups.reload() }
				/>
			) : null }
			{ groups.data && list.length === 0 ? (
				<EmptyState
					as="h2"
					title={ __( 'All clear', 'estasoft-erp-crm-sync-engine' ) }
				>
					{ __(
						'No order needs a person. Failed orders will appear here, grouped by cause.',
						'estasoft-erp-crm-sync-engine'
					) }
				</EmptyState>
			) : null }
			{ list.map( ( group ) => (
				<GroupCard
					key={ `${ group.error_code }|${ group.cause }` }
					group={ group }
					system={ system }
					canManage={ canManage }
					canConfigure={ canConfigure }
					onRetry={ ( items ) =>
						setDialog( { kind: 'retry', group, items } )
					}
					onSkip={ ( items ) =>
						setDialog( { kind: 'skip', group, items } )
					}
					onMap={ () => setDialog( { kind: 'map', group } ) }
				/>
			) ) }
			{ dialog?.kind === 'retry' ? (
				<RetryDialog
					items={ dialog.items }
					intro={ sprintf(
						/* translators: %s: reason, for example "Product not found in Odoo" */ __(
							'All of them failed with: %s. If you fixed the cause, they should now pass.',
							'estasoft-erp-crm-sync-engine'
						),
						groupTitle( dialog.group, system )
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ () =>
						actions.retryGroup(
							{ ids: dialog.items.map( ( item ) => item.id ) },
							dialog.items.length
						)
					}
				/>
			) : null }
			{ dialog?.kind === 'skip' ? (
				<SkipDialog
					items={ dialog.items }
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
			{ dialog?.kind === 'map' ? (
				<MapSkuDialog
					sku={ causeValue( dialog.group.cause ) }
					failedOrders={ dialog.group.count }
					onClose={ () => setDialog( null ) }
					onMapped={ () => {
						setDialog( null );
						void groups.reload();
					} }
				/>
			) : null }
		</>
	);
}

interface GroupCardProps {
	group: AttentionGroup;
	system: string;
	canManage: boolean;
	canConfigure: boolean;
	onRetry: ( items: QueueItem[] ) => void;
	onSkip: ( items: QueueItem[] ) => void;
	onMap: () => void;
}

function GroupCard( {
	group,
	system,
	canManage,
	canConfigure,
	onRetry,
	onSkip,
	onMap,
}: GroupCardProps ) {
	const cfg = useFormat();
	const items = useResource(
		async ( signal ) => {
			const page = await api.queue.list(
				{
					state: 'failed',
					error_code: group.error_code,
					per_page: 100,
				},
				signal
			);
			return page.items.filter( ( item ) =>
				group.item_ids.includes( item.id )
			);
		},
		[ group.error_code, group.cause, group.count ],
		{ keepPrevious: true }
	);
	const rows = items.data ?? [];
	const first = rows[ 0 ];
	return (
		<Card
			title={ groupTitle( group, system ) }
			description={ [ group.cause, ordersPhrase( group.count ) ]
				.filter( Boolean )
				.join( ' · ' ) }
			actions={
				<StatusBadge tone="danger">
					{ ordersPhrase( group.count ) }
				</StatusBadge>
			}
			className="estasoft-sync-group"
		>
			<p>{ groupRemedy( group, system ) }</p>
			{ canManage ? (
				<div className="estasoft-sync-actions">
					{ group.error_code === 'SYNC-MAP-001' && canConfigure ? (
						<Btn small onClick={ onMap }>
							{ sprintf(
								/* translators: %s: SKU */ __(
									'Map %s',
									'estasoft-erp-crm-sync-engine'
								),
								causeValue( group.cause )
							) }
						</Btn>
					) : null }
					{ group.count === 1 &&
					safeHttpUrl( first?.order_edit_url ) ? (
						<Btn
							small
							href={
								safeHttpUrl( first?.order_edit_url ) ??
								undefined
							}
						>
							{ sprintf(
								/* translators: %s: order number */ __(
									'Open order #%s',
									'estasoft-erp-crm-sync-engine'
								),
								first.entity_label
							) }
						</Btn>
					) : null }
					<Btn
						small
						disabled={ rows.length === 0 }
						onClick={ () => onRetry( rows ) }
					>
						{ group.count === 1
							? __( 'Retry', 'estasoft-erp-crm-sync-engine' )
							: sprintf(
									/* translators: %s: "3 orders" */ __(
										'Retry %s',
										'estasoft-erp-crm-sync-engine'
									),
									ordersPhrase( group.count )
								) }
					</Btn>
					<Btn
						small
						disabled={ rows.length === 0 }
						onClick={ () => onSkip( rows ) }
					>
						{ group.count === 1
							? __( 'Skip order', 'estasoft-erp-crm-sync-engine' )
							: sprintf(
									/* translators: %s: "3 orders" */ __(
										'Skip %s',
										'estasoft-erp-crm-sync-engine'
									),
									ordersPhrase( group.count )
								) }
					</Btn>
				</div>
			) : null }
			{ items.loading ? (
				<LoadingState
					rows={ 1 }
					label={ __(
						'Loading the orders of this group',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ items.error ? (
				<ErrorState
					error={ items.error }
					onRetry={ () => void items.reload() }
				/>
			) : null }
			{ rows.length > 0 ? (
				<ResponsiveTable< QueueItem, number >
					caption={ sprintf(
						/* translators: %s: group title */ __(
							'Orders in the group: %s',
							'estasoft-erp-crm-sync-engine'
						),
						groupTitle( group, system )
					) }
					rows={ rows }
					rowKey={ ( item ) => item.id }
					columns={ [
						{
							id: 'order',
							header: __(
								'Order',
								'estasoft-erp-crm-sync-engine'
							),
							primary: true,
							cell: ( item ) => (
								<Link
									to={ `/queue/${ item.id }` }
									className="estasoft-sync-strong estasoft-sync-link"
								>
									#{ item.entity_label }
								</Link>
							),
						},
						{
							id: 'customer',
							header: __(
								'Customer',
								'estasoft-erp-crm-sync-engine'
							),
							cell: ( item ) => (
								<span dir="auto">
									{ item.customer_name ?? '—' }
								</span>
							),
						},
						{
							id: 'failed',
							header: __(
								'Failed at',
								'estasoft-erp-crm-sync-engine'
							),
							cell: ( item ) => (
								<DateTime value={ item.updated_at } />
							),
						},
						{
							id: 'detail',
							header: __(
								'Detail',
								'estasoft-erp-crm-sync-engine'
							),
							wide: true,
							cell: ( item ) =>
								[
									item.error_message,
									item.total
										? formatMoney( item.total, cfg )
										: '',
								]
									.filter( Boolean )
									.join( ' · ' ),
						},
					] }
				/>
			) : null }
		</Card>
	);
}
