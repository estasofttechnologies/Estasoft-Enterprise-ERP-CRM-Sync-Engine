import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type {
	MappingRule,
	MappingSet,
	MappingTransform,
} from '../../api/types';
import { useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Dialog } from '../../components/Dialog';
import { CheckField, SelectField, TextField } from '../../components/Fields';
import {
	getTransformDefs,
	nextRuleId,
	sourcePaths,
	transformDef,
} from '../../lib/rules';

type SourceMode = 'field' | 'fields' | 'const';

interface Step {
	type: string;
	args: Record< string, string >;
}

function toStep( transform: MappingTransform ): Step {
	const args: Record< string, string > = {};
	for ( const [ key, value ] of Object.entries( transform.args ?? {} ) ) {
		if ( key === 'map' && value && typeof value === 'object' ) {
			args.map = Object.entries( value as Record< string, string > )
				.map( ( [ from, to ] ) => `${ from } = ${ to }` )
				.join( '\n' );
		} else {
			args[ key ] = String( value );
		}
	}
	return { type: transform.type, args };
}

function fromStep( step: Step ): MappingTransform {
	const def = transformDef( step.type );
	const args: NonNullable< MappingTransform[ 'args' ] > = {};
	for ( const arg of def?.args ?? [] ) {
		const raw = step.args[ arg.name ] ?? '';
		if ( arg.kind === 'map' ) {
			const map: Record< string, string > = {};
			raw.split( '\n' ).forEach( ( line ) => {
				const at = line.indexOf( '=' );
				if ( at > 0 ) {
					map[ line.slice( 0, at ).trim() ] = line
						.slice( at + 1 )
						.trim();
				}
			} );
			args.map = map;
		} else if ( raw !== '' || arg.default !== undefined ) {
			args[ arg.name ] = arg.name === 'index' ? Number( raw || 0 ) : raw;
		}
	}
	return Object.keys( args ).length > 0
		? { type: step.type, args }
		: { type: step.type };
}

export interface RuleDialogProps {
	/** The rule to edit, or null to add a new field. */
	rule: MappingRule | null;
	set: MappingSet;
	existing: MappingRule[];
	onClose: () => void;
	onSave: ( rule: MappingRule ) => void;
	onRemove?: () => void;
	/** Saves the draft and shows a preview with it. */
	onTest?: ( rule: MappingRule ) => void;
}

/**
 * Add a field or edit a rule: pick the WooCommerce field, the steps from the fixed library
 * and the target field the system offers. No code can be entered.
 *
 * @param props          Rule and handlers.
 * @param props.rule
 * @param props.set
 * @param props.existing
 * @param props.onClose
 * @param props.onSave
 * @param props.onRemove
 * @param props.onTest
 * @return The dialog.
 */
