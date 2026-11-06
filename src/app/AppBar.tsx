import { __, sprintf } from '@wordpress/i18n';
import { BrandMark } from '../components/BrandMark';
import { Icon } from '../components/Icon';
import type { IconName } from '../components/Icon';
import { SectionsMenu } from '../components/SectionsMenu';
import { systemName } from '../lib/text';
import type { Status } from '../api/types';
import { Link } from './router';
import type { SectionId } from './sections';
import { getSections } from './sections';

type PillTone = 'success' | 'warning' | 'danger' | 'neutral' | 'info';

interface Pill {
	tone: PillTone;
	icon: IconName;
	text: string;
}

/**
 * The words at the end of the app bar: whether the connection is healthy.
 *
 * @param status Status from the server, if loaded.
 * @return Tone, icon and text; null while loading.
 */
export function connectionPill( status: Status | undefined ): Pill | null {
	if ( ! status ) {
		return null;
	}
	const connection = status.connection;
	if ( status.mode === 'shadow' ) {
		return {
			tone: 'info',
			icon: 'eye',
			text: __( 'Shadow mode', 'estasoft-enterprise-erpcrm-sync-engine' ),
		};
	}
	if (
		! connection ||
		status.mode === 'not_connected' ||
		status.mode === 'first_run'
	) {
		return {
			tone: 'neutral',
			icon: 'minus',
			text: __(
				'Not connected',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	const name = systemName( connection );
	switch ( connection.status ) {
		case 'active':
			return {
				tone: 'success',
				icon: 'synced',
				text: sprintf(
					/* translators: %s: system name such as Odoo */
					__(
						'%s connected',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					name
				),
			};
		case 'paused_circuit':
			return {
				tone: 'danger',
				icon: 'failed',
				text: sprintf(
					/* translators: %s: system name such as Odoo */
					__(
						'%s unreachable',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					name
				),
			};
		case 'paused':
			if ( connection.paused_reason === 'circuit' ) {
				return {
					tone: 'danger',
					icon: 'failed',
					text: sprintf(
						/* translators: %s: system name such as Odoo */
						__(
							'%s unreachable',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						name
					),
				};
			}
			return {
				tone: 'warning',
				icon: 'paused',
				text: sprintf(
					/* translators: %s: system name such as Odoo */
					__( '%s paused', 'estasoft-enterprise-erpcrm-sync-engine' ),
					name
				),
			};
		case 'needs_reconnect':
			return {
				tone: 'warning',
				icon: 'warning',
				text: sprintf(
					/* translators: %s: system name such as Odoo */
					__(
						'%s needs reconnect',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					name
				),
			};
		case 'disconnected':
			return {
				tone: 'neutral',
				icon: 'minus',
				text: __(
					'Not connected',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			};
	}
}

export interface AppBarProps {
	current: SectionId | null;
	status: Status | undefined;
	/** The wizard shows "Setup guide" instead of the tabs. */
	setup: boolean;
}

/**
 * Brand mark, the nine tabs (a sections menu at 782 px and below) and the connection state.
 *
 * @param props         Current section and status.
 * @param props.current
 * @param props.status
 * @param props.setup
 * @return The app bar.
 */
export function AppBar( { current, status, setup }: AppBarProps ) {
	const sections = getSections();
	const pill = connectionPill( status );
	const counts: Partial< Record< SectionId, number > > =
		status && status.waiting > 0 ? { queue: status.waiting } : {};
	return (
		<div className="estasoft-sync-appbar">
			<div className="estasoft-sync-appbar__brand">
				<BrandMark />
				<span>ESTASOFT SYNC</span>
				{ setup ? (
					<span className="estasoft-sync-muted estasoft-sync-appbar__guide">
						{ __(
							'Setup guide',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</span>
				) : null }
			</div>
			{ setup ? null : (
				<>
					<nav
						className="estasoft-sync-tabs"
						aria-label={ __(
							'ESTASOFT SYNC sections',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					>
						{ sections.map( ( section ) => (
							<Link
								key={ section.id }
								to={ section.to }
								className="estasoft-sync-tab"
								aria-current={
									section.id === current ? 'page' : undefined
								}
							>
								{ section.label }
							</Link>
						) ) }
					</nav>
					<SectionsMenu
						sections={ sections }
						current={ current }
						counts={ counts }
					/>
				</>
			) }
			{ pill ? (
				<div className="estasoft-sync-appbar__end">
					<span
						className={ `estasoft-sync-pill estasoft-sync-pill--${ pill.tone }` }
					>
						<Icon name={ pill.icon } />
						{ pill.text }
					</span>
				</div>
			) : null }
		</div>
	);
}
