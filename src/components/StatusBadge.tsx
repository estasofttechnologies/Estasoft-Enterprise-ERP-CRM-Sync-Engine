import type { ReactNode } from 'react';
import { Icon } from './Icon';
import type { IconName } from './Icon';

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const TONE_ICON: Record< Tone, IconName > = {
	success: 'synced',
	warning: 'warning',
	danger: 'failed',
	info: 'eye',
	neutral: 'minus',
};

export interface StatusBadgeProps {
	tone: Tone;
	children: ReactNode;
	icon?: IconName;
}

/**
 * A status word with an icon (Passed, Worth fixing, Needs fixing, ...).
 *
 * @param props          Tone, text and optional icon.
 * @param props.tone
 * @param props.children
 * @param props.icon
 * @return The badge.
 */
export function StatusBadge( { tone, children, icon }: StatusBadgeProps ) {
	return (
		<span
			className={ `estasoft-sync-badge estasoft-sync-badge--${ tone }` }
		>
			<Icon name={ icon ?? TONE_ICON[ tone ] } />
			{ children }
		</span>
	);
}

/**
 * A plain neutral tag (Community, Changed, New).
 *
 * @param props          Text.
 * @param props.children
 * @return The tag.
 */
export function Tag( { children }: { children: ReactNode } ) {
	return <span className="estasoft-sync-tag">{ children }</span>;
}
