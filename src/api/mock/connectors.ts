/**
 * Sample data for the connect screens in the mock build: for every row of the catalog, the numbered
 * steps, the hosts, the links and the connect form of its connector, in the shape the REST API sends.
 * It is made up for the mock (hosts end in `.example` unless a vendor's address is public knowledge),
 * and it never ships: the production bundle does not import this folder.
 */
import type {
	CatalogVariant,
	ConnectionMethod,
	ConnectorField,
	SystemEntry,
} from '../local-types';
import type { CatalogEntry } from '../types';

const field = (
	name: string,
	label: string,
	extra: Partial< ConnectorField > = {}
): ConnectorField => ( {
	name,
	label,
	secret: false,
	required: true,
	hint: '',
	default: '',
	...extra,
} );

const secret = (
	name: string,
	label: string,
	extra: Partial< ConnectorField > = {}
): ConnectorField =>
	field( name, label, {
		secret: true,
		type: 'password',
		hint: 'Saved encrypted. It is never shown again.',
		...extra,
	} );

const slug = ( name: string ): string =>
	name
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

/** The connector id of each catalog row (the Sage rows hold several products, see VARIANTS). */
const CONNECTOR_ID: Record< string, string | null > = {
	'QuickBooks Online': 'quickbooks',
	Xero: 'xero',
	'Zoho Books': 'zoho-books',
	MYOB: 'myob',
	'Sage Business Cloud Accounting and Sage 50': null,
	Qoyod: 'qoyod',
	Wafeq: 'wafeq',
	Daftra: 'daftra',
	SMACC: null,
	TallyPrime: 'tallyprime',
	DATEV: 'datev',
	'Lexware Office': 'lexware-office',
	sevDesk: 'sevdesk',
	'Exact Online': 'exact-online',
	Moneybird: 'moneybird',
	Pennylane: 'pennylane',
	Fortnox: 'fortnox',
	Odoo: 'odoo',
	ERPNext: 'erpnext',
	'Microsoft Dynamics 365 Business Central': 'business-central',
	NetSuite: 'netsuite',
	'SAP Business One': 'sap-business-one',
	'SAP S/4HANA': 'sap-s4hana',
	'Oracle Fusion Cloud ERP': 'oracle-fusion',
	'Dynamics 365 Finance and Supply Chain': 'dynamics-365-finance',
	'Sage 200, Sage 300 and Sage X3': null,
	Acumatica: 'acumatica',
	Holded: 'holded',
	HubSpot: 'hubspot',
	'Zoho CRM': 'zoho-crm',
	Salesforce: 'salesforce',
	'Dynamics 365 Sales': 'dynamics-365-sales',
	Pipedrive: 'pipedrive',
	SuiteCRM: 'suitecrm',
};

const VARIANTS: Record< string, CatalogVariant[] > = {
	'Sage Business Cloud Accounting and Sage 50': [
		{
			id: 'sage-accounting',
			label: 'Sage Business Cloud Accounting',
			connection_method: 'own_app',
		},
		{
			id: 'sage50-export',
			label: 'Sage 50 (desktop program)',
			connection_method: 'export_file',
		},
	],
	'Sage 200, Sage 300 and Sage X3': [
		{ id: 'sage200', label: 'Sage 200', connection_method: 'own_app' },
		{
			id: 'sage300',
			label: 'Sage 300',
			connection_method: 'server_login',
		},
		{ id: 'sagex3', label: 'Sage X3', connection_method: 'unknown' },
	],
};

/** Hosts that are public knowledge. Every other row gets an `.example` host. */
const KNOWN_HOSTS: Record< string, string[] > = {
	Xero: [ 'api.xero.com', 'identity.xero.com' ],
	'QuickBooks Online': [
		'quickbooks.api.intuit.com',
		'oauth.platform.intuit.com',
	],
	'Microsoft Dynamics 365 Business Central': [
		'api.businesscentral.dynamics.com',
		'login.microsoftonline.com',
	],
};

