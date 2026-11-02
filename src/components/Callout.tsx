import type { ReactNode } from 'react';
import { Icon } from './Icon';
import type { IconName } from './Icon';

export type CalloutTone = 'info' | 'success' | 'warning' | 'danger';

const ICON: Record< CalloutTone, IconName > = {
	info: 'info',
	success: 'synced',
	warning: 'warning',
	danger: 'failed',
};

export interface CalloutProps {
	tone?: CalloutTone;
	children: ReactNode;
	/** One action: a button or a link. */
	action?: ReactNode;
	/** Live-region role when the message appears after a user action. */
	role?: 'status' | 'alert';
	className?: string;
}

/**
 * A message inside the plugin: icon, text and at most one action.
 *
 * @param props           Tone, text and action.
 * @param props.tone
 * @param props.children
 * @param props.action
 * @param props.role
 * @param props.className
 * @return The message.
 */
export function Callout( {
	tone = 'info',
	children,
	action,
	role,
	className = '',
}: CalloutProps ) {
	return (
		<div
			className={ `estasoft-sync-callout estasoft-sync-callout--${ tone } ${ className }`.trim() }
			role={ role }
		>
			<Icon name={ ICON[ tone ] } size={ 20 } />
			<div className="estasoft-sync-callout__text">{ children }</div>
			{ action ? (
				<div className="estasoft-sync-callout__action">{ action }</div>
			) : null }
		</div>
	);
}
