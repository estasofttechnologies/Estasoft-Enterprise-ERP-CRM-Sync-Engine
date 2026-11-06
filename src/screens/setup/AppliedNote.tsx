import { __ } from '@wordpress/i18n';
import { Callout } from '../../components/Callout';

/**
 * Says plainly which choices of step 3 change what the plugin does today. The others are kept with
 * the setup answers and shown again in the final summary; a system's own options (for example which
 * documents it creates) are set on its connection form.
 *
 * @return The note.
 */
export function AppliedNote() {
	return (
		<Callout tone="info">
			{ __(
				'What these choices do today: they decide whether orders and refunds are sent, when an order is sent (when it is paid or placed with cash on delivery, unless this page offers another choice), and whether stock and tracking are switched on when you go live. The rest are saved with your setup and do not change anything yet. A system’s own options, such as which documents it creates, are on its connection form.',
				'estasoft-erp-crm-sync-engine'
			) }
		</Callout>
	);
}
