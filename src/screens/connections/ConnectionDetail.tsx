import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type {
	Connection,
	ConnectionTestResult,
	ReliabilitySettings,
} from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { Link, navigate, routeTo, useRoute } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CopyButton } from '../../components/CopyButton';
import { DateTime } from '../../components/DateTime';
import { ConfirmDialog } from '../../components/Dialog';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { ErrorDetails } from '../../components/ErrorDetails';
import {
	CheckField,
	SelectField,
	TextField,
	validateWholeNumber,
} from '../../components/Fields';
import { useFormat } from '../../components/FormatContext';
import { KeyValueList } from '../../components/KeyValueList';
import { PageHeader } from '../../components/PageHeader';
import { PauseDialog } from '../../components/PauseDialog';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SaveBar } from '../../components/SaveBar';
import { StatusBadge } from '../../components/StatusBadge';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import type { ConnectShape } from '../../lib/connect';
import {
	entryForConnector,
	readSignIn,
	secretWord,
	shapeOfEntry,
} from '../../lib/connect';
import { formatDay, formatDuration } from '../../lib/format';
import { catalogMessage, errorText, systemName } from '../../lib/text';
import { ConnectOutcome } from '../connect/ConnectOutcome';
import { SignInReturn } from '../connect/SignInReturn';
import { daysUntil } from './capabilities';
import {
	EditSettingsDialog,
	lowerFirst,
	ReconnectDialog,
	ReplaceSecretDialog,
	savedFields,
} from './ConnectionDialogs';
import { ConnectionStateChip } from './ConnectionsList';

const RETRY_OPTIONS = [
	{ key: 'four', delays: [ 60, 300, 3600, 86400 ] },
	{ key: 'two', delays: [ 60, 300 ] },
	{ key: 'none', delays: [] as number[] },
];

function retryKey( delays: number[] ): string {
	const match = RETRY_OPTIONS.find(
		( option ) => option.delays.length === delays.length
	);
	return match ? match.key : 'custom';
}

interface Draft {
	retry: string;
	jitter: boolean;
	breaker: string;
	probe: string;
	rps: string;
	obey: boolean;
	concurrency: string;
}

function toDraft( settings: ReliabilitySettings ): Draft {
	return {
		retry: retryKey( settings.retry_delays_seconds ),
		jitter: settings.jitter,
		breaker: String( settings.breaker_threshold ),
		probe: String( settings.probe_interval_minutes ),
		rps: String( settings.requests_per_second ),
		obey: settings.obey_retry_after,
		concurrency: String( settings.concurrency ),
	};
}

/**
 * Ranges for the reliability fields (board Z05).
 */
const RANGES = {
	breaker: [ 3, 20 ],
	probe: [ 1, 60 ],
	rps: [ 1, 50 ],
	concurrency: [ 1, 10 ],
} as const;

/**
 * One connection: health, credentials (write-only), reliability limits, warehouses, disconnect.
 *
 * @param props    Connection id.
 * @param props.id
 * @return The screen.
 */
