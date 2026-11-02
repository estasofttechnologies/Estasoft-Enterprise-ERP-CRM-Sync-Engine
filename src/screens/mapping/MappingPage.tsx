import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { navigate, routeTo, useRoute } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { SelectField } from '../../components/Fields';
import { PageHeader } from '../../components/PageHeader';
import { TabNav } from '../../components/TabNav';
import { useResource } from '../../hooks/useResource';
import { ProductsPanel } from './ProductsPanel';
import { RuleSetEditor } from './RuleSetEditor';
import { TaxMapEditor } from './TaxMapEditor';

export type MappingTab =
	'orders' | 'customers' | 'products' | 'refunds' | 'stock' | 'tax';

const TABS: MappingTab[] = [
	'orders',
	'customers',
	'products',
	'refunds',
	'stock',
	'tax',
];

/**
 * Mapping: a tab for each type of data. Orders, customers and refunds have versions and a draft;
 * Products (SKU matching) and Tax apply when saved.
 *
 * @param props     Current tab.
 * @param props.tab
 * @return The screen.
 */
export function MappingPage( { tab }: { tab: MappingTab } ) {
	const route = useRoute();
	const system = useSystemName();
	const { status } = useStatus();
	const connections = useResource(
		( signal ) => api.connections.list( signal ),
		[]
	);
	const list = connections.data ?? [];
	const chosen = Number(
		route.params.get( 'connection' ) ??
			status?.connection?.id ??
			list[ 0 ]?.id ??
			0
	);
	const labels: Record< MappingTab, string > = {
		orders: __( 'Orders', 'estasoft-erp-crm-sync-engine' ),
		customers: __( 'Customers', 'estasoft-erp-crm-sync-engine' ),
		products: __( 'Products', 'estasoft-erp-crm-sync-engine' ),
		refunds: __( 'Refunds', 'estasoft-erp-crm-sync-engine' ),
		stock: __( 'Stock and tracking', 'estasoft-erp-crm-sync-engine' ),
		tax: __( 'Tax', 'estasoft-erp-crm-sync-engine' ),
	};
	const subtitles: Record< MappingTab, string > = {
		orders: sprintf(
			/* translators: %s: system name */ __(
				'Orders to %s sale orders.',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		customers: sprintf(
			/* translators: %s: system name */ __(
				'Customers to %s contacts.',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		products: __(
			'Products and SKU matching.',
			'estasoft-erp-crm-sync-engine'
		),
		refunds: sprintf(
			/* translators: %s: system name */ __(
				'Refunds to %s credit notes.',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		stock: sprintf(
			/* translators: %s: system name */ __(
				'What comes back from %s.',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		tax: __(
			'Tax codes. Each order must arrive with the code your system expects.',
			'estasoft-erp-crm-sync-engine'
		),
	};

	return (
		<>
			<PageHeader
				title={ __( 'Mapping', 'estasoft-erp-crm-sync-engine' ) }
				description={ subtitles[ tab ] }
			/>
			<div className="estasoft-sync-connpick">
				<SelectField
					label={ __( 'Connection', 'estasoft-erp-crm-sync-engine' ) }
					value={ String( chosen ) }
					onChange={ ( value ) =>
						navigate(
							routeTo( `/mapping/${ tab }`, {
								connection: value,
							} ),
							true
						)
					}
					disabled={ list.length <= 1 }
					options={
						list.length > 0
							? list.map( ( connection ) => ( {
									value: String( connection.id ),
									label: `${ connection.connector === 'odoo' ? 'Odoo' : connection.connector }, ${ connection.label }`,
								} ) )
							: [
									{
										value: '0',
										label: __(
											'No connection yet',
											'estasoft-erp-crm-sync-engine'
										),
									},
								]
					}
					hint={ __(
						'Each connection has its own settings.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</div>
			<TabNav
				label={ __( 'Mapping pages', 'estasoft-erp-crm-sync-engine' ) }
				items={ TABS.map( ( id ) => ( {
					label: labels[ id ],
					to: routeTo( `/mapping/${ id }`, {
						connection: route.params.get( 'connection' ),
					} ),
					current: id === tab,
				} ) ) }
			/>
			{ tab === 'orders' ? (
				<RuleSetEditor
					key="orders"
					type="orders"
					title={ labels.orders }
					description={ subtitles.orders }
				/>
			) : null }
			{ tab === 'customers' ? (
				<RuleSetEditor
					key="customers"
					type="customers"
					title={ labels.customers }
					description={ subtitles.customers }
				/>
			) : null }
			{ tab === 'refunds' ? (
				<RuleSetEditor
					key="refunds"
					type="refunds"
					title={ labels.refunds }
					description={ subtitles.refunds }
				/>
			) : null }
			{ tab === 'stock' ? (
				<div className="estasoft-sync-stack">
					<p className="estasoft-sync-muted">
						{ sprintf(
							/* translators: %s: system name */ __(
								'These rules read from %s and write to WooCommerce. The buffer, warehouses and the winner rule are on the Stock page.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</p>
					<RuleSetEditor
						key="stock"
						type="stock"
						asSection
						title={ __(
							'Stock in',
							'estasoft-erp-crm-sync-engine'
						) }
						description={ __(
							'Runs on a schedule, and when the system says a quantity changed.',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					<RuleSetEditor
						key="tracking"
						type="tracking"
						asSection
						title={ __(
							'Delivery in',
							'estasoft-erp-crm-sync-engine'
						) }
						description={ __(
							'Delivery state, carrier and tracking number.',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
				</div>
			) : null }
			{ tab === 'products' ? <ProductsPanel /> : null }
			{ tab === 'tax' ? <TaxMapEditor /> : null }
		</>
	);
}