function stepsFor(
	method: ConnectionMethod,
	name: string,
	id: string | null
): string[] {
	if ( id === 'odoo' ) {
		// Odoo's own steps are in the app (they follow board B03).
		return [];
	}
	if ( id === 'wafeq' ) {
		return [
			'In Wafeq, open Settings, then API keys.',
			'Create a key and press Enable API. Wafeq shows the key once.',
			'Paste it below.',
		];
	}
	if ( id === 'xero' ) {
		return [
			'Open developer.xero.com and choose My apps, then New app.',
			'Name it, choose Web app, and paste the redirect address below into Redirect URI.',
			'Create a client secret. Xero shows it once.',
			'Paste the Client ID and the secret below.',
		];
	}
	if ( id === 'business-central' ) {
		return [
			'Open the Microsoft Entra admin center, then App registrations, then New registration. Name it ESTASOFT SYNC and keep Single tenant.',
			'Copy the Directory (tenant) ID and the Application (client) ID.',
			'Open Certificates and secrets, make a new client secret, and copy its value. Choose an end date.',
			'Open API permissions, add Dynamics 365 Business Central, choose Application permissions, tick `API.ReadWrite.All`, then Grant admin consent.',
			'In Business Central, search for Microsoft Entra applications and choose New. Paste the Application (client) ID and set State to Enabled.',
			'Give it permissions for sales, customers, items and inventory. Do not give it SUPER.',
		];
	}
	if ( id === 'sap-business-one' ) {
		return [
			'Create a user just for syncing, with its own licence.',
			'Create a user-defined field on sales orders called `U_WooOrder`. The plugin uses it to avoid duplicates.',
			'Paste the address, company database, user and password below.',
		];
	}
	if ( id === 'tallyprime' ) {
		return [
			'In TallyPrime, create the ledgers named below. Tally refuses a file that names a ledger it does not have.',
			'Type the company name exactly as Tally shows it.',
			'Save here. Then make a file on the Exports screen and import it with Alt+O in Tally.',
		];
	}
	switch ( method ) {
		case 'key':
			return [
				`In ${ name }, open the settings for API access.`,
				'Create a key. It is usually shown once.',
				'Paste it below.',
			];
		case 'own_app':
			return [
				`Open the developer area of ${ name } and create a new app.`,
				'Paste the redirect address below where the app asks for a redirect or callback address.',
				'Create a client secret. It is usually shown once.',
				'Paste the Client ID and the secret below.',
			];
		case 'cloud_app':
			return [
				'An administrator registers an app in the cloud directory and copies its ID and secret.',
				`An administrator of ${ name } adds the app and gives it the access it needs.`,
				'Paste the details below.',
			];
		case 'server_login':
			return [
				'Create a user just for syncing.',
				'Make sure this website can reach the server over https.',
				'Paste the address, company database, user and password below.',
			];
		case 'export_file':
			return [
				'Save the settings below.',
				'Make a file on the Exports screen and download it.',
				`Import the file in ${ name }.`,
			];
		default:
			return [];
	}
}

/** The redirect address the merchant registers in the vendor's app settings (a field that is only shown). */
const redirectField = (): ConnectorField =>
	field( 'redirect_uri', 'Redirect address', {
		type: 'display',
		required: false,
		group: 'app',
		hint: 'Add this address to the redirect addresses of your app. It must match exactly.',
		default:
			'https://cedarstone.example/wp-json/estasoft-sync/v1/oauth/callback',
	} );

/**
 * The fields of a connector's connect form.
 * @param method
 * @param name
 * @param id
 * @param type
 */
