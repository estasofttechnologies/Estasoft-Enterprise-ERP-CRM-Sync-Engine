import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ProductSearchHit } from '../../api/local-types';
import type { ProductMatch } from '../../api/types';
import { useNotices } from '../../app/NoticeProvider';
import { Link } from '../../app/router';
import { useSystemName } from '../../app/StatusProvider';
import { Callout } from '../../components/Callout';
import { ConfirmDialog } from '../../components/Dialog';
import { Busy } from '../../components/EmptyState';
import { CheckField, ChoiceGroup, TextField } from '../../components/Fields';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { useResource } from '../../hooks/useResource';

/** How many pages of products without a match are searched for the SKU. */
const MAX_PAGES = 20;

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
		// The list is paged, so the SKU may be on a later page than the first.
		const look = async (): Promise< ProductMatch | null > => {
			for ( let pageNumber = 1; pageNumber <= MAX_PAGES; pageNumber++ ) {
				const page = await api.products.unmatched( pageNumber );
				const found = page.items.find( ( item ) => item.sku === sku );
				if ( found ) {
					return found;
				}
				if ( pageNumber >= page.total_pages ) {
					break;
				}
			}
			return null;
		};
		look()
			.then( ( found ) => {
				if ( ! cancelled ) {
					setResolved( found );
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
		( signal ) => api.products.search( settled, undefined, signal ),
		[ settled ],
		{ enabled: settled.trim().length > 0, keepPrevious: true }
	);
	const hits = results.data ?? [];

	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: SKU */ __(
					'Map SKU %s',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				sku
			) }
			onClose={ onClose }
			confirmLabel={ __(
				'Save mapping',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			confirmDisabled={ ! chosen || ! resolved }
			onConfirm={ async () => {
				if ( ! resolved ) {
					return;
				}
				await api.products.map(
					resolved.product_id,
					chosen,
					hits.find( ( hit ) => hit.id === chosen )?.ref ?? null
				);
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
									/* translators: 1: SKU, 2: number of orders sent back to the queue */
									_n(
										'SKU %1$s is mapped. %2$d order was sent back to the queue.',
										'SKU %1$s is mapped. %2$d orders were sent back to the queue.',
										retried,
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									sku,
									retried
								)
							: sprintf(
									/* translators: %s: SKU */ __(
										'SKU %s is mapped.',
										'estasoft-enterprise-erpcrm-sync-engine'
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
						'estasoft-enterprise-erpcrm-sync-engine'
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
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Link>
					}
				>
					{ __(
						'This SKU is not in the list of products without a match. Open Mapping, Products to map it there.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</Callout>
			) : null }
			<TextField
				label={ sprintf(
					/* translators: %s: system name */ __(
						'Search %s products',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				) }
				type="search"
				value={ search }
				onChange={ setSearch }
				placeholder={ __(
					'Name or reference',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			/>
			{ results.loading ? (
				<Busy
					label={ __(
						'Searching…',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			) : null }
			{ results.error ? (
				<Callout tone="danger" role="alert">
					{ __(
						'The search failed. Try again in a moment.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</Callout>
			) : null }
			{ results.data && hits.length === 0 ? (
				<p className="estasoft-sync-muted">
					{ __(
						'No product matches this search.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</p>
			) : null }
			{ hits.length > 0 ? (
				<ChoiceGroup
					legend={ __(
						'Products found',
						'estasoft-enterprise-erpcrm-sync-engine'
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
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							hit.ref
						),
					} ) ) }
				/>
			) : null }
			{ failedOrders > 0 ? (
				<CheckField
					label={ sprintf(
						/* translators: %d: number of orders */
						_n(
							'Retry the %d order that failed on this SKU',
							'Retry the %d orders that failed on this SKU',
							failedOrders,
							'estasoft-enterprise-erpcrm-sync-engine'
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
