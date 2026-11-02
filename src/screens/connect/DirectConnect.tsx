import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ApiError } from '../../api/client';
import { api } from '../../api/endpoints';
import type { ConnectorField, SystemEntry } from '../../api/local-types';
import type { Connection, ConnectionTestResult } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { Busy } from '../../components/EmptyState';
import { KeyLineBlock } from '../../components/ErrorDetails';
import { useFormat } from '../../components/FormatContext';
import { formatDay } from '../../lib/format';
import type { ConnectShape } from '../../lib/connect';
import {
	defaultValues,
	hasRequired,
	secretWord,
	splitInput,
	validateValues,
} from '../../lib/connect';
import { catalogMessage } from '../../lib/text';
import { ConnectOutcome, errorCodeOf, shownTestOf } from './ConnectOutcome';
import { ConnectGuide, DataDisclosure } from './ConnectParts';
import { CredentialForm } from './CredentialForm';
import { OrganisationStep } from './OrganisationStep';
import {
	expectationsOf,
	fieldsOf,
	guideTexts,
	planNoteOf,
	stepsOf,
} from './copy';

export interface ConnectProps {
	entry: SystemEntry;
	/** Called once a connection passed its test and was saved. */
	onConnected: ( connection: Connection ) => void;
	/** The connection that already exists for this system, if setup was resumed. */
	existing?: Connection | null;
}

/**
 * The messages that belong beside a field: which field a refusal is about.
 *
 * @param code   Error code of the failed test.
 * @param fields The form's fields.
 * @param system Name of the system.
 * @return A message by field name.
 */
export function fieldMessages(
	code: string,
	fields: ConnectorField[],
	system: string
): Record< string, string > {
	const out: Record< string, string > = {};
	if ( code === 'SYNC-AUTH-001' ) {
		const person = fields.find(
			( field ) =>
				! field.secret &&
				/^(user|username|login|email|client_id)$/i.test( field.name )
		);
		const secret = fields.find( ( field ) => field.secret );
		const target = person ?? secret;
		if ( target ) {
			out[ target.name ] =
				person && secret
					? sprintf(
							/* translators: 1: system name, 2: "key", "secret" or "password" */
							__(
								'%1$s refused this user or %2$s.',
								'estasoft-erp-crm-sync-engine'
							),
							system,
							secretWord( fields )
						)
					: sprintf(
							/* translators: 1: system name, 2: "key", "secret" or "password" */
							__(
								'%1$s refused this %2$s.',
								'estasoft-erp-crm-sync-engine'
							),
							system,
							secretWord( fields )
						);
		}
	}
	if ( code === 'SYNC-CFG-003' ) {
		const database = fields.find( ( field ) =>
			/database/i.test( field.name )
		);
		if ( database ) {
			out[ database.name ] = sprintf(
				/* translators: %s: system name */
				__(
					'%s has no database with this name.',
					'estasoft-erp-crm-sync-engine'
				),
				system
			);
		}
	}
	return out;
}

/**
 * The table "If the test does not pass": what each answer means and which code it carries.
 *
 * @param props        Fields and system.
 * @param props.fields
 * @param props.shape
 * @param props.system
 * @return The table.
 */
