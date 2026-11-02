import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ConnectionSummary } from '../../api/types';
import { navigate } from '../../app/router';
import { Btn } from '../../components/Btn';
import { Card } from '../../components/Card';
import { useResource } from '../../hooks/useResource';
import { entryForConnector } from '../../lib/connect';
import { ordersPhrase } from '../../lib/text';

function ExportRow( { connection }: { connection: ConnectionSummary } ) {
	const list = useResource(
		( signal ) => api.exports.list( connection.id, signal ),
		[ connection.id ]
	);
	const waiting = list.data?.open_batch?.orders ?? 0;
	return (
		<div className="estasoft-sync-stack">
			<p>
				<span className="estasoft-sync-strong" dir="auto">
					{ connection.label }
				</span>
				<span className="estasoft-sync-muted estasoft-sync-block">
					{ list.data
						? waiting > 0
							? sprintf(
									/* translators: %s: number of orders such as "38 orders" */
									__(
										'%s waiting for the next file.',
										'estasoft-erp-crm-sync-engine'
									),
									ordersPhrase( waiting )
								)
							: __(
									'No orders are waiting for a file.',
									'estasoft-erp-crm-sync-engine'
								)
						: '' }
				</span>
			</p>
			<div className="estasoft-sync-actions">
				<Btn
					small
					onClick={ () =>
						navigate( `/connections/${ connection.id }/exports` )
					}
				>
					{ __( 'Open Exports', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			</div>
		</div>
	);
}

/**
 * A card on the dashboard for each connection that works with files: how many orders wait for the
 * next file, and a way into the Exports screen. Shows nothing when no connection uses files.
 *
 * @return The card, or nothing.
 */
export function ExportsCard() {
	const connections = useResource(
		( signal ) => api.connections.list( signal ),
		[]
	);
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const exporting = ( connections.data ?? [] ).filter(
		( connection ) =>
			entryForConnector( catalog.data, connection.connector )
				?.connection_method === 'export_file' &&
			connection.status !== 'disconnected'
	);
	if ( exporting.length === 0 ) {
		return null;
	}
	return (
		<Card
			id="estasoft-sync-exports-card"
			title={ __( 'Export files', 'estasoft-erp-crm-sync-engine' ) }
		>
			<p className="estasoft-sync-muted">
				{ __(
					'These systems use files. The plugin writes a file, you import it.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			{ exporting.map( ( connection ) => (
				<ExportRow key={ connection.id } connection={ connection } />
			) ) }
		</Card>
	);
}
