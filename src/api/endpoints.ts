/**
 * One typed function per route of `estasoft-sync/v1`, grouped like the endpoint list in types.ts.
 */
import { request, requestPaged } from './client';
import type {
	BulkActionRequest,
	InvoiceQuery,
	LogQuery,
	MappingSaveInput,
	NotificationTestResult,
	ProductSearchHit,
	ReconSettingsInput,
	SettingsInput,
	StockRulesInput,
	TaxMapSaveRow,
	ToolsExportResult,
	ToolsImportResult,
} from './local-types';
import type {
	AttentionGroup,
	CapacityResult,
	CatalogEntry,
	Connection,
	ConnectionInput,
	ConnectionSummary,
	ConnectionTestResult,
	InvoiceRow,
	LogEntry,
	LogEntryDetail,
	MappingPreview,
	MappingPreviewRequest,
	MappingSet,
	MappingType,
	Onboarding,
	ProductMatch,
	QueueItem,
	QueueItemDetail,
	QueueQuery,
	ReconDiff,
	ReconOverview,
	ReconRun,
	Readiness,
	RetryRequest,
	SampleOrder,
	Settings,
	ShadowReport,
	Status,
	StockOverview,
	StockRules,
	SupportReport,
	TaxMap,
} from './types';

const json = ( body: unknown ) => ( { body } );

