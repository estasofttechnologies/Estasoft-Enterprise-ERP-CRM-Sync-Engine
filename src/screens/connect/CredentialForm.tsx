import { __, sprintf } from '@wordpress/i18n';
import type { ConnectorField } from '../../api/local-types';
import { useApp } from '../../app/AppContext';
import { CopyButton } from '../../components/CopyButton';
import { SelectField, TextAreaField, TextField } from '../../components/Fields';
import { fieldKind, redirectAddress } from '../../lib/connect';

export interface CredentialFormProps {
	fields: ConnectorField[];
	values: Record< string, string >;
	onChange: ( name: string, value: string ) => void;
	/** A message by field name; the field is marked invalid and shows it in place of its hint. */
	errors?: Record< string, string | null | undefined >;
	disabled?: boolean;
	system: string;
	/** Heading above the fields of the `app` group (the person's own app in the vendor's portal). */
	appTitle?: string;
}

/**
 * A read-only value to copy, such as the redirect address of a sign-in app.
 *
 * @param props       The field.
 * @param props.field
 * @return The value with a copy button.
 */
export function DisplayValue( { field }: { field: ConnectorField } ) {
	const { boot } = useApp();
	const value =
		field.default ||
		( /redirect/i.test( field.name ) ? redirectAddress( boot ) : '' );
	if ( ! value ) {
		return null;
	}
	return (
		<div className="estasoft-sync-display">
			<span className="estasoft-sync-label">{ field.label }</span>
			<div className="estasoft-sync-display__row">
				<code className="estasoft-sync-mono" dir="ltr">
					{ value }
				</code>
				<CopyButton
					text={ value }
					label={ sprintf(
						/* translators: %s: name of the value, such as "Redirect address" */
						__(
							'Copy: %s',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						field.label
					) }
					copiedLabel={ __(
						'Copied',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			</div>
			{ field.hint ? (
				<p className="estasoft-sync-muted">{ field.hint }</p>
			) : null }
		</div>
	);
}

function Input( {
	field,
	value,
	onChange,
	error,
	disabled,
	system,
}: {
	field: ConnectorField;
	value: string;
	onChange: ( value: string ) => void;
	error?: string | null;
	disabled?: boolean;
	system: string;
} ) {
	const kind = fieldKind( field );
	const hint =
		field.hint ||
		( field.secret
			? __(
					'Saved encrypted. It is never shown again.',
					'estasoft-enterprise-erpcrm-sync-engine'
				)
			: undefined );
	if ( kind === 'select' ) {
		const options = Object.entries( field.options ?? {} ).map(
			( [ optionValue, label ] ) => ( { value: optionValue, label } )
		);
		if ( ! options.some( ( option ) => option.value === value ) ) {
			options.unshift( {
				value: '',
				label: __(
					'Choose…',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			} );
		}
		return (
			<SelectField
				label={ field.label }
				value={ value }
				onChange={ onChange }
				options={ options }
				hint={ hint }
				error={ error }
				disabled={ disabled }
			/>
		);
	}
	if ( kind === 'textarea' ) {
		return (
			<TextAreaField
				label={ field.label }
				value={ value }
				onChange={ onChange }
				hint={ hint }
				error={ error }
				disabled={ disabled }
				rows={ 6 }
				placeholder={
					field.secret
						? sprintf(
								/* translators: 1: name of the value, 2: system name */
								__(
									'Paste the %1$s from %2$s',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								field.label,
								system
							)
						: undefined
				}
			/>
		);
	}
	return (
		<TextField
			label={ field.label }
			type={
				kind === 'password'
					? 'password'
					: kind === 'url'
						? 'url'
						: kind === 'number'
							? 'number'
							: 'text'
			}
			value={ value }
			onChange={ onChange }
			hint={ hint }
			error={ error }
			disabled={ disabled }
			autoComplete={ field.secret ? 'new-password' : 'off' }
			spellCheck={ false }
			placeholder={
				field.secret
					? sprintf(
							/* translators: 1: name of the value, 2: system name */
							__(
								'Paste the %1$s from %2$s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							field.label,
							system
						)
					: undefined
			}
		/>
	);
}

/**
 * The fields of a connector, drawn by type and sorted by group: the connection fields first, then the
 * ones about the person's own app, and the advanced ones behind a "More settings" disclosure.
 *
 * @param props          The fields, the values and the handlers.
 * @param props.fields
 * @param props.values
 * @param props.onChange
 * @param props.errors
 * @param props.disabled
 * @param props.system
 * @param props.appTitle
 * @return The fields.
 */
export function CredentialForm( {
	fields,
	values,
	onChange,
	errors = {},
	disabled,
	system,
	appTitle,
}: CredentialFormProps ) {
	const draw = ( list: ConnectorField[] ) =>
		list.map( ( field ) =>
			fieldKind( field ) === 'display' ? (
				<DisplayValue key={ field.name } field={ field } />
			) : (
				<Input
					key={ field.name }
					field={ field }
					value={ values[ field.name ] ?? '' }
					onChange={ ( next ) => onChange( field.name, next ) }
					error={ errors[ field.name ] }
					disabled={ disabled }
					system={ system }
				/>
			)
		);
	const connection = fields.filter(
		( field ) => ( field.group ?? 'connection' ) === 'connection'
	);
	const app = fields.filter( ( field ) => field.group === 'app' );
	const advanced = fields.filter( ( field ) => field.group === 'advanced' );
	const advancedHasError = advanced.some( ( field ) => errors[ field.name ] );
	return (
		<>
			{ draw( connection ) }
			{ app.length > 0 ? (
				<>
					{ appTitle ? (
						<h3 className="estasoft-sync-subtitle">{ appTitle }</h3>
					) : null }
					{ draw( app ) }
				</>
			) : null }
			{ advanced.length > 0 ? (
				<details
					className="estasoft-sync-more"
					open={ advancedHasError || undefined }
				>
					<summary>
						{ __(
							'More settings',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</summary>
					<div className="estasoft-sync-stack">
						{ draw( advanced ) }
					</div>
				</details>
			) : null }
		</>
	);
}
