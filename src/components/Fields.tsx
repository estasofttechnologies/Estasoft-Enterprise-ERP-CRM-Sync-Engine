/**
 * Form fields on top of the WordPress controls: label above, hint below, the error replaces the
 * hint. Wrapping them once keeps the WordPress-specific props (margins, sizes) in one place.
 */
import {
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Icon } from './Icon';

/**
 * An error message for a field: red text with an icon, so colour is never the only signal.
 *
 * @param props          Message and optional id for aria-describedby.
 * @param props.children
 * @param props.id
 * @return The message, or nothing.
 */
export function FieldError( {
	children,
	id,
}: {
	children?: ReactNode;
	id?: string;
} ) {
	if ( ! children ) {
		return null;
	}
	return (
		<span className="estasoft-sync-field-error" id={ id }>
			<Icon name="failed" />
			<span>{ children }</span>
		</span>
	);
}

function helpOf(
	hint: ReactNode,
	error: string | undefined | null
): ReactNode {
	if ( error ) {
		return <FieldError>{ error }</FieldError>;
	}
	return hint;
}

export interface TextFieldProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	hint?: ReactNode;
	error?: string | null;
	type?: 'text' | 'password' | 'email' | 'search' | 'url' | 'tel' | 'number';
	placeholder?: string;
	disabled?: boolean;
	readOnly?: boolean;
	required?: boolean;
	autoComplete?: string;
	hideLabel?: boolean;
	min?: number;
	max?: number;
	step?: number;
	className?: string;
	/** Text direction hint for names that may be Arabic. */
	dir?: 'auto' | 'ltr' | 'rtl';
	spellCheck?: boolean;
	onBlur?: () => void;
}

/**
 * @param props            Field properties.
 * @param props.label
 * @param props.value
 * @param props.onChange
 * @param props.hint
 * @param props.error
 * @param props.hideLabel
 * @param props.className
 * @param props.dir
 * @param props.spellCheck
 * @return A labelled text input.
 */
export function TextField( {
	label,
	value,
	onChange,
	hint,
	error,
	hideLabel,
	className = '',
	dir,
	spellCheck,
	...rest
}: TextFieldProps ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			className={ `estasoft-sync-field estasoft-sync-field--line ${ className }`.trim() }
			label={ label }
			hideLabelFromVision={ hideLabel }
			value={ value }
			onChange={ onChange }
			help={ helpOf( hint, error ) }
			aria-invalid={ error ? true : undefined }
			dir={ dir }
			spellCheck={ spellCheck }
			{ ...rest }
		/>
	);
}

export interface SelectFieldProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	options: { label: string; value: string; disabled?: boolean }[];
	hint?: ReactNode;
	error?: string | null;
	disabled?: boolean;
	hideLabel?: boolean;
	className?: string;
}

/**
 * @param props           Field properties.
 * @param props.label
 * @param props.value
 * @param props.onChange
 * @param props.options
 * @param props.hint
 * @param props.error
 * @param props.disabled
 * @param props.hideLabel
 * @param props.className
 * @return A labelled select.
 */
export function SelectField( {
	label,
	value,
	onChange,
	options,
	hint,
	error,
	disabled,
	hideLabel,
	className = '',
}: SelectFieldProps ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			className={ `estasoft-sync-field estasoft-sync-field--line ${ className }`.trim() }
			label={ label }
			hideLabelFromVision={ hideLabel }
			value={ value }
			options={ options }
			onChange={ ( next: string ) => onChange( next ) }
			help={ helpOf( hint, error ) }
			disabled={ disabled }
			aria-invalid={ error ? true : undefined }
		/>
	);
}

export interface TextAreaFieldProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	hint?: ReactNode;
	error?: string | null;
	rows?: number;
	disabled?: boolean;
	placeholder?: string;
}

/**
 * @param props             Field properties.
 * @param props.label
 * @param props.value
 * @param props.onChange
 * @param props.hint
 * @param props.error
 * @param props.rows
 * @param props.disabled
 * @param props.placeholder
 * @return A labelled multi-line input.
 */