export function TestFailureTable( {
	fields,
	shape,
	system,
}: {
	fields: ConnectorField[];
	shape: ConnectShape;
	system: string;
} ) {
	const rows: { when: string; see: string; code: string }[] = [
		{
			when: sprintf(
				/* translators: %s: system name */
				__(
					'The address is wrong, or %s is down',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			see: `${ catalogMessage( 'SYNC-NET-001', {}, system ) } ${ __( 'Check the address.', 'estasoft-erp-crm-sync-engine' ) }`,
			code: 'SYNC-NET-001',
		},
	];
	if ( fields.some( ( field ) => /database/i.test( field.name ) ) ) {
		rows.push( {
			when: __(
				'The database name is wrong',
				'estasoft-erp-crm-sync-engine'
			),
			see: sprintf(
				/* translators: %s: system name */
				__(
					'%s has no database with that name. Check the name on its sign-in page.',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			code: 'SYNC-CFG-003',
		} );
	}
	rows.push(
		{
			when: sprintf(
				/* translators: %s: system name */
				__(
					'The security certificate of %s is not trusted',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			see: `${ catalogMessage( 'SYNC-NET-003', {}, system ) } ${ __( 'Ask your host to fix it. Do not turn the check off.', 'estasoft-erp-crm-sync-engine' ) }`,
			code: 'SYNC-NET-003',
		},
		{
			when: __(
				'The plan does not include outside access',
				'estasoft-erp-crm-sync-engine'
			),
			see: catalogMessage( 'SYNC-AUTH-006', {}, system ),
			code: 'SYNC-AUTH-006',
		}
	);
	if ( shape === 'cloud_app' ) {
		rows.push( {
			when: __(
				'The app is not enabled inside the system yet',
				'estasoft-erp-crm-sync-engine'
			),
			see: catalogMessage( 'SYNC-AUTH-007', {}, system ),
			code: 'SYNC-AUTH-007',
		} );
	}
	rows.push( {
		when: __(
			'The user may not do something',
			'estasoft-erp-crm-sync-engine'
		),
		see: sprintf(
			/* translators: %s: system name */
			__(
				'This user cannot do everything the plugin needs. Give it the right access in %s.',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		code: 'SYNC-AUTH-004',
	} );
	return (
		<Card
			title={ __(
				'If the test does not pass',
				'estasoft-erp-crm-sync-engine'
			) }
			flush
		>
			<div
				className="estasoft-sync-table-wrap"
				role="region"
				tabIndex={ 0 }
				aria-label={ __(
					'Other results of the connection test',
					'estasoft-erp-crm-sync-engine'
				) }
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
						{ rows.map( ( row ) => (
							<tr key={ row.code }>
								<th scope="row">{ row.when }</th>
								<td>{ row.see }</td>
								<td className="estasoft-sync-mono">
									{ row.code }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</Card>
	);
}

/**
 * The summary after a connection is saved.
 *
 * @param props            Connection and the words for its secrets.
 * @param props.connection
 * @param props.fields
 * @param props.system
 * @return The success message.
 */
export function ConnectedSummary( {
	connection,
	fields,
	system,
}: {
	connection: Connection;
	fields: ConnectorField[];
	system: string;
} ) {
	const cfg = useFormat();
	const hasSecret = fields.some( ( field ) => field.secret );
	const word = secretWord( fields );
	return (
		<Callout tone="success" role="status">
			<p className="estasoft-sync-strong">
				{ connection.company
					? sprintf(
							/* translators: 1: system name and version, 2: company */ __(
								'Connected and saved. %1$s, company %2$s.',
								'estasoft-erp-crm-sync-engine'
							),
							connection.version || system,
							connection.company
						)
					: sprintf(
							/* translators: %s: system name and version */
							__(
								'Connected and saved. %s.',
								'estasoft-erp-crm-sync-engine'
							),
							connection.version || system
						) }
			</p>
			{ connection.warehouses.length > 0 || hasSecret ? (
				<p>
					{ connection.warehouses.length > 0
						? sprintf(
								/* translators: 1: number of warehouses, 2: warehouse names */
								_n(
									'%1$d warehouse found (%2$s).',
									'%1$d warehouses found (%2$s).',
									connection.warehouses.length,
									'estasoft-erp-crm-sync-engine'
								),
								connection.warehouses.length,
								connection.warehouses
									.map( ( warehouse ) => warehouse.name )
									.join( ', ' )
							)
						: '' }{ ' ' }
					{ hasSecret
						? sprintf(
								/* translators: %s: "key", "secret" or "password" */
								__(
									'The %s is stored encrypted.',
									'estasoft-erp-crm-sync-engine'
								),
								word
							)
						: '' }
				</p>
			) : null }
			{ connection.credential_expires_at ? (
				<p>
					{ sprintf(
						/* translators: 1: "key", "secret" or "password", 2: date */
						__(
							'The %1$s ends on %2$s. We remind you before then.',
							'estasoft-erp-crm-sync-engine'
						),
						word,
						formatDay( connection.credential_expires_at, cfg )
					) }
				</p>
			) : null }
		</Callout>
	);
}

type Phase = 'form' | 'testing' | 'result';

/**
 * Connect screen for a system that tests first and saves after: a pasted key, an app of a cloud
 * directory (an administrator makes it), or a server sign-in. One form from the connector's own
 * fields; Test connection tests without saving, and a passing test saves.
 *
 * @param props             The system and what to do once it is saved.
 * @param props.entry
 * @param props.shape
 * @param props.onConnected
 * @param props.existing
 * @return The screen.
 */
export function DirectConnect( {
	entry,
	shape,
	onConnected,
	existing,
}: ConnectProps & { shape: 'key' | 'cloud_app' | 'server_login' } ) {
	const { canConfigure, boot } = useApp();
	const { status, reload } = useStatus();
	const fields = fieldsOf( entry );
	const system = entry.name;
	const texts = guideTexts( shape, system );
	const steps = stepsOf( entry );
	const [ values, setValues ] = useState( () => defaultValues( fields ) );
	const [ errors, setErrors ] = useState<
		Record< string, string | null | undefined >
	>( {} );
	const [ phase, setPhase ] = useState< Phase >( 'form' );
	const [ result, setResult ] = useState< ConnectionTestResult | null >(
		null
	);
	const [ failure, setFailure ] = useState< ApiError | Error | null >( null );
	const [ saved, setSaved ] = useState< Connection | null >(
		existing ?? null
	);
	// The connection of a resumed setup arrives after the screen is first drawn.
	useEffect( () => {
		if ( existing ) {
			setSaved( existing );
		}
	}, [ existing ] );
	const abort = useRef< AbortController | null >( null );
	const form = useRef< HTMLFormElement >( null );
	const keyMissing = Boolean( status?.encryption_key_missing );

	const set = ( name: string, value: string ) => {
		setValues( { ...values, [ name ]: value } );
		if ( errors[ name ] ) {
			setErrors( { ...errors, [ name ]: null } );
		}
	};

	const run = async () => {
		const found = validateValues( fields, values );
		setErrors( found );
		if ( Object.values( found ).some( Boolean ) ) {
			window.setTimeout(
				() =>
					form.current
						?.querySelector< HTMLElement >(
							'[aria-invalid="true"]'
						)
						?.focus(),
				0
			);
			return;
		}
		const controller = new AbortController();
		abort.current = controller;
		setPhase( 'testing' );
		setResult( null );
		setFailure( null );
		const { config, secrets } = splitInput( fields, values );
		const input = {
			connector: entry.connector ?? '',
			config,
			secrets,
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
				// Secrets are not kept in the form once they are saved.
				setValues(
					Object.fromEntries(
						Object.entries( values ).map( ( [ name, value ] ) => [
							name,
							fields.find( ( field ) => field.name === name )
								?.secret
								? ''
								: value,
						] )
					)
				);
				await reload();
				if ( shape !== 'cloud_app' ) {
					onConnected( created );
				}
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
	const shown = shownTestOf( result, failure );
	const code = errorCodeOf( shown, failure );
	const fromServer = fieldMessages( code, fields, system );
	const mergedErrors = {
		...fromServer,
		...Object.fromEntries(
			Object.entries( errors ).filter( ( [ , message ] ) =>
				Boolean( message )
			)
		),
	};
	const failed =
		phase === 'result' &&
		( Boolean( failure ) || Boolean( shown && ! shown.ok ) );
	const partial = Boolean(
		shown && shown.ok && shown.missing_permissions.length > 0
	);

	if ( saved ) {
		return (
			<div className="estasoft-sync-stack">
				<ConnectedSummary
					connection={ saved }
					fields={ fields }
					system={ system }
				/>
				{ shape === 'cloud_app' && ! existing ? (
					<OrganisationStep
						connection={ saved }
						system={ system }
						label={ __(
							'Company',
							'estasoft-erp-crm-sync-engine'
						) }
						onDone={ ( next ) => {
							setSaved( next );
							onConnected( next );
						} }
					/>
				) : null }
			</div>
		);
	}

	const planNote = planNoteOf( entry );
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
			{ shape === 'server_login' ? (
				<Callout tone="warning">
					<p className="estasoft-sync-strong">
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Your %s server must be reachable from this website.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</p>
					<p>
						{ __(
							'Many of these servers sit inside an office network. Your IT team may need to publish the address and allow connections from the server that hosts this website. Your web host can tell you that server’s outgoing address.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</Callout>
			) : null }
			{ shape === 'cloud_app' ? (
				<Callout tone="info">
					{ __(
						'Parts of this are done by administrators, not by you. If that is not you, copy the steps and send them.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			<div className="estasoft-sync-split estasoft-sync-split--even">
				<ConnectGuide
					title={ texts.title }
					system={ system }
					steps={ steps }
					planNote={ planNote }
					developerUrl={ entry.developer_url }
					copyLabel={ texts.copyLabel }
					outro={
						entry.connector === 'odoo' ? (
							<p className="estasoft-sync-muted">
								{ __(
									'Menu names can differ between Odoo versions. Not the Odoo administrator? Send these steps to them.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						) : null
					}
				/>
				<Card
					title={ sprintf(
						/* translators: %s: system name */
						__(
							'What to expect from %s',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				>
					<ul className="estasoft-sync-list">
						{ expectationsOf( entry ).map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</Card>
			</div>
			<Card title={ texts.formTitle }>
				<form
					ref={ form }
					className="estasoft-sync-stack"
					noValidate
					onSubmit={ ( event ) => {
						event.preventDefault();
						if (
							hasRequired( fields, values ) &&
							phase !== 'testing' &&
							canConfigure &&
							! keyMissing
						) {
							void run();
						}
					} }
				>
					<CredentialForm
						fields={ fields }
						values={ values }
						onChange={ set }
						errors={ mergedErrors }
						disabled={ phase === 'testing' }
						system={ system }
					/>
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							type="submit"
							disabled={
								! hasRequired( fields, values ) ||
								phase === 'testing' ||
								! canConfigure ||
								keyMissing
							}
						>
							{ phase === 'result' && ( partial || failed )
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
				{ phase === 'result' ? (
					<ConnectOutcome
						shape={ shape }
						system={ system }
						result={ result }
						failure={ failure }
					/>
				) : null }
				<p className="estasoft-sync-muted">
					{ __(
						'Continue stays off until a test passes.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			</Card>
			<DataDisclosure
				entry={ { ...entry, credential_fields: fields } }
				shape={ shape }
			/>
			<TestFailureTable
				fields={ fields }
				shape={ shape }
				system={ system }
			/>
		</div>
	);
}
