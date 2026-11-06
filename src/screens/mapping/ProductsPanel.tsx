import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ProductMatch } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { Pager } from '../../components/Pager';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { StatusBadge } from '../../components/StatusBadge';
import type { Tone } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import { MapSkuDialog } from './MapSkuDialog';

function problem(
	product: ProductMatch,
	system: string
): { tone: Tone; text: string } {
	switch ( product.status ) {
		case 'no_sku':
			return {
				tone: 'warning',
				text: __( 'No SKU', 'estasoft-enterprise-erpcrm-sync-engine' ),
			};
		case 'unmatched':
			return {
				tone: 'danger',
				text: sprintf(
					/* translators: %s: system name */ __(
						'SKU not found in %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				),
			};
		case 'override':
			return {
				tone: 'info',
				text: __(
					'Mapped by hand',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			};
		default:
			return {
				tone: 'success',
				text: __( 'Matched', 'estasoft-enterprise-erpcrm-sync-engine' ),
			};
	}
}

/**
 * Products and SKU matching: how products are matched, and the products that have no match.
 *
 * @return The panel.
 */
export function ProductsPanel() {
	const { canConfigure, boot } = useApp();
	const system = useSystemName();
	const [ page, setPage ] = useState( 1 );
	const list = useResource(
		( signal ) => api.products.unmatched( page, signal ),
		[ page ],
		{ keepPrevious: true }
	);
	const [ mapping, setMapping ] = useState< ProductMatch | null >( null );
	const items = list.data?.items ?? [];

	return (
		<div className="estasoft-sync-stack">
			<Callout tone="info">
				{ __(
					'Changes on this tab apply as soon as you save, and are written to the log. SKU matches have no drafts or versions.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			</Callout>
			<Card
				title={ __(
					'How products are matched',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			>
				<p>
					{ sprintf(
						/* translators: %s: system name */ __(
							'SKU is the safest. The SKU of each WooCommerce product, and of each variation, is matched against the reference of the product in %s.',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						system
					) }
				</p>
				<p className="estasoft-sync-muted">
					{ __(
						'If there is no match, the order fails and waits in Needs attention until you map the SKU. Nothing is created in your system without you.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</p>
			</Card>
			<Card
				title={
					list.data
						? sprintf(
								/* translators: %d: number of products */ _n(
									'Product without a match (%d)',
									'Products without a match (%d)',
									list.data.total,
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								list.data.total
							)
						: __(
								'Products without a match',
								'estasoft-enterprise-erpcrm-sync-engine'
							)
				}
				flush
			>
				{ list.loading ? (
					<LoadingState
						rows={ 4 }
						label={ __(
							'Loading products',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					/>
				) : null }
				{ list.error && ! list.data ? (
					<ErrorState
						error={ list.error }
						onRetry={ () => void list.reload() }
					/>
				) : null }
				{ list.data && items.length === 0 ? (
					<EmptyState
						title={ __(
							'Every product matches',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					>
						{ __(
							'No product is waiting for a SKU or a mapping.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</EmptyState>
				) : null }
				{ items.length > 0 ? (
					<ResponsiveTable< ProductMatch, number >
						caption={ __(
							'Products without a match',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						rows={ items }
						rowKey={ ( product ) => product.product_id }
						columns={ [
							{
								id: 'product',
								header: __(
									'Product',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								primary: true,
								cell: ( product ) => (
									<span
										className="estasoft-sync-strong"
										dir="auto"
									>
										{ product.name }
									</span>
								),
							},
							{
								id: 'sku',
								header: __(
									'SKU',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								cell: ( product ) =>
									product.sku ? (
										<span className="estasoft-sync-mono">
											{ product.sku }
										</span>
									) : (
										__(
											'(none)',
											'estasoft-enterprise-erpcrm-sync-engine'
										)
									),
							},
							{
								id: 'problem',
								header: __(
									'Problem',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								wide: true,
								cell: ( product ) => {
									const issue = problem( product, system );
									return (
										<>
											<StatusBadge tone={ issue.tone }>
												{ issue.text }
											</StatusBadge>
											{ product.orders_blocked > 0 ? (
												<span className="estasoft-sync-muted estasoft-sync-block">
													{ sprintf(
														/* translators: %d: number of orders */ _n(
															'%d order is waiting on it',
															'%d orders are waiting on it',
															product.orders_blocked,
															'estasoft-enterprise-erpcrm-sync-engine'
														),
														product.orders_blocked
													) }
												</span>
											) : null }
										</>
									);
								},
							},
							{
								id: 'action',
								header: __(
									'Action',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								hideHeader: true,
								align: 'end',
								cell: ( product ) =>
									product.status === 'no_sku' ||
									! canConfigure ? (
										<Btn
											small
											href={ `${ boot.adminUrl }post.php?post=${ product.product_id }&action=edit` }
										>
											{ __(
												'Edit product',
												'estasoft-enterprise-erpcrm-sync-engine'
											) }
										</Btn>
									) : (
										<Btn
											small
											aria-label={ sprintf(
												/* translators: %s: SKU */ __(
													'Map this SKU %s',
													'estasoft-enterprise-erpcrm-sync-engine'
												),
												product.sku
											) }
											onClick={ () =>
												setMapping( product )
											}
										>
											{ __(
												'Map this SKU',
												'estasoft-enterprise-erpcrm-sync-engine'
											) }
										</Btn>
									),
							},
						] }
					/>
				) : null }
				{ list.data && items.length > 0 ? (
					<Pager
						page={ list.data.page }
						totalPages={ list.data.total_pages }
						total={ list.data.total }
						perPage={ list.data.per_page }
						shown={ items.length }
						onPage={ setPage }
					/>
				) : null }
			</Card>
			{ mapping ? (
				<MapSkuDialog
					sku={ mapping.sku }
					product={ mapping }
					failedOrders={ mapping.orders_blocked }
					onClose={ () => setMapping( null ) }
					onMapped={ () => {
						setMapping( null );
						void list.reload();
					} }
				/>
			) : null }
		</div>
	);
}
