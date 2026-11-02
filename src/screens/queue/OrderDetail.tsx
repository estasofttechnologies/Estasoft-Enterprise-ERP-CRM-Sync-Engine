import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { QueueItemDetail } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { Link, navigate } from '../../app/router';
import { useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CodeBlock } from '../../components/CodeBlock';
import { CopyButton } from '../../components/CopyButton';
import { DateTime } from '../../components/DateTime';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { useFormat } from '../../components/FormatContext';
import { Icon } from '../../components/Icon';
import { KeyValueList } from '../../components/KeyValueList';
import { PageHeader } from '../../components/PageHeader';
import { ItemStateChip } from '../../components/StateChip';
import { useResource } from '../../hooks/useResource';
import { causeValue, groupRemedy } from '../../lib/attention';
import { formatMoney } from '../../lib/format';
import { catalogMessage, eventLabel } from '../../lib/text';
import { MapSkuDialog } from '../mapping/MapSkuDialog';
import {
	RestoreDialog,
	RetryDialog,
	SkipDialog,
	useQueueActions,
} from './actions';
import { supportText } from './QueueList';

/**
 * One queue item: the order, why it failed, what happened step by step and the redacted
 * request and answer.
 *
 * @param props    Queue item id.
 * @param props.id
 * @return The screen.
 */
