import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ConnectorField, SystemEntry } from '../../api/local-types';
import type { Connection } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { Busy } from '../../components/EmptyState';
import { ErrorDetails } from '../../components/ErrorDetails';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useKeepFocus } from '../../hooks/useKeepFocus';
import {
	defaultValues,
	hasRequired,
	inputFields,
	isDisplayField,
	redirectAddress,
	splitInput,
	validateValues,
} from '../../lib/connect';
import { catalogMessage, errorText } from '../../lib/text';
import { ConnectedSummary } from './DirectConnect';
import { ConnectGuide, DataDisclosure, useStepFocus } from './ConnectParts';
import type { ConnectProps } from './DirectConnect';
import { CredentialForm, DisplayValue } from './CredentialForm';
import {
	expectationsOf,
	fieldsOf,
	guideTexts,
	planNoteOf,
	stepsOf,
} from './copy';
import { useSignInStart } from './signin';

/**
 * What the plugin asks the vendor for, in words, by the kind of system. The vendor's own consent
 * page lists the exact permissions; this tells the person what to expect.
 *
 * @param type Type of the system.
 * @return The lines.
 */
function asks( type: SystemEntry[ 'type' ] ): string[] {
	if ( type === 'crm' ) {
		return [
			__(
				'Read and create contacts and companies, so each customer matches one contact.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Create deals, or notes, for your orders.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Read your pipelines and stages, so you can map them.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		];
	}
	if ( type === 'erp' ) {
		return [
			__(
				'Read and create customers, and read your products.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Create sales orders, invoices and credit notes for your orders and refunds.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Read stock levels and tax codes, where the system offers them.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		];
	}
	return [
		__(
			'Read and create contacts, so each customer matches one contact.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		__(
			'Create invoices, and payments if you choose that, for your orders.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		__(
			'Create credit notes for refunds.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		__(
			'Read your chart of accounts and tax rates, so you can map them.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	];
}

/**
 * Connect screen for a system where the merchant makes their own app in the vendor's developer area
 * and then signs in once. Step 1 is the app (steps, the redirect address to copy, the app's ID and
 * secret); step 2 saves them and sends the browser to the vendor's sign-in page. The way back is
 * handled where the browser lands (`SignInReturn`).
 *
 * @param props             The system and the connection that exists, if any.
 * @param props.entry
 * @param props.onConnected
 * @param props.existing
 * @return The screen.
 */
export function OwnAppConnect( {
	entry,
	onConnected,
	existing,
}: ConnectProps ) {
	const { canConfigure, boot } = useApp();
	const { status, reload } = useStatus();
	const system = entry.name;
	const allFields = fieldsOf( entry );
	const inputs = inputFields( allFields );
	const display =
		allFields.find( ( field ) => isDisplayField( field ) ) ??
		( {
			name: 'redirect_uri',
			label: __(
				'Redirect address',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			secret: false,
			required: false,
			hint: __(
				'Add this address to the redirect addresses of your app. It must match exactly.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			default: redirectAddress( boot ),
			type: 'display',
		} as ConnectorField );
	const texts = guideTexts( 'own_app', system );
	const saved =
		existing && existing.connector === entry.connector ? existing : null;
	const held = new Set(
		( saved?.credentials ?? [] )
			.filter( ( field ) => field.secret && field.has_value )
			.map( ( field ) => field.name )
	);
	const [ values, setValues ] = useState( () => ( {
		...defaultValues( inputs ),
		...Object.fromEntries(
			( saved?.credentials ?? [] )
				.filter( ( field ) => ! field.secret && field.value )
				.map( ( field ) => [ field.name, field.value ?? '' ] )
		),
	} ) );
	const [ errors, setErrors ] = useState<
		Record< string, string | null | undefined >
	>( {} );
	const [ connection, setConnection ] = useState< Connection | null >(
		saved
	);
	// The connection of a resumed setup arrives after the screen is first drawn.
	useEffect( () => {
		if ( saved ) {
			setConnection( saved );
		}
	}, [ saved ] );
	const form = useRef< HTMLFormElement >( null );
	const signIn = useSignInStart();
	const keyMissing = Boolean( status?.encryption_key_missing );
	const heading = useStepFocus< HTMLHeadingElement >(
		signIn.busy ? 'leaving' : 'form',
		sprintf(
			/* translators: %s: system name */
			__(
				'Opening the %s sign-in page',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			system
		)
	);
	const start = useAsyncAction( async () => {
		const found = validateValues(
			inputs.map( ( field ) =>
				field.secret && held.has( field.name )
					? { ...field, required: false }
					: field
			),
			values
		);
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
		const { config, secrets } = splitInput( inputs, values );
		let target = connection;
		if ( target ) {
			target = await api.connections.update( target.id, {
				config,
				secrets,
			} );
		} else {
			target = await api.connections.create( {
				connector: entry.connector ?? '',
				config,
				secrets,
			} );
		}
		setConnection( target );
		setValues( {
			...values,
			...Object.fromEntries(
				inputs
					.filter( ( field ) => field.secret )
					.map( ( field ) => [ field.name, '' ] )
			),
		} );
		// A connector that can also connect without a sign-in page (a signed token) is tested and active
		// at once; there is nothing to sign in to.
		if ( target.status === 'active' ) {
			await reload();
			onConnected( target );
			return;
		}
		await signIn.run( target.id );
	} );
	const busy = start.busy || signIn.busy;
	const summary = useRef< HTMLDivElement >( null );
	// The form is switched off while the app is saved; the keyboard stays where it was, or goes to the result.
	useKeepFocus( busy, summary );
	const missing = ! hasRequired(
		inputs.map( ( field ) =>
			field.secret && held.has( field.name )
				? { ...field, required: false }
				: field
		),
		values
	);

	if ( connection && connection.status === 'active' ) {
		return (
			<div className="estasoft-sync-stack">
				<div ref={ summary } tabIndex={ -1 }>
					<ConnectedSummary
						connection={ connection }
						fields={ allFields }
						system={ system }
					/>
				</div>
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
							'Saved credentials cannot be stored until you add the key line to wp-config.php. The Connections screen shows it.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</p>
				</Callout>
			) : null }
			{ connection ? (
				<Callout tone="info" role="status">
					{ sprintf(
						/* translators: %s: system name */
						__(
							'Your app details are saved, but you have not signed in at %s yet. Press the sign-in button below.',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						system
					) }
				</Callout>
			) : null }
			<ConnectGuide
				title={ texts.title }
				system={ system }
				steps={ stepsOf( entry ) }
				planNote={ planNoteOf( entry ) }
				intro={
					<p>
						{ sprintf(
							/* translators: 1: system name, 2: system name again */
							__(
								'%1$s does not let one plugin sign in for every store. You create a small app in your own %2$s account, once. It usually takes a few minutes.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system,
							system
						) }
					</p>
				}
				outro={ <DisplayValue field={ display } /> }
				developerUrl={ entry.developer_url }
				copyLabel={ texts.copyLabel }
			/>
			<Card title={ texts.formTitle }>
				<form
					ref={ form }
					className="estasoft-sync-stack"
					noValidate
					onSubmit={ ( event ) => {
						event.preventDefault();
						if (
							! missing &&
							! busy &&
							canConfigure &&
							! keyMissing
						) {
							void start.run();
						}
					} }
				>
					<CredentialForm
						fields={ inputs }
						values={ values }
						onChange={ ( name, value ) => {
							setValues( { ...values, [ name ]: value } );
							if ( errors[ name ] ) {
								setErrors( { ...errors, [ name ]: null } );
							}
						} }
						errors={ errors }
						disabled={ busy }
						system={ system }
					/>
					<h3
						className="estasoft-sync-subtitle"
						ref={ heading }
						tabIndex={ -1 }
					>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Step 2: sign in at %s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
					</h3>
					<p>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'%s opens its own sign-in page. After you approve, you come back here.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
					</p>
					<p>
						{ __(
							'The plugin asks for access to what syncing needs:',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</p>
					<ul className="estasoft-sync-list">
						{ asks( entry.type ).map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
					<p className="estasoft-sync-muted">
						{ __(
							'The plugin never sees your password. The vendor’s page lists exactly what you approve.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</p>
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							type="submit"
							busy={ busy }
							disabled={
								missing || busy || ! canConfigure || keyMissing
							}
						>
							{ sprintf(
								/* translators: %s: system name */
								__(
									'Save and sign in at %s',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						</Btn>
					</div>
				</form>
				{ busy ? (
					<div
						className="estasoft-sync-callout estasoft-sync-callout--info"
						role="status"
					>
						<Busy
							label={ sprintf(
								/* translators: %s: system name */
								__(
									'Saving, then opening the %s sign-in page…',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						/>
					</div>
				) : null }
				{ start.error || signIn.error ? (
					<Callout tone="danger" role="alert">
						<p>{ errorText( start.error ?? signIn.error ) }</p>
						<ErrorDetails error={ start.error ?? signIn.error } />
					</Callout>
				) : null }
				<p className="estasoft-sync-muted">
					{ __(
						'Continue stays off until you have signed in and the test passes.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</p>
			</Card>
			<Card
				title={ sprintf(
					/* translators: %s: system name */
					__(
						'What to expect from %s',
						'estasoft-enterprise-erpcrm-sync-engine'
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
			<DataDisclosure
				entry={ { ...entry, credential_fields: allFields } }
				shape="own_app"
			/>
			<Card
				title={ __(
					'If the sign-in does not finish',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			>
				<ul className="estasoft-sync-list">
					<li>
						{ __(
							'Cancelled: nothing is connected. Press the sign-in button to try again. If the sign-in page did not open, check that your browser is not blocking it. (SYNC-AUTH-003)',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</li>
					<li>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Only partly approved: %s did not give access to everything we need. Sign in again and approve all the items. (SYNC-AUTH-004)',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
					</li>
				</ul>
			</Card>
		</div>
	);
}
