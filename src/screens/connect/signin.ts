import { __ } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { leaveTo } from '../../lib/external';

/**
 * Starts the sign-in at the vendor: asks the server for the address of the vendor's sign-in page
 * and goes there. The vendor sends the browser back to this admin page with `signin=` in the address.
 *
 * @return The action; `run( connectionId )` starts it.
 */
export function useSignInStart() {
	return useAsyncAction( async ( connectionId: number ) => {
		const { url } = await api.connections.oauthStart( connectionId );
		if ( ! leaveTo( url ) ) {
			throw new Error(
				__(
					'The sign-in page address was not a web address, so it was not opened. Try again. If it keeps happening, the log has the details.',
					'estasoft-enterprise-erpcrm-sync-engine'
				)
			);
		}
	} );
}
