import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Callout } from '../../components/Callout';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { MapSkuDialog } from '../mapping/MapSkuDialog';
import { PreviewPanel, useSampleOrders } from '../mapping/PreviewPanel';

/**
 * Step 4, Preview an order: what the system will receive for a real order, before anything is sent.
 *
 * @param props           `orderId` and its setter belong to the wizard so the choice is remembered.
 * @param props.orderId
 * @param props.onOrderId
 * @return The step.
 */
export function PreviewStep( {
	orderId,
	onOrderId,
}: {
	orderId: number | null;
	onOrderId: ( id: number ) => void;
} ) {
	const sample = useSampleOrders();
	const [ skuToMap, setSkuToMap ] = useState< string | null >( null );
	const chosen = orderId ?? sample.orderId;

	if ( sample.loading ) {
		return (
			<LoadingState
				rows={ 4 }
				label={ __(
					'Loading your latest orders',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
		);
	}
	if ( sample.error && sample.orders.length === 0 ) {
		return (
			<ErrorState
				error={ sample.error }
				onRetry={ () => void sample.reload() }
			/>
		);
	}
	if ( sample.orders.length === 0 ) {
		return (
			<Callout tone="info">
				{ __(
					'No orders yet? A new store has nothing to preview. Place a test order in WooCommerce, or continue without a preview.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
		);
	}
	return (
		<>
			<PreviewPanel
				type="orders"
				useDraft={ false }
				orderId={ chosen }
				onOrderChange={ ( id ) => {
					sample.setOrderId( id );
					onOrderId( id );
				} }
				orders={ sample.orders }
				onMapSku={ setSkuToMap }
			/>
			{ skuToMap ? (
				<MapSkuDialog
					sku={ skuToMap }
					onClose={ () => setSkuToMap( null ) }
					onMapped={ () => setSkuToMap( null ) }
				/>
			) : null }
		</>
	);
}
