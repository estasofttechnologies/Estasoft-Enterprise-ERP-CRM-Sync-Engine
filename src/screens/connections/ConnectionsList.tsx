import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ConnectionSummary } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { Link, navigate } from '../../app/router';
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
					<Btn onClick={ () => navigate( '/connections/other' ) }>
						{ __(
							'Other systems',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				}
			/>
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
}: {
	connection: ConnectionSummary;
	now: string;
	canConfigure: boolean;
} ) {
	const cfg = useFormat();
	const name = systemName( connection );
	const days = daysUntil( connection.credential_expires_at ?? now, now );
	const date = formatDay( connection.credential_expires_at, cfg );
	const expired = days < 0;
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
						{ __(
							'Replace the key',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				) : undefined
			}
		>
			<p>
				{ expired
					? sprintf(
							/* translators: 1: system name, 2: date */ __(
								'The %1$s key ran out on %2$s. Syncing has stopped and orders wait in the queue.',
								'estasoft-erp-crm-sync-engine'
							),
							name,
							date
						)
					: sprintf(
							/* translators: 1: system name, 2: date, 3: days */ _n(
								'The %1$s key runs out on %2$s, in %3$d day.',
								'The %1$s key runs out on %2$s, in %3$d days.',
								days,
								'estasoft-erp-crm-sync-engine'
							),
							name,
							date,
							days
						) }{ ' ' }
				<span className="estasoft-sync-mono">SYNC-AUTH-005</span>
			</p>
			<p className="estasoft-sync-muted">
				{ sprintf(
					/* translators: %s: system name */ __(
						'When it runs out, syncing stops and orders wait in the queue. Make a new key in %s and paste it here; your mappings stay.',
						'estasoft-erp-crm-sync-engine'
					),
					name
				) }
			</p>
		</Callout>
	);
}
