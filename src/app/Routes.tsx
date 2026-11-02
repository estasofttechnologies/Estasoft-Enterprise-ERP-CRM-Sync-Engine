import { __ } from '@wordpress/i18n';
import { Callout } from '../components/Callout';
import { PageHeader } from '../components/PageHeader';
import { AddConnection } from '../screens/connections/AddConnection';
import { ConnectionDetail } from '../screens/connections/ConnectionDetail';
import { ConnectionProblems } from '../screens/connections/ConnectionProblems';
import { ConnectionsList } from '../screens/connections/ConnectionsList';
import { OtherSystems } from '../screens/connections/OtherSystems';
import { Dashboard } from '../screens/dashboard/Dashboard';
import { ExportsScreen } from '../screens/exports/ExportsScreen';
import { Invoices } from '../screens/invoices/Invoices';
import { LogDetail, Logs } from '../screens/logs/Logs';
import type { MappingTab } from '../screens/mapping/MappingPage';
import { MappingPage } from '../screens/mapping/MappingPage';
import { NeedsAttention } from '../screens/queue/NeedsAttention';
import { OrderDetail } from '../screens/queue/OrderDetail';
import { QueueList } from '../screens/queue/QueueList';
import { Reconciliation } from '../screens/reconciliation/Reconciliation';
import type { SettingsTab } from '../screens/settings/SettingsPage';
import { SettingsPage } from '../screens/settings/SettingsPage';
import { ShadowResults } from '../screens/shadow/ShadowResults';
import { Wizard } from '../screens/setup/Wizard';
import type { StockTab } from '../screens/stock/StockPage';
import { StockPage } from '../screens/stock/StockPage';
import { useApp } from './AppContext';
import type { RouteState } from './router';

const MAPPING_TABS: MappingTab[] = [
	'orders',
	'customers',
	'products',
	'refunds',
	'stock',
	'tax',
];
const SETTINGS_TABS: SettingsTab[] = [
	'general',
	'notifications',
	'performance',
	'access',
	'tools',
	'readiness',
];

function numeric( value: string | undefined ): number | null {
	return value !== undefined && /^\d+$/.test( value )
		? Number( value )
		: null;
}

/**
 * Picks the screen for a route.
 *
 * @param props       Current route.
 * @param props.route
 * @return The screen.
 */
export function Routes( { route }: { route: RouteState } ) {
	const { canManage } = useApp();
	const [ first = '', second ] = route.segments;
	if ( ! canManage ) {
		return (
			<>
				<PageHeader
					title={ __(
						'ESTASOFT SYNC',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<Callout tone="info">
					{ __(
						'Your account does not have access to ESTASOFT SYNC. Ask an administrator to give you the “View and operate” permission.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			</>
		);
	}
	switch ( first ) {
		case '':
		case 'dashboard':
			return <Dashboard />;
		case 'setup':
			return <Wizard />;
		case 'shadow':
			return <ShadowResults />;
		case 'queue': {
			if ( second === 'attention' ) {
				return <NeedsAttention />;
			}
			const id = numeric( second );
			return id !== null ? <OrderDetail id={ id } /> : <QueueList />;
		}
		case 'invoices':
			return <Invoices />;
		case 'connections': {
			if ( second === 'add' ) {
				return <AddConnection />;
			}
			if ( second === 'other' ) {
				return <OtherSystems />;
			}
			if ( second === 'problems' ) {
				return <ConnectionProblems />;
			}
			const id = numeric( second );
			if ( id !== null && route.segments[ 2 ] === 'exports' ) {
				return <ExportsScreen id={ id } />;
			}
			return id !== null ? (
				<ConnectionDetail id={ id } />
			) : (
				<ConnectionsList />
			);
		}
		case 'mapping': {
			const tab: MappingTab =
				second === 'tracking'
					? 'stock'
					: MAPPING_TABS.includes( second as MappingTab )
						? ( second as MappingTab )
						: 'orders';
			return <MappingPage tab={ tab } />;
		}
		case 'stock': {
			const tab: StockTab =
				second === 'tracking' || second === 'conflicts'
					? second
					: 'stock';
			return <StockPage tab={ tab } />;
		}
		case 'reconciliation':
			return <Reconciliation />;
		case 'logs': {
			const id = numeric( second );
			return id !== null ? <LogDetail id={ id } /> : <Logs />;
		}
		case 'settings': {
			const tab: SettingsTab = SETTINGS_TABS.includes(
				second as SettingsTab
			)
				? ( second as SettingsTab )
				: 'general';
			return <SettingsPage tab={ tab } />;
		}
		default:
			return <Dashboard />;
	}
}
