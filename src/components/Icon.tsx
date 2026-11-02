/**
 * Small inline icons (16 px grid, 1.6 px stroke) drawn from the design boards. Decorative by
 * default: the word or label next to an icon carries the meaning.
 */
import type { ReactNode } from 'react';

export type IconName =
	| 'queued'
	| 'syncing'
	| 'synced'
	| 'retrying'
	| 'failed'
	| 'paused'
	| 'warning'
	| 'info'
	| 'check'
	| 'minus'
	| 'eye'
	| 'chevron-down'
	| 'chevron-right'
	| 'chevron-left'
	| 'more'
	| 'external'
	| 'copy'
	| 'search'
	| 'menu'
	| 'close'
	| 'plus'
	| 'refresh'
	| 'download'
	| 'lock'
	| 'trash'
	| 'upload'
	| 'box'
	| 'play'
	| 'sliders';

const PATHS: Record< IconName, ReactNode > = {
	queued: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M8 4.8V8l2.2 1.4" />
		</>
	),
	syncing: (
		<>
			<path d="M13 8a5 5 0 1 1-1.5-3.6" />
			<path d="M13 2.8v2.8h-2.8" />
		</>
	),
	synced: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M5.2 8.2l2 2 3.6-4" />
		</>
	),
	retrying: (
		<>
			<path d="M8 2.3l6.2 11H1.8z" />
			<path d="M8 6.6v3.2M8 11.6v.1" />
		</>
	),
	failed: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M5.8 5.8l4.4 4.4M10.2 5.8l-4.4 4.4" />
		</>
	),
	paused: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M6.5 5.8v4.4M9.5 5.8v4.4" />
		</>
	),
	warning: (
		<>
			<path d="M8 2.3l6.2 11H1.8z" />
			<path d="M8 6.6v3.2M8 11.6v.1" />
		</>
	),
	info: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M8 7.4v3.6M8 5.2v.1" />
		</>
	),
	check: <path d="M3 8.4l3.2 3.2L13 4.6" />,
	minus: (
		<>
			<circle cx="8" cy="8" r="6.5" />
			<path d="M5.4 8h5.2" />
		</>
	),
	eye: (
		<>
			<path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z" />
			<circle cx="8" cy="8" r="2" />
		</>
	),
	'chevron-down': <path d="M4 6l4 4 4-4" />,
	'chevron-right': <path d="M6 4l4 4-4 4" />,
	'chevron-left': <path d="M10 4L6 8l4 4" />,
	more: <path d="M3 8h.1M8 8h.1M13 8h.1" strokeWidth="2.4" />,
	external: (
		<path d="M9 2.5h4.5V7M13.5 2.5L7.5 8.5M11.5 9.5v3a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h3" />
	),
	copy: (
		<>
			<rect x="5.5" y="5.5" width="8" height="8" rx="1.2" />
			<path d="M10.5 5.5v-2a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2" />
		</>
	),
	search: (
		<>
			<circle cx="7" cy="7" r="4.5" />
			<path d="M10.4 10.4L14 14" />
		</>
	),
	menu: <path d="M2.5 4h11M2.5 8h11M2.5 12h11" />,
	close: <path d="M3.5 3.5l9 9M12.5 3.5l-9 9" />,
	plus: <path d="M8 3v10M3 8h10" />,
	refresh: (
		<>
			<path d="M13 8a5 5 0 1 1-1.5-3.6" />
			<path d="M13 2.8v2.8h-2.8" />
		</>
	),
	download: <path d="M8 2.5v8M4.8 7.5L8 10.7l3.2-3.2M2.5 13.5h11" />,
	upload: <path d="M8 10.5v-8M4.8 5.5L8 2.3l3.2 3.2M2.5 13.5h11" />,
	lock: (
		<>
			<rect x="3.2" y="7" width="9.6" height="6.5" rx="1.2" />
			<path d="M5.2 7V5.2a2.8 2.8 0 0 1 5.6 0V7" />
		</>
	),
	trash: (
		<path d="M2.8 4.2h10.4M6.2 4.2V2.8h3.6v1.4M4 4.2l.6 9h6.8l.6-9M6.6 6.8v4.2M9.4 6.8v4.2" />
	),
	box: (
		<path d="M2.5 5.2L8 2.5l5.5 2.7v5.6L8 13.5l-5.5-2.7zM2.5 5.2L8 8l5.5-2.8M8 8v5.5" />
	),
	play: <path d="M5 3.2l7 4.8-7 4.8z" />,
	sliders: (
		<path d="M2.5 4.5h6M11.5 4.5h2M2.5 11.5h2M7.5 11.5h6M9 3v3M6 10v3" />
	),
};

export interface IconProps {
	name: IconName;
	/** Size in pixels. */
	size?: number;
	className?: string;
}

/**
 * @param props           Icon name, size and class.
 * @param props.name
 * @param props.size
 * @param props.className
 * @return An inline SVG hidden from assistive technology.
 */
export function Icon( { name, size = 16, className = '' }: IconProps ) {
	return (
		<svg
			className={ `estasoft-sync-icon ${ className }`.trim() }
			viewBox="0 0 16 16"
			width={ size }
			height={ size }
			aria-hidden="true"
			focusable="false"
		>
			{ PATHS[ name ] }
		</svg>
	);
}
