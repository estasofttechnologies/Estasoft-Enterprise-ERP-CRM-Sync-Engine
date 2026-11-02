import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { DiffType, ReconDiff, ReconOverview } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ConfirmDialog } from '../../components/Dialog';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { CheckField, ChoiceGroup, SelectField } from '../../components/Fields';
import { useFormat } from '../../components/FormatContext';
import { KpiGrid } from '../../components/Kpi';
import { PageHeader } from '../../components/PageHeader';
import { ProgressBar } from '../../components/Meter';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SaveBar } from '../../components/SaveBar';
import { StatusBadge } from '../../components/StatusBadge';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { formatMoney } from '../../lib/format';
import { errorText } from '../../lib/text';

function typeLabel( type: DiffType ): string {
	switch ( type ) {
		case 'missing_in_system':
			return __(
				'Missing in the system',
				'estasoft-erp-crm-sync-engine'
			);
		case 'total_differs':
			return __( 'Total differs', 'estasoft-erp-crm-sync-engine' );
		case 'refund_missing':
			return __(
				'Refund not in the system',
				'estasoft-erp-crm-sync-engine'
			);
		case 'status_differs':
			return __( 'Status differs', 'estasoft-erp-crm-sync-engine' );
	}
}

/**
 * Reconciliation: a nightly check that WooCommerce and the system agree. Lists differences with
 * Repair and Ignore, runs the check on demand, and sets the schedule.
 *
 * @return The screen.
 */
