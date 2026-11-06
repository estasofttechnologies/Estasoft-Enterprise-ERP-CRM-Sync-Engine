import { __ } from '@wordpress/i18n';
import type { ItemState } from '../api/types';
import { stateLabel } from '../lib/text';
import { Icon } from './Icon';
import type { IconName } from './Icon';

export type ChipState = Exclude< ItemState, 'skipped' > | 'paused';

export interface StateChipProps {
	state: ChipState;
	/** Replaces the default word (for example "Paused: Odoo is not answering"). */
	label?: string;
	large?: boolean;
}

/**
 * A sync state: icon plus word, never colour alone.
 *
 * @param props       State and optional label.
 * @param props.state
 * @param props.label
 * @param props.large
 * @return The chip.
 */
export function StateChip( { state, label, large = false }: StateChipProps ) {
	const text =
		label ??
		( state === 'paused'
			? __( 'Paused', 'estasoft-enterprise-erpcrm-sync-engine' )
			: stateLabel( state ) );
	const icon: IconName = state;
	return (
		<span
			className={ `estasoft-sync-chip estasoft-sync-chip--${ state }${ large ? ' estasoft-sync-chip--lg' : '' }` }
		>
			<Icon name={ icon } />
			{ text }
		</span>
	);
}

/**
 * The neutral grey tag for a skipped item. Skipped is a tag, not a seventh state.
 *
 * @param props        Optional reason for the tooltip.
 * @param props.reason
 * @return The tag.
 */
export function SkippedTag( { reason }: { reason?: string | null } ) {
	return (
		<span className="estasoft-sync-tag" title={ reason ?? undefined }>
			{ stateLabel( 'skipped' ) }
		</span>
	);
}

/**
 * Chip for any queue state, using the tag for skipped.
 *
 * @param props            Item state and reason.
 * @param props.state
 * @param props.skipReason
 * @return The chip or the tag.
 */
export function ItemStateChip( {
	state,
	skipReason,
}: {
	state: ItemState;
	skipReason?: string | null;
} ) {
	return state === 'skipped' ? (
		<SkippedTag reason={ skipReason } />
	) : (
		<StateChip state={ state } />
	);
}
