import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ApiError } from '../../api/client';
import { api } from '../../api/endpoints';
import type { ConnectorField } from '../../api/local-types';
import type { Connection } from '../../api/types';
import { useNotices } from '../../app/NoticeProvider';
import { ConfirmDialog } from '../../components/Dialog';
import { TextAreaField, TextField } from '../../components/Fields';
import { KeyValueList } from '../../components/KeyValueList';
import type { ConnectShape } from '../../lib/connect';
import { fieldKind, secretWord, splitInput } from '../../lib/connect';
import { leaveTo } from '../../lib/external';
import { catalogMessage } from '../../lib/text';
import { CredentialForm } from '../connect/CredentialForm';

/** A field of a saved connection: its definition, its value (never a secret) and whether a secret is held. */
export type SavedField = ConnectorField & {
	value: string | null;
	has_value: boolean;
};

/**
 * The fields of a saved connection, with the extra keys V0 adds.
 *
 * @param connection A connection.
 * @return Its fields.
 */
export function savedFields( connection: Connection ): SavedField[] {
	return connection.credentials as SavedField[];
}

/**
 * Lower-cases the first letter of a label when it begins a word ("Client secret" becomes
 * "client secret") and leaves an acronym alone ("API key").
 *
 * @param label A field label.
 * @return The label, ready to follow "the".
 */
export function lowerFirst( label: string ): string {
	return /^[A-Z][a-z]/.test( label )
		? label.charAt( 0 ).toLowerCase() + label.slice( 1 )
		: label;
}

/**
 * Starts the sign-in at the vendor for a connection and goes there.
 *
 * @param  connectionId Connection id.
 * @throws {Error} When the address the server gave is not a web address.
 */
export async function startSignIn( connectionId: number ): Promise< void > {
	const { url } = await api.connections.oauthStart( connectionId );
	if ( ! leaveTo( url ) ) {
		throw new Error(
			__(
				'The sign-in page address was not a web address, so it was not opened. Try again. If it keeps happening, the log has the details.',
				'estasoft-erp-crm-sync-engine'
			)
		);
	}
}

/**
 * Replace the key, the secret or the password of a connection, for every shape. A key or a password
 * is tested first and saved only if the test passes (the old one stays until then). The secret of
 * a person's own app cannot be tested before signing in, so it is saved and the sign-in starts again.
 *
 * @param props            The connection and handlers.
 * @param props.connection
 * @param props.system
 * @param props.shape
 * @param props.onClose
 * @param props.onDone
 * @return The dialog.
 */
export function ReplaceSecretDialog( {
	connection,
	system,
	shape,
	onClose,
	onDone,
}: {
	connection: Connection;
	system: string;
	shape: ConnectShape;
	onClose: () => void;
	onDone: () => void;
} ) {
	const { notify } = useNotices();
	const fields = savedFields( connection );
	const secrets = fields.filter( ( field ) => field.secret );
	const [ values, setValues ] = useState< Record< string, string > >( {} );
	const config: Record< string, string > = {};
	fields
		.filter( ( field ) => ! field.secret && field.type !== 'display' )
		.forEach( ( field ) => {
			config[ field.name ] = field.value ?? '';
		} );
	const filled = secrets.every(
		( field ) =>
			! field.required || ( values[ field.name ] ?? '' ).trim() !== ''
	);
	const word = secretWord( secrets );
	const signInAgain = shape === 'own_app';
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: 1: system name, 2: "key", "secret" or "password" */ __(
					'Replace the %1$s %2$s',
					'estasoft-erp-crm-sync-engine'
				),
				system,
				word
			) }
			confirmLabel={
				signInAgain
					? __( 'Save and sign in', 'estasoft-erp-crm-sync-engine' )
					: __( 'Test and save', 'estasoft-erp-crm-sync-engine' )
			}
			confirmDisabled={ ! filled }
			onClose={ onClose }
			onConfirm={ async () => {
				const entered: Record< string, string > = {};
				secrets.forEach( ( field ) => {
					if ( ( values[ field.name ] ?? '' ).trim() ) {
						entered[ field.name ] =
							fieldKind( field ) === 'textarea'
								? values[ field.name ]
								: values[ field.name ].trim();
					}
				} );
				if ( signInAgain ) {
					await api.connections.update( connection.id, {
						secrets: entered,
					} );
					onDone();
					await startSignIn( connection.id );
					return;
				}
				const result = await api.connections.testInput( {
					connector: connection.connector,
					config,
					secrets: entered,
				} );
				if ( ! result.ok ) {
					// Carry the whole test with the error, so the dialog can list what is missing.
					throw new ApiError(
						result.error?.code ?? 'SYNC-AUTH-001',
						result.error?.message ||
							catalogMessage(
								result.error?.code ?? 'SYNC-AUTH-001',
								{},
								system
							),
						result.error?.data?.status ?? 422,
						null,
						{},
						{},
						{ errors: [], test: result, keyLine: null }
					);
				}
				await api.connections.update( connection.id, {
					secrets: entered,
				} );
				notify( {
					kind: 'success',
					text: sprintf(
						/* translators: 1: system name, 2: "key", "secret" or "password" */ __(
							'The new %1$s %2$s is saved, encrypted. Your mappings stay.',
							'estasoft-erp-crm-sync-engine'
						),
						system,
						word
					),
				} );
				onDone();
			} }
		>
			<p>
				{ signInAgain
					? sprintf(
							/* translators: 1: "key", "secret" or "password", 2: system name */ __(
								'Paste the new %1$s from your %2$s app. It is saved, and then you sign in at %2$s again.',
								'estasoft-erp-crm-sync-engine'
							),
							word,
							system
						)
					: sprintf(
							/* translators: 1: "key", "secret" or "password", 2: system name */ __(
								'Paste the new %1$s from %2$s. It is tested first, and saved only if the test passes. The old one stays until then.',
								'estasoft-erp-crm-sync-engine'
							),
							word,
							system
						) }
			</p>
			{ secrets.map( ( field ) =>
				fieldKind( field ) === 'textarea' ? (
					<TextAreaField
						key={ field.name }
						label={ field.label }
						value={ values[ field.name ] ?? '' }
						rows={ 6 }
						onChange={ ( value ) =>
							setValues( { ...values, [ field.name ]: value } )
						}
						hint={
							field.hint ||
							__(
								'Saved encrypted. It is never shown again.',
								'estasoft-erp-crm-sync-engine'
							)
						}
					/>
				) : (
					<TextField
						key={ field.name }
						label={ field.label }
						type="password"
						autoComplete="new-password"
						value={ values[ field.name ] ?? '' }
						onChange={ ( value ) =>
							setValues( { ...values, [ field.name ]: value } )
						}
						hint={
							field.hint ||
							__(
								'Saved encrypted. It is never shown again.',
								'estasoft-erp-crm-sync-engine'
							)
						}
					/>
				)
			) }
		</ConfirmDialog>
	);
}