export function ConnectionDetail( { id }: { id: number } ) {
	const { canConfigure, canManage } = useApp();
	const { status, reload: reloadStatus } = useStatus();
	const { notify } = useNotices();
	const route = useRoute();
	const cfg = useFormat();
	const detail = useResource(
		( signal ) => api.connections.get( id, signal ),
		[ id ],
		{ poll: 60000 }
	);
	const connection = detail.data;
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const entry = entryForConnector(
		catalog.data,
		connection?.connector ?? ''
	);
	const shape = shapeOfEntry( entry );
	const signIn = readSignIn( route.params, route.segments );
	const onboarding = useResource(
		( signal ) => api.onboarding.get( signal ),
		[],
		{ enabled: signIn.result !== null }
	);
	const [ dialog, setDialog ] = useState<
		'replace' | 'reconnect' | 'edit' | 'disconnect' | 'pause' | null
	>( null );
	const [ testResult, setTestResult ] =
		useState< ConnectionTestResult | null >( null );
	const test = useAsyncAction( async () => {
		const result = await api.connections.test( id );
		setTestResult( result );
		await detail.reload();
	} );
	const resume = useAsyncAction( async () => {
		await api.resume( { connection_id: id } );
		notify( {
			kind: 'success',
			text: __(
				'Syncing resumed. Waiting orders are sent oldest first.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
		await Promise.all( [ detail.reload(), reloadStatus() ] );
	} );

	// A sign-in that comes back while this screen is already open (a hash change, not a page load) must
	// see the connection as the server has it now: the server may have activated it. Read it again first.
	const returnKey = signIn.result ? route.hash : '';
	const [ freshKey, setFreshKey ] = useState( returnKey );
	useEffect( () => {
		if ( returnKey && returnKey !== freshKey ) {
			void detail.reload().then( () => setFreshKey( returnKey ) );
		}
		// Only a new return starts a reload.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ returnKey ] );

	// Open the dialog once, when arriving from the expiry notice or a Reconnect button. The way to
	// reconnect depends on how the system connects, so wait for the catalog.
	const opened = useRef( false );
	useEffect( () => {
		if (
			opened.current ||
			! canConfigure ||
			! connection ||
			catalog.loading
		) {
			return;
		}
		if (
			route.params.get( 'reconnect' ) === '1' ||
			route.params.get( 'replace' ) === '1'
		) {
			opened.current = true;
			setDialog( shape === 'own_app' ? 'reconnect' : 'replace' );
		}
	}, [ canConfigure, connection, catalog.loading, shape, route.params ] );

	if ( detail.loading || ( catalog.loading && ! detail.error ) ) {
		return (
			<>
				<PageHeader
					title={ __( 'Connection', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ [
						{
							label: __(
								'Connections',
								'estasoft-erp-crm-sync-engine'
							),
							to: '/connections',
						},
					] }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the connection',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! connection ) {
		return (
			<>
				<PageHeader
					title={ __( 'Connection', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ [
						{
							label: __(
								'Connections',
								'estasoft-erp-crm-sync-engine'
							),
							to: '/connections',
						},
					] }
				/>
				<ErrorState
					error={ detail.error }
					onRetry={ () => void detail.reload() }
					title={ __(
						'This connection could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}

	const name = systemName( connection );
	const now = status?.now ?? new Date().toISOString();
	const paused =
		connection.status === 'paused' ||
		connection.status === 'paused_circuit';
	const expiresIn = connection.credential_expires_at
		? daysUntil( connection.credential_expires_at, now )
		: null;
	const keyProblem = expiresIn !== null && expiresIn <= 30;
	const fields = savedFields( connection );
	const word =
		shape === 'own_app'
			? __( 'sign-in', 'estasoft-erp-crm-sync-engine' )
			: secretWord( fields );
	const firstSecret = fields.find( ( field ) => field.secret );
	const isExport = shape === 'export_file';
	// What Disconnect removes: the saved key, secret or password, the sign-in, or nothing secret at all.
	const removedText = isExport
		? __( 'the saved settings', 'estasoft-erp-crm-sync-engine' )
		: shape === 'own_app'
			? __(
					'the saved sign-in and secret',
					'estasoft-erp-crm-sync-engine'
				)
			: shape === 'key' || ! shape
				? __( 'the saved API key', 'estasoft-erp-crm-sync-engine' )
				: sprintf(
						/* translators: %s: "secret" or "password" */
						__( 'the saved %s', 'estasoft-erp-crm-sync-engine' ),
						word
					);
	const reconnect = () =>
		setDialog( shape === 'own_app' ? 'reconnect' : 'replace' );
	const leaveSignIn = () =>
		navigate( routeTo( `/connections/${ id }` ), true );

	return (
		<>
			<PageHeader
				title={ name }
				description={ <span dir="auto">{ connection.company }</span> }
				breadcrumb={ [
					{
						label: __(
							'Connections',
							'estasoft-erp-crm-sync-engine'
						),
						to: '/connections',
					},
					{ label: name },
				] }
			/>
			{ ! canConfigure ? (
				<Callout tone="info">
					{ __(
						'You can see this connection but not change it. Ask an administrator to change addresses, keys or limits.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			{ signIn.result && canConfigure && freshKey === returnKey ? (
				<SignInReturn
					key={ returnKey }
					connection={ connection }
					system={ entry?.name ?? name }
					result={ signIn.result }
					continueSetup={
						Boolean( onboarding.data ) &&
						! onboarding.data?.completed
					}
					onClose={ leaveSignIn }
					onTested={ () => {
						void detail.reload();
						void reloadStatus();
					} }
				/>
			) : null }
			{ connection.status === 'needs_reconnect' && ! signIn.result ? (
				<Callout
					tone="warning"
					role="alert"
					action={
						canConfigure ? (
							<Btn small onClick={ reconnect }>
								{ __(
									'Reconnect',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : undefined
					}
				>
					<p>
						{ catalogMessage( 'SYNC-AUTH-002', {}, name ) }{ ' ' }
						<span className="estasoft-sync-mono">
							SYNC-AUTH-002
						</span>
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'The plugin stopped sending to this connection. Items wait as Queued and are kept.',
							'estasoft-erp-crm-sync-engine'
						) }{ ' ' }
						<Link
							to="/connections/problems"
							className="estasoft-sync-link"
						>
							{ __(
								'What does this mean?',
								'estasoft-erp-crm-sync-engine'
							) }
						</Link>
					</p>
				</Callout>
			) : null }
			{ paused && connection.paused_reason === 'sign_in' ? (
				<Callout
					tone="danger"
					role="alert"
					action={
						canConfigure && firstSecret ? (
							<Btn small onClick={ reconnect }>
								{ sprintf(
									/* translators: %s: name of the secret such as "API key" */ __(
										'Change the %s',
										'estasoft-erp-crm-sync-engine'
									),
									lowerFirst( firstSecret.label )
								) }
							</Btn>
						) : undefined
					}
				>
					<p>
						{ catalogMessage( 'SYNC-AUTH-001', {}, name ) }{ ' ' }
						<span className="estasoft-sync-mono">
							SYNC-AUTH-001
						</span>
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'The connection is paused. Orders wait as Queued; none are marked Failed. Check the details, then test again.',
							'estasoft-erp-crm-sync-engine'
						) }{ ' ' }
						<Link
							to="/connections/problems"
							className="estasoft-sync-link"
						>
							{ __(
								'What does this mean?',
								'estasoft-erp-crm-sync-engine'
							) }
						</Link>
					</p>
				</Callout>
			) : null }
			{ paused && connection.paused_reason !== 'sign_in' ? (
				<Callout
					tone={
						connection.paused_reason === 'circuit'
							? 'danger'
							: 'warning'
					}
					action={
						canManage ? (
							<Btn
								small
								busy={ resume.busy }
								onClick={ () => void resume.run() }
							>
								{ __(
									'Resume syncing',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : undefined
					}
				>
					{ connection.paused_reason === 'circuit'
						? sprintf(
								/* translators: 1: system name */ __(
									'%1$s did not answer several times in a row, so this connection is paused. It checks again by itself and resumes when %1$s answers. Orders wait as Queued.',
									'estasoft-erp-crm-sync-engine'
								),
								name
							)
						: __(
								'This connection is paused. Orders wait as Queued until you resume.',
								'estasoft-erp-crm-sync-engine'
							) }
				</Callout>
			) : null }
			{ keyProblem && connection.credential_expires_at ? (
				<Callout
					tone={
						expiresIn !== null && expiresIn <= 7
							? 'danger'
							: 'warning'
					}
					action={
						canConfigure ? (
							<Btn small onClick={ reconnect }>
								{ shape === 'own_app'
									? __(
											'Sign in again',
											'estasoft-erp-crm-sync-engine'
										)
									: sprintf(
											/* translators: %s: "key", "secret" or "password" */ __(
												'Replace the %s',
												'estasoft-erp-crm-sync-engine'
											),
											word
										) }
							</Btn>
						) : undefined
					}
				>
					{ expiresIn !== null && expiresIn < 0
						? sprintf(
								/* translators: 1: "key", "secret", "password" or "sign-in", 2: date */ __(
									'The %1$s ran out on %2$s. Make a new one and replace it here.',
									'estasoft-erp-crm-sync-engine'
								),
								word,
								formatDay(
									connection.credential_expires_at,
									cfg
								)
							)
						: sprintf(
								/* translators: 1: "key", "secret", "password" or "sign-in", 2: date */ __(
									'The %1$s runs out on %2$s. Make a new one and replace it here before then; your mappings stay.',
									'estasoft-erp-crm-sync-engine'
								),
								word,
								formatDay(
									connection.credential_expires_at,
									cfg
								)
							) }
				</Callout>
			) : null }

			<div className="estasoft-sync-split estasoft-sync-split--even">
				<Card title={ __( 'Health', 'estasoft-erp-crm-sync-engine' ) }>
					<KeyValueList
						items={ [
							{
								label: __(
									'State',
									'estasoft-erp-crm-sync-engine'
								),
								value: (
									<ConnectionStateChip
										connection={ connection }
									/>
								),
							},
							{
								label: __(
									'Response time',
									'estasoft-erp-crm-sync-engine'
								),
								value:
									connection.typical_response_ms === null
										? '—'
										: sprintf(
												/* translators: %s: duration such as 340 ms */ __(
													'%s typical',
													'estasoft-erp-crm-sync-engine'
												),
												formatDuration(
													connection.typical_response_ms
												)
											),
							},
							{
								label: __(
									'Last success',
									'estasoft-erp-crm-sync-engine'
								),
								value: (
									<DateTime
										value={ connection.last_success_at }
										kind="smart"
									/>
								),
							},
							{
								label: __(
									'Last failure',
									'estasoft-erp-crm-sync-engine'
								),
								value: (
									<DateTime
										value={ connection.last_failure_at }
										kind="smart"
									/>
								),
							},
							{
								label: __(
									'Failures in a row',
									'estasoft-erp-crm-sync-engine'
								),
								value: sprintf(
									/* translators: 1: failures so far, 2: failures allowed */ __(
										'%1$d of %2$d allowed',
										'estasoft-erp-crm-sync-engine'
									),
									connection.failures_in_a_row,
									connection.settings.breaker_threshold
								),
							},
						] }
					/>
					<div className="estasoft-sync-actions">
						{ canConfigure && ! isExport ? (
							<Btn
								small
								busy={ test.busy }
								onClick={ () => void test.run() }
							>
								{ __(
									'Test connection',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						{ canManage &&
						! paused &&
						connection.status === 'active' ? (
							<Btn small onClick={ () => setDialog( 'pause' ) }>
								{ __(
									'Pause this connection',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
					</div>
					{ test.error ? (
						<Callout tone="danger" role="alert">
							<p>{ errorText( test.error ) }</p>
							<ErrorDetails error={ test.error } />
						</Callout>
					) : null }
					{ testResult ? (
						<TestResultView
							result={ testResult }
							system={ name }
							shape={ shape ?? 'key' }
						/>
					) : null }
				</Card>

				<Card
					title={ __(
						'Credentials',
						'estasoft-erp-crm-sync-engine'
					) }
					description={
						isExport
							? __(
									'The settings that shape the file. There is no key: nothing is sent anywhere.',
									'estasoft-erp-crm-sync-engine'
								)
							: sprintf(
									/* translators: %s: "key", "secret", "password" or "sign-in" */
									__(
										'The saved %s is never shown to anyone. It can only be replaced.',
										'estasoft-erp-crm-sync-engine'
									),
									shape === 'own_app'
										? __(
												'secret',
												'estasoft-erp-crm-sync-engine'
											)
										: word
								)
					}
				>
					<KeyValueList
						items={ [
							...fields.map( ( field ) => ( {
								label: field.label,
								value:
									field.type === 'display' ? (
										<span className="estasoft-sync-codecell">
											<code
												className="estasoft-sync-mono"
												dir="ltr"
											>
												{ field.value || field.default }
											</code>
											<CopyButton
												text={
													field.value || field.default
												}
												label={ __(
													'Copy',
													'estasoft-erp-crm-sync-engine'
												) }
											/>
										</span>
									) : field.secret ? (
										<span className="estasoft-sync-mono">
											{ field.has_value
												? sprintf(
														/* translators: bullets then the words "saved encrypted" */ '•••••••••••• %s',
														__(
															'saved encrypted',
															'estasoft-erp-crm-sync-engine'
														)
													)
												: __(
														'Not set',
														'estasoft-erp-crm-sync-engine'
													) }
										</span>
									) : (
										<span dir="ltr">
											{ field.value ?? '—' }
										</span>
									),
							} ) ),
							...( isExport
								? []
								: [
										{
											label: __(
												'Encryption key',
												'estasoft-erp-crm-sync-engine'
											),
											value: status?.encryption_key_missing ? (
												<StatusBadge tone="danger">
													{ __(
														'Missing from wp-config.php',
														'estasoft-erp-crm-sync-engine'
													) }
												</StatusBadge>
											) : (
												<StatusBadge tone="success">
													{ __(
														'Found in wp-config.php',
														'estasoft-erp-crm-sync-engine'
													) }
												</StatusBadge>
											),
										},
									] ),
						] }
					/>
					{ canConfigure ? (
						<div className="estasoft-sync-actions">
							{ firstSecret ? (
								<Btn
									small
									onClick={ () => setDialog( 'replace' ) }
									disabled={ Boolean(
										status?.encryption_key_missing
									) }
								>
									{ sprintf(
										/* translators: %s: name of the secret such as "API key" */ __(
											'Change the %s',
											'estasoft-erp-crm-sync-engine'
										),
										lowerFirst( firstSecret.label )
									) }
								</Btn>
							) : null }
							<Btn small onClick={ () => setDialog( 'edit' ) }>
								{ shape === 'key'
									? __(
											'Edit address and user',
											'estasoft-erp-crm-sync-engine'
										)
									: __(
											'Edit settings',
											'estasoft-erp-crm-sync-engine'
										) }
							</Btn>
							{ shape === 'own_app' ? (
								<Btn small onClick={ reconnect }>
									{ __(
										'Sign in again',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							) : null }
						</div>
					) : null }
				</Card>
			</div>

			{ isExport ? (
				<Card title={ __( 'Exports', 'estasoft-erp-crm-sync-engine' ) }>
					<p>
						{ __(
							'This connection uses files. The plugin writes a file, you download it and import it in your program. Nothing is sent over the internet.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							onClick={ () =>
								navigate( `/connections/${ id }/exports` )
							}
						>
							{ __(
								'Open the Exports screen',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</div>
				</Card>
			) : (
				<Reliability
					connection={ connection }
					disabled={ ! canConfigure }
					onSaved={ () => void detail.reload() }
				/>
			) }

			{ connection.warehouses.length > 0 ? (
				<Card
					title={ __(
						'Warehouses found',
						'estasoft-erp-crm-sync-engine'
					) }
					flush
				>
					<ResponsiveTable<
						Connection[ 'warehouses' ][ number ],
						string
					>
						caption={ __(
							'Warehouses found',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ connection.warehouses }
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
										dir="auto"
										className="estasoft-sync-strong"
									>
										{ warehouse.name }
									</span>
								),
							},
							{
								id: 'used',
								header: __(
									'Used for',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( warehouse ) =>
									warehouse.countries.length > 0
										? warehouse.countries.join( ', ' )
										: '—',
							},
							{
								id: 'counts',
								header: __(
									'Counts toward shop stock',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( warehouse ) =>
									warehouse.counts_toward_stock ? (
										<StatusBadge tone="success">
											{ __(
												'Yes',
												'estasoft-erp-crm-sync-engine'
											) }
										</StatusBadge>
									) : (
										<StatusBadge tone="neutral">
											{ __(
												'No',
												'estasoft-erp-crm-sync-engine'
											) }
										</StatusBadge>
									),
							},
						] }
					/>
				</Card>
			) : null }

			{ canConfigure ? (
				<Card
					title={ __( 'Disconnect', 'estasoft-erp-crm-sync-engine' ) }
				>
					<div className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ sprintf(
									/* translators: %s: system name */ __(
										'Disconnect %s',
										'estasoft-erp-crm-sync-engine'
									),
									name
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ sprintf(
									/* translators: 1: number of orders waiting, 2: number of failed orders, 3: what is removed, such as "the saved API key" */
									__(
										'Stops syncing and removes %3$s. Waiting orders (%1$d) and failed orders (%2$d) are kept for 30 days but are not sent. Nothing in your system or WooCommerce is deleted.',
										'estasoft-erp-crm-sync-engine'
									),
									status?.waiting ?? 0,
									status?.by_state.failed ?? 0,
									removedText
								) }
							</p>
						</div>
						<Btn
							danger
							variant="primary"
							onClick={ () => setDialog( 'disconnect' ) }
						>
							{ __(
								'Disconnect',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</div>
				</Card>
			) : null }

			{ dialog === 'pause' ? (
				<PauseDialog
					scope="connection"
					connectionId={ id }
					onClose={ () => setDialog( null ) }
					onDone={ () =>
						void Promise.all( [ detail.reload(), reloadStatus() ] )
					}
				/>
			) : null }
			{ dialog === 'replace' ? (
				<ReplaceSecretDialog
					connection={ connection }
					system={ entry?.name ?? name }
					shape={ shape ?? 'key' }
					onClose={ () => setDialog( null ) }
					onDone={ () => {
						void detail.reload();
						void reloadStatus();
					} }
				/>
			) : null }
			{ dialog === 'reconnect' ? (
				<ReconnectDialog
					connection={ connection }
					system={ entry?.name ?? name }
					onClose={ () => setDialog( null ) }
				/>
			) : null }
			{ dialog === 'edit' ? (
				<EditSettingsDialog
					connection={ connection }
					system={ entry?.name ?? name }
					title={
						shape === 'key'
							? sprintf(
									/* translators: %s: system name */ __(
										'Edit the %s address and user',
										'estasoft-erp-crm-sync-engine'
									),
									name
								)
							: sprintf(
									/* translators: %s: system name */ __(
										'Edit the %s settings',
										'estasoft-erp-crm-sync-engine'
									),
									name
								)
					}
					onClose={ () => setDialog( null ) }
					onDone={ () => void detail.reload() }
				/>
			) : null }
			{ dialog === 'disconnect' ? (
				<ConfirmDialog
					title={ sprintf(
						/* translators: %s: system name */ __(
							'Disconnect %s?',
							'estasoft-erp-crm-sync-engine'
						),
						name
					) }
					tone="danger"
					confirmLabel={ __(
						'Disconnect',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						await api.connections.remove( id );
						notify( {
							kind: 'success',
							text: sprintf(
								/* translators: %s: system name */ __(
									'%s is disconnected. The saved key was removed.',
									'estasoft-erp-crm-sync-engine'
								),
								name
							),
						} );
						await reloadStatus();
						navigate( '/connections' );
					} }
				>
					<p>
						{ sprintf(
							/* translators: %s: what is removed, such as "the saved API key" */
							__(
								'Syncing stops and %s is removed. Waiting and failed orders are kept for 30 days but are not sent.',
								'estasoft-erp-crm-sync-engine'
							),
							removedText
						) }
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'Nothing in your system or in WooCommerce is deleted. You can connect again later; your mappings stay.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
		</>
	);
}

/**
 * Shows what a connection test found: the checks, the access that is missing, or the reason it failed.
 *
 * @param props        Result, system name and the way the system connects.
 * @param props.result
 * @param props.system
 * @param props.shape
 * @return The message.
 */
export function TestResultView( {
	result,
	system,
	shape = 'key',
}: {
	result: ConnectionTestResult;
	system: string;
	shape?: ConnectShape;
} ) {
	const passed = result.ok && result.missing_permissions.length === 0;
	return (
		<>
			<ConnectOutcome
				shape={ shape }
				system={ system }
				result={ result }
				failure={ null }
				nothingSaved={ false }
			/>
			{ passed ? (
				<Callout tone="success" role="status">
					{ sprintf(
						/* translators: 1: system version, 2: company, 3: number of warehouses */
						_n(
							'Connected. %1$s, company %2$s, %3$d warehouse found.',
							'Connected. %1$s, company %2$s, %3$d warehouses found.',
							result.warehouses.length,
							'estasoft-erp-crm-sync-engine'
						),
						result.version,
						result.company,
						result.warehouses.length
					) }
				</Callout>
			) : null }
		</>
	);
}

function Reliability( {
	connection,
	disabled,
	onSaved,
}: {
	connection: Connection;
	disabled: boolean;
	onSaved: () => void;
} ) {
	const { notify } = useNotices();
	const original = toDraft( connection.settings );
	const [ draft, setDraft ] = useState< Draft >( original );
	const [ errors, setErrors ] = useState< Record< string, string | null > >(
		{}
	);
	const originalKey = JSON.stringify( original );
	useEffect(
		() => setDraft( JSON.parse( originalKey ) as Draft ),
		[ originalKey ]
	);
	const changes = ( Object.keys( draft ) as ( keyof Draft )[] ).filter(
		( key ) => draft[ key ] !== original[ key ]
	).length;

	const save = useAsyncAction( async () => {
		const next: Record< string, string | null > = {
			breaker: validateWholeNumber( draft.breaker, ...RANGES.breaker ),
			probe: validateWholeNumber( draft.probe, ...RANGES.probe ),
			rps: validateWholeNumber( draft.rps, ...RANGES.rps ),
			concurrency: validateWholeNumber(
				draft.concurrency,
				...RANGES.concurrency
			),
		};
		setErrors( next );
		if ( Object.values( next ).some( Boolean ) ) {
			return;
		}
		const delays =
			RETRY_OPTIONS.find( ( option ) => option.key === draft.retry )
				?.delays ?? connection.settings.retry_delays_seconds;
		await api.connections.update( connection.id, {
			settings: {
				retry_delays_seconds: delays,
				jitter: draft.jitter,
				breaker_threshold: Number( draft.breaker ),
				probe_interval_minutes: Number( draft.probe ),
				requests_per_second: Number( draft.rps ),
				concurrency: Number( draft.concurrency ),
				obey_retry_after: draft.obey,
			},
		} );
		notify( {
			kind: 'success',
			text: __( 'Limits saved.', 'estasoft-erp-crm-sync-engine' ),
		} );
		onSaved();
	} );
	const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) =>
		setDraft( { ...draft, [ key ]: value } );
	const errorCount = Object.values( errors ).filter( Boolean ).length;
	const system = systemName( connection );

	return (
		<Card
			title={ __(
				'Keeping orders safe',
				'estasoft-erp-crm-sync-engine'
			) }
		>
			<fieldset className="estasoft-sync-fieldset" disabled={ disabled }>
				<legend className="estasoft-sync-sr-only">
					{ __(
						'Limits for this connection',
						'estasoft-erp-crm-sync-engine'
					) }
				</legend>
				<h3 className="estasoft-sync-subtitle">
					{ __( 'Retries', 'estasoft-erp-crm-sync-engine' ) }
				</h3>
				<p className="estasoft-sync-muted">
					{ __(
						'Only for errors that can pass by themselves.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
				<SelectField
					label={ __(
						'If a send fails',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ draft.retry }
					onChange={ ( value ) => set( 'retry', value ) }
					options={ [
						{
							value: 'four',
							label: __(
								'Retry 4 times: after 1 minute, 5 minutes, 1 hour, 24 hours',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'two',
							label: __(
								'Retry 2 times',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'none',
							label: __(
								'Do not retry',
								'estasoft-erp-crm-sync-engine'
							),
						},
						...( draft.retry === 'custom'
							? [
									{
										value: 'custom',
										label: __(
											'Custom (set by a developer)',
											'estasoft-erp-crm-sync-engine'
										),
										disabled: true,
									},
								]
							: [] ),
					] }
				/>
				<CheckField
					label={ __(
						'Add a random delay of up to 20 seconds, so retries do not arrive together',
						'estasoft-erp-crm-sync-engine'
					) }
					checked={ draft.jitter }
					onChange={ ( value ) => set( 'jitter', value ) }
				/>

				<h3 className="estasoft-sync-subtitle">
					{ __( 'Pause on trouble', 'estasoft-erp-crm-sync-engine' ) }
				</h3>
				<p className="estasoft-sync-muted">
					{ sprintf(
						/* translators: %d: number of failures */
						_n(
							'Stops us hammering a system that is down. After %d failure the connection pauses and checks again by itself.',
							'Stops us hammering a system that is down. After %d failures the connection pauses and checks again by itself.',
							Number( draft.breaker ) ||
								connection.settings.breaker_threshold,
							'estasoft-erp-crm-sync-engine'
						),
						Number( draft.breaker ) ||
							connection.settings.breaker_threshold
					) }
				</p>
				<div className="estasoft-sync-inline-fields">
					<TextField
						label={ __(
							'Pause after (failures in a row)',
							'estasoft-erp-crm-sync-engine'
						) }
						type="number"
						min={ RANGES.breaker[ 0 ] }
						max={ RANGES.breaker[ 1 ] }
						value={ draft.breaker }
						onChange={ ( value ) => set( 'breaker', value ) }
						error={ errors.breaker }
					/>
					<TextField
						label={ __(
							'Check again every (minutes)',
							'estasoft-erp-crm-sync-engine'
						) }
						type="number"
						min={ RANGES.probe[ 0 ] }
						max={ RANGES.probe[ 1 ] }
						value={ draft.probe }
						onChange={ ( value ) => set( 'probe', value ) }
						error={ errors.probe }
					/>
				</div>

				<h3 className="estasoft-sync-subtitle">
					{ __( 'Speed limit', 'estasoft-erp-crm-sync-engine' ) }
				</h3>
				<TextField
					label={ __(
						'Send at most (requests per second)',
						'estasoft-erp-crm-sync-engine'
					) }
					type="number"
					min={ RANGES.rps[ 0 ] }
					max={ RANGES.rps[ 1 ] }
					value={ draft.rps }
					onChange={ ( value ) => set( 'rps', value ) }
					error={ errors.rps }
				/>
				<CheckField
					label={ __(
						'Obey the system’s own “slow down” requests',
						'estasoft-erp-crm-sync-engine'
					) }
					hint={ sprintf(
						/* translators: %s: system name */ __(
							'When %s says wait 60 seconds, we wait 60 seconds.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
					checked={ draft.obey }
					onChange={ ( value ) => set( 'obey', value ) }
				/>

				<h3 className="estasoft-sync-subtitle">
					{ __( 'At the same time', 'estasoft-erp-crm-sync-engine' ) }
				</h3>
				<TextField
					label={ __(
						'Process up to (items at once)',
						'estasoft-erp-crm-sync-engine'
					) }
					type="number"
					min={ RANGES.concurrency[ 0 ] }
					max={ RANGES.concurrency[ 1 ] }
					value={ draft.concurrency }
					onChange={ ( value ) => set( 'concurrency', value ) }
					error={ errors.concurrency }
					hint={ __(
						'This is the limit for this connection. The limit for all connections together is under Settings, Performance. The lower one applies.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</fieldset>
			{ ! disabled ? (
				<SaveBar
					changes={ changes }
					saving={ save.busy }
					onSave={ () => void save.run() }
					onDiscard={ () => {
						setDraft( original );
						setErrors( {} );
					} }
					message={
						errorCount > 0
							? sprintf(
									/* translators: %d: number of fields */ _n(
										'Fix %d field to save',
										'Fix %d fields to save',
										errorCount,
										'estasoft-erp-crm-sync-engine'
									),
									errorCount
								)
							: undefined
					}
				/>
			) : null }
			{ save.error ? (
				<Callout tone="danger" role="alert">
					<p>{ errorText( save.error ) }</p>
					<ErrorDetails error={ save.error } />
				</Callout>
			) : null }
		</Card>
	);
}