export function Reconciliation() {
	const { canManage, canConfigure } = useApp();
	const { notify } = useNotices();
	const { status } = useStatus();
	const system = useSystemName();
	const cfg = useFormat();
	const [ polling, setPolling ] = useState( false );
	const overview = useResource< ReconOverview >(
		( signal ) => api.reconciliation.get( signal ),
		[],
		{ keepPrevious: true, poll: polling ? 3000 : undefined }
	);
	const data = overview.data;
	const running = data?.runs.find( ( run ) => run.status === 'running' );
	// Poll every few seconds while a run is in progress so the progress bar moves and the result appears.
	useEffect( () => {
		setPolling( Boolean( running ) );
		if ( running ) {
			void overview.reload();
		}
		// Only a change of the running run matters here.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ running?.id ] );

	const [ dialog, setDialog ] = useState<
		| { kind: 'run' }
		| { kind: 'repair'; diff: ReconDiff }
		| { kind: 'ignore'; diff: ReconDiff }
		| null
	>( null );
	const [ windowDays, setWindowDays ] = useState( '7' );
	const [ time, setTime ] = useState( '' );
	const [ autoRepair, setAutoRepair ] = useState( false );
	const [ days, setDays ] = useState( '7' );
	useEffect( () => {
		if ( data ) {
			setTime( data.schedule_time );
			setAutoRepair( data.auto_repair_missing );
			setDays( String( data.window_days ) );
			setWindowDays( String( data.window_days ) );
		}
	}, [ data ] );
	const settingsChanges = data
		? ( time !== data.schedule_time ? 1 : 0 ) +
			( autoRepair !== data.auto_repair_missing ? 1 : 0 ) +
			( days !== String( data.window_days ) ? 1 : 0 )
		: 0;
	const saveSettings = useAsyncAction( async () => {
		const next = await api.reconciliation.saveSettings( {
			schedule_time: time,
			window_days: Number( days ),
			auto_repair_missing: autoRepair,
		} );
		overview.setData( next );
		notify( {
			kind: 'success',
			text: __( 'Schedule saved.', 'estasoft-erp-crm-sync-engine' ),
		} );
	} );

	if ( overview.loading ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Reconciliation',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the last check',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Reconciliation',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<ErrorState
					error={ overview.error }
					onRetry={ () => void overview.reload() }
				/>
			</>
		);
	}
	if (
		status?.connection &&
		status.connection.capabilities.reconciliation === false
	) {
		return (
			<>
				<PageHeader
					title={ __(
						'Reconciliation',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<EmptyState
					as="h2"
					title={ __(
						'Not offered for this system',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					{ sprintf(
						/* translators: %s: system name */ __(
							'%s cannot list its orders for comparison, so the nightly check is not available.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				</EmptyState>
			</>
		);
	}
	const run = data.last_run;
	const open = data.diffs.filter( ( diff ) => diff.status === 'open' ).length;
	const progress = running
		? Math.min(
				0.95,
				running.compared /
					Math.max(
						running.compared + 1,
						( run?.compared ?? 0 ) || running.compared + 100
					)
			)
		: 0;

	return (
		<>
			<PageHeader
				title={ __( 'Reconciliation', 'estasoft-erp-crm-sync-engine' ) }
				description={ __(
					'A nightly check that WooCommerce and your system agree. It finds problems that a retry queue cannot see.',
					'estasoft-erp-crm-sync-engine'
				) }
				actions={
					canManage ? (
						<Btn
							variant="primary"
							disabled={ Boolean( running ) }
							onClick={ () => setDialog( { kind: 'run' } ) }
						>
							{ __(
								'Run the check now',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null
				}
			/>
			{ running ? (
				<Callout tone="info" role="status">
					<p className="estasoft-sync-strong">
						{ __(
							'A check is running.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<ProgressBar
						label={ __(
							'Progress of the check',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ progress }
					/>
					<p className="estasoft-sync-muted">
						{ sprintf(
							/* translators: %d: number of orders compared so far */ __(
								'%d orders compared so far. The check only reads, and runs in the background.',
								'estasoft-erp-crm-sync-engine'
							),
							running.compared
						) }
					</p>
				</Callout>
			) : null }
			{ run ? (
				<KpiGrid
					items={ [
						{
							label: __(
								'Last check',
								'estasoft-erp-crm-sync-engine'
							),
							value: (
								<DateTime
									value={ run.finished_at ?? run.started_at }
									kind="smart"
								/>
							),
							hint: sprintf(
								/* translators: %s: clock time */ __(
									'nightly at %s',
									'estasoft-erp-crm-sync-engine'
								),
								data.schedule_time
							),
						},
						{
							label: __(
								'Compared',
								'estasoft-erp-crm-sync-engine'
							),
							value: run.compared,
							hint: sprintf(
								/* translators: %d: days */ __(
									'orders from the last %d days',
									'estasoft-erp-crm-sync-engine'
								),
								run.window_days
							),
						},
						{
							label: __(
								'Match',
								'estasoft-erp-crm-sync-engine'
							),
							value: run.matched,
							hint: __(
								'no action needed',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							label: __(
								'Differences',
								'estasoft-erp-crm-sync-engine'
							),
							value: run.differences,
							hint: sprintf(
								/* translators: 1: repaired, 2: open */ __(
									'%1$d repaired, %2$d open',
									'estasoft-erp-crm-sync-engine'
								),
								run.repaired,
								open
							),
						},
					] }
				/>
			) : (
				<Callout tone="info">
					{ __(
						'No check has run yet. The first one runs tonight, or press Run the check now.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) }
			<Card
				title={ __(
					'Differences from the last check',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				{ data.diffs.length === 0 ? (
					<EmptyState
						title={ __(
							'No differences',
							'estasoft-erp-crm-sync-engine'
						) }
						action={
							canManage ? (
								<Btn
									small
									onClick={ () =>
										setDialog( { kind: 'run' } )
									}
								>
									{ __(
										'Run the check now',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							) : undefined
						}
					>
						{ sprintf(
							/* translators: %s: system name */ __(
								'The last nightly check found no differences between WooCommerce and %s.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</EmptyState>
				) : (
					<ResponsiveTable< ReconDiff, number >
						caption={ __(
							'Differences',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ data.diffs }
						rowKey={ ( diff ) => diff.id }
						columns={ [
							{
								id: 'order',
								header: __(
									'Order',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( diff ) => (
									<>
										<span className="estasoft-sync-strong">
											{ diff.order_label }
										</span>
										<span className="estasoft-sync-muted estasoft-sync-block">
											{ typeLabel( diff.type ) }
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
								cell: ( diff ) => (
									<>
										{ sprintf(
											/* translators: 1: WooCommerce value, 2: system name, 3: value there */ __(
												'WooCommerce: %1$s. %2$s: %3$s.',
												'estasoft-erp-crm-sync-engine'
											),
											diff.wc_value,
											system,
											diff.system_value
										) }
									</>
								),
							},
							{
								id: 'state',
								header: __(
									'State',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( diff ) =>
									diff.status === 'open' ? (
										<StatusBadge tone="warning">
											{ __(
												'Open',
												'estasoft-erp-crm-sync-engine'
											) }
										</StatusBadge>
									) : diff.status === 'repaired' ? (
										<StatusBadge tone="success">
											{ diff.acted_at ? (
												<>
													{ __(
														'Repaired at',
														'estasoft-erp-crm-sync-engine'
													) }{ ' ' }
													<DateTime
														value={ diff.acted_at }
													/>
												</>
											) : (
												__(
													'Repaired',
													'estasoft-erp-crm-sync-engine'
												)
											) }
										</StatusBadge>
									) : (
										<StatusBadge tone="neutral">
											{ __(
												'Ignored',
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
								cell: ( diff ) =>
									canManage && diff.status === 'open' ? (
										<span className="estasoft-sync-rowactions">
											<Btn
												small
												aria-label={ sprintf(
													/* translators: %s: order label */ __(
														'Repair %s',
														'estasoft-erp-crm-sync-engine'
													),
													diff.order_label
												) }
												onClick={ () =>
													setDialog( {
														kind: 'repair',
														diff,
													} )
												}
											>
												{ __(
													'Repair',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
											<Btn
												small
												aria-label={ sprintf(
													/* translators: %s: order label */ __(
														'Ignore %s',
														'estasoft-erp-crm-sync-engine'
													),
													diff.order_label
												) }
												onClick={ () =>
													setDialog( {
														kind: 'ignore',
														diff,
													} )
												}
											>
												{ __(
													'Ignore',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
										</span>
									) : diff.status === 'ignored' &&
									  diff.note ? (
										<span className="estasoft-sync-muted">
											{ diff.note }
										</span>
									) : null,
							},
						] }
					/>
				) }
			</Card>
			<Card
				title={ __(
					'Schedule and tolerance',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<fieldset
					className="estasoft-sync-fieldset"
					disabled={ ! canConfigure }
				>
					<legend className="estasoft-sync-sr-only">
						{ __(
							'Schedule and tolerance',
							'estasoft-erp-crm-sync-engine'
						) }
					</legend>
					<SelectField
						label={ __(
							'Runs every night at',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ time }
						onChange={ setTime }
						options={ [
							...( [
								'00:00',
								'01:00',
								'02:00',
								'03:00',
								'04:00',
							].includes( time )
								? []
								: [ time ] ),
							'00:00',
							'01:00',
							'02:00',
							'03:00',
							'04:00',
						]
							.filter( Boolean )
							.map( ( value ) => ( { value, label: value } ) ) }
						hint={ __(
							'Store time.',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					<SelectField
						label={ __(
							'Compare orders from',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ days }
						onChange={ setDays }
						options={ [
							{
								value: '1',
								label: __(
									'The last 24 hours',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '7',
								label: __(
									'The last 7 days',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '30',
								label: __(
									'The last 30 days',
									'estasoft-erp-crm-sync-engine'
								),
							},
						] }
					/>
					<p>
						<span className="estasoft-sync-strong">
							{ __(
								'Tolerance',
								'estasoft-erp-crm-sync-engine'
							) }
						</span>
						<span className="estasoft-sync-muted estasoft-sync-block">
							{ sprintf(
								/* translators: %s: one minor unit of the store currency, for example AED 0.01 */ __(
									'Totals closer than this count as equal: %s. One minor unit of each currency. Not editable in this version.',
									'estasoft-erp-crm-sync-engine'
								),
								formatMoney(
									{
										minor: data.tolerance_minor,
										currency: cfg.currency,
									},
									cfg
								)
							) }
						</span>
					</p>
					<CheckField
						label={ __(
							'Send missing orders automatically',
							'estasoft-erp-crm-sync-engine'
						) }
						hint={ sprintf(
							/* translators: %s: system name */ __(
								'Only orders that are missing in %s. Amounts and refunds always wait for you.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
						checked={ autoRepair }
						onChange={ setAutoRepair }
					/>
				</fieldset>
				{ canConfigure ? (
					<SaveBar
						changes={ settingsChanges }
						saving={ saveSettings.busy }
						onSave={ () => void saveSettings.run() }
						onDiscard={ () => {
							setTime( data.schedule_time );
							setDays( String( data.window_days ) );
							setAutoRepair( data.auto_repair_missing );
						} }
					/>
				) : null }
				{ saveSettings.error ? (
					<Callout tone="danger" role="alert">
						{ errorText( saveSettings.error ) }
					</Callout>
				) : null }
			</Card>

			{ dialog?.kind === 'run' ? (
				<ConfirmDialog
					title={ __(
						'Run the check now?',
						'estasoft-erp-crm-sync-engine'
					) }
					confirmLabel={ __(
						'Run the check',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						await api.reconciliation.run( Number( windowDays ) );
						notify( {
							kind: 'info',
							text: __(
								'The check started. It runs in the background.',
								'estasoft-erp-crm-sync-engine'
							),
						} );
						await overview.reload();
					} }
				>
					<SelectField
						label={ __(
							'Compare orders from',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ windowDays }
						onChange={ setWindowDays }
						options={ [
							{
								value: '7',
								label: __(
									'The last 7 days',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '1',
								label: __(
									'The last 24 hours',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: '30',
								label: __(
									'The last 30 days',
									'estasoft-erp-crm-sync-engine'
								),
							},
						] }
					/>
					<p className="estasoft-sync-muted">
						{ __(
							'The check only reads, and runs in the background. About 2,000 orders take a few minutes.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
			{ dialog?.kind === 'repair' ? (
				<RepairDialog
					diff={ dialog.diff }
					system={ system }
					onClose={ () => setDialog( null ) }
					onDone={ () => void overview.reload() }
				/>
			) : null }
			{ dialog?.kind === 'ignore' ? (
				<IgnoreDialog
					diff={ dialog.diff }
					system={ system }
					onClose={ () => setDialog( null ) }
					onDone={ () => void overview.reload() }
				/>
			) : null }
		</>
	);
}

function RepairDialog( {
	diff,
	system,
	onClose,
	onDone,
}: {
	diff: ReconDiff;
	system: string;
	onClose: () => void;
	onDone: () => void;
} ) {
	const { notify } = useNotices();
	const options: {
		value: 'wc_to_system' | 'system_to_wc';
		label: string;
		description: string;
	}[] = [];
	if ( diff.type === 'missing_in_system' ) {
		options.push( {
			value: 'wc_to_system',
			label: sprintf(
				/* translators: %s: system name */ __(
					'Send the order to %s',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			description: sprintf(
				/* translators: %s: the value as WooCommerce or the system shows it */ __(
					'Creates the sale order that is missing: %s.',
					'estasoft-erp-crm-sync-engine'
				),
				diff.wc_value
			),
		} );
	} else if ( diff.type === 'refund_missing' ) {
		options.push( {
			value: 'wc_to_system',
			label: sprintf(
				/* translators: %s: system name */ __(
					'Create the credit note in %s',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			description: sprintf(
				/* translators: %s: the value as WooCommerce or the system shows it */ __(
					'A credit note for the refund: %s.',
					'estasoft-erp-crm-sync-engine'
				),
				diff.wc_value
			),
		} );
	} else {
		options.push(
			{
				value: 'wc_to_system',
				label: sprintf(
					/* translators: %s: system name */ __(
						'Update %s to match WooCommerce',
						'estasoft-erp-crm-sync-engine'
					),
					system
				),
				description: sprintf(
					/* translators: %s: the value as WooCommerce or the system shows it */ __(
						'WooCommerce says %s.',
						'estasoft-erp-crm-sync-engine'
					),
					diff.wc_value
				),
			},
			{
				value: 'system_to_wc',
				label: sprintf(
					/* translators: %s: system name */ __(
						'Update WooCommerce to match %s',
						'estasoft-erp-crm-sync-engine'
					),
					system
				),
				description: sprintf(
					/* translators: %s: the value as WooCommerce or the system shows it */ __(
						'The system says %s.',
						'estasoft-erp-crm-sync-engine'
					),
					diff.system_value
				),
			}
		);
	}
	const [ direction, setDirection ] = useState( options[ 0 ].value );
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: 1: kind of difference, 2: order label */ __(
					'Repair %1$s for order %2$s?',
					'estasoft-erp-crm-sync-engine'
				),
				typeLabel( diff.type ).toLowerCase(),
				diff.order_label
			) }
			confirmLabel={ __( 'Repair', 'estasoft-erp-crm-sync-engine' ) }
			onClose={ onClose }
			onConfirm={ async () => {
				await api.reconciliation.repair( diff.id, direction );
				notify( {
					kind: 'success',
					text: sprintf(
						/* translators: %s: order label */ __(
							'Order %s was repaired. The change is written to the log, with who made it.',
							'estasoft-erp-crm-sync-engine'
						),
						diff.order_label
					),
				} );
				onDone();
			} }
		>
			<ChoiceGroup
				legend={ __(
					'What should the repair do?',
					'estasoft-erp-crm-sync-engine'
				) }
				hideLegend
				value={ direction }
				onChange={ ( value ) =>
					setDirection( value as typeof direction )
				}
				choices={ options }
			/>
			<p className="estasoft-sync-muted">
				{ __(
					'Refunds are never sent automatically. The change is written to the log, with who made it.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
		</ConfirmDialog>
	);
}

function IgnoreDialog( {
	diff,
	system,
	onClose,
	onDone,
}: {
	diff: ReconDiff;
	system: string;
	onClose: () => void;
	onDone: () => void;
} ) {
	const { notify } = useNotices();
	const reasons = [
		__( 'A known rounding difference', 'estasoft-erp-crm-sync-engine' ),
		sprintf(
			/* translators: %s: system name */ __(
				'Fixed by hand in %s',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		__( 'Not a real order', 'estasoft-erp-crm-sync-engine' ),
		__( 'Other', 'estasoft-erp-crm-sync-engine' ),
	];
	const [ reason, setReason ] = useState< string >( reasons[ 0 ] );
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: order label */ __(
					'Ignore the difference on order %s?',
					'estasoft-erp-crm-sync-engine'
				),
				diff.order_label
			) }
			confirmLabel={ __( 'Ignore', 'estasoft-erp-crm-sync-engine' ) }
			onClose={ onClose }
			onConfirm={ async () => {
				await api.reconciliation.ignore( diff.id, reason );
				notify( {
					kind: 'success',
					text: sprintf(
						/* translators: %s: order label */ __(
							'The difference on order %s is marked Ignored.',
							'estasoft-erp-crm-sync-engine'
						),
						diff.order_label
					),
				} );
				onDone();
			} }
		>
			<SelectField
				label={ __( 'Why?', 'estasoft-erp-crm-sync-engine' ) }
				value={ reason }
				onChange={ setReason }
				options={ reasons.map( ( entry ) => ( {
					value: entry,
					label: entry,
				} ) ) }
			/>
			<p className="estasoft-sync-muted">
				{ __(
					'The difference is marked Ignored with your name and the reason. It stays in the log, and you can reopen it.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
		</ConfirmDialog>
	);
}