export function RuleDialog( {
	rule,
	set,
	existing,
	onClose,
	onSave,
	onRemove,
	onTest,
}: RuleDialogProps ) {
	const system = useSystemName();
	const defs = getTransformDefs();
	const initialPaths = rule ? sourcePaths( rule ) : [];
	const [ mode, setMode ] = useState< SourceMode >(
		rule &&
			typeof rule.source === 'object' &&
			! Array.isArray( rule.source )
			? 'const'
			: initialPaths.length > 1
				? 'fields'
				: 'field'
	);
	const [ path, setPath ] = useState(
		initialPaths[ 0 ] ?? set.source_fields[ 0 ]?.path ?? ''
	);
	const [ paths, setPaths ] = useState< string[] >(
		initialPaths.length > 1 ? initialPaths : []
	);
	const [ constant, setConstant ] = useState(
		rule &&
			typeof rule.source === 'object' &&
			! Array.isArray( rule.source )
			? String( rule.source.const )
			: ''
	);
	const [ steps, setSteps ] = useState< Step[] >(
		rule ? rule.transforms.map( toStep ) : [ { type: 'trim', args: {} } ]
	);
	const [ target, setTarget ] = useState(
		rule?.target ?? set.target_fields[ 0 ]?.path ?? ''
	);
	const [ required, setRequired ] = useState( rule?.required ?? false );
	const [ confirmRemove, setConfirmRemove ] = useState( false );

	const sourceLabel = ( p: string ) =>
		set.source_fields.find( ( field ) => field.path === p )?.label ?? p;
	const build = (): MappingRule => {
		const source: MappingRule[ 'source' ] =
			mode === 'const'
				? { const: constant }
				: mode === 'fields'
					? paths
					: path;
		return {
			...( rule ?? {
				id: nextRuleId( existing ),
				transforms: [],
				required: false,
				target: '',
				source: '',
			} ),
			source,
			transforms: steps.map( fromStep ),
			target,
			required,
		};
	};
	const valid =
		target !== '' &&
		( mode === 'const'
			? constant !== ''
			: mode === 'fields'
				? paths.length > 0
				: path !== '' );
	const groups = Array.from(
		new Set( set.target_fields.map( ( field ) => field.group ) )
	);

	return (
		<Dialog
			title={
				rule
					? sprintf(
							/* translators: %s: field name */ __(
								'Edit rule: %s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							sourceLabel( initialPaths[ 0 ] ?? rule.target )
						)
					: __(
							'Add a field',
							'estasoft-enterprise-erpcrm-sync-engine'
						)
			}
			onClose={ onClose }
			size="large"
			footer={
				<>
					{ rule && onRemove ? (
						confirmRemove ? (
							<Btn danger variant="primary" onClick={ onRemove }>
								{ __(
									'Yes, remove this field',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</Btn>
						) : (
							<Btn onClick={ () => setConfirmRemove( true ) }>
								{ __(
									'Remove this field',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</Btn>
						)
					) : null }
					<Btn variant="tertiary" onClick={ onClose }>
						{ __(
							'Cancel',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
					{ onTest && valid ? (
						<Btn onClick={ () => onTest( build() ) }>
							{ __(
								'Test on an order',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Btn>
					) : null }
					<Btn
						variant="primary"
						disabled={ ! valid }
						onClick={ () => onSave( build() ) }
					>
						{ rule
							? __(
									'Save rule',
									'estasoft-enterprise-erpcrm-sync-engine'
								)
							: __(
									'Add field',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
					</Btn>
				</>
			}
		>
			<div className="estasoft-sync-stack">
				<SelectField
					label={ __(
						'Where the value comes from',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					value={ mode }
					onChange={ ( value ) => setMode( value as SourceMode ) }
					options={ [
						{
							value: 'field',
							label: __(
								'One WooCommerce field',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
						},
						{
							value: 'fields',
							label: __(
								'Several WooCommerce fields, joined',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
						},
						{
							value: 'const',
							label: __(
								'A fixed value',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
						},
					] }
				/>
				{ mode === 'field' ? (
					<SelectField
						label={ __(
							'WooCommerce field',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						value={ path }
						onChange={ setPath }
						options={ set.source_fields.map( ( field ) => ( {
							value: field.path,
							label: `${ field.label } (${ field.group })`,
						} ) ) }
						hint={ __(
							'Only fields that exist on the order can be chosen.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					/>
				) : null }
				{ mode === 'fields' ? (
					<fieldset className="estasoft-sync-checklist">
						<legend className="estasoft-sync-label">
							{ __(
								'WooCommerce fields, in order',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</legend>
						{ set.source_fields.map( ( field ) => (
							<CheckField
								key={ field.path }
								label={ field.label }
								checked={ paths.includes( field.path ) }
								onChange={ ( checked ) =>
									setPaths(
										checked
											? [ ...paths, field.path ]
											: paths.filter(
													( p ) => p !== field.path
												)
									)
								}
							/>
						) ) }
					</fieldset>
				) : null }
				{ mode === 'const' ? (
					<TextField
						label={ __(
							'Value to send',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						value={ constant }
						onChange={ setConstant }
					/>
				) : null }

				<fieldset className="estasoft-sync-steplist">
					<legend className="estasoft-sync-label">
						{ __(
							'Rule',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					{ steps.map( ( step, index ) => {
						const def = transformDef( step.type );
						return (
							<div className="estasoft-sync-step" key={ index }>
								<SelectField
									label={
										index === 0
											? __(
													'Rule',
													'estasoft-enterprise-erpcrm-sync-engine'
												)
											: sprintf(
													/* translators: %d: step number */ __(
														'Then, step %d',
														'estasoft-enterprise-erpcrm-sync-engine'
													),
													index + 1
												)
									}
									value={ step.type }
									onChange={ ( value ) => {
										const next = [ ...steps ];
										const picked = transformDef( value );
										const args: Record< string, string > =
											{};
										picked?.args.forEach( ( arg ) => {
											if ( arg.default !== undefined ) {
												args[ arg.name ] = arg.default;
											}
										} );
										next[ index ] = { type: value, args };
										setSteps( next );
									} }
									options={ defs.map( ( item ) => ( {
										value: item.type,
										label: item.label,
									} ) ) }
								/>
								{ def?.args.map( ( arg ) => {
									const value = step.args[ arg.name ] ?? '';
									const change = ( next: string ) => {
										const copy = [ ...steps ];
										copy[ index ] = {
											...step,
											args: {
												...step.args,
												[ arg.name ]: next,
											},
										};
										setSteps( copy );
									};
									if (
										arg.kind === 'select' &&
										arg.options
									) {
										return (
											<SelectField
												key={ arg.name }
												label={ arg.label }
												value={
													value || arg.default || ''
												}
												onChange={ change }
												options={ arg.options }
											/>
										);
									}
									if ( arg.kind === 'map' ) {
										return (
											<div
												key={ arg.name }
												className="estasoft-sync-field"
											>
												<label
													className="estasoft-sync-label"
													htmlFor={ `estasoft-sync-map-${ index }` }
												>
													{ arg.label }
												</label>
												<textarea
													id={ `estasoft-sync-map-${ index }` }
													className="estasoft-sync-textarea"
													rows={ 4 }
													value={ value }
													onChange={ ( event ) =>
														change(
															event.target.value
														)
													}
												/>
												{ arg.hint ? (
													<p className="estasoft-sync-muted estasoft-sync-small">
														{ arg.hint }
													</p>
												) : null }
											</div>
										);
									}
									return (
										<TextField
											key={ arg.name }
											label={ arg.label }
											value={ value }
											onChange={ change }
											hint={ arg.hint }
										/>
									);
								} ) }
								{ steps.length > 1 ? (
									<Btn
										small
										variant="tertiary"
										onClick={ () =>
											setSteps(
												steps.filter(
													( _step, i ) => i !== index
												)
											)
										}
									>
										{ sprintf(
											/* translators: %d: step number */ __(
												'Remove step %d',
												'estasoft-enterprise-erpcrm-sync-engine'
											),
											index + 1
										) }
									</Btn>
								) : null }
							</div>
						);
					} ) }
					<Btn
						small
						icon="plus"
						onClick={ () =>
							setSteps( [ ...steps, { type: 'trim', args: {} } ] )
						}
					>
						{ __(
							'Add a step',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
				</fieldset>

				<SelectField
					label={ sprintf(
						/* translators: %s: system name */ __(
							'Target in %s',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						system
					) }
					value={ target }
					onChange={ setTarget }
					hint={ sprintf(
						/* translators: %s: system name */ __(
							'Fields %s offers for this type of data.',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						system
					) }
					options={
						groups.length > 1
							? set.target_fields.map( ( field ) => ( {
									value: field.path,
									label: `${ field.path } (${ field.type }) — ${ field.group }`,
								} ) )
							: set.target_fields.map( ( field ) => ( {
									value: field.path,
									label: `${ field.path } (${ field.type })`,
								} ) )
					}
				/>
				<CheckField
					label={ __(
						'Required: fail the order when this is empty',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					checked={ required }
					onChange={ setRequired }
					hint={ __(
						'The order waits in Needs attention instead of arriving incomplete.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			</div>
		</Dialog>
	);
}
