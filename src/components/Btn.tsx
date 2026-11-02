import { Button } from '@wordpress/components';
import type { MouseEventHandler, ReactNode } from 'react';
import { Icon } from './Icon';
import type { IconName } from './Icon';

export interface BtnProps {
	children?: ReactNode;
	variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
	/** Compact height for rows and cards. */
	small?: boolean;
	/** Red filled button for destructive confirms. */
	danger?: boolean;
	icon?: IconName;
	busy?: boolean;
	disabled?: boolean;
	href?: string;
	target?: string;
	onClick?: MouseEventHandler< HTMLElement >;
	type?: 'button' | 'submit';
	'aria-label'?: string;
	'aria-expanded'?: boolean;
	'aria-haspopup'?: boolean | 'true' | 'dialog' | 'menu';
	'aria-pressed'?: boolean;
	className?: string;
	title?: string;
	/** Hide the text and show only the icon (needs aria-label). */
	iconOnly?: boolean;
}

/**
 * The plugin's button: a WordPress Button with the plugin's sizes (32 px, 24 px compact, 44 px on touch).
 *
 * @param props           Button properties.
 * @param props.children
 * @param props.variant
 * @param props.small
 * @param props.danger
 * @param props.icon
 * @param props.busy
 * @param props.iconOnly
 * @param props.className
 * @return The button.
 */
export function Btn( {
	children,
	variant = 'secondary',
	small,
	danger,
	icon,
	busy,
	iconOnly,
	className = '',
	...rest
}: BtnProps ) {
	const classes = [
		'estasoft-sync-btn',
		small ? 'estasoft-sync-btn--sm' : '',
		danger ? 'estasoft-sync-btn--danger' : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );
	return (
		<Button
			variant={ variant }
			className={ classes }
			isBusy={ busy }
			{ ...rest }
		>
			{ icon ? <Icon name={ icon } /> : null }
			{ iconOnly ? null : children }
		</Button>
	);
}
