import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type {
	StockChange,
	StockConflict,
	StockOverview,
	StockRules,
} from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { Link } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import {
	CheckField,
	SelectField,
	TextField,
	validateWholeNumber,
} from '../../components/Fields';
import { KpiGrid } from '../../components/Kpi';
import { PageHeader } from '../../components/PageHeader';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SaveBar } from '../../components/SaveBar';
import { StatusBadge } from '../../components/StatusBadge';
import { TabNav } from '../../components/TabNav';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';

export type StockTab = 'stock' | 'tracking' | 'conflicts';

/**
 * Stock, Tracking and Conflicts: what comes back from the system and the rules that apply.
 *
 * @param props     Current tab.
 * @param props.tab
 * @return The screen.
 */
export function StockPage( { tab }: { tab: StockTab } ) {
	const system = useSystemName();
	const { status } = useStatus();
	const { notify } = useNotices();
	const { canManage, canConfigure } = useApp();
	const rules = useResource< StockRules >(
		( signal ) => api.stock.rules( signal ),
		[]
	);
	const overview = useResource< StockOverview >(
		( signal ) => api.stock.overview( signal ),
		[],
		{ poll: 60000 }
	);
	const supported = status?.connection
		? status.connection.capabilities.stock_in !== false ||
			status.connection.capabilities.tracking_in !== false
		: true;
	const conflicts = overview.data?.conflicts ?? [];
	const run = useAsyncAction( async () => {
		await api.sync.run();
		notify( {
			kind: 'success',
			text: __(
				'Stock sync started. The numbers update when it finishes.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
		await overview.reload();
	} );

	const subtitle =
		tab === 'tracking'
			? sprintf(
					/* translators: %s: system name */ __(
						'Tracking numbers and delivery status come from %s.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				)
			: sprintf(
					/* translators: %s: system name */ __(
						'%s is the source of truth for stock quantities.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				);

	return (
		<>
			<PageHeader
				title={ __( 'Stock', 'estasoft-erp-crm-sync-engine' ) }
				description={ subtitle }
				actions={
					tab === 'stock' && canManage && supported ? (
						<Btn
							variant="primary"
							icon="refresh"
							busy={ run.busy }
							onClick={ () => void run.run() }
						>
							{ __(
								'Run stock sync now',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null
				}
			/>
			<TabNav
				label={ __( 'Stock pages', 'estasoft-erp-crm-sync-engine' ) }
				items={ [
					{
						label: __( 'Stock', 'estasoft-erp-crm-sync-engine' ),
						to: '/stock',
						current: tab === 'stock',
					},
					{
						label: __( 'Tracking', 'estasoft-erp-crm-sync-engine' ),
						to: '/stock/tracking',
						current: tab === 'tracking',
					},
					{
						label: __(
							'Conflicts',
							'estasoft-erp-crm-sync-engine'
						),
						to: '/stock/conflicts',
						current: tab === 'conflicts',
						count: conflicts.length,
					},
				] }
			/>
			{ run.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( run.error ) }
				</Callout>
			) : null }
			{ ! supported ? (
				<EmptyState
					as="h2"
					title={ __(
						'Not offered for this system',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					{ sprintf(
						/* translators: %s: system name */ __(
							'%s does not give the plugin stock quantities or tracking numbers to read, so there is nothing to set up here.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				</EmptyState>
			) : null }
			{ supported && ( rules.loading || overview.loading ) ? (
				<LoadingState
					rows={ 5 }
					label={ __(
						'Loading stock',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ supported &&
			( ( rules.error && ! rules.data ) ||
				( overview.error && ! overview.data ) ) ? (
				<ErrorState
					error={ rules.error ?? overview.error }
					onRetry={ () => {
						void rules.reload();
						void overview.reload();
					} }
				/>
			) : null }
			{ supported && rules.data && overview.data ? (
				tab === 'stock' ? (
					<StockTabView
						rules={ rules.data }
						overview={ overview.data }
						canConfigure={ canConfigure }
						system={ system }
						onSaved={ ( next ) => rules.setData( next ) }
					/>
				) : tab === 'tracking' ? (
					<TrackingTabView
						rules={ rules.data }
						overview={ overview.data }
						canConfigure={ canConfigure }
						system={ system }
						onSaved={ ( next ) => rules.setData( next ) }
					/>
				) : (
					<ConflictsView
						conflicts={ overview.data.conflicts }
						canManage={ canManage }
						system={ system }
						onChanged={ () => void overview.reload() }
					/>
				)
			) : null }
		</>
	);
}

const Kpis = KpiGrid;

interface TabProps {
	rules: StockRules;
	overview: StockOverview;
	canConfigure: boolean;
	system: string;
	onSaved: ( next: StockRules ) => void;
}

function warehouseState( rules: StockRules ): Record< string, boolean > {
	return Object.fromEntries(
		rules.warehouses.map( ( warehouse ) => [
			warehouse.id,
			warehouse.counts_toward_stock,
		] )
	);
}

function StockTabView( {
	rules,
	overview,
	canConfigure,
	system,
	onSaved,
}: TabProps ) {
	const { notify } = useNotices();
	const [ interval, setIntervalValue ] = useState(
		String( rules.interval_minutes )
	);
	const [ buffer, setBuffer ] = useState( String( rules.safety_buffer ) );
	const [ held, setHeld ] = useState( rules.subtract_held );
	const [ enabled, setEnabled ] = useState( rules.enabled );
	const [ counts, setCounts ] = useState< Record< string, boolean > >(
		warehouseState( rules )
	);
	const [ error, setError ] = useState< string | null >( null );
	const reset = () => {
		setIntervalValue( String( rules.interval_minutes ) );
		setBuffer( String( rules.safety_buffer ) );
		setHeld( rules.subtract_held );
		setEnabled( rules.enabled );
		setCounts( warehouseState( rules ) );
		setError( null );
	};
	useEffect( reset, [ rules ] );
	const changes =
		( interval !== String( rules.interval_minutes ) ? 1 : 0 ) +
		( buffer !== String( rules.safety_buffer ) ? 1 : 0 ) +
		( held !== rules.subtract_held ? 1 : 0 ) +
		( enabled !== rules.enabled ? 1 : 0 ) +
		rules.warehouses.filter(
			( warehouse ) =>
				counts[ warehouse.id ] !== warehouse.counts_toward_stock
		).length;
	const save = useAsyncAction( async () => {
		const problem = validateWholeNumber( buffer, 0, 999 );
		setError( problem );
		if ( problem ) {
			return;
		}
		// The server refuses this too; saying it here saves a round trip.
		if (
			rules.warehouses.length > 0 &&
			! rules.warehouses.some(
				( warehouse ) =>
					counts[ warehouse.id ] ?? warehouse.counts_toward_stock
			)
		) {
			throw new Error(
				__(
					'At least one warehouse must count toward shop stock.',
					'estasoft-erp-crm-sync-engine'
				)
			);
		}
		const next = await api.stock.saveRules( {
			enabled,
			interval_minutes: Number( interval ),
			safety_buffer: Number( buffer ),
			subtract_held: held,
			warehouses: rules.warehouses.map( ( warehouse ) => ( {
				id: warehouse.id,
				counts_toward_stock:
					counts[ warehouse.id ] ?? warehouse.counts_toward_stock,
			} ) ),
		} );
		onSaved( next );
		notify( {
			kind: 'success',
			text: __( 'Stock rules saved.', 'estasoft-erp-crm-sync-engine' ),
		} );
	} );
	return (
		<div className="estasoft-sync-stack">
			<Kpis
				items={ [
					{
						label: __(
							'Products checked',
							'estasoft-erp-crm-sync-engine'
						),
						value: overview.matched,
						hint: overview.last_run_at
							? __(
									'matched in the last run',
									'estasoft-erp-crm-sync-engine'
								)
							: __(
									'not run yet',
									'estasoft-erp-crm-sync-engine'
								),
					},
					{
						label: __( 'Changed', 'estasoft-erp-crm-sync-engine' ),
						value: overview.changes.length,
						hint: __(
							'in the last run',
							'estasoft-erp-crm-sync-engine'
						),
					},
					{
						label: __(
							'Conflicts',
							'estasoft-erp-crm-sync-engine'
						),
						value: overview.conflicts.length,
						hint: __(
							'need a decision',
							'estasoft-erp-crm-sync-engine'
						),
					},
					{
						label: __( 'Runs', 'estasoft-erp-crm-sync-engine' ),
						value: sprintf(
							/* translators: %d: minutes */ __(
								'Every %d min',
								'estasoft-erp-crm-sync-engine'
							),
							rules.interval_minutes
						),
						hint: sprintf(
							/* translators: %s: system name */ __(
								'and when %s reports a change',
								'estasoft-erp-crm-sync-engine'
							),
							system
						),
					},
				] }
			/>
			{ overview.last_run_at ? (
				<p className="estasoft-sync-muted">
					{ __( 'Last run:', 'estasoft-erp-crm-sync-engine' ) }{ ' ' }
					<DateTime value={ overview.last_run_at } kind="smart" />
				</p>
			) : null }
			{ overview.unmatched > 0 ? (
				<Callout
					tone="warning"
					action={
						<Link
							to="/mapping/products"
							className="estasoft-sync-link"
						>
							{ __(
								'Open Products',
								'estasoft-erp-crm-sync-engine'
							) }
						</Link>
					}
				>
					{ sprintf(
						/* translators: %d: number of products */ _n(
							'%d product has no match, so its stock is not updated.',
							'%d products have no match, so their stock is not updated.',
							overview.unmatched,
							'estasoft-erp-crm-sync-engine'
						),
						overview.unmatched
					) }
				</Callout>
			) : null }
			<Card title={ __( 'Rules', 'estasoft-erp-crm-sync-engine' ) }>
				<fieldset
					className="estasoft-sync-fieldset"
					disabled={ ! canConfigure }
				>
					<legend className="estasoft-sync-sr-only">
						{ __( 'Stock rules', 'estasoft-erp-crm-sync-engine' ) }
					</legend>
					<p>
						<span className="estasoft-sync-strong">
							{ __(
								'Which side wins',
								'estasoft-erp-crm-sync-engine'
							) }
						</span>
						<span className="estasoft-sync-muted estasoft-sync-block">
							{ sprintf(
								/* translators: 1: system name */ __(
									'%1$s wins. WooCommerce never writes stock to %1$s.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</span>
					</p>
					<CheckField
						label={ __(
							'Update WooCommerce stock',
							'estasoft-erp-crm-sync-engine'
						) }
						checked={ enabled }
						onChange={ setEnabled }
					/>
					<SelectField
						label={ sprintf(
							/* translators: %s: system name */ __(
								'Check %s for stock',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
						value={ interval }
						onChange={ setIntervalValue }
						options={ [
							{
								value: '5',
								label: __(
									'Every 5 minutes',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '15',
								label: __(
									'Every 15 minutes',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '60',
								label: __(
									'Every hour',
									'estasoft-erp-crm-sync-engine'
								),
							},
							...( [ 5, 15, 60 ].includes(
								rules.interval_minutes
							)
								? []
								: [
										{
											value: String(
												rules.interval_minutes
											),
											label: sprintf(
												/* translators: %d: minutes */ __(
													'Every %d minutes',
													'estasoft-erp-crm-sync-engine'
												),
												rules.interval_minutes
											),
										},
									] ),
						] }
						hint={ sprintf(
							/* translators: %s: system name */ __(
								'And whenever %s says a quantity changed.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					/>
					<TextField
						label={ __(
							'Safety buffer (units kept in reserve for each product)',
							'estasoft-erp-crm-sync-engine'
						) }
						type="number"
						min={ 0 }
						max={ 999 }
						value={ buffer }
						onChange={ setBuffer }
						error={ error }
						hint={ __(
							'Shows less than your system has, so a short delay is less likely to oversell.',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					<CheckField
						label={ __(
							'Count items held in unpaid orders',
							'estasoft-erp-crm-sync-engine'
						) }
						hint={ sprintf(
							/* translators: %s: system name */ __(
								'WooCommerce holds stock for unpaid orders for a while. The shop quantity never rises above %s minus what is held.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
						checked={ held }
						onChange={ setHeld }
					/>
				</fieldset>
			</Card>
			<Card
				title={ __( 'Warehouses', 'estasoft-erp-crm-sync-engine' ) }
				description={ __(
					'The shop shows the sum of the warehouses that count, minus the safety buffer. WooCommerce keeps one quantity per product, so per-warehouse stock is not shown in the shop.',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				<fieldset
					className="estasoft-sync-fieldset estasoft-sync-fieldset--flush"
					disabled={ ! canConfigure }
				>
					<legend className="estasoft-sync-sr-only">
						{ __( 'Warehouses', 'estasoft-erp-crm-sync-engine' ) }
					</legend>
					<ResponsiveTable<
						StockRules[ 'warehouses' ][ number ],
						string
					>
						caption={ __(
							'Warehouses',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ rules.warehouses }
						rowKey={ ( warehouse ) => warehouse.id }
						columns={ [
							{
								id: 'name',
								header: __(
									'Warehouse',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( warehouse ) => (
									<span
										className="estasoft-sync-strong"
										dir="auto"
									>
										{ warehouse.name }
									</span>
								),
							},
							{
								id: 'counts',
								header: __(
									'Counts toward shop stock',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( warehouse ) => (
									<CheckField
										label={ sprintf(
											/* translators: %s: warehouse name */ __(
												'Counts: %s',
												'estasoft-erp-crm-sync-engine'
											),
											warehouse.name
										) }
										checked={
											counts[ warehouse.id ] ?? false
										}
										onChange={ ( value ) =>
											setCounts( {
												...counts,
												[ warehouse.id ]: value,
											} )
										}
									/>
								),
							},
							{
								id: 'used',
								header: __(
									'Used for',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( warehouse ) =>
									warehouse.countries.join( ', ' ) || '—',
							},
						] }
					/>
				</fieldset>
			</Card>
			<Card
				title={ __( 'Recent changes', 'estasoft-erp-crm-sync-engine' ) }
				description={ sprintf(
					/* translators: %s: system name */ __(
						'The shop quantity is the quantity in %s minus the safety buffer.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
				flush
			>
				{ overview.changes.length === 0 ? (
					<EmptyState
						title={ __(
							'Stock is up to date',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ __(
							'Nothing changed in the last run.',
							'estasoft-erp-crm-sync-engine'
						) }
					</EmptyState>
				) : (
					<ResponsiveTable< StockChange, number >
						caption={ __(
							'Recent stock changes',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ overview.changes }
						rowKey={ ( change ) => change.id }
						columns={ [
							{
								id: 'sku',
								header: __(
									'SKU',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( change ) => (
									<span className="estasoft-sync-mono">
										{ change.sku }
									</span>
								),
							},
							{
								id: 'product',
								header: __(
									'Product',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( change ) => (
									<span dir="auto">
										{ change.product_name }
									</span>
								),
							},
							{
								id: 'system',
								header: system,
								cell: ( change ) => change.system_quantity,
							},
							{
								id: 'shop',
								header: __(
									'Shop before, then after',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( change ) =>
									sprintf(
										/* translators: 1: quantity before, 2: quantity after */ __(
											'%1$d, then %2$d',
											'estasoft-erp-crm-sync-engine'
										),
										change.shop_before,
										change.shop_after
									),
							},
							{
								id: 'time',
								header: __(
									'Time',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( change ) => (
									<DateTime value={ change.at } />
								),
							},
						] }
					/>
				) }
			</Card>
			{ canConfigure ? (
				<SaveBar
					changes={ changes }
					saving={ save.busy }
					onSave={ () => void save.run() }
					onDiscard={ reset }
				/>
			) : null }
			{ save.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( save.error ) }
				</Callout>
			) : null }
		</div>
	);
}

/**
 * The order status slug without the `wc-` prefix the server accepts but WooCommerce does not store
 * in its own lists.
 *
 * @param value Saved value of `delivered_becomes`.
 * @return The slug.
 */
function statusSlug( value: string ): string {
	return value.replace( /^wc-/, '' );
}

function TrackingTabView( {
	rules,
	overview,
	canConfigure,
	system,
	onSaved,
}: TabProps ) {
	const { notify } = useNotices();
	const saved = statusSlug( rules.delivered_becomes );
	const [ becomes, setBecomes ] = useState< string >( saved );
	const [ tracking, setTracking ] = useState( rules.tracking_enabled );
	const [ cod, setCod ] = useState( rules.cod_payment_on_delivery );
	const reset = () => {
		setBecomes( statusSlug( rules.delivered_becomes ) );
		setTracking( rules.tracking_enabled );
		setCod( rules.cod_payment_on_delivery );
	};
	useEffect( reset, [ rules ] );
	const changes =
		( becomes !== saved ? 1 : 0 ) +
		( tracking !== rules.tracking_enabled ? 1 : 0 ) +
		( cod !== rules.cod_payment_on_delivery ? 1 : 0 );
	const save = useAsyncAction( async () => {
		const next = await api.stock.saveRules( {
			delivered_becomes: becomes,
			tracking_enabled: tracking,
			cod_payment_on_delivery: cod,
		} );
		onSaved( next );
		notify( {
			kind: 'success',
			text: __(
				'Tracking settings saved.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
	} );
	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'When %s ships an order',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			>
				<fieldset
					className="estasoft-sync-fieldset"
					disabled={ ! canConfigure }
				>
					<legend className="estasoft-sync-sr-only">
						{ __(
							'Tracking settings',
							'estasoft-erp-crm-sync-engine'
						) }
					</legend>
					<SelectField
						label={ __(
							'Move the order to',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ becomes }
						onChange={ setBecomes }
						hint={ __(
							'The status only moves forward, never back.',
							'estasoft-erp-crm-sync-engine'
						) }
						options={ [
							{
								value: 'completed',
								label: __(
									'Completed when delivered',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: 'none',
								label: __(
									'Do not change the status',
									'estasoft-erp-crm-sync-engine'
								),
							},
							// A status saved some other way stays visible, labelled with its slug, so saving
							// this page does not silently replace it.
							...( [ 'completed', 'none' ].includes( saved )
								? []
								: [ { value: saved, label: saved } ] ),
						] }
					/>
					<CheckField
						label={ __(
							'Save the carrier and tracking number on the order',
							'estasoft-erp-crm-sync-engine'
						) }
						hint={ __(
							'Added as an order note and made available to tracking plugins.',
							'estasoft-erp-crm-sync-engine'
						) }
						checked={ tracking }
						onChange={ setTracking }
					/>
					<CheckField
						label={ sprintf(
							/* translators: %s: system name */ __(
								'Tell %s when the payment is collected',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
						hint={ __(
							'When a cash on delivery order is marked delivered, a payment event is queued.',
							'estasoft-erp-crm-sync-engine'
						) }
						checked={ cod }
						onChange={ setCod }
					/>
				</fieldset>
			</Card>
			<Card
				title={ __(
					'Recent shipments',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				{ overview.tracking_recent.length === 0 ? (
					<EmptyState
						title={ __(
							'No shipments yet',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ sprintf(
							/* translators: %s: system name */ __(
								'Tracking numbers appear here once %s has shipped an order.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</EmptyState>
				) : (
					<ResponsiveTable<
						StockOverview[ 'tracking_recent' ][ number ],
						number
					>
						caption={ __(
							'Recent shipments',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ overview.tracking_recent }
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
									<span className="estasoft-sync-strong">
										{ row.order_label }
									</span>
								),
							},
							{
								id: 'carrier',
								header: __(
									'Carrier',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( row ) => row.carrier,
							},
							{
								id: 'number',
								header: __(
									'Tracking number',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( row ) => (
									<span className="estasoft-sync-mono">
										{ row.number }
									</span>
								),
							},
							{
								id: 'at',
								header: __(
									'Updated',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( row ) => <DateTime value={ row.at } />,
							},
						] }
					/>
				) }
			</Card>
			{ canConfigure ? (
				<SaveBar
					changes={ changes }
					saving={ save.busy }
					onSave={ () => void save.run() }
					onDiscard={ reset }
				/>
			) : null }
			{ save.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( save.error ) }
				</Callout>
			) : null }
		</div>
	);
}

function ConflictsView( {
	conflicts,
	canManage,
	system,
	onChanged,
}: {
	conflicts: StockConflict[];
	canManage: boolean;
	system: string;
	onChanged: () => void;
} ) {
	const { notify } = useNotices();
	const act = useAsyncAction(
		async ( id: number, action: 'accept' | 'hold', sku: string ) => {
			if ( action === 'accept' ) {
				await api.stock.accept( id );
				notify( {
					kind: 'success',
					text: sprintf(
						/* translators: 1: SKU, 2: system name */ __(
							'The shop now shows the %2$s quantity for %1$s.',
							'estasoft-erp-crm-sync-engine'
						),
						sku,
						system
					),
				} );
			} else {
				await api.stock.hold( id );
				notify( {
					kind: 'info',
					text: sprintf(
						/* translators: %s: SKU */ __(
							'Holding %s. The shop quantity stays as it is, and the conflict stays open.',
							'estasoft-erp-crm-sync-engine'
						),
						sku
					),
				} );
			}
			onChanged();
		}
	);
	if ( conflicts.length === 0 ) {
		return (
			<EmptyState
				as="h2"
				title={ __( 'No conflicts', 'estasoft-erp-crm-sync-engine' ) }
			>
				{ sprintf(
					/* translators: %s: system name */ __(
						'A conflict is a case where the quantity in %s does not fit what WooCommerce already sold. None right now.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			</EmptyState>
		);
	}
	return (
		<div className="estasoft-sync-stack">
			<p className="estasoft-sync-muted">
				{ sprintf(
					/* translators: 1: system name */ __(
						'A conflict is a case where the quantity in %1$s does not fit what WooCommerce already sold. Nothing changes in the shop until you choose. Accept sets the shop to what %1$s says. Hold keeps the shop quantity as it is and leaves the conflict open until you decide.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			</p>
			{ act.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( act.error ) }
				</Callout>
			) : null }
			<Card
				title={ sprintf(
					/* translators: %d: number of conflicts */ _n(
						'%d conflict',
						'%d conflicts',
						conflicts.length,
						'estasoft-erp-crm-sync-engine'
					),
					conflicts.length
				) }
				flush
			>
				<ResponsiveTable< StockConflict, number >
					caption={ __(
						'Stock conflicts',
						'estasoft-erp-crm-sync-engine'
					) }
					rows={ conflicts }
					rowKey={ ( conflict ) => conflict.id }
					columns={ [
						{
							id: 'product',
							header: __(
								'Product',
								'estasoft-erp-crm-sync-engine'
							),
							primary: true,
							cell: ( conflict ) => (
								<>
									<span className="estasoft-sync-strong estasoft-sync-mono">
										{ conflict.sku }
									</span>
									<span
										className="estasoft-sync-muted estasoft-sync-block"
										dir="auto"
									>
										{ conflict.product_name }
									</span>
								</>
							),
						},
						{
							id: 'differs',
							header: __(
								'What differs',
								'estasoft-erp-crm-sync-engine'
							),
							wide: true,
							cell: ( conflict ) =>
								sprintf(
									/* translators: 1: system name, 2: quantity there, 3: units sold and not synced */ __(
										'%1$s has %2$d. %3$d sold and not synced yet.',
										'estasoft-erp-crm-sync-engine'
									),
									system,
									conflict.system_quantity,
									conflict.sold_unsynced
								),
						},
						{
							id: 'problem',
							header: __(
								'Problem',
								'estasoft-erp-crm-sync-engine'
							),
							cell: ( conflict ) => (
								<StatusBadge
									tone={
										conflict.system_quantity < 0
											? 'danger'
											: 'warning'
									}
								>
									{ conflict.system_quantity < 0
										? __(
												'Negative quantity',
												'estasoft-erp-crm-sync-engine'
											)
										: __(
												'Sold more than the system has',
												'estasoft-erp-crm-sync-engine'
											) }
								</StatusBadge>
							),
						},
						{
							id: 'actions',
							header: __(
								'Actions',
								'estasoft-erp-crm-sync-engine'
							),
							hideHeader: true,
							align: 'end',
							cell: ( conflict ) =>
								canManage ? (
									<span className="estasoft-sync-rowactions">
										<Btn
											small
											disabled={ act.busy }
											aria-label={ sprintf(
												/* translators: 1: system name, 2: SKU */ __(
													'Accept %1$s’s quantity for %2$s',
													'estasoft-erp-crm-sync-engine'
												),
												system,
												conflict.sku
											) }
											onClick={ () =>
												void act.run(
													conflict.id,
													'accept',
													conflict.sku
												)
											}
										>
											{ sprintf(
												/* translators: %s: system name */ __(
													'Accept %s’s quantity',
													'estasoft-erp-crm-sync-engine'
												),
												system
											) }
										</Btn>
										<Btn
											small
											disabled={ act.busy }
											aria-label={ sprintf(
												/* translators: %s: SKU */ __(
													'Hold %s',
													'estasoft-erp-crm-sync-engine'
												),
												conflict.sku
											) }
											onClick={ () =>
												void act.run(
													conflict.id,
													'hold',
													conflict.sku
												)
											}
										>
											{ __(
												'Hold',
												'estasoft-erp-crm-sync-engine'
											) }
										</Btn>
									</span>
								) : null,
						},
					] }
				/>
			</Card>
		</div>
	);
}