export const api = {
	status: {
		get: ( signal?: AbortSignal ) =>
			request< Status >( '/status', { signal } ),
	},

	queue: {
		list: ( query: QueueQuery, signal?: AbortSignal ) =>
			requestPaged< QueueItem >( '/queue', {
				query: { ...query },
				signal,
			} ),
		attention: ( signal?: AbortSignal ) =>
			request< AttentionGroup[] >( '/queue/attention', { signal } ),
		get: ( id: number, signal?: AbortSignal ) =>
			request< QueueItemDetail >( `/queue/${ id }`, { signal } ),
		retry: ( id: number ) =>
			request< QueueItem >( `/queue/${ id }/retry`, { method: 'POST' } ),
		skip: ( id: number, reason: string ) =>
			request< QueueItem >( `/queue/${ id }/skip`, {
				method: 'POST',
				...json( { reason } ),
			} ),
		restore: ( id: number ) =>
			request< QueueItem >( `/queue/${ id }/restore`, {
				method: 'POST',
			} ),
		retryMany: ( input: RetryRequest ) =>
			request< { retried: number } >( '/queue/retry', {
				method: 'POST',
				...json( input ),
			} ),
		bulk: ( input: BulkActionRequest ) =>
			request< { done: number } >( '/queue/bulk', {
				method: 'POST',
				...json( input ),
			} ),
	},

	sync: {
		run: () =>
			request< { started: true } >( '/sync/run', { method: 'POST' } ),
	},

	pause: ( input: { connection_id?: number; minutes?: number } ) =>
		request< ConnectionSummary >( '/pause', {
			method: 'POST',
			...json( input ),
		} ),
	resume: ( input: { connection_id?: number } ) =>
		request< ConnectionSummary >( '/resume', {
			method: 'POST',
			...json( input ),
		} ),

	connections: {
		list: ( signal?: AbortSignal ) =>
			request< ConnectionSummary[] >( '/connections', { signal } ),
		get: ( id: number, signal?: AbortSignal ) =>
			request< Connection >( `/connections/${ id }`, { signal } ),
		create: ( input: ConnectionInput ) =>
			request< Connection >( '/connections', {
				method: 'POST',
				...json( input ),
			} ),
		update: ( id: number, input: Partial< ConnectionInput > ) =>
			request< Connection >( `/connections/${ id }`, {
				method: 'POST',
				...json( input ),
			} ),
		remove: ( id: number ) =>
			request< { disconnected: true } >( `/connections/${ id }`, {
				method: 'DELETE',
			} ),
		test: ( id: number ) =>
			request< ConnectionTestResult >( `/connections/${ id }/test`, {
				method: 'POST',
			} ),
		testInput: ( input: ConnectionInput ) =>
			request< ConnectionTestResult >( '/connections/test', {
				method: 'POST',
				...json( input ),
			} ),
	},

	catalog: {
		list: ( signal?: AbortSignal ) =>
			request< CatalogEntry[] >( '/catalog', { signal } ),
	},

	mappings: {
		get: ( type: MappingType, signal?: AbortSignal ) =>
			request< MappingSet >( `/mappings/${ type }`, { signal } ),
		saveDraft: ( type: MappingType, input: MappingSaveInput ) =>
			request< MappingSet >( `/mappings/${ type }`, {
				method: 'PUT',
				...json( input ),
			} ),
		publish: ( type: MappingType, note?: string ) =>
			request< MappingSet >( `/mappings/${ type }/publish`, {
				method: 'POST',
				...json( note ? { note } : {} ),
			} ),
		discard: ( type: MappingType ) =>
			request< MappingSet >( `/mappings/${ type }/discard`, {
				method: 'POST',
			} ),
		rollback: ( type: MappingType, version: number ) =>
			request< MappingSet >( `/mappings/${ type }/rollback`, {
				method: 'POST',
				...json( { version } ),
			} ),
		preview: ( input: MappingPreviewRequest ) =>
			request< MappingPreview >( '/mappings/preview', {
				method: 'POST',
				...json( input ),
			} ),
	},

	taxMap: {
		get: ( signal?: AbortSignal ) =>
			request< TaxMap >( '/tax-map', { signal } ),
		save: ( rows: TaxMapSaveRow[] ) =>
			request< TaxMap >( '/tax-map', {
				method: 'PUT',
				...json( { rows } ),
			} ),
	},

	products: {
		unmatched: ( page: number, signal?: AbortSignal ) =>
			requestPaged< ProductMatch >( '/products/unmatched', {
				query: { page },
				signal,
			} ),
		search: (
			q: string,
			connectionId: number | undefined,
			signal?: AbortSignal
		) =>
			request< ProductSearchHit[] >( '/products/search', {
				query: { q, connection_id: connectionId },
				signal,
			} ),
		map: ( productId: number, externalId: string | null ) =>
			request< ProductMatch >( '/products/map', {
				method: 'POST',
				...json( { product_id: productId, external_id: externalId } ),
			} ),
	},

	stock: {
		rules: ( signal?: AbortSignal ) =>
			request< StockRules >( '/stock/rules', { signal } ),
		saveRules: ( input: StockRulesInput ) =>
			request< StockRules >( '/stock/rules', {
				method: 'PUT',
				...json( input ),
			} ),
		overview: ( signal?: AbortSignal ) =>
			request< StockOverview >( '/stock/overview', { signal } ),
		accept: ( id: number ) =>
			request< { ok: true } >( `/stock/conflicts/${ id }/accept`, {
				method: 'POST',
			} ),
		hold: ( id: number ) =>
			request< { ok: true } >( `/stock/conflicts/${ id }/hold`, {
				method: 'POST',
			} ),
	},

	reconciliation: {
		get: ( signal?: AbortSignal ) =>
			request< ReconOverview >( '/reconciliation', { signal } ),
		run: ( windowDays: number ) =>
			request< ReconRun >( '/reconciliation/run', {
				method: 'POST',
				...json( { window_days: windowDays } ),
			} ),
		repair: ( id: number, direction: 'wc_to_system' | 'system_to_wc' ) =>
			request< ReconDiff >(
				`/reconciliation/differences/${ id }/repair`,
				{
					method: 'POST',
					...json( { direction } ),
				}
			),
		ignore: ( id: number, reason: string ) =>
			request< ReconDiff >(
				`/reconciliation/differences/${ id }/ignore`,
				{
					method: 'POST',
					...json( { reason } ),
				}
			),
		saveSettings: ( input: ReconSettingsInput ) =>
			request< ReconOverview >( '/reconciliation/settings', {
				method: 'PUT',
				...json( input ),
			} ),
	},

	invoices: {
		list: ( query: InvoiceQuery, signal?: AbortSignal ) =>
			requestPaged< InvoiceRow >( '/invoices', {
				query: { ...query },
				signal,
			} ),
	},

	logs: {
		list: ( query: LogQuery, signal?: AbortSignal ) =>
			requestPaged< LogEntry >( '/logs', {
				query: { ...query },
				signal,
			} ),
		get: ( id: number, signal?: AbortSignal ) =>
			request< LogEntryDetail >( `/logs/${ id }`, { signal } ),
		csv: ( query: LogQuery ) =>
			request< string >( '/logs', {
				query: { ...query },
				text: true,
				accept: 'text/csv',
			} ),
	},

	readiness: {
		get: ( signal?: AbortSignal ) =>
			request< Readiness >( '/readiness', { signal } ),
		run: () => request< Readiness >( '/readiness/run', { method: 'POST' } ),
	},

	supportReport: ( orderNumbers: boolean, signal?: AbortSignal ) =>
		request< SupportReport >( '/support-report', {
			query: { order_numbers: orderNumbers ? 1 : 0 },
			signal,
		} ),

	settings: {
		get: ( signal?: AbortSignal ) =>
			request< Settings >( '/settings', { signal } ),
		save: ( input: SettingsInput ) =>
			request< Settings >( '/settings', {
				method: 'PUT',
				...json( input ),
			} ),
	},

	tools: {
		export: () =>
			request< ToolsExportResult >( '/tools/export', { method: 'POST' } ),
		import: ( payload: string, confirm: boolean ) =>
			request< ToolsImportResult >( '/tools/import', {
				method: 'POST',
				...json( { json: payload, confirm } ),
			} ),
		clearLogs: () =>
			request< { cleared: number } >( '/tools/clear-logs', {
				method: 'POST',
			} ),
		deleteData: ( word: string ) =>
			request< { deleted: true } >( '/tools/delete-data', {
				method: 'POST',
				...json( { word } ),
			} ),
	},

	capacityTest: () =>
		request< CapacityResult >( '/capacity-test', { method: 'POST' } ),

	onboarding: {
		get: ( signal?: AbortSignal ) =>
			request< Onboarding >( '/onboarding', { signal } ),
		save: (
			input: Partial<
				Pick< Onboarding, 'step' | 'connector' | 'answers' >
			>
		) =>
			request< Onboarding >( '/onboarding', {
				method: 'PUT',
				...json( input ),
			} ),
		goLive: ( mode: 'shadow' | 'live' ) =>
			request< Onboarding >( '/go-live', {
				method: 'POST',
				...json( { mode } ),
			} ),
	},

	shadow: {
		report: ( signal?: AbortSignal ) =>
			request< ShadowReport >( '/shadow/report', { signal } ),
		end: () => request< Onboarding >( '/shadow/end', { method: 'POST' } ),
	},

	sampleOrders: ( signal?: AbortSignal ) =>
		request< SampleOrder[] >( '/orders/sample', { signal } ),

	/** Not in the endpoint list of types.ts; requested as a contract change. */
	notificationsTest: () =>
		request< NotificationTestResult >( '/notifications/test', {
			method: 'POST',
		} ),
};
