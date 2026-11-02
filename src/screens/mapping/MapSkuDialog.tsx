import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ProductSearchHit } from '../../api/local-types';
import type { ProductMatch } from '../../api/types';
import { useNotices } from '../../app/NoticeProvider';
import { Link } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Callout } from '../../components/Callout';
import { ConfirmDialog } from '../../components/Dialog';
import { Busy } from '../../components/EmptyState';
import { CheckField, ChoiceGroup, TextField } from '../../components/Fields';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { useResource } from '../../hooks/useResource';

export interface MapSkuDialogProps {
	sku: string;
	/** The WooCommerce product, when the caller already has it. */
	product?: ProductMatch | null;
	/** Number of orders that failed on this SKU; offers to retry them. */
	failedOrders?: number;
	onClose: () => void;
	onMapped: () => void;
}

/**
 * "Map SKU CS-BOWL-04": pick the product in the connected system that this WooCommerce product
 * should use. Optionally retries the orders that failed on this SKU.
 *
 * @param props              SKU, product and handlers.
 * @param props.sku
 * @param props.product
 * @param props.failedOrders
 * @param props.onClose
 * @param props.onMapped
 * @return The dialog.
 */
export function MapSkuDialog( {
	sku,
	product,
	failedOrders = 0,
	onClose,
	onMapped,
}: MapSkuDialogProps ) {
	const system = useSystemName();
	const { status } = useStatus();
	const { notify } = useNotices();
	const [ search, setSearch ] = useState( sku );
	const settled = useDebouncedValue( search, 300 );
	const [ chosen, setChosen ] = useState( '' );
	const [ retry, setRetry ] = useState( failedOrders > 0 );
	const [ resolved, setResolved ] = useState<
		ProductMatch | null | undefined
	>( product ?? undefined );

	// Find the WooCommerce product when the caller only knows the SKU.
	useEffect( () => {
		if ( product !== undefined && product !== null ) {
			return undefined;
		}
		let cancelled = false;
		void api.products
			.unmatched( 1 )
			.then( ( page ) => {
				if ( ! cancelled ) {
					setResolved(
						page.items.find( ( item ) => item.sku === sku ) ?? null
					);
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setResolved( null );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ product, sku ] );

	const results = useResource< ProductSearchHit[] >(
		( signal ) =>
			api.products.search( settled, status?.connection?.id, signal ),
		[ settled ],
		{ enabled: settled.trim().length > 0, keepPrevious: true }
	);
	const hits = results.data ?? [];

	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: SKU */ __(
					'Map SKU %s',
					'estasoft-erp-crm-sync-engine'
				),
				sku
			) }
			onClose={ onClose }
			confirmLabel={ __(
				'Save mapping',
				'estasoft-erp-crm-sync-engine'
			) }
			confirmDisabled={ ! chosen || ! resolved }
			onConfirm={ async () => {
				if ( ! resolved ) {
					return;
				}
				await api.products.map( resolved.product_id, chosen );
				let retried = 0;
				if ( retry && failedOrders > 0 ) {
					const result = await api.queue.retryMany( {
						error_code: 'SYNC-MAP-001',
						cause: `SKU ${ sku }`,
					} );
					retried = result.retried;
				}
				notify( {
					kind: 'success',
					text:
						retried > 0
							? sprintf(
									/* translators: 1: SKU, 2: number of orders sent back to the queue */ __(
										'SKU %1$s is mapped. %2$d orders were sent back to the queue.',
										'estasoft-erp-crm-sync-engine'
									),
									sku,
									retried
								)
							: sprintf(
									/* translators: %s: SKU */ __(
										'SKU %s is mapped.',
										'estasoft-erp-crm-sync-engine'
									),
									sku
								),
				} );
				onMapped();
			} }
		>
			<p>
				{ sprintf(
					/* translators: 1: system name, 2: SKU */
					__(
						'%1$s has no product with the reference %2$s. Pick the %1$s product that this WooCommerce product should use.',
						'estasoft-erp-crm-sync-engine'
					),
					system,
					sku
				) }
			</p>
			{ resolved === null ? (
				<Callout
					tone="warning"
					action={
						<Link
							to="/mapping/products"
							className="estasoft-sync-link"
							onClick={ onClose }
						>
							{ __(
								'Open Products',
								'estasoft-erp-crm-sync-engine'
							) }
						</Link>
					}
				>
					{ __(
						'This SKU is not in the list of products without a match. Open Mapping, Products to map it there.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			<TextField
				label={ sprintf(
					/* translators: %s: system name */ __(
						'Search %s products',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
				type="search"
				value={ search }
				onChange={ setSearch }
				placeholder={ __(
					'Name or reference',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			{ results.loading ? (
				<Busy
					label={ __( 'Searching…', 'estasoft-erp-crm-sync-engine' ) }
				/>
			) : null }
			{ results.error ? (
				<Callout tone="danger" role="alert">
					{ __(
						'The search failed. Try again in a moment.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			{ results.data && hits.length === 0 ? (
				<p className="estasoft-sync-muted">
					{ __(
						'No product matches this search.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			) : null }
			{ hits.length > 0 ? (
				<ChoiceGroup
					legend={ __(
						'Products found',
						'estasoft-erp-crm-sync-engine'
					) }
					hideLegend
					value={ chosen }
					onChange={ setChosen }
					choices={ hits.map( ( hit ) => ( {
						value: hit.id,
						label: hit.name,
						description: sprintf(
							/* translators: %s: product reference */ __(
								'Reference %s',
								'estasoft-erp-crm-sync-engine'
							),
							hit.ref
						),
					} ) ) }
				/>
			) : null }
			{ failedOrders > 0 ? (
				<CheckField
					label={ sprintf(
						/* translators: %d: number of orders */ __(
							'Retry the %d orders that failed on this SKU',
							'estasoft-erp-crm-sync-engine'
						),
						failedOrders
					) }
					checked={ retry }
					onChange={ setRetry }
				/>
			) : null }
		</ConfirmDialog>
	);
}
