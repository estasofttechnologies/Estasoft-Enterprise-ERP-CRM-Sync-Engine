import { __ } from '@wordpress/i18n';
import { useApp } from '../app/AppContext';
import { navigate } from '../app/router';
import { Btn } from './Btn';
import { EmptyState } from './EmptyState';

/**
 * What a screen shows before any system is connected. The screen asks the server for nothing then:
 * every one of its routes would answer "connect a system first".
 *
 * @return The empty state, with a way to start setup for people who may configure.
 */
export function NotConnected() {
	const { canConfigure } = useApp();
	return (
		<EmptyState
			as="h2"
			title={ __(
				'Connect a system first',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			action={
				canConfigure ? (
					<Btn
						variant="primary"
						onClick={ () => navigate( '/setup' ) }
					>
						{ __(
							'Start setup',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
				) : undefined
			}
		>
			{ __(
				'This page works with a connected system. Until then there is nothing to set here.',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
		</EmptyState>
	);
}
