import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { ApiError, newIdempotencyKey } from '../../api/client';
import type {
	CatalogEntry,
	Connection,
	ConnectionTestResult,
} from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CopyButton } from '../../components/CopyButton';
import { Busy } from '../../components/EmptyState';
import { ErrorDetails, KeyLineBlock } from '../../components/ErrorDetails';
import { TextField } from '../../components/Fields';
import { StatusBadge } from '../../components/StatusBadge';
import { errorText, catalogMessage } from '../../lib/text';
import { methodExplanation, methodLabel } from '../../lib/catalog';

/**
 * Field names the Odoo connector expects in `config` and `secrets`.
 */
export const ODOO_FIELDS = {
	address: 'address',
	database: 'database',
	user: 'user',
	key: 'api_key',
} as const;

export interface ConnectSystemProps {
	entry: CatalogEntry;
	/** Called once a connection passed its test and was saved. */
	onConnected: ( connection: Connection ) => void;
	/** The connection that already exists for this system, if setup was resumed. */
	existing?: Connection | null;
}

/**
 * Connect screen for one system. Key systems with an installed connector get a form (Odoo);
 * every other way of connecting is explained from the catalog.
 *
 * @param props             Chosen system and handlers.
 * @param props.entry
 * @param props.onConnected
 * @param props.existing
 * @return The connect step.
 */
export function ConnectSystem( {
	entry,
	onConnected,
	existing,
}: ConnectSystemProps ) {
	if ( entry.connector === 'odoo' && entry.connection_method === 'key' ) {
		return (
			<OdooConnect
				entry={ entry }
				onConnected={ onConnected }
				existing={ existing }
			/>
		);
	}
	return <MethodExplainer entry={ entry } />;
}

/**
 * For systems this release cannot connect yet: what connecting involves, and nothing that pretends to work.
 *
 * @param props       Chosen system.
 * @param props.entry
 * @return The explanation.
 */
export function MethodExplainer( { entry }: { entry: CatalogEntry } ) {
	return (
		<div className="estasoft-sync-stack">
			<Callout tone="info">
				{ entry.connector
					? sprintf(
							/* translators: %s: system name */ __(
								'The connector for %s is installed, but this screen has no form for it yet. Open Connections to continue.',
								'estasoft-erp-crm-sync-engine'
							),
							entry.name
						)
					: sprintf(
							/* translators: %s: system name */ __(
								'The %s connector is not part of this release yet, so it cannot be connected today. This page shows what connecting will involve.',
								'estasoft-erp-crm-sync-engine'
							),
							entry.name
						) }
			</Callout>
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'How you connect to %s',
						'estasoft-erp-crm-sync-engine'
					),
					entry.name
				) }
			>
				<p>
					<StatusBadge
						tone={
							entry.connection_method === 'none'
								? 'warning'
								: 'neutral'
						}
						icon="box"
					>
						{ methodLabel( entry.connection_method ) }
					</StatusBadge>
				</p>
				<p>{ methodExplanation( entry.connection_method ) }</p>
				<p className="estasoft-sync-muted">{ entry.needs }</p>
			</Card>
		</div>
	);
}

type Phase = 'form' | 'testing' | 'result';