/**
 * Edit the settings of a connection that are not secret: the address and user, the tenant, or the
 * choices that shape an export file. Saving does not test; the person tests afterwards.
 *
 * @param props            The connection and handlers.
 * @param props.connection
 * @param props.system
 * @param props.title
 * @param props.onClose
 * @param props.onDone
 * @return The dialog.
 */
export function EditSettingsDialog( {
	connection,
	system,
	title,
	onClose,
	onDone,
}: {
	connection: Connection;
	system: string;
	title: string;
	onClose: () => void;
	onDone: () => void;
} ) {
	const { notify } = useNotices();
	const fields = savedFields( connection ).filter(
		( field ) => ! field.secret && field.type !== 'display'
	);
	const [ values, setValues ] = useState< Record< string, string > >( () =>
		Object.fromEntries(
			fields.map( ( field ) => [ field.name, field.value ?? '' ] )
		)
	);
	return (
		<ConfirmDialog
			title={ title }
			confirmLabel={ __( 'Save', 'estasoft-erp-crm-sync-engine' ) }
			onClose={ onClose }
			onConfirm={ async () => {
				const { config } = splitInput( fields, values );
				await api.connections.update( connection.id, { config } );
				notify( {
					kind: 'success',
					text: __(
						'Saved. Test the connection to check the new details.',
						'estasoft-erp-crm-sync-engine'
					),
				} );
				onDone();
			} }
		>
			<CredentialForm
				fields={ fields }
				values={ values }
				onChange={ ( name, value ) =>
					setValues( { ...values, [ name ]: value } )
				}
				system={ system }
			/>
		</ConfirmDialog>
	);
}

/**
 * Reconnect a system that signs in on its own page (board D05): says what will happen, then goes
 * to the vendor's page. Orders that waited are sent after the sign-in.
 *
 * @param props            The connection and handlers.
 * @param props.connection
 * @param props.system
 * @param props.onClose
 * @return The dialog.
 */
export function ReconnectDialog( {
	connection,
	system,
	onClose,
}: {
	connection: Connection;
	system: string;
	onClose: () => void;
} ) {
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: system name */ __(
					'Reconnect %s',
					'estasoft-erp-crm-sync-engine'
				),
				system
			) }
			confirmLabel={ sprintf(
				/* translators: %s: system name */ __(
					'Continue to %s',
					'estasoft-erp-crm-sync-engine'
				),
				system
			) }
			onClose={ onClose }
			onConfirm={ () => startSignIn( connection.id ) }
		>
			<p>
				{ sprintf(
					/* translators: %s: system name */ __(
						'%s opens its own sign-in page. After you approve, you come back here and syncing continues. Orders that waited are sent in the order they arrived.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			</p>
			{ connection.company ? (
				<KeyValueList
					items={ [
						{
							label: __(
								'Organisation',
								'estasoft-erp-crm-sync-engine'
							),
							value: (
								<span dir="auto">{ connection.company }</span>
							),
						},
					] }
				/>
			) : null }
		</ConfirmDialog>
	);
}
