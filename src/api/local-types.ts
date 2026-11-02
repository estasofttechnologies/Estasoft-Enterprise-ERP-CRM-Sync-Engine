/**
 * Request and response shapes that `types.ts` leaves inline or does not list. They follow the
 * endpoint list at the bottom of `types.ts`; anything beyond it is marked.
 */
import type {
	CatalogEntry,
	ConnectionTestResult,
	CredentialFieldDef,
	ExportFileInfo as RestExportFileInfo,
	ExportsResponse,
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

export type ToolsImportResult =
	| { preview: string[] }
	| { applied: true; drafts?: number; skipped?: string[] };

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
	/** The values the REST route accepts; `all` means everything that is kept. */
	range?: '1h' | '24h' | '7d' | '30d' | 'all';
	page?: number;
	per_page?: number;
}

export interface InvoiceQuery {
	status?: 'waiting' | 'rejected' | 'reported' | 'not_required';
	page?: number;
	per_page?: number;
}

/** Not in the endpoint list of types.ts: requested as a contract change (see docs/admin-app.md). */
export interface NotificationTestResult {
	sent_to: string[];
}

export type QueueCounts = Partial< Record< ItemState | 'all', number > >;

export type SettingsInput = Partial< Settings >;

export type { MappingType, ReconDiff };

/** One problem of a mapping that cannot be published (`data.details.errors` of the error answer). */
export interface RuleProblem {
	rule_id: string;
	field: string;
	code: string;
	message: string;
}

/** Extra structure the server adds to an error answer (`data.details`). */
export interface ApiErrorDetails {
	/** Every problem of a mapping that cannot be published. */
	errors: RuleProblem[];
	/** The failed connection test. */
	test: ConnectionTestResult | null;
	/** The line to add to wp-config.php when the encryption key is missing. */
	keyLine: string | null;
}

/* ---------------------------------------------------------------- connect experience
 *
 * The REST side (agent V0) put the connect fields in `types.ts`: the extra keys of `CredentialFieldDef`,
 * the extra keys of `CatalogEntry` and the export types. What follows is what the screens read on top of
 * them, all optional, so an older answer never breaks a screen.
 */

/** How a system connects (`CatalogEntry.connection_method`). */
export type ConnectionMethod =
	| 'key'
	| 'own_app'
	| 'cloud_app'
	| 'server_login'
	| 'export_file'
	| 'none'
	| 'unknown';

/** A field of a connect form: the key, the secret, the address, a choice, or a value to copy. */
export type ConnectorField = CredentialFieldDef;

/**
 * One product of a catalog row that holds several (Sage). `id` is the connector id. The keys after
 * `available` are optional overrides of the row: a row says one way to connect, and a product may differ.
 */
export interface CatalogVariant {
	id: string;
	label: string;
	available?: boolean;
	connection_method?: ConnectionMethod;
	needs?: string;
	steps?: string[];
	hosts?: string[];
	terms_url?: string;
	privacy_url?: string;
	developer_url?: string;
	credential_fields?: ConnectorField[];
	auth_style?: CatalogEntry[ 'auth_style' ];
	can_sign_in?: boolean;
}

/** What the connect screens read on top of a catalog row. */
export interface SystemDetails {
	/**
	 * The connector's connect form. REQUESTED: the catalog answer does not carry it yet. Until it does,
	 * a pasted key and a sign-in app get the standard fields and every other system is explained.
	 */
	credential_fields?: ConnectorField[];
	/** True for a row that comes from another plugin (shows the Community tag). */
	community?: boolean;
}

/** A catalog row as the connect screens read it. */
export type SystemEntry = Omit< CatalogEntry, 'variants' > &
	SystemDetails & { variants?: CatalogVariant[] };

/** `GET /connections/{id}/oauth/organisations`. */
export interface OAuthOrganisation {
	id: string;
	name: string;
}

/** What the browser brings back from the vendor's sign-in page (`signin=` in the address). */
export type SignInResult = 'ok' | 'cancelled' | 'failed';

/* ---------------------------------------------------------------- exports (board D06) */

/** A second file that goes with an export file (a list of the ledgers, accounts and items it names). Download with `?part=`, from 1. */
export interface ExportAttachment {
	name: string;
	filename: string;
	size_bytes: number;
}

export type ExportFileInfo = RestExportFileInfo & {
	attachments?: ExportAttachment[];
};
export type ExportList = Omit< ExportsResponse, 'files' > & {
	files: ExportFileInfo[];
};
export type ExportLeftOut = RestExportFileInfo[ 'left_out' ][ number ];