function fieldsFor(
	method: ConnectionMethod,
	name: string,
	id: string | null,
	type: CatalogEntry[ 'type' ]
): ConnectorField[] {
	if ( id === 'odoo' ) {
		return [];
	}
	if ( id === 'wafeq' ) {
		return [
			secret( 'api_key', 'API key', {
				hint: 'Saved encrypted. It is never shown again.',
			} ),
		];
	}
	if ( id === 'hubspot' ) {
		return [
			secret( 'service_key', 'Service key' ),
			field( 'pipeline', 'Pipeline', {
				type: 'select',
				required: false,
				group: 'advanced',
				options: {
					default: 'Sales pipeline (default)',
					'online-orders': 'Online orders',
				},
				default: 'default',
				hint: 'The pipeline that deals go to.',
			} ),
		];
	}
	if ( id === 'zoho-books' || id === 'zoho-crm' ) {
		return [
			redirectField(),
			field( 'data_centre', 'Data centre', {
				type: 'select',
				group: 'app',
				options: {
					com: 'zoho.com',
					eu: 'zoho.eu',
					sa: 'zoho.sa',
					in: 'zoho.in',
				},
				default: 'com',
				hint: 'The address you use to sign in to Zoho ends like this.',
			} ),
			field( 'client_id', 'Client ID', { group: 'app' } ),
			secret( 'client_secret', 'Client secret', { group: 'app' } ),
		];
	}
	if ( id === 'business-central' ) {
		return [
			field( 'tenant_id', 'Tenant ID', {
				hint: 'The Directory (tenant) ID from Microsoft Entra.',
			} ),
			field( 'client_id', 'Client ID', {
				hint: 'The Application (client) ID.',
			} ),
			secret( 'client_secret', 'Client secret', {
				hint: 'The secret value. Saved encrypted. It is never shown again.',
			} ),
			field( 'environment', 'Environment', {
				default: 'Production',
				hint: 'The name shown in the Business Central admin center.',
			} ),
		];
	}
	if ( id === 'sap-business-one' ) {
		return [
			field( 'address', 'Service Layer address', {
				type: 'url',
				hint: 'Starts with https. The port is often 50000.',
			} ),
			field( 'company_db', 'Company database' ),
			field( 'user', 'User' ),
			secret( 'password', 'Password', {
				hint: 'Saved encrypted. It is never shown again.',
			} ),
			field( 'certificate', 'Security certificate', {
				type: 'select',
				group: 'advanced',
				options: {
					public: 'Trust the standard list of authorities',
					own: 'Trust my own authority certificate',
				},
				default: 'public',
				hint: 'SAP servers often use a certificate that you made yourself. Paste its authority file below. Never turn the check off.',
			} ),
			field( 'ca_certificate', 'Authority certificate (PEM)', {
				type: 'textarea',
				required: false,
				group: 'advanced',
			} ),
		];
	}
	if ( id === 'sage300' ) {
		return [
			field( 'address', 'Web API address', {
				type: 'url',
				hint: 'Your own web server. It must start with https.',
			} ),
			field( 'company_db', 'Company database' ),
			field( 'user', 'User' ),
			secret( 'password', 'Password' ),
		];
	}
	if ( id === 'tallyprime' ) {
		return [
			field( 'company_name', 'Company name in TallyPrime', {
				hint: 'Type it exactly as Tally shows it. Tally refuses a file for another company.',
			} ),
			field( 'tax', 'Tax', {
				type: 'select',
				options: {
					uae_vat: 'UAE VAT',
					ksa_vat: 'Saudi Arabia VAT',
					none: 'No tax in the file',
				},
				default: 'uae_vat',
				hint: 'The tax rates come from your tax mapping.',
			} ),
			field( 'customers', 'Customers in Tally', {
				type: 'select',
				options: {
					one: 'One ledger called WooCommerce customers',
					each: 'One ledger for each customer',
				},
				default: 'one',
				hint: 'One ledger for each customer needs the ledger to exist before you import.',
			} ),
			field( 'ledger_sales', 'Ledger for sales', {
				group: 'advanced',
				default: 'Online sales',
			} ),
			field( 'ledger_shipping', 'Ledger for shipping charged', {
				group: 'advanced',
				default: 'Shipping income',
			} ),
		];
	}
	if ( id === 'datev' ) {
		return [
			field( 'consultant_number', 'Consultant number' ),
			field( 'client_number', 'Client number' ),
			field( 'fiscal_year_start', 'First day of the financial year', {
				hint: 'For example 01.01.',
			} ),
			field( 'account_length', 'Length of account numbers', {
				type: 'number',
				default: '4',
			} ),
			field( 'debtor_account', 'Debtor account' ),
		];
	}
	if ( id === 'sage50-export' ) {
		return [
			field( 'customer_account', 'Customer account for web orders', {
				default: 'WEB',
				hint: 'It must already exist in Sage 50.',
			} ),
			field( 'nominal_sales', 'Nominal code for sales', {
				default: '4000',
			} ),
			field( 'nominal_shipping', 'Nominal code for shipping', {
				required: false,
			} ),
		];
	}
	switch ( method ) {
		case 'key':
			return [
				...( type === 'erp' || type === 'crm'
					? [
							field( 'address', `${ name } address`, {
								type: 'url',
							} ),
						]
					: [] ),
				secret( 'api_key', 'API key' ),
			];
		case 'own_app':
			return [
				redirectField(),
				field( 'client_id', 'Client ID', { group: 'app' } ),
				secret( 'client_secret', 'Client secret', { group: 'app' } ),
			];
		case 'cloud_app':
			return [
				field( 'tenant_id', 'Tenant ID' ),
				field( 'client_id', 'Client ID' ),
				secret( 'client_secret', 'Client secret' ),
			];
		case 'server_login':
			return [
				field( 'address', 'Server address', { type: 'url' } ),
				field( 'company_db', 'Company database' ),
				field( 'user', 'User' ),
				secret( 'password', 'Password' ),
			];
		default:
			return [];
	}
}

function details(
	row: CatalogEntry,
	method: ConnectionMethod,
	id: string | null,
	variantId: string | null
): Pick<
	CatalogVariant,
	| 'hosts'
	| 'terms_url'
	| 'privacy_url'
	| 'developer_url'
	| 'steps'
	| 'credential_fields'
> {
	const key = variantId ?? id;
	const host = KNOWN_HOSTS[ row.name ];
	const base = slug( variantId ?? row.name );
	return {
		hosts:
			method === 'export_file' || method === 'none' || id === 'odoo'
				? []
				: ( host ?? [ `api.${ base }.example` ] ),
		terms_url: `https://www.${ base }.example/terms`,
		privacy_url: `https://www.${ base }.example/privacy`,
		developer_url:
			method === 'export_file' || method === 'none'
				? ''
				: `https://developer.${ base }.example/`,
		steps: stepsFor( method, row.name, key ),
		credential_fields: fieldsFor( method, row.name, key, row.type ),
	};
}

/**
 * Adds what the connect screens read to a catalog row.
 *
 * @param row One row of the catalog as it was before the connectors.
 * @return The row with the connector id, availability, hosts, links, steps and form fields.
 */
export function withConnector( row: CatalogEntry ): SystemEntry {
	const variants = VARIANTS[ row.name ];
	const id = CONNECTOR_ID[ row.name ] ?? null;
	const method = row.connection_method as ConnectionMethod;
	const available = row.name !== 'SMACC';
	const extra = details( row, method, id, null );
	return {
		...row,
		connector: id,
		available,
		verified: false,
		variants: ( variants ?? [] ).map( ( variant ) => ( {
			...variant,
			available: true,
			...details(
				row,
				variant.connection_method ?? method,
				id,
				variant.id
			),
		} ) ),
		...( available
			? extra
			: { ...extra, steps: [], credential_fields: [] } ),
	};
}