export function OrderDetail( { id }: { id: number } ) {
	const { canManage, canConfigure } = useApp();
	const system = useSystemName();
	const cfg = useFormat();
	const detail = useResource(
		( signal ) => api.queue.get( id, signal ),
		[ id ]
	);
	const [ dialog, setDialog ] = useState<
		'retry' | 'skip' | 'restore' | 'map' | null
	>( null );
	const actions = useQueueActions( () => {
		setDialog( null );
		void detail.reload();
	} );
	const item = detail.data;

	if ( detail.loading ) {
		return (
			<>
				<PageHeader
					title={ __( 'Order', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ [
						{
							label: __(
								'Queue',
								'estasoft-erp-crm-sync-engine'
							),
							to: '/queue',
						},
					] }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the order',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! item ) {
		return (
			<>
				<PageHeader
					title={ __( 'Order', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ [
						{
							label: __(
								'Queue',
								'estasoft-erp-crm-sync-engine'
							),
							to: '/queue',
						},
					] }
				/>
				<ErrorState
					error={ detail.error }
					onRetry={ () => void detail.reload() }
					title={ __(
						'This order could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}

	const label = `#${ item.entity_label }`;
	const problem =
		item.error_message ||
		( item.error_code
			? catalogMessage( item.error_code, {}, system )
			: '' );
	const remedy = item.error_code
		? groupRemedy(
				{
					error_code: item.error_code,
					cause: '',
					count: 1,
					oldest_at: item.created_at,
					item_ids: [ item.id ],
				},
				system
			)
		: '';
	const sku =
		item.error_code === 'SYNC-MAP-001' ? skuFromMessage( problem ) : '';
	const canRetry =
		canManage && ( item.state === 'failed' || item.state === 'retrying' );

	return (
		<>
			<PageHeader
				title={ sprintf(
					/* translators: %s: order number such as #10485 */ __(
						'Order %s',
						'estasoft-erp-crm-sync-engine'
					),
					label
				) }
				breadcrumb={ [
					{
						label: __( 'Queue', 'estasoft-erp-crm-sync-engine' ),
						to: '/queue',
					},
					{
						label: sprintf(
							/* translators: %s: order number */ __(
								'Order %s',
								'estasoft-erp-crm-sync-engine'
							),
							label
						),
					},
				] }
				description={
					<>
						{ eventLabel( item.event, item.entity_type ) } ·{ ' ' }
						{ __( 'placed', 'estasoft-erp-crm-sync-engine' ) }{ ' ' }
						<DateTime value={ item.created_at } />
						{ item.total
							? ` · ${ formatMoney( item.total, cfg ) }`
							: '' }
					</>
				}
				actions={
					<>
						{ canRetry ? (
							<Btn onClick={ () => setDialog( 'retry' ) }>
								{ __(
									'Retry this order',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						{ canManage && item.state === 'skipped' ? (
							<Btn onClick={ () => setDialog( 'restore' ) }>
								{ __(
									'Restore this order',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						{ canManage &&
						( item.state === 'failed' ||
							item.state === 'retrying' ||
							item.state === 'queued' ) ? (
							<Btn onClick={ () => setDialog( 'skip' ) }>
								{ __(
									'Skip this order',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						<CopyButton
							text={ () => supportText( item ) }
							label={ __(
								'Copy support details',
								'estasoft-erp-crm-sync-engine'
							) }
							small={ false }
						/>
					</>
				}
			/>
			<div className="estasoft-sync-statusline">
				<span className="estasoft-sync-statusline__chip">
					<ItemStateChip
						state={ item.state }
						skipReason={ item.skip_reason }
					/>
				</span>
				<span className="estasoft-sync-muted">
					{ stateHint( item ) }
				</span>
			</div>

			<div className="estasoft-sync-split">
				<div className="estasoft-sync-split__main">
					{ item.state === 'failed' && problem ? (
						<Callout tone="danger" role="status">
							<p>
								{ problem }{ ' ' }
								<span className="estasoft-sync-mono">
									{ item.error_code }
								</span>
							</p>
							{ remedy ? (
								<p>
									<strong>
										{ __(
											'What to do',
											'estasoft-erp-crm-sync-engine'
										) }
									</strong>{ ' ' }
									<span className="estasoft-sync-muted">
										{ remedy }
									</span>
								</p>
							) : null }
							{ sku && canConfigure ? (
								<p>
									<Btn
										small
										onClick={ () => setDialog( 'map' ) }
									>
										{ sprintf(
											/* translators: %s: SKU */ __(
												'Map %s',
												'estasoft-erp-crm-sync-engine'
											),
											sku
										) }
									</Btn>
								</p>
							) : null }
						</Callout>
					) : null }
					{ item.state === 'retrying' && problem ? (
						<Callout tone="warning">{ problem }</Callout>
					) : null }
					<Card
						title={ __(
							'What happened',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ item.timeline.length === 0 ? (
							<p className="estasoft-sync-muted">
								{ __(
									'Nothing has been logged for this item yet.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						) : (
							<ol className="estasoft-sync-timeline">
								{ item.timeline.map( ( event, index ) => (
									<li key={ `${ event.at }-${ index }` }>
										<Icon
											name={
												event.level === 'error'
													? 'failed'
													: event.level === 'warning'
														? 'warning'
														: 'synced'
											}
										/>
										<div>
											<p className="estasoft-sync-strong">
												{ event.message }
											</p>
											<p className="estasoft-sync-muted estasoft-sync-small">
												<DateTime
													value={ event.at }
													kind="seconds"
												/>
												{ event.code ? (
													<>
														{ ' ' }
														·{ ' ' }
														<span className="estasoft-sync-mono">
															{ event.code }
														</span>
													</>
												) : null }
											</p>
										</div>
									</li>
								) ) }
							</ol>
						) }
					</Card>
					<Card
						title={ __(
							'What we sent',
							'estasoft-erp-crm-sync-engine'
						) }
						description={ __(
							'Redacted. Names, emails, phones and addresses are removed.',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ item.request_excerpt ? (
							<CodeBlock
								label={ __(
									'Request excerpt',
									'estasoft-erp-crm-sync-engine'
								) }
							>
								{ item.request_excerpt }
							</CodeBlock>
						) : (
							<p className="estasoft-sync-muted">
								{ __(
									'No request was kept for this item. Excerpts are kept as set under Settings, General.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						) }
					</Card>
					<Card
						title={ sprintf(
							/* translators: %s: system name */ __(
								'What %s answered',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
						description={ __(
							'Redacted. Cut at the size set under Settings.',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ item.response_excerpt ? (
							<CodeBlock
								label={ __(
									'Answer excerpt',
									'estasoft-erp-crm-sync-engine'
								) }
							>
								{ item.response_excerpt }
							</CodeBlock>
						) : (
							<p className="estasoft-sync-muted">
								{ __(
									'No answer was kept for this item.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						) }
					</Card>
				</div>
				<div className="estasoft-sync-split__side">
					<Card
						title={ __(
							'The order',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						<KeyValueList
							stacked
							items={ [
								{
									label: __(
										'Customer',
										'estasoft-erp-crm-sync-engine'
									),
									value: (
										<span dir="auto">
											{ item.customer_name ?? '—' }
										</span>
									),
								},
								{
									label: __(
										'Total',
										'estasoft-erp-crm-sync-engine'
									),
									value: formatMoney( item.total, cfg ),
								},
								{
									label: __(
										'Step',
										'estasoft-erp-crm-sync-engine'
									),
									value: eventLabel(
										item.event,
										item.entity_type
									),
								},
								{
									label: __(
										'Try',
										'estasoft-erp-crm-sync-engine'
									),
									value: sprintf(
										/* translators: 1: attempt, 2: maximum */ __(
											'%1$d of %2$d',
											'estasoft-erp-crm-sync-engine'
										),
										Math.max( item.attempt, 1 ),
										item.max_attempts
									),
								},
								...( item.next_attempt_at
									? [
											{
												label: __(
													'Next try',
													'estasoft-erp-crm-sync-engine'
												),
												value: (
													<DateTime
														value={
															item.next_attempt_at
														}
													/>
												),
											},
										]
									: [] ),
								...( item.external_ref
									? [
											{
												label: sprintf(
													/* translators: %s: system name */ __(
														'Number in %s',
														'estasoft-erp-crm-sync-engine'
													),
													system
												),
												value: item.external_url ? (
													<a
														href={
															item.external_url
														}
														target="_blank"
														rel="noopener noreferrer"
														className="estasoft-sync-link"
													>
														{ item.external_ref }
													</a>
												) : (
													item.external_ref
												),
											},
										]
									: [] ),
							] }
						/>
					</Card>
					<Card
						title={ __(
							'Related',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						<ul className="estasoft-sync-list estasoft-sync-list--plain">
							{ item.order_edit_url ? (
								<li>
									<a
										href={ item.order_edit_url }
										className="estasoft-sync-link"
									>
										{ sprintf(
											/* translators: %s: order number */ __(
												'Open order %s in WooCommerce',
												'estasoft-erp-crm-sync-engine'
											),
											label
										) }
									</a>
								</li>
							) : null }
							{ item.mapping_version !== null ? (
								<li>
									<Link
										to="/mapping/orders"
										className="estasoft-sync-link"
									>
										{ sprintf(
											/* translators: %d: mapping version */ __(
												'Mapping version %d',
												'estasoft-erp-crm-sync-engine'
											),
											item.mapping_version
										) }
									</Link>
								</li>
							) : null }
							<li>
								<Link
									to={ `/connections/${ item.connection_id }` }
									className="estasoft-sync-link"
								>
									{ sprintf(
										/* translators: %s: system name */ __(
											'Connection: %s',
											'estasoft-erp-crm-sync-engine'
										),
										system
									) }
								</Link>
							</li>
							{ item.log_id ? (
								<li>
									<Link
										to={ `/logs/${ item.log_id }` }
										className="estasoft-sync-link"
									>
										{ __(
											'The log entry',
											'estasoft-erp-crm-sync-engine'
										) }
									</Link>
								</li>
							) : null }
						</ul>
					</Card>
					{ item.siblings.length > 1 ? (
						<Card
							title={ __(
								'Other steps of this order',
								'estasoft-erp-crm-sync-engine'
							) }
							flush
						>
							<ul className="estasoft-sync-events">
								{ item.siblings.map( ( sibling ) => (
									<li key={ sibling.id }>
										<div className="estasoft-sync-events__text">
											<p>
												{ sibling.id === item.id ? (
													<strong>
														{ eventLabel(
															sibling.event,
															item.entity_type
														) }
													</strong>
												) : (
													<Link
														to={ `/queue/${ sibling.id }` }
														className="estasoft-sync-link"
													>
														{ eventLabel(
															sibling.event,
															item.entity_type
														) }
													</Link>
												) }
											</p>
										</div>
										<ItemStateChip
											state={ sibling.state }
										/>
									</li>
								) ) }
							</ul>
						</Card>
					) : null }
				</div>
			</div>
			<p>
				<Btn variant="tertiary" onClick={ () => navigate( '/queue' ) }>
					{ __(
						'Back to the queue',
						'estasoft-erp-crm-sync-engine'
					) }
				</Btn>
			</p>

			{ dialog === 'retry' ? (
				<RetryDialog
					items={ [ item ] }
					onClose={ () => setDialog( null ) }
					onConfirm={ () => actions.retryItems( [ item.id ] ) }
				/>
			) : null }
			{ dialog === 'skip' ? (
				<SkipDialog
					items={ [ item ] }
					system={ system }
					onClose={ () => setDialog( null ) }
					onConfirm={ ( reason ) =>
						actions.skipItems( [ item.id ], reason )
					}
				/>
			) : null }
			{ dialog === 'restore' ? (
				<RestoreDialog
					item={ item }
					onClose={ () => setDialog( null ) }
					onConfirm={ () => actions.restoreItems( [ item.id ] ) }
				/>
			) : null }
			{ dialog === 'map' && sku ? (
				<MapSkuDialog
					sku={ sku }
					onClose={ () => setDialog( null ) }
					onMapped={ () => {
						setDialog( null );
						void detail.reload();
					} }
				/>
			) : null }
		</>
	);
}

function skuFromMessage( message: string ): string {
	const match = /(?:Product|SKU)\s+([A-Za-z0-9._\-/]+)/i.exec( message );
	return match ? causeValue( match[ 1 ] ) : '';
}

function stateHint( item: QueueItemDetail ): string {
	switch ( item.state ) {
		case 'failed':
			return __( 'Needs a person', 'estasoft-erp-crm-sync-engine' );
		case 'retrying':
			return __(
				'The plugin will try again on its own',
				'estasoft-erp-crm-sync-engine'
			);
		case 'skipped':
			return (
				item.skip_reason ??
				__( 'Skipped by a person', 'estasoft-erp-crm-sync-engine' )
			);
		default:
			return '';
	}
}
