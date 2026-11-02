import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ConnectionSummary, ReconOverview, Status } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { navigate, routeTo } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { useFormat } from '../../components/FormatContext';
import { Icon } from '../../components/Icon';
import { KeyValueList } from '../../components/KeyValueList';
import { PageHeader } from '../../components/PageHeader';
import { PauseDialog } from '../../components/PauseDialog';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { groupRemedy, groupSentence } from '../../lib/attention';
import { formatFullDateTime, formatSpan, formatTime } from '../../lib/format';
import {
	daysPhrase,
	errorText,
	groupsPhrase,
	notesPhrase,
	ordersPhrase,
	systemName,
} from '../../lib/text';
import { HourlyChart } from './HourlyChart';
import { LatestEvents } from './LatestEvents';
import { Kpi } from '../../components/Kpi';
import { ConnectionCard, QueueMeters } from './parts';
import { ShadowDashboard } from './ShadowDashboard';

/**
 * The dashboard: picks the variant for the mode the server reports.
 *
 * @return The screen.
 */
export function Dashboard() {
	const { status, error, loading, reload } = useStatus();
	if ( loading && ! status ) {
		return (
			<>
				<PageHeader
					title={ __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ) }
				/>
				<LoadingState
					label={ __(
						'Loading the dashboard',
						'estasoft-erp-crm-sync-engine'
					) }
					rows={ 5 }
				/>
			</>
		);
	}
	if ( ! status ) {
		return (
			<>
				<PageHeader
					title={ __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ) }
				/>
				<ErrorState
					error={ error }
					onRetry={ () => void reload() }
					title={ __(
						'The dashboard could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( status.mode === 'not_connected' || status.mode === 'first_run' ) {
		return <FirstRun />;
	}
	if ( status.mode === 'shadow' ) {
		return <ShadowDashboard status={ status } />;
	}
	return <NormalDashboard status={ status } />;
}

function FirstRun() {
	const { canConfigure } = useApp();
	const steps: [ string, string ][] = [
		[
			__( 'Check your store', 'estasoft-erp-crm-sync-engine' ),
			__(
				'Looks for duplicate SKUs, missing country codes and other things that break syncing.',
				'estasoft-erp-crm-sync-engine'
			),
		],
		[
			__( 'Connect a system', 'estasoft-erp-crm-sync-engine' ),
			__(
				'Choose your system and test the connection.',
				'estasoft-erp-crm-sync-engine'
			),
		],
		[
			__( 'Choose what to sync', 'estasoft-erp-crm-sync-engine' ),
			__(
				'Orders, customers, stock, tracking. You decide which side is the source of truth.',
				'estasoft-erp-crm-sync-engine'
			),
		],
		[
			__( 'Preview a real order', 'estasoft-erp-crm-sync-engine' ),
			__(
				'See exactly what your system will receive before anything is sent.',
				'estasoft-erp-crm-sync-engine'
			),
		],
		[
			__( 'Go live', 'estasoft-erp-crm-sync-engine' ),
			__(
				'Start in shadow mode, then switch to live when the comparison looks right.',
				'estasoft-erp-crm-sync-engine'
			),
		],
	];
	return (
		<>
			<PageHeader
				title={ __(
					'Welcome to ESTASOFT SYNC',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'Connect your store to your accounting or ERP system. Checkout never waits for it.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			<Card
				title={ __(
					'Connect your first system',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'Setup takes about ten minutes. Nothing is sent to your system until you say so: you start in shadow mode, which only compares.',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				{ canConfigure ? (
					<Btn
						variant="primary"
						onClick={ () => navigate( '/setup' ) }
					>
						{ __( 'Start setup', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
				) : (
					<Callout tone="info">
						{ __(
							'An administrator needs to set up the connection. You can see the queue once it is connected.',
							'estasoft-erp-crm-sync-engine'
						) }
					</Callout>
				) }
			</Card>
			<Card
				title={ __(
					'What setup does',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				<ol className="estasoft-sync-numbered">
					{ steps.map( ( [ title, text ], index ) => (
						<li key={ title }>
							<span
								className="estasoft-sync-steps__num"
								aria-hidden="true"
							>
								{ index + 1 }
							</span>
							<div>
								<p className="estasoft-sync-strong">
									{ title }
								</p>
								<p className="estasoft-sync-muted">{ text }</p>
							</div>
						</li>
					) ) }
				</ol>
			</Card>
		</>
	);
}

function NormalDashboard( { status }: { status: Status } ) {
	const { canManage, canConfigure } = useApp();
	const { reload } = useStatus();
	const { notify } = useNotices();
	const cfg = useFormat();
	const connection = status.connection;
	const system = systemName( connection );
	const paused = status.mode === 'paused';
	const [ pauseOpen, setPauseOpen ] = useState( false );

	const attention = useResource(
		( signal ) => api.queue.attention( signal ),
		[ status.needs_attention ],
		{ keepPrevious: true }
	);
	const recon = useResource(
		( signal ) => api.reconciliation.get( signal ),
		[],
		{ enabled: connection?.capabilities.reconciliation !== false }
	);
	const rejected = useResource(
		( signal ) => api.invoices.list( { status: 'rejected' }, signal ),
		[],
		{ enabled: connection?.capabilities.invoice_readback === true }
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
		await reload();
	} );
	const resume = useAsyncAction( async () => {
		await api.resume( { connection_id: connection?.id } );
		notify( {
			kind: 'success',
			text: __(
				'Syncing resumed. Waiting orders are sent oldest first.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
		await reload();
	} );
	const check = useAsyncAction( async () => {
		if ( ! connection ) {
			return;
		}
		const result = await api.connections.test( connection.id );
		if ( result.ok ) {
			notify( {
				kind: 'success',
				text: sprintf(
					/* translators: %s: system name such as Odoo */
					__(
						'%s answered. The connection checks again by itself and resumes when it is healthy.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				),
			} );
		} else {
			notify( {
				kind: 'error',
				text:
					result.error?.message ??
					sprintf(
						/* translators: %s: system name */ __(
							'%s did not answer.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					),
			} );
		}
		await reload();
	} );

	const oldest = status.oldest_waiting_at
		? Math.max(
				0,
				( new Date( status.now ).getTime() -
					new Date( status.oldest_waiting_at ).getTime() ) /
					1000
			)
		: null;
	const actionError = syncNow.error ?? resume.error ?? check.error;
	const groups = attention.data ?? [];
	const notes = buildNotes(
		status,
		recon.data,
		rejected.data?.total ?? 0,
		rejected.data?.items[ 0 ]?.order_label
	);

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ) }
				description={ formatFullDateTime( status.now, cfg ) }
				actions={
					canManage ? (
						paused ? (
							<>
								{ canConfigure ? (
									<Btn
										variant="primary"
										busy={ check.busy }
										onClick={ () => void check.run() }
									>
										{ sprintf(
											/* translators: %s: system name */ __(
												'Check %s now',
												'estasoft-erp-crm-sync-engine'
											),
											system
										) }
									</Btn>
								) : null }
								<Btn
									variant={
										canConfigure ? undefined : 'primary'
									}
									busy={ resume.busy }
									onClick={ () => void resume.run() }
								>
									{ __(
										'Resume syncing',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							</>
						) : (
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
						)
					) : null
				}
			/>
			{ actionError ? (
				<Callout tone="danger" role="alert">
					{ errorText( actionError ) }
				</Callout>
			) : null }
			{ paused && connection ? (
				<PausedBanner
					connection={ connection }
					status={ status }
					system={ system }
				/>
			) : null }

			<div className="estasoft-sync-grid estasoft-sync-kpis">
				<Kpi
					label={ __(
						'Synced today',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ status.synced_today }
					hint={ __(
						'since midnight',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<Kpi
					label={ __( 'Waiting', 'estasoft-erp-crm-sync-engine' ) }
					value={ status.waiting }
					hint={ sprintf(
						/* translators: 1: queued count, 2: syncing count, 3: retrying count */
						__(
							'%1$d queued, %2$d syncing, %3$d retrying',
							'estasoft-erp-crm-sync-engine'
						),
						status.by_state.queued,
						status.by_state.syncing,
						status.by_state.retrying
					) }
				/>
				<Kpi
					label={ __(
						'Needs attention',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ status.needs_attention }
					hint={
						paused
							? __(
									'orders failed before the pause',
									'estasoft-erp-crm-sync-engine'
								)
							: __(
									'orders failed and need a person',
									'estasoft-erp-crm-sync-engine'
								)
					}
				/>
				<Kpi
					label={ __(
						'Oldest waiting',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ oldest === null ? '—' : formatSpan( oldest ) }
					hint={
						status.oldest_waiting_order
							? sprintf(
									/* translators: %s: order number such as #10484 */
									__(
										'Order %s',
										'estasoft-erp-crm-sync-engine'
									),
									status.oldest_waiting_order.startsWith(
										'#'
									)
										? status.oldest_waiting_order
										: `#${ status.oldest_waiting_order }`
								)
							: __(
									'Nothing is waiting',
									'estasoft-erp-crm-sync-engine'
								)
					}
				/>
			</div>

			<div className="estasoft-sync-split">
				<div className="estasoft-sync-split__main">
					<Card
						title={ __(
							'Needs attention',
							'estasoft-erp-crm-sync-engine'
						) }
						description={ attentionSummary(
							groups.length,
							status.needs_attention,
							notes.length
						) }
						flush
					>
						{ attention.loading ? (
							<LoadingState
								rows={ 2 }
								label={ __(
									'Loading what needs attention',
									'estasoft-erp-crm-sync-engine'
								) }
							/>
						) : null }
						{ attention.error && ! attention.data ? (
							<ErrorState
								error={ attention.error }
								onRetry={ () => void attention.reload() }
							/>
						) : null }
						{ ! attention.loading &&
						! attention.error &&
						groups.length + notes.length === 0 ? (
							<p className="estasoft-sync-pad estasoft-sync-muted">
								{ __(
									'All clear. No order needs a person. Failed orders will appear here, grouped by cause.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						) : null }
						<ul className="estasoft-sync-attlist">
							{ groups.map( ( group ) => (
								<li
									key={ `${ group.error_code }|${ group.cause }` }
									className="estasoft-sync-att"
								>
									<Icon name="failed" size={ 20 } />
									<div className="estasoft-sync-att__text">
										<p className="estasoft-sync-strong">
											{ groupSentence( group, system ) }
										</p>
										<p className="estasoft-sync-muted">
											{ groupRemedy( group, system ) }
										</p>
									</div>
									{ canManage ? (
										<Btn
											small
											onClick={ () =>
												navigate(
													routeTo(
														'/queue/attention'
													)
												)
											}
										>
											{ group.count > 1
												? __(
														'Review and retry',
														'estasoft-erp-crm-sync-engine'
													)
												: __(
														'Open order',
														'estasoft-erp-crm-sync-engine'
													) }
										</Btn>
									) : null }
								</li>
							) ) }
							{ notes.map( ( note ) => (
								<li
									key={ note.key }
									className="estasoft-sync-att"
								>
									<Icon
										name={
											note.tone === 'warning'
												? 'warning'
												: 'info'
										}
										size={ 20 }
									/>
									<div className="estasoft-sync-att__text">
										<p className="estasoft-sync-strong">
											{ note.title }
										</p>
										<p className="estasoft-sync-muted">
											{ note.text }
										</p>
									</div>
									<Btn
										small
										onClick={ () => navigate( note.route ) }
									>
										{ note.action }
									</Btn>
								</li>
							) ) }
						</ul>
					</Card>
					<HourlyChart points={ status.hourly } />
					<LatestEvents events={ status.events } />
				</div>
				<div className="estasoft-sync-split__side">
					{ paused ? (
						<Card
							title={ __(
								'What happens next',
								'estasoft-erp-crm-sync-engine'
							) }
							flush
						>
							<ul className="estasoft-sync-attlist">
								<li className="estasoft-sync-att">
									<Icon name="synced" size={ 20 } />
									<p>
										{ sprintf(
											/* translators: %s: system name */ __(
												'Customers can still check out. Nothing waits for %s.',
												'estasoft-erp-crm-sync-engine'
											),
											system
										) }
									</p>
								</li>
								<li className="estasoft-sync-att">
									<Icon name="synced" size={ 20 } />
									<p>
										{ sprintf(
											/* translators: %s: system name */ __(
												'When %s answers, the oldest orders go first, two at a time.',
												'estasoft-erp-crm-sync-engine'
											),
											system
										) }
									</p>
								</li>
								<li className="estasoft-sync-att">
									<Icon name="synced" size={ 20 } />
									<p>
										{ sprintf(
											/* translators: %s: system name */ __(
												'Resume by hand at any time. If %s still does not answer, it pauses again.',
												'estasoft-erp-crm-sync-engine'
											),
											system
										) }
									</p>
								</li>
							</ul>
						</Card>
					) : null }
					<ConnectionCard
						status={ status }
						onCheck={ () => void check.run() }
						checking={ check.busy }
					/>
					<Card
						title={ __(
							'Queue right now',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						<QueueMeters status={ status } />
					</Card>
					{ recon.data && recon.data.last_run ? (
						<ReconCard overview={ recon.data } />
					) : null }
				</div>
			</div>
			{ pauseOpen ? (
				<PauseDialog
					connectionId={ connection?.id }
					onClose={ () => setPauseOpen( false ) }
					onDone={ () => void reload() }
				/>
			) : null }
		</>
	);
}

function attentionSummary(
	groupCount: number,
	failed: number,
	notes: number
): string {
	// The orders and the groups are each counted in their own phrase, so each noun agrees with its own number.
	const failedPart = sprintf(
		/* translators: 1: number of failed orders, 2: number of groups such as "3 groups" */
		_n(
			'%1$d failed order in %2$s',
			'%1$d failed orders in %2$s',
			failed,
			'estasoft-erp-crm-sync-engine'
		),
		failed,
		groupsPhrase( groupCount )
	);
	if ( notes === 0 ) {
		return sprintf(
			/* translators: %s: sentence about failed orders */
			__( '%s.', 'estasoft-erp-crm-sync-engine' ),
			failedPart
		);
	}
	return sprintf(
		/* translators: 1: sentence about failed orders, 2: number of notes such as "2 notes" */
		__( '%1$s, and %2$s.', 'estasoft-erp-crm-sync-engine' ),
		failedPart,
		notesPhrase( notes )
	);
}

interface Note {
	key: string;
	tone: 'warning' | 'info';
	title: string;
	text: string;
	action: string;
	route: string;
}

function buildNotes(
	status: Status,
	recon: ReconOverview | undefined,
	rejectedCount: number,
	rejectedOrder: string | undefined
): Note[] {
	const notes: Note[] = [];
	if ( status.encryption_key_missing ) {
		notes.push( {
			key: 'key',
			tone: 'warning',
			title: __(
				'The encryption key is missing from wp-config.php',
				'estasoft-erp-crm-sync-engine'
			),
			text: __(
				'Saved credentials cannot be stored safely until you add it.',
				'estasoft-erp-crm-sync-engine'
			),
			action: __( 'Show me how', 'estasoft-erp-crm-sync-engine' ),
			route: '/settings/readiness',
		} );
	}
	if ( rejectedCount > 0 ) {
		notes.push( {
			key: 'invoices',
			tone: 'warning',
			title: sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d invoice was rejected by the tax authority',
					'%d invoices were rejected by the tax authority',
					rejectedCount,
					'estasoft-erp-crm-sync-engine'
				),
				rejectedCount
			),
			text: rejectedOrder
				? sprintf(
						/* translators: %s: order label */
						__(
							'See order %s and the reason on the Invoices page. Fix it in your system, then ask it to report again.',
							'estasoft-erp-crm-sync-engine'
						),
						rejectedOrder
					)
				: __(
						'Fix the cause in your system, then ask it to report again.',
						'estasoft-erp-crm-sync-engine'
					),
			action: __( 'Open Invoices', 'estasoft-erp-crm-sync-engine' ),
			route: '/invoices',
		} );
	}
	const open = recon
		? recon.diffs.filter( ( diff ) => diff.status === 'open' ).length
		: 0;
	if ( recon && recon.last_run && open > 0 ) {
		notes.push( {
			key: 'recon',
			tone: 'warning',
			title: sprintf(
				/* translators: %d: number of orders */
				_n(
					'The last check found %d difference',
					'The last check found %d differences',
					open,
					'estasoft-erp-crm-sync-engine'
				),
				open
			),
			text: sprintf(
				/* translators: 1: number of orders that differ, 2: number of orders compared such as "2104 orders", 3: length of the window such as "7 days" */
				__(
					'WooCommerce and your system disagree on %1$d of %2$s from the last %3$s.',
					'estasoft-erp-crm-sync-engine'
				),
				open,
				ordersPhrase( recon.last_run.compared ),
				daysPhrase( recon.window_days )
			),
			action: __( 'Open Reconciliation', 'estasoft-erp-crm-sync-engine' ),
			route: '/reconciliation',
		} );
	}
	if ( status.cron_on_visits ) {
		notes.push( {
			key: 'cron',
			tone: 'info',
			title: __(
				'Scheduled tasks depend on site visits',
				'estasoft-erp-crm-sync-engine'
			),
			text: __(
				'A real cron job keeps timing steady when traffic is quiet.',
				'estasoft-erp-crm-sync-engine'
			),
			action: __(
				'Open Readiness check',
				'estasoft-erp-crm-sync-engine'
			),
			route: '/settings/readiness',
		} );
	}
	return notes;
}

function PausedBanner( {
	connection,
	status,
	system,
}: {
	connection: ConnectionSummary;
	status: Status;
	system: string;
} ) {
	const cfg = useFormat();
	const byCircuit =
		connection.paused_reason === 'circuit' ||
		connection.status === 'paused_circuit';
	const text = byCircuit
		? sprintf(
				/* translators: 1: system name, 2: clock time of its last answer, 3: number of orders waiting, 4: clock time of the next check */
				_n(
					'%1$s has not answered since %2$s. Syncing is paused. Customers can still check out. %3$d order is waiting in the queue. The next automatic check is at %4$s.',
					'%1$s has not answered since %2$s. Syncing is paused. Customers can still check out. %3$d orders are waiting in the queue. The next automatic check is at %4$s.',
					status.waiting,
					'estasoft-erp-crm-sync-engine'
				),
				system,
				formatTime( connection.last_success_at, cfg ),
				status.waiting,
				formatTime( connection.paused_until, cfg )
			)
		: sprintf(
				/* translators: 1: system name, 2: number of orders waiting */
				_n(
					'Syncing to %1$s is paused. Customers can still check out. %2$d order is waiting in the queue.',
					'Syncing to %1$s is paused. Customers can still check out. %2$d orders are waiting in the queue.',
					status.waiting,
					'estasoft-erp-crm-sync-engine'
				),
				system,
				status.waiting
			);
	return (
		<Callout
			tone={ byCircuit ? 'danger' : 'warning' }
			action={
				<Btn
					small
					onClick={ () =>
						navigate( routeTo( `/connections/${ connection.id }` ) )
					}
				>
					{ __( 'Open connection', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			}
		>
			{ text }
		</Callout>
	);
}

function ReconCard( { overview }: { overview: ReconOverview } ) {
	const cfg = useFormat();
	const run = overview.last_run;
	if ( ! run ) {
		return null;
	}
	const open = overview.diffs.filter(
		( diff ) => diff.status === 'open'
	).length;
	return (
		<Card
			title={ __( 'Last night’s check', 'estasoft-erp-crm-sync-engine' ) }
		>
			<KeyValueList
				stacked
				items={ [
					{
						label: __( 'Ran', 'estasoft-erp-crm-sync-engine' ),
						value: (
							<DateTime
								value={ run.finished_at ?? run.started_at }
								kind="smart"
							/>
						),
					},
					{
						label: __( 'Compared', 'estasoft-erp-crm-sync-engine' ),
						value: sprintf(
							/* translators: 1: number of orders such as "2104 orders", 2: length of the window such as "7 days" */
							__(
								'%1$s from the last %2$s',
								'estasoft-erp-crm-sync-engine'
							),
							ordersPhrase( run.compared ),
							daysPhrase( run.window_days )
						),
					},
					{
						label: __( 'Result', 'estasoft-erp-crm-sync-engine' ),
						value: sprintf(
							/* translators: 1: matching count, 2: differing count, 3: repaired count */
							__(
								'%1$d match, %2$d differ, %3$d repaired',
								'estasoft-erp-crm-sync-engine'
							),
							run.matched,
							run.differences,
							run.repaired
						),
					},
				] }
			/>
			<div className="estasoft-sync-actions">
				<Btn small onClick={ () => navigate( '/reconciliation' ) }>
					{ open > 0
						? __(
								'Open Reconciliation',
								'estasoft-erp-crm-sync-engine'
							)
						: __(
								'View the check',
								'estasoft-erp-crm-sync-engine'
							) }
				</Btn>
			</div>
			<span className="estasoft-sync-sr-only">
				{ formatTime( run.started_at, cfg ) }
			</span>
		</Card>
	);
}
