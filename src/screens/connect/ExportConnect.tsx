import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { Connection } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { Link } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ErrorDetails } from '../../components/ErrorDetails';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import {
	defaultValues,
	hasRequired,
	splitInput,
	validateValues,
} from '../../lib/connect';
import { errorText } from '../../lib/text';
import { ConnectGuide, DataDisclosure } from './ConnectParts';
import type { ConnectProps } from './DirectConnect';
import { CredentialForm } from './CredentialForm';
import { fieldsOf, guideTexts, stepsOf } from './copy';

/**
 * Connect screen for a system that has no web connection (TallyPrime, DATEV, Sage 50): no
 * credentials, only the choices that shape the file. It says how it works, and that nothing is sent
 * anywhere. Saving makes the connection; the files are made on the Exports screen.
 *
 * @param props             The system and what to do once it is saved.
 * @param props.entry
 * @param props.onConnected
 * @param props.existing
 * @return The screen.
 */
export function ExportConnect( {
	entry,
	onConnected,
	existing,
}: ConnectProps ) {
	const { canConfigure } = useApp();
	const { reload } = useStatus();
	const system = entry.name;
	const fields = fieldsOf( entry ).filter( ( field ) => ! field.secret );
	const texts = guideTexts( 'export_file', system );
	const [ values, setValues ] = useState( () => {
		const start = defaultValues( fields );
		( existing?.credentials ?? [] ).forEach( ( field ) => {
			if ( ! field.secret && field.value !== null ) {
				start[ field.name ] = field.value;
			}
		} );
		return start;
	} );
	const [ errors, setErrors ] = useState<
		Record< string, string | null | undefined >
	>( {} );
	const [ saved, setSaved ] = useState< Connection | null >(
		existing ?? null
	);
	// The connection of a resumed setup arrives after the screen is first drawn.
	useEffect( () => {
		if ( existing ) {
			setSaved( existing );
		}
	}, [ existing ] );
	const form = useRef< HTMLFormElement >( null );
	const save = useAsyncAction( async () => {
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
		const { config } = splitInput( fields, values );
		const connection = saved
			? await api.connections.update( saved.id, { config } )
			: await api.connections.create( {
					connector: entry.connector ?? '',
					config,
					secrets: {},
				} );
		setSaved( connection );
		await reload();
		onConnected( connection );
	} );

	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ sprintf(
					/* translators: %s: system name */
					__(
						'Connect to %s with an export file',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			>
				<p>
					{ sprintf(
						/* translators: 1: system name, 2: system name again */
						__(
							'%1$s cannot be reached over the web by this plugin, so the plugin sends nothing to it. It writes a file of your orders, you download the file here, and you import it in %2$s. Nothing leaves your website by itself.',
							'estasoft-erp-crm-sync-engine'
						),
						system,
						system
					) }
				</p>
				<p>
					{ __(
						'The file is made on your own website and kept in a protected folder. Only people who may manage syncing can download it.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
				<p className="estasoft-sync-muted">
					{ __(
						'The plugin cannot see whether the program accepted a file. An order shows as Exported when it is in a file, not when it is in your program.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			</Card>
			<ConnectGuide
				title={ texts.title }
				system={ system }
				steps={ stepsOf( entry ) }
				planNote={ entry.needs }
				developerUrl={ entry.developer_url }
				copyLabel={ texts.copyLabel }
				outro={
					<p className="estasoft-sync-muted">
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Everything named in a file (ledgers, accounts, tax codes, customers) must already exist in %s, or it refuses the file. The Exports screen lists what each file uses.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</p>
				}
			/>
			<Card title={ texts.formTitle }>
				{ fields.length === 0 ? (
					<p className="estasoft-sync-muted">
						{ __(
							'This system has no settings to choose. The plugin writes its standard file.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) : null }
				<form
					ref={ form }
					className="estasoft-sync-stack"
					noValidate
					onSubmit={ ( event ) => {
						event.preventDefault();
						if ( canConfigure && hasRequired( fields, values ) ) {
							void save.run();
						}
					} }
				>
					<CredentialForm
						fields={ fields }
						values={ values }
						onChange={ ( name, value ) => {
							setValues( { ...values, [ name ]: value } );
							if ( errors[ name ] ) {
								setErrors( { ...errors, [ name ]: null } );
							}
						} }
						errors={ errors }
						disabled={ save.busy }
						system={ system }
					/>
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							type="submit"
							busy={ save.busy }
							disabled={
								! canConfigure ||
								save.busy ||
								! hasRequired( fields, values )
							}
						>
							{ saved
								? __(
										'Save settings',
										'estasoft-erp-crm-sync-engine'
									)
								: __(
										'Save and continue',
										'estasoft-erp-crm-sync-engine'
									) }
						</Btn>
					</div>
				</form>
				{ save.error ? (
					<Callout tone="danger" role="alert">
						<p>{ errorText( save.error ) }</p>
						<ErrorDetails error={ save.error } />
					</Callout>
				) : null }
				{ saved && ! save.error ? (
					<Callout tone="success" role="status">
						<p className="estasoft-sync-strong">
							{ sprintf(
								/* translators: %s: system name */
								__(
									'Saved. Files for %s are made on the Exports screen.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</p>
						<p>
							<Link
								to={ `/connections/${ saved.id }/exports` }
								className="estasoft-sync-link"
							>
								{ __(
									'Open the Exports screen',
									'estasoft-erp-crm-sync-engine'
								) }
							</Link>
						</p>
					</Callout>
				) : null }
			</Card>
			<DataDisclosure
				entry={ { ...entry, credential_fields: fields } }
				shape="export_file"
			/>
		</div>
	);
}
