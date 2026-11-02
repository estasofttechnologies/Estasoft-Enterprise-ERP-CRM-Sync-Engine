/**
 * The fixed transform library as the admin app presents it, and plain-language descriptions of
 * mapping rules. There is no code editing: a rule is a source, a list of library steps and a target.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { MappingRule, MappingTransform } from '../api/types';

export type ArgKind = 'text' | 'select' | 'map';

export interface ArgDef {
	name: string;
	label: string;
	kind: ArgKind;
	options?: { value: string; label: string }[];
	hint?: string;
	/** Used when the step is added. */
	default?: string;
}

export interface TransformDef {
	type: string;
	label: string;
	/** One sentence for the rule table, with `%s` for the main argument when there is one. */
	describe: ( args: NonNullable< MappingTransform[ 'args' ] > ) => string;
	args: ArgDef[];
}

const str = ( value: unknown ): string =>
	typeof value === 'string' || typeof value === 'number'
		? String( value )
		: '';

/** The conditions of the If-then step, in the order the rule dialog shows them. They match the PHP transform. */
const CONDITIONS: { value: string; label: string; phrase: string }[] = [
	{
		value: 'equals',
		label: __( 'equals', 'estasoft-erp-crm-sync-engine' ),
		/* translators: %s: the value compared with */
		phrase: __( 'equals %s', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'not_equals',
		label: __( 'does not equal', 'estasoft-erp-crm-sync-engine' ),
		/* translators: %s: the value compared with */
		phrase: __( 'does not equal %s', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'contains',
		label: __( 'contains', 'estasoft-erp-crm-sync-engine' ),
		/* translators: %s: the value compared with */
		phrase: __( 'contains %s', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'empty',
		label: __( 'is empty', 'estasoft-erp-crm-sync-engine' ),
		phrase: __( 'is empty', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'not_empty',
		label: __( 'is not empty', 'estasoft-erp-crm-sync-engine' ),
		phrase: __( 'is not empty', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'gt',
		label: __( 'is greater than', 'estasoft-erp-crm-sync-engine' ),
		/* translators: %s: the value compared with */
		phrase: __( 'is greater than %s', 'estasoft-erp-crm-sync-engine' ),
	},
	{
		value: 'lt',
		label: __( 'is less than', 'estasoft-erp-crm-sync-engine' ),
		/* translators: %s: the value compared with */
		phrase: __( 'is less than %s', 'estasoft-erp-crm-sync-engine' ),
	},
];

const describeCondition = ( operator: string, value: string ): string => {
	const condition =
		CONDITIONS.find( ( item ) => item.value === operator ) ??
		CONDITIONS[ 0 ];
	return condition.phrase.replace( '%s', value );
};

/**
 * The library, in the order shown in the Rule menu.
 *
 * @return Definitions with translated labels.
 */
export function getTransformDefs(): TransformDef[] {
	return [
		{
			type: 'trim',
			label: __(
				'Keep as typed (trim spaces)',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__( 'Keep as typed', 'estasoft-erp-crm-sync-engine' ),
			args: [],
		},
		{
			type: 'prefix',
			label: __( 'Add a prefix', 'estasoft-erp-crm-sync-engine' ),
			describe: ( args ) =>
				sprintf(
					/* translators: %s: the prefix, for example WC- */ __(
						'Add the prefix %s',
						'estasoft-erp-crm-sync-engine'
					),
					str( args.value )
				),
			args: [
				{
					name: 'value',
					label: __( 'Prefix', 'estasoft-erp-crm-sync-engine' ),
					kind: 'text',
				},
			],
		},
		{
			type: 'suffix',
			label: __( 'Add a suffix', 'estasoft-erp-crm-sync-engine' ),
			describe: ( args ) =>
				sprintf(
					/* translators: %s: the suffix */ __(
						'Add the suffix %s',
						'estasoft-erp-crm-sync-engine'
					),
					str( args.value )
				),
			args: [
				{
					name: 'value',
					label: __( 'Suffix', 'estasoft-erp-crm-sync-engine' ),
					kind: 'text',
				},
			],
		},
		{
			type: 'case',
			label: __(
				'Change the letter case',
				'estasoft-erp-crm-sync-engine'
			),
			describe: ( args ) => {
				const mode = str( args.mode );
				if ( mode === 'upper' ) {
					return __(
						'Capital letters',
						'estasoft-erp-crm-sync-engine'
					);
				}
				if ( mode === 'lower' ) {
					return __(
						'Small letters',
						'estasoft-erp-crm-sync-engine'
					);
				}
				return __(
					'First letter of each word in capitals',
					'estasoft-erp-crm-sync-engine'
				);
			},
			args: [
				{
					name: 'mode',
					label: __( 'Letters', 'estasoft-erp-crm-sync-engine' ),
					kind: 'select',
					default: 'title',
					options: [
						{
							value: 'upper',
							label: __(
								'Capital letters',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'lower',
							label: __(
								'Small letters',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'title',
							label: __(
								'First letter of each word in capitals',
								'estasoft-erp-crm-sync-engine'
							),
						},
					],
				},
			],
		},
		{
			type: 'phone_e164',
			label: __(
				'International phone format',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__(
					'International format, with a default country',
					'estasoft-erp-crm-sync-engine'
				),
			args: [
				{
					name: 'default_country',
					label: __(
						'Default country when none is typed (ISO code, for example AE)',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
					default: '',
				},
			],
		},
		{
			type: 'iso_country',
			label: __(
				'Country name to ISO code',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__(
					'Country name to ISO code',
					'estasoft-erp-crm-sync-engine'
				),
			args: [],
		},
		{
			type: 'iso_state',
			label: __(
				'Match to a state code',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__( 'Match to a state code', 'estasoft-erp-crm-sync-engine' ),
			args: [],
		},
		{
			type: 'money_minor',
			label: __(
				'Amount in the currency’s decimals',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__(
					'Keep the decimals of the currency',
					'estasoft-erp-crm-sync-engine'
				),
			args: [],
		},
		{
			type: 'date_utc',
			label: __( 'Date and time in UTC', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__( 'Date and time in UTC', 'estasoft-erp-crm-sync-engine' ),
			args: [],
		},
		{
			type: 'value_map',
			label: __( 'Look up in a table', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__(
					'Look the value up in a table',
					'estasoft-erp-crm-sync-engine'
				),
			args: [
				{
					name: 'map',
					label: __(
						'Table: one “from = to” pair per line',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'map',
					hint: __(
						'For example: cod = Pay on delivery',
						'estasoft-erp-crm-sync-engine'
					),
				},
				{
					name: 'else',
					label: __(
						'When nothing matches, use',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
				},
			],
		},
		{
			type: 'if_then',
			label: __( 'If then', 'estasoft-erp-crm-sync-engine' ),
			describe: ( args ) =>
				sprintf(
					/* translators: 1: condition such as "equals 5", 2: value used */ __(
						'If it %1$s use %2$s',
						'estasoft-erp-crm-sync-engine'
					),
					describeCondition(
						str( args.operator ),
						str( args.value )
					),
					str( args.then )
				),
			args: [
				{
					name: 'operator',
					label: __( 'If the value', 'estasoft-erp-crm-sync-engine' ),
					kind: 'select',
					default: 'equals',
					options: CONDITIONS.map( ( condition ) => ( {
						value: condition.value,
						label: condition.label,
					} ) ),
				},
				{
					name: 'value',
					label: __(
						'Compared with (not used for “is empty” and “is not empty”)',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
				},
				{
					name: 'then',
					label: __( 'Then use', 'estasoft-erp-crm-sync-engine' ),
					kind: 'text',
				},
				{
					name: 'else',
					label: __(
						'Otherwise use (leave empty to keep the value)',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
				},
			],
		},
		{
			type: 'default',
			label: __(
				'Default value if empty',
				'estasoft-erp-crm-sync-engine'
			),
			describe: ( args ) =>
				sprintf(
					/* translators: %s: the default value */ __(
						'Use %s when empty',
						'estasoft-erp-crm-sync-engine'
					),
					str( args.value )
				),
			args: [
				{
					name: 'value',
					label: __(
						'Value to use when empty',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
				},
			],
		},
		{
			type: 'required',
			label: __( 'Must not be empty', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__( 'Must not be empty', 'estasoft-erp-crm-sync-engine' ),
			args: [],
		},
		{
			type: 'join',
			label: __( 'Join several fields', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__(
					'Join the fields, keep as typed',
					'estasoft-erp-crm-sync-engine'
				),
			args: [
				{
					name: 'separator',
					label: __(
						'Put this between them',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
					default: ' ',
				},
			],
		},
		{
			type: 'split',
			label: __( 'Split a field', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__(
					'Split the value at a separator',
					'estasoft-erp-crm-sync-engine'
				),
			args: [
				{
					name: 'separator',
					label: __( 'Split at', 'estasoft-erp-crm-sync-engine' ),
					kind: 'text',
					default: ' ',
				},
				{
					name: 'index',
					label: __(
						'Keep part number (0 is the first)',
						'estasoft-erp-crm-sync-engine'
					),
					kind: 'text',
					default: '0',
				},
			],
		},
		{
			type: 'tax_lookup',
			label: __( 'Use the tax mapping', 'estasoft-erp-crm-sync-engine' ),
			describe: () =>
				__( 'Use the tax mapping', 'estasoft-erp-crm-sync-engine' ),
			args: [],
		},
		{
			type: 'sku_match',
			label: __(
				'Match the product by SKU',
				'estasoft-erp-crm-sync-engine'
			),
			describe: () =>
				__(
					'Match the product by reference',
					'estasoft-erp-crm-sync-engine'
				),
			args: [],
		},
	];
}

/**
 * Finds the definition of a transform type.
 *
 * @param type Transform type.
 * @return The definition, or undefined for a type this app does not know.
 */
export function transformDef( type: string ): TransformDef | undefined {
	return getTransformDefs().find( ( def ) => def.type === type );
}

/**
 * One line that says what a rule does, for the rule table.
 *
 * @param rule Mapping rule.
 * @return Text such as "Add the prefix WC-".
 */
export function describeRule( rule: MappingRule ): string {
	if (
		typeof rule.source === 'object' &&
		! Array.isArray( rule.source ) &&
		'const' in rule.source
	) {
		return sprintf(
			/* translators: %s: the fixed value */ __(
				'Always send %s',
				'estasoft-erp-crm-sync-engine'
			),
			String( rule.source.const )
		);
	}
	const meaningful = rule.transforms.filter(
		( transform ) =>
			transform.type !== 'trim' || rule.transforms.length === 1
	);
	if ( meaningful.length === 0 ) {
		return __( 'Keep as typed', 'estasoft-erp-crm-sync-engine' );
	}
	return meaningful
		.map( ( transform ) => {
			const def = transformDef( transform.type );
			return def ? def.describe( transform.args ?? {} ) : transform.type;
		} )
		.join( '. ' );
}

/**
 * The source of a rule as a list of paths.
 *
 * @param rule Mapping rule.
 * @return Paths; empty for a fixed value.
 */
export function sourcePaths( rule: MappingRule ): string[] {
	if ( typeof rule.source === 'string' ) {
		return [ rule.source ];
	}
	if ( Array.isArray( rule.source ) ) {
		return rule.source;
	}
	return [];
}

/**
 * Compares two rule lists by id.
 *
 * @param live    Rules of the live version.
 * @param working Rules being edited.
 * @return Ids that were added, changed or removed.
 */
export function diffRules(
	live: MappingRule[],
	working: MappingRule[]
): { added: string[]; changed: string[]; removed: string[] } {
	const strip = ( rule: MappingRule ) =>
		JSON.stringify( { ...rule, changed: undefined, added: undefined } );
	const liveById = new Map( live.map( ( rule ) => [ rule.id, rule ] ) );
	const added: string[] = [];
	const changed: string[] = [];
	for ( const rule of working ) {
		const before = liveById.get( rule.id );
		if ( ! before ) {
			added.push( rule.id );
		} else if ( strip( before ) !== strip( rule ) ) {
			changed.push( rule.id );
		}
	}
	const workingIds = new Set( working.map( ( rule ) => rule.id ) );
	const removed = live
		.filter( ( rule ) => ! workingIds.has( rule.id ) )
		.map( ( rule ) => rule.id );
	return { added, changed, removed };
}

/**
 * A new rule id that is not used yet.
 *
 * @param rules Existing rules.
 * @return Id such as `r14`.
 */
export function nextRuleId( rules: MappingRule[] ): string {
	const used = new Set( rules.map( ( rule ) => rule.id ) );
	let n = rules.length + 1;
	while ( used.has( `r${ n }` ) ) {
		n += 1;
	}
	return `r${ n }`;
}
