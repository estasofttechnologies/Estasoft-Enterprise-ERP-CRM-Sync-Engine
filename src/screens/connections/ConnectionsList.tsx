import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ConnectionSummary } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { Link, navigate, useRoute } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CopyButton } from '../../components/CopyButton';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { useFormat } from '../../components/FormatContext';
import { PageHeader } from '../../components/PageHeader';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { StateChip } from '../../components/StateChip';
import { StatusBadge } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import type { SystemEntry } from '../../api/local-types';
import { entryForConnector, readSignIn, shapeOfEntry } from '../../lib/connect';
import { formatDay } from '../../lib/format';
import { systemName } from '../../lib/text';
import { typeLabel } from '../../lib/catalog';
import { daysUntil, usedFor } from './capabilities';

/**
 * The list of connections with the key-expiry warning and an entry to add another.
 *
 * @return The screen.
 */
export function ConnectionsList() {
	const { canConfigure, boot } = useApp();
	const { status } = useStatus();
	const list = useResource(
		( signal ) => api.connections.list( signal ),
		[],
		{ keepPrevious: true }
	);
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const route = useRoute();
	// A sign-in that ends here, not on a connection, is one the server could not match to a connection
	// (an unknown, used or expired link): it says failed and nothing was connected.
	const lostSignIn = readSignIn( route.params, route.segments );
	const connections = list.data ?? [];
	const now = status?.now ?? new Date().toISOString();
	const expiring = connections.filter(
		( connection ) =>
			connection.credential_expires_at &&
			daysUntil( connection.credential_expires_at, now ) <= 30
	);
	const active = connections.filter(
		( connection ) => connection.status === 'active'
	).length;
	const description = list.data
		? connections.length === 0
			? __(
					'No system is connected yet.',
					'estasoft-erp-crm-sync-engine'
				)
			: sprintf(
					/* translators: 1: number of active connections, 2: sentence about keys */
					_n(
						'%1$d connection is active%2$s',
						'%1$d connections are active%2$s',
						active,
						'estasoft-erp-crm-sync-engine'
					),
					active,
					expiring.length > 0
						? sprintf(
								/* translators: %d: number of keys */ _n(
									', and %d key needs renewing soon.',
									', and %d keys need renewing soon.',
									expiring.length,
									'estasoft-erp-crm-sync-engine'
								),
								expiring.length
							)
						: '.'
				)
		: undefined;

	return (
		<>
			<PageHeader
				title={ __( 'Connections', 'estasoft-erp-crm-sync-engine' ) }
				description={ description }
				actions={
					<>
						<Btn
							onClick={ () =>
								navigate( '/connections/problems' )
							}
						>
							{ __(
								'Connection problems',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
						<Btn onClick={ () => navigate( '/connections/other' ) }>
							{ __(
								'Other systems',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</>
				}
			/>
			{ lostSignIn.result && lostSignIn.connectionId === null ? (
				<Callout
					tone={
						lostSignIn.result === 'cancelled' ? 'warning' : 'danger'
					}
					role="alert"
					action={
						<Btn
							small
							onClick={ () => navigate( '/connections', true ) }
						>
							{ __( 'Close', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					}
				>
					<p>
						{ lostSignIn.result === 'cancelled'
							? __(
									'Sign-in was cancelled. Nothing was connected.',
									'estasoft-erp-crm-sync-engine'
								)
							: __(
									'The sign-in could not be finished. Nothing was connected. The link may have expired or been used already.',
									'estasoft-erp-crm-sync-engine'
								) }{ ' ' }
						{ lostSignIn.result === 'cancelled' ? (
							<span className="estasoft-sync-mono">
								SYNC-AUTH-003
							</span>
						) : null }
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'Open the connection and press Sign in again, or add the system again. The log has the details.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</Callout>
			) : null }
			{ status?.encryption_key_missing ? (
				<Callout
					tone="danger"
					role="alert"
					action={
						boot.encryptionKeyLine ? (
							<CopyButton
								text={ boot.encryptionKeyLine }
								label={ __(
									'Copy the line',
									'estasoft-erp-crm-sync-engine'
								) }
							/>
						) : undefined
					}
				>
					<p>
						{ __(
							'The encryption key is missing from wp-config.php. Saved credentials cannot be stored safely until you add it.',
							'estasoft-erp-crm-sync-engine'
						) }{ ' ' }
						<span className="estasoft-sync-mono">SYNC-CFG-002</span>
					</p>
					{ boot.encryptionKeyLine ? (
						<pre className="estasoft-sync-code estasoft-sync-code--inline">
							<code>{ boot.encryptionKeyLine }</code>
						</pre>
					) : null }
					<p className="estasoft-sync-muted">
						{ __(
							'Add the line to wp-config.php above the line that says “That’s all, stop editing”, then reload this page. Existing connections keep working; new secrets are refused until then.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</Callout>
			) : null }
			{ expiring.map( ( connection ) => (
				<ExpiryNotice
					key={ connection.id }
					connection={ connection }
					now={ now }
					canConfigure={ canConfigure }
					entry={ entryForConnector(
						catalog.data,
						connection.connector
					) }
				/>
			) ) }
			{ list.loading ? (
				<LoadingState
					rows={ 3 }
					label={ __(
						'Loading connections',
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
			{ list.data && connections.length === 0 ? (
				<EmptyState
					as="h2"
					title={ __(
						'No connections yet',
						'estasoft-erp-crm-sync-engine'
					) }
					action={
						canConfigure ? (
							<Btn
								variant="primary"
								onClick={ () => navigate( '/setup' ) }
							>
								{ __(
									'Start setup',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : undefined
					}
				>
					{ __(
						'Connect your accounting, ERP or CRM system to start syncing.',
						'estasoft-erp-crm-sync-engine'
					) }
				</EmptyState>
			) : null }
			{ connections.length > 0 ? (
				<Card
					title={ __(
						'Your connections',
						'estasoft-erp-crm-sync-engine'
					) }
					flush
				>
					<ResponsiveTable< ConnectionSummary, number >
						caption={ __(
							'Your connections',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ connections }
						rowKey={ ( connection ) => connection.id }
						columns={ [
							{
								id: 'connection',
								header: __(
									'Connection',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( connection ) => (
									<>
										<Link
											to={ `/connections/${ connection.id }` }
											className="estasoft-sync-strong estasoft-sync-link"
											dir="auto"
										>
											{ connection.label }
										</Link>
										<span
											className="estasoft-sync-muted estasoft-sync-block"
											dir="ltr"
										>
											{ [
												connection.version,
												connection.address,
											]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
									</>
								),
							},
							{
								id: 'type',
								header: __(
									'Type',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( connection ) => (
									<StatusBadge tone="neutral" icon="box">
										{ typeLabel( connection.type ) }
									</StatusBadge>
								),
							},
							{
								id: 'state',
								header: __(
									'State',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( connection ) => (
									<ConnectionStateChip
										connection={ connection }
									/>
								),
							},
							{
								id: 'last',
								header: __(
									'Last success',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( connection ) => (
									<DateTime
										value={ connection.last_success_at }
										kind="smart"
									/>
								),
							},
							{
								id: 'used',
								header: __(
									'Used for',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( connection ) =>
									usedFor( connection.capabilities ),
							},
							{
								id: 'open',
								header: __(
									'Open',
									'estasoft-erp-crm-sync-engine'
								),
								hideHeader: true,
								align: 'end',
								cell: ( connection ) => (
									<span className="estasoft-sync-actions">
										{ connection.status ===
											'needs_reconnect' &&
										canConfigure ? (
											<Btn
												small
												variant="primary"
												aria-label={ sprintf(
													/* translators: %s: connection name */ __(
														'Reconnect %s',
														'estasoft-erp-crm-sync-engine'
													),
													connection.label
												) }
												onClick={ () =>
													navigate(
														`/connections/${ connection.id }?reconnect=1`
													)
												}
											>
												{ __(
													'Reconnect',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
										) : null }
										<Btn
											small
											aria-label={ sprintf(
												/* translators: %s: connection name */ __(
													'Open %s',
													'estasoft-erp-crm-sync-engine'
												),
												connection.label
											) }
											onClick={ () =>
												navigate(
													`/connections/${ connection.id }`
												)
											}
										>
											{ __(
												'Open',
												'estasoft-erp-crm-sync-engine'
											) }
										</Btn>
									</span>
								),
							},
						] }
					/>
				</Card>
			) : null }
			{ canConfigure && connections.length > 0 ? (
				<Card
					title={ __(
						'Add another connection',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					<p className="estasoft-sync-strong">
						{ __(
							'One store, more than one system',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'For example Odoo for orders and stock, and HubSpot for contacts. Each connection has its own mapping, limits and pause switch.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<div className="estasoft-sync-actions">
						<StatusBadge tone="neutral" icon="box">
							{ typeLabel( 'erp' ) }
						</StatusBadge>
						<StatusBadge tone="neutral" icon="box">
							{ typeLabel( 'accounting' ) }
						</StatusBadge>
						<StatusBadge tone="neutral" icon="box">
							{ typeLabel( 'crm' ) }
						</StatusBadge>
						<Btn
							variant="primary"
							onClick={ () => navigate( '/connections/add' ) }
						>
							{ __(
								'Add a connection',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</div>
				</Card>
			) : null }
		</>
	);
}

/**
 * Connected, Paused or Needs reconnect as a chip.
 *
 * @param props            Connection summary.
 * @param props.connection
 * @return The chip.
 */
export function ConnectionStateChip( {
	connection,
}: {
	connection: ConnectionSummary;
} ) {
	switch ( connection.status ) {
		case 'active':
			return (
				<StateChip
					state="synced"
					label={ __( 'Connected', 'estasoft-erp-crm-sync-engine' ) }
				/>
			);
		case 'paused':
		case 'paused_circuit':
			return (
				<StateChip
					state="paused"
					label={
						connection.paused_reason === 'circuit' ||
						connection.status === 'paused_circuit'
							? __(
									'Paused: not answering',
									'estasoft-erp-crm-sync-engine'
								)
							: __( 'Paused', 'estasoft-erp-crm-sync-engine' )
					}
				/>
			);
		case 'needs_reconnect':
			return (
				<StateChip
					state="retrying"
					label={ __(
						'Needs reconnect',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			);
		case 'disconnected':
			return (
				<StateChip
					state="queued"
					label={ __(
						'Disconnected',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			);
	}
}

function ExpiryNotice( {
	connection,
	now,
	canConfigure,
	entry,
}: {
	connection: ConnectionSummary;
	now: string;
	canConfigure: boolean;
	entry: SystemEntry | undefined;
} ) {
	const cfg = useFormat();
	const name = systemName( connection );
	const days = daysUntil( connection.credential_expires_at ?? now, now );
	const date = formatDay( connection.credential_expires_at, cfg );
	const expired = days < 0;
	const shape = shapeOfEntry( entry );
	const word =
		shape === 'own_app'
			? __( 'sign-in', 'estasoft-erp-crm-sync-engine' )
			: shape === 'cloud_app'
				? __( 'secret', 'estasoft-erp-crm-sync-engine' )
				: shape === 'server_login'
					? __( 'password', 'estasoft-erp-crm-sync-engine' )
					: __( 'key', 'estasoft-erp-crm-sync-engine' );
	return (
		<Callout
			tone={ expired || days <= 7 ? 'danger' : 'warning' }
			action={
				canConfigure ? (
					<Btn
						small
						onClick={ () =>
							navigate(
								`/connections/${ connection.id }?replace=1`
							)
						}
					>
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
			<p>
				{ expired
					? sprintf(
							/* translators: 1: system name, 2: "key", "secret", "password" or "sign-in", 3: date */ __(
								'The %1$s %2$s ran out on %3$s. Syncing has stopped and orders wait in the queue.',
								'estasoft-erp-crm-sync-engine'
							),
							name,
							word,
							date
						)
					: sprintf(
							/* translators: 1: system name, 2: "key", "secret", "password" or "sign-in", 3: date, 4: days */ _n(
								'The %1$s %2$s runs out on %3$s, in %4$d day.',
								'The %1$s %2$s runs out on %3$s, in %4$d days.',
								days,
								'estasoft-erp-crm-sync-engine'
							),
							name,
							word,
							date,
							days
						) }{ ' ' }
				<span className="estasoft-sync-mono">SYNC-AUTH-005</span>
			</p>
			<p className="estasoft-sync-muted">
				{ shape === 'own_app'
					? __(
							'When it runs out, syncing stops and orders wait in the queue. Sign in again before then; your mappings stay.',
							'estasoft-erp-crm-sync-engine'
						)
					: sprintf(
							/* translators: 1: system name, 2: "key", "secret" or "password" */ __(
								'When it runs out, syncing stops and orders wait in the queue. Make a new %2$s in %1$s and paste it here; your mappings stay.',
								'estasoft-erp-crm-sync-engine'
							),
							name,
							word
						) }
			</p>
		</Callout>
	);
}
