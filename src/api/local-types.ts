/**
 * Request and response shapes that `types.ts` leaves inline or does not list. They follow the
 * endpoint list at the bottom of `types.ts`; anything beyond it is marked.
 */
import type {
	ItemState,
	MappingRule,
	MappingType,
	ReconDiff,
	Settings,
	TaxMapRow,
} from './types';

export interface ProductSearchHit {
	id: string;
	ref: string;
	name: string;
}

export interface ToolsExportResult {
	filename: string;
	json: string;
}

export type ToolsImportResult = { preview: string[] } | { applied: true };

export interface StockRulesInput {
	enabled?: boolean;
	interval_minutes?: number;
	safety_buffer?: number;
	subtract_held?: boolean;
	warehouses?: { id: string; counts_toward_stock: boolean }[];
	tracking_enabled?: boolean;
	delivered_becomes?: string;
	cod_payment_on_delivery?: boolean;
}

export interface ReconSettingsInput {
	schedule_time?: string;
	window_days?: number;
	auto_repair_missing?: boolean;
}

export interface MappingSaveInput {
	rules: MappingRule[];
	note?: string;
}

export interface TaxMapSaveRow extends Pick<
	TaxMapRow,
	'country' | 'tax_name' | 'rate_percent' | 'system_code'
> {}

export interface BulkActionRequest {
	ids: number[];
	action: 'skip' | 'retry' | 'restore';
	reason?: string;
}

export interface LogQuery {
	level?: 'info' | 'warning' | 'error';
	search?: string;
	connection_id?: number;
	range?: 'hour' | 'today' | '7d' | '14d';
	page?: number;
	per_page?: number;
}

export interface InvoiceQuery {
	status?: 'waiting' | 'rejected' | 'reported' | 'not_required';
	page?: number;
}

/** Not in the endpoint list of types.ts: requested as a contract change (see docs/admin-app.md). */
export interface NotificationTestResult {
	sent_to: string[];
}

export type QueueCounts = Partial< Record< ItemState | 'all', number > >;

export type SettingsInput = Partial< Settings >;

export type { MappingType, ReconDiff };