function OdooConnect( { entry, onConnected, existing }: ConnectSystemProps ) {
	const { canConfigure, boot } = useApp();
	const { status, reload } = useStatus();
	const name = 'Odoo';
	const [ values, setValues ] = useState( {
		address: '',
		database: '',
		user: '',
		key: '',
	} );
	const [ phase, setPhase ] = useState< Phase >( 'form' );
	const [ result, setResult ] = useState< ConnectionTestResult | null >(
		null
	);
	const [ failure, setFailure ] = useState< ApiError | Error | null >( null );
	const [ saved, setSaved ] = useState< Connection | null >(
		existing ?? null
	);
	const abort = useRef< AbortController | null >( null );
	const idempotency = useRef( newIdempotencyKey() );
	const keyMissing = Boolean( status?.encryption_key_missing );

	const missing =
		! values.address.trim() ||
		! values.database.trim() ||
		! values.key.trim();
	const set = ( key: keyof typeof values, value: string ) =>
		setValues( { ...values, [ key ]: value } );

	const run = async () => {
		const controller = new AbortController();
		abort.current = controller;
		setPhase( 'testing' );
		setResult( null );
		setFailure( null );
		const input = {
			connector: 'odoo',
			config: {
				[ ODOO_FIELDS.address ]: values.address.trim(),
				[ ODOO_FIELDS.database ]: values.database.trim(),
				[ ODOO_FIELDS.user ]: values.user.trim(),
			},
			secrets: { [ ODOO_FIELDS.key ]: values.key.trim() },
		};
		try {
			const tested = await api.connections.testInput( input );
			if ( controller.signal.aborted ) {
				return;
			}
			setResult( tested );
			if ( tested.ok && tested.missing_permissions.length === 0 ) {
				const created = await api.connections.create( input );
				if ( controller.signal.aborted ) {
					return;
				}
				setSaved( created );
				setValues( { ...values, key: '' } );
				idempotency.current = newIdempotencyKey();
				await reload();
				onConnected( created );
			}
		} catch ( error ) {
			if ( controller.signal.aborted ) {
				return;
			}
			setFailure(
				error instanceof Error ? error : new Error( String( error ) )
			);
		}
		setPhase( 'result' );
	};
	const cancel = () => {
		abort.current?.abort();
		setPhase( 'form' );
	};

	// Saving tests the connection again, and a failed test comes back inside the error
	// (`data.details.test`); it is shown the same way as a failed test of its own.
	const shownTest =
		failure instanceof ApiError && failure.details.test
			? failure.details.test
			: result;
	const error = shownTest && ! shownTest.ok ? shownTest.error : null;
	const errorCode =
		error?.code ?? ( failure instanceof ApiError ? failure.code : '' );
	const refused = errorCode === 'SYNC-AUTH-001';
	const partial = Boolean(
		shownTest && shownTest.ok && shownTest.missing_permissions.length > 0
	);
	const steps = [
		__(
			'In Odoo, open Settings, then Users, and create a user just for syncing. Give it access to Sales and Inventory.',
			'estasoft-erp-crm-sync-engine'
		),
		__(
			'Open that user’s profile, go to Account Security, and create an API key. Odoo shows it once and asks for an end date, at most 3 months away. We remind you before it ends.',
			'estasoft-erp-crm-sync-engine'
		),
		__(
			'The database name is on Odoo’s sign-in page. Paste the address, database, user and key below.',
			'estasoft-erp-crm-sync-engine'
		),
	];
	const instructions = steps.join( '\n' );

	if ( saved ) {
		return (
			<div className="estasoft-sync-stack">
				<Callout tone="success" role="status">
					<p className="estasoft-sync-strong">
						{ sprintf(
							/* translators: 1: system name and version, 2: company */ __(
								'Connected and saved. %1$s, company %2$s.',
								'estasoft-erp-crm-sync-engine'
							),
							saved.version || name,
							saved.company
						) }
					</p>
					<p>
						{ sprintf(
							/* translators: 1: number of warehouses, 2: warehouse names */
							_n(
								'%1$d warehouse found (%2$s). The key is stored encrypted.',
								'%1$d warehouses found (%2$s). The key is stored encrypted.',
								saved.warehouses.length,
								'estasoft-erp-crm-sync-engine'
							),
							saved.warehouses.length,
							saved.warehouses
								.map( ( warehouse ) => warehouse.name )
								.join( ', ' )
						) }
					</p>
				</Callout>
			</div>
		);
	}

	return (
		<div className="estasoft-sync-stack">
			{ keyMissing ? (
				<Callout tone="danger" role="alert">
					<p>
						{ catalogMessage( 'SYNC-CFG-002' ) }{ ' ' }
						<span className="estasoft-sync-mono">SYNC-CFG-002</span>
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'Saved credentials cannot be stored until you add the line below to wp-config.php. Existing connections keep working.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					{ boot.encryptionKeyLine ? (
						<KeyLineBlock line={ boot.encryptionKeyLine } />
					) : null }
				</Callout>
			) : null }
			<div className="estasoft-sync-split estasoft-sync-split--even">
				<Card
					title={ __(
						'Where do I find these?',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					<p>
						{ __(
							'Odoo Online needs the Custom plan. The free One App plan and the Standard plan do not allow outside programs. Odoo.sh and your own Odoo server have no such limit. If the key is refused with a plan message, this is the reason.',
							'estasoft-erp-crm-sync-engine'
						) }{ ' ' }
						<span className="estasoft-sync-mono">
							SYNC-AUTH-006
						</span>
					</p>
					<ol className="estasoft-sync-ol">
						{ steps.map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ol>
					<p className="estasoft-sync-muted">
						{ __(
							'Menu names can differ between Odoo versions. Not the Odoo administrator? Send these steps to them.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<CopyButton
						text={ instructions }
						label={ __(
							'Copy these steps for your Odoo administrator',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
				</Card>
				<Card
					title={ __(
						'What to expect from Odoo',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					<ul className="estasoft-sync-list">
						<li>
							{ __(
								'Orders become sale orders and invoices, and refunds become credit notes. Tracking numbers need the Odoo Delivery module.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
						<li>
							{ __(
								'Odoo asks outside programs to make about one call a second, with none at the same time. The plugin keeps to that, so a large backlog empties slowly.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
						<li>
							{ __(
								'In Saudi Arabia, the sales journal must be onboarded with ZATCA inside Odoo before invoices are posted. That is done by hand in Odoo, and cannot be undone once Production starts.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
						<li>
							{ __(
								'Odoo 17 and 18 and Odoo 19 and later use different connection methods. The plugin detects which one you have.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
					</ul>
				</Card>
			</div>
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'Connect to %s',
						'estasoft-erp-crm-sync-engine'
					),
					entry.name
				) }
			>
				<form
					className="estasoft-sync-stack"
					onSubmit={ ( event ) => {
						event.preventDefault();
						if (
							! missing &&
							phase !== 'testing' &&
							canConfigure
						) {
							void run();
						}
					} }
				>
					<TextField
						label={ __(
							'Odoo address',
							'estasoft-erp-crm-sync-engine'
						) }
						type="url"
						value={ values.address }
						onChange={ ( value ) => set( 'address', value ) }
						hint={ __(
							'The web address you use to sign in to Odoo.',
							'estasoft-erp-crm-sync-engine'
						) }
						placeholder="https://erp.example.com"
						disabled={ phase === 'testing' }
						autoComplete="off"
						spellCheck={ false }
					/>
					<TextField
						label={ __(
							'Database',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ values.database }
						onChange={ ( value ) => set( 'database', value ) }
						hint={ __(
							'Shown on Odoo’s sign-in page.',
							'estasoft-erp-crm-sync-engine'
						) }
						disabled={ phase === 'testing' }
						autoComplete="off"
						spellCheck={ false }
						error={
							errorCode === 'SYNC-CFG-003'
								? __(
										'Odoo has no database with this name.',
										'estasoft-erp-crm-sync-engine'
									)
								: null
						}
					/>
					<TextField
						label={ __(
							'Sync user (email)',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ values.user }
						onChange={ ( value ) => set( 'user', value ) }
						hint={ __(
							'A dedicated Odoo user for syncing. Odoo 17 and 18 need it with the key. Odoo 19 and later use the key alone, and this field can stay empty.',
							'estasoft-erp-crm-sync-engine'
						) }
						disabled={ phase === 'testing' }
						autoComplete="off"
						spellCheck={ false }
						error={
							refused
								? __(
										'Odoo refused this user or key.',
										'estasoft-erp-crm-sync-engine'
									)
								: null
						}
					/>
					<TextField
						label={ __(
							'API key',
							'estasoft-erp-crm-sync-engine'
						) }
						type="password"
						value={ values.key }
						onChange={ ( value ) => set( 'key', value ) }
						placeholder={ __(
							'Paste the key from the sync user’s Odoo profile',
							'estasoft-erp-crm-sync-engine'
						) }
						hint={ __(
							'Saved encrypted. It is never shown again.',
							'estasoft-erp-crm-sync-engine'
						) }
						disabled={ phase === 'testing' }
						autoComplete="new-password"
					/>
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							type="submit"
							disabled={
								missing ||
								phase === 'testing' ||
								! canConfigure ||
								keyMissing
							}
						>
							{ phase === 'result' &&
							( partial || error || failure )
								? __(
										'Test again',
										'estasoft-erp-crm-sync-engine'
									)
								: __(
										'Test connection',
										'estasoft-erp-crm-sync-engine'
									) }
						</Btn>
					</div>
				</form>
				{ phase === 'testing' ? (
					<div
						className="estasoft-sync-callout estasoft-sync-callout--info"
						role="status"
					>
						<Busy
							label={ __(
								'Testing the connection… This can take up to 30 seconds.',
								'estasoft-erp-crm-sync-engine'
							) }
						/>
						<Btn small onClick={ cancel }>
							{ __( 'Cancel', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					</div>
				) : null }
				{ phase === 'result' && partial && shownTest ? (
					<Callout tone="warning" role="status">
						<p className="estasoft-sync-strong">
							{ __(
								'Connected, but one thing is missing.',
								'estasoft-erp-crm-sync-engine'
							) }
						</p>
						<p>
							{ sprintf(
								/* translators: 1: system and version, 2: list of missing permissions */ __(
									'%1$s answered, but this user cannot do the following: %2$s. In Odoo, give the user the access right it needs, then test again.',
									'estasoft-erp-crm-sync-engine'
								),
								shownTest.version || name,
								shownTest.missing_permissions.join( ', ' )
							) }{ ' ' }
							<span className="estasoft-sync-mono">
								SYNC-AUTH-004
							</span>{ ' ' }
							{ __(
								'Nothing was saved yet.',
								'estasoft-erp-crm-sync-engine'
							) }
						</p>
					</Callout>
				) : null }
				{ phase === 'result' && ( error || failure ) ? (
					<Callout tone="danger" role="alert">
						<p>
							{ error?.message ||
								( failure ? errorText( failure ) : '' ) ||
								catalogMessage(
									errorCode,
									error?.data?.context,
									name
								) }{ ' ' }
							{ errorCode ? (
								<span className="estasoft-sync-mono">
									{ errorCode }
								</span>
							) : null }{ ' ' }
							{ __(
								'Nothing was saved.',
								'estasoft-erp-crm-sync-engine'
							) }
						</p>
						<ErrorDetails error={ failure } />
					</Callout>
				) : null }
				<p className="estasoft-sync-muted">
					{ __(
						'Continue stays off until a test passes.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			</Card>
			<Card
				title={ __(
					'If the test does not pass',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				<table className="estasoft-sync-plain-table estasoft-sync-plain-table--wide">
					<caption className="estasoft-sync-sr-only">
						{ __(
							'Other results of the connection test',
							'estasoft-erp-crm-sync-engine'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'If this happens',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __(
									'You see',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __( 'Code', 'estasoft-erp-crm-sync-engine' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th scope="row">
								{ __(
									'The address is wrong, or Odoo is down',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>
								{ __(
									'Couldn’t reach Odoo, or it didn’t answer within 30 seconds. Check the address.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
							<td className="estasoft-sync-mono">SYNC-NET-001</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'The database name is wrong',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>
								{ __(
									'Odoo has no database with that name. Check the name on Odoo’s sign-in page.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
							<td className="estasoft-sync-mono">SYNC-CFG-003</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'Odoo’s security certificate is not trusted',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>
								{ __(
									'Odoo’s certificate could not be checked. Ask your host to fix it. Do not turn the check off.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
							<td className="estasoft-sync-mono">SYNC-NET-003</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'The user may not do something',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>
								{ __(
									'This user cannot create sale orders. Give it the right access in Odoo.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
							<td className="estasoft-sync-mono">
								SYNC-AUTH-004
							</td>
						</tr>
					</tbody>
				</table>
			</Card>
		</div>
	);
}
