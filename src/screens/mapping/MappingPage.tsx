import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { navigate, routeTo, useRoute } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { SelectField } from '../../components/Fields';
import { NotConnected } from '../../components/NotConnected';
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
 * Name of a connection in the picker: the system and the label, without saying the same word twice.
 *
 * @param connection           Connection with its connector id and label.
 * @param connection.connector
 * @param connection.label
 * @return Text such as "Odoo, Cedar & Stone" or just "Odoo".
 */
function connectionName( connection: {
	connector: string;
	label: string;
} ): string {
	const system =
		connection.connector === 'odoo' ? 'Odoo' : connection.connector;
	return connection.label === system || connection.label === ''
		? system
		: `${ system }, ${ connection.label }`;
}

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
	const { status, loading: statusLoading } = useStatus();
	const connections = useResource(
		( signal ) => api.connections.list( signal ),
		[]
	);
	const list = connections.data ?? [];
	const notConnected = status?.mode === 'not_connected';
	// Nothing is asked of the server until the status says a system is connected: its routes answer 409 otherwise.
	const hold = notConnected || ( status === undefined && statusLoading );
	const chosen = Number(
		route.params.get( 'connection' ) ??
			status?.connection?.id ??
			list[ 0 ]?.id ??
			0
	);
	const labels: Record< MappingTab, string > = {
		orders: __( 'Orders', 'estasoft-enterprise-erpcrm-sync-engine' ),
		customers: __( 'Customers', 'estasoft-enterprise-erpcrm-sync-engine' ),
		products: __( 'Products', 'estasoft-enterprise-erpcrm-sync-engine' ),
		refunds: __( 'Refunds', 'estasoft-enterprise-erpcrm-sync-engine' ),
		stock: __(
			'Stock and tracking',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		tax: __( 'Tax', 'estasoft-enterprise-erpcrm-sync-engine' ),
	};
	const subtitles: Record< MappingTab, string > = {
		orders: sprintf(
			/* translators: %s: system name */ __(
				'Orders to %s sale orders.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			system
		),
		customers: sprintf(
			/* translators: %s: system name */ __(
				'Customers to %s contacts.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			system
		),
		products: __(
			'Products and SKU matching.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		refunds: sprintf(
			/* translators: %s: system name */ __(
				'Refunds to %s credit notes.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			system
		),
		stock: sprintf(
			/* translators: %s: system name */ __(
				'What comes back from %s.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			system
		),
		tax: __(
			'Tax codes. Each order must arrive with the code your system expects.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};

	return (
		<>
			<PageHeader
				title={ __(
					'Mapping',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				description={ subtitles[ tab ] }
			/>
			<div className="estasoft-sync-connpick">
				<SelectField
					label={ __(
						'Connection',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
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
									label: connectionName( connection ),
								} ) )
							: [
									{
										value: '0',
										label: __(
											'No connection yet',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
									},
								]
					}
					hint={ __(
						'Each connection has its own settings.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			</div>
			<TabNav
				label={ __(
					'Mapping pages',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				items={ TABS.map( ( id ) => ( {
					label: labels[ id ],
					to: routeTo( `/mapping/${ id }`, {
						connection: route.params.get( 'connection' ),
					} ),
					current: id === tab,
				} ) ) }
			/>
			{ notConnected ? <NotConnected /> : null }
			{ ! hold && tab === 'orders' ? (
				<RuleSetEditor
					key="orders"
					type="orders"
					title={ labels.orders }
					description={ subtitles.orders }
				/>
			) : null }
			{ ! hold && tab === 'customers' ? (
				<RuleSetEditor
					key="customers"
					type="customers"
					title={ labels.customers }
					description={ subtitles.customers }
				/>
			) : null }
			{ ! hold && tab === 'refunds' ? (
				<RuleSetEditor
					key="refunds"
					type="refunds"
					title={ labels.refunds }
					description={ subtitles.refunds }
				/>
			) : null }
			{ ! hold && tab === 'stock' ? (
				<div className="estasoft-sync-stack">
					<p className="estasoft-sync-muted">
						{ sprintf(
							/* translators: %s: system name */ __(
								'These rules read from %s and write to WooCommerce. The buffer, warehouses and the winner rule are on the Stock page.',
								'estasoft-enterprise-erpcrm-sync-engine'
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
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						description={ __(
							'Runs on a schedule, and when the system says a quantity changed.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					/>
					<RuleSetEditor
						key="tracking"
						type="tracking"
						asSection
						title={ __(
							'Delivery in',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						description={ __(
							'Delivery state, carrier and tracking number.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					/>
				</div>
			) : null }
			{ ! hold && tab === 'products' ? <ProductsPanel /> : null }
			{ ! hold && tab === 'tax' ? <TaxMapEditor /> : null }
		</>
	);
}