export function TextAreaField( {
	label,
	value,
	onChange,
	hint,
	error,
	rows = 4,
	disabled,
	placeholder,
}: TextAreaFieldProps ) {
	return (
		<TextareaControl
			__nextHasNoMarginBottom
			className="estasoft-sync-field"
			label={ label }
			value={ value }
			onChange={ onChange }
			help={ helpOf( hint, error ) }
			rows={ rows }
			disabled={ disabled }
			placeholder={ placeholder }
		/>
	);
}

export interface CheckFieldProps {
	label: ReactNode;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	hint?: ReactNode;
	disabled?: boolean;
}

/**
 * @param props          Field properties.
 * @param props.label
 * @param props.checked
 * @param props.onChange
 * @param props.hint
 * @param props.disabled
 * @return A checkbox with a bold label and a hint.
 */
export function CheckField( {
	label,
	checked,
	onChange,
	hint,
	disabled,
}: CheckFieldProps ) {
	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			className="estasoft-sync-check"
			label={ label as string }
			help={ hint }
			checked={ checked }
			onChange={ onChange }
			disabled={ disabled }
		/>
	);
}

/**
 * @param props          Field properties.
 * @param props.label
 * @param props.checked
 * @param props.onChange
 * @param props.hint
 * @param props.disabled
 * @return A switch with a label and a hint.
 */
export function SwitchField( {
	label,
	checked,
	onChange,
	hint,
	disabled,
}: CheckFieldProps ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			className="estasoft-sync-switch"
			label={ label as string }
			help={ hint }
			checked={ checked }
			onChange={ onChange }
			disabled={ disabled }
		/>
	);
}

export interface Choice {
	value: string;
	label: string;
	description?: ReactNode;
	disabled?: boolean;
}

export interface ChoiceGroupProps {
	legend: string;
	name?: string;
	value: string;
	choices: Choice[];
	onChange: ( value: string ) => void;
	disabled?: boolean;
	hideLegend?: boolean;
	/** Draw each option as a bordered card. */
	cards?: boolean;
}

/**
 * Native radio buttons, each with a title and a sentence.
 *
 * @param props            Group properties.
 * @param props.legend
 * @param props.name
 * @param props.value
 * @param props.choices
 * @param props.onChange
 * @param props.disabled
 * @param props.hideLegend
 * @param props.cards
 * @return A fieldset of radio inputs.
 */
export function ChoiceGroup( {
	legend,
	name,
	value,
	choices,
	onChange,
	disabled,
	hideLegend,
	cards = true,
}: ChoiceGroupProps ) {
	const generated = useId();
	const group = name ?? `choice-${ generated }`;
	return (
		<fieldset className="estasoft-sync-choices" disabled={ disabled }>
			<legend
				className={
					hideLegend ? 'estasoft-sync-sr-only' : 'estasoft-sync-label'
				}
			>
				{ legend }
			</legend>
			{ choices.map( ( choice ) => (
				<label
					key={ choice.value }
					htmlFor={ `${ group }-${ choice.value }` }
					className={ `estasoft-sync-choice${ cards ? ' estasoft-sync-choice--card' : '' }${ value === choice.value ? ' is-selected' : '' }` }
				>
					<input
						id={ `${ group }-${ choice.value }` }
						type="radio"
						name={ group }
						value={ choice.value }
						checked={ value === choice.value }
						disabled={ choice.disabled }
						onChange={ () => onChange( choice.value ) }
					/>
					<span className="estasoft-sync-choice__text">
						<span className="estasoft-sync-strong">
							{ choice.label }
						</span>
						{ choice.description ? (
							<span className="estasoft-sync-muted">
								{ choice.description }
							</span>
						) : null }
					</span>
				</label>
			) ) }
		</fieldset>
	);
}

/**
 * Validates a whole number against a range. Returns the message of the design ("Enter a number
 * from 1 to 60.") or null when the value is fine.
 *
 * @param value Text typed by the person.
 * @param min   Lowest allowed value.
 * @param max   Highest allowed value.
 * @return An error message or null.
 */
export function validateWholeNumber(
	value: string,
	min: number,
	max: number
): string | null {
	const trimmed = value.trim();
	const number = Number( trimmed );
	if (
		trimmed === '' ||
		! Number.isInteger( number ) ||
		number < min ||
		number > max
	) {
		return sprintf(
			/* translators: 1: lowest value, 2: highest value */
			__(
				'Enter a number from %1$d to %2$d.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			min,
			max
		);
	}
	return null;
}
