/**
 * Helpers for the connect screens: which of the five shapes a system has, the form values, the checks
 * of a connection test, and what the browser brings back from a vendor's sign-in page.
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	BootData,
	ConnectionTestResult,
	CredentialFieldType,
} from '../api/types';
import type {
	CatalogVariant,
	ConnectionMethod,
	ConnectorField,
	SignInResult,
	SystemEntry,
} from '../api/local-types';

/** The five ways to connect that have a screen. `none` and `unknown` have none. */
export type ConnectShape =
	'key' | 'own_app' | 'cloud_app' | 'server_login' | 'export_file';

/**
 * Which connect screen a way to connect uses.
 *
 * @param method `connection_method` of a catalog row.
 * @return The shape, or null when the method has no connect screen (`none`, `unknown`).
 */
export function shapeOf( method: string | undefined ): ConnectShape | null {
	switch ( method ) {
		case 'key':
		case 'own_app':
		case 'cloud_app':
		case 'server_login':
		case 'export_file':
			return method;
		default:
			return null;
	}
}

/**
 * Which connect screen a catalog row (with its product chosen) uses. A way to connect that is still "to be confirmed" has no screen of
 * its own, but a system whose connector is installed and sends its own form (Sage X3) is drawn from the sign-in style of that
 * connector, so the person is not left at a dead end.
 *
 * @param entry Catalog row as it reads once the product is chosen, or undefined.
 * @return The shape, or null when there is nothing to draw (no way to connect, or no form).
 */
export function shapeOfEntry(
	entry: SystemEntry | undefined
): ConnectShape | null {
	const byMethod = shapeOf( entry?.connection_method );
	if (
		byMethod ||
		! entry ||
		entry.connection_method !== 'unknown' ||
		! entry.connector ||
		inputFields( entry.credential_fields ?? [] ).length === 0
	) {
		return byMethod;
	}
	switch ( entry.auth_style ) {
		case 'key':
			return 'key';
		case 'basic':
		case 'server_login':
			return 'server_login';
		case 'oauth_authorization_code':
			return 'own_app';
		case 'oauth_client_credentials':
		case 'oauth_jwt_bearer':
			return 'cloud_app';
		case 'export_file':
			return 'export_file';
		default:
			return null;
	}
}

/**
 * The way to connect of a product that holds several, when the catalog row does not say. These two
 * follow the connection-methods spec (Sage 50 is a desktop program, Sage 300 signs in on the
 * merchant's own web server); the catalog answer wins over this table.
 */
const VARIANT_METHOD: Record< string, ConnectionMethod > = {
	'sage-accounting': 'own_app',
	'sage50-export': 'export_file',
	sage200: 'own_app',
	sage300: 'server_login',
	sagex3: 'unknown',
};

/**
 * The products a row holds, when it holds several.
 *
 * @param entry Catalog row.
 * @return The products; empty for a row with one product.
 */
export function variantsOf( entry: SystemEntry ): CatalogVariant[] {
	return Array.isArray( entry.variants ) ? entry.variants : [];
}

/**
 * Whether the person can pick this system at all.
 *
 * @param entry Catalog row.
 * @return False for a row the vendor gives no way to connect to (SMACC) or one that is switched off.
 */
export function isSelectable( entry: SystemEntry ): boolean {
	return entry.available !== false && entry.connection_method !== 'none';
}

/**
 * The row as it reads once a product is chosen: the product's connector id and its own way to connect.
 *
 * @param entry   Catalog row.
 * @param variant Id of the chosen product (a connector id), or null.
 * @return The row to connect; the row itself when it holds one product.
 */
export function resolveSystem(
	entry: SystemEntry,
	variant: string | null | undefined
): SystemEntry {
	const chosen = variantsOf( entry ).find( ( item ) => item.id === variant );
	if ( ! chosen ) {
		return entry;
	}
	return {
		...entry,
		name: chosen.label,
		connector: chosen.id,
		connection_method:
			chosen.connection_method ??
			VARIANT_METHOD[ chosen.id ] ??
			entry.connection_method,
		needs: chosen.needs ?? entry.needs,
		steps: chosen.steps ?? entry.steps,
		hosts: chosen.hosts ?? entry.hosts,
		terms_url: chosen.terms_url ?? entry.terms_url,
		privacy_url: chosen.privacy_url ?? entry.privacy_url,
		developer_url: chosen.developer_url ?? entry.developer_url,
		credential_fields: chosen.credential_fields ?? entry.credential_fields,
		auth_style: chosen.auth_style ?? entry.auth_style,
		can_sign_in: chosen.can_sign_in ?? entry.can_sign_in,
	};
}

/**
 * Whether the person still has to say which product they use.
 *
 * @param entry   Catalog row, or null when nothing is chosen.
 * @param variant Id of the chosen product, or null.
 * @return True when the row holds several products and none is chosen.
 */
export function needsVariant(
	entry: SystemEntry | null | undefined,
	variant: string | null | undefined
): boolean {
	return (
		!! entry &&
		variantsOf( entry ).length > 0 &&
		! variantsOf( entry ).some( ( item ) => item.id === variant )
	);
}

/**
 * The redirect address a merchant registers in the vendor's app settings. The plugin shows the same
 * address for every connection (`estasoft-sync/v1/oauth/callback`, built from the REST address of
 * this site), so it can be registered once.
 *
 * @param boot Boot data.
 * @return The address.
 */
export function redirectAddress( boot: BootData ): string {
	return `${ boot.restUrl.replace( /\/+$/, '' ) }/oauth/callback`;
}

/**
 * Whether a field is only shown (the redirect address), never sent.
 *
 * @param field Field.
 * @return True for a `display` field.
 */
export function isDisplayField( field: ConnectorField ): boolean {
	return field.type === 'display';
}

/**
 * The kind of input a field is drawn as.
 *
 * @param field Field.
 * @return The input kind; `password` for a secret that does not say.
 */
export function fieldKind( field: ConnectorField ): CredentialFieldType {
	// A secret is never drawn as a plain text box, whatever the connector says: the browser would show it as it is typed.
	if ( field.type && ! ( field.secret && field.type === 'text' ) ) {
		return field.type;
	}
	return field.secret ? 'password' : 'text';
}

/**
 * The fields to type in: everything but the display fields.
 *
 * @param fields Fields of a connector.
 * @return The inputs.
 */
export function inputFields( fields: ConnectorField[] ): ConnectorField[] {
	return fields.filter( ( field ) => ! isDisplayField( field ) );
}

/**
 * The values a form starts with: each field's default, and the first option of a select that has none.
 *
 * @param fields Fields of a connector.
 * @return Values by field name.
 */
export function defaultValues(
	fields: ConnectorField[]
): Record< string, string > {
	const values: Record< string, string > = {};
	inputFields( fields ).forEach( ( field ) => {
		const options = Object.keys( field.options ?? {} );
		values[ field.name ] =
			field.default ||
			( fieldKind( field ) === 'select' && field.required
				? ( options[ 0 ] ?? '' )
				: '' );
	} );
	return values;
}

/**
 * Splits what was typed into the two parts the server stores differently: plain settings, and secrets
 * (encrypted, write-only). An empty secret is left out so it never replaces a saved one with nothing.
 *
 * @param fields Fields of a connector.
 * @param values Typed values by field name.
 * @return The `config` and `secrets` of a connection input.
 */
export function splitInput(
	fields: ConnectorField[],
	values: Record< string, string >
): { config: Record< string, string >; secrets: Record< string, string > } {
	const config: Record< string, string > = {};
	const secrets: Record< string, string > = {};
	inputFields( fields ).forEach( ( field ) => {
		const value = ( values[ field.name ] ?? '' ).trim();
		if ( field.secret ) {
			if ( value !== '' ) {
				secrets[ field.name ] = value;
			}
			return;
		}
		config[ field.name ] =
			fieldKind( field ) === 'textarea'
				? ( values[ field.name ] ?? '' )
				: value;
	} );
	return { config, secrets };
}

/**
 * Whether every required field has something in it.
 *
 * @param fields Fields of a connector.
 * @param values Typed values by field name.
 * @return True when nothing required is empty.
 */
export function hasRequired(
	fields: ConnectorField[],
	values: Record< string, string >
): boolean {
	return inputFields( fields ).every(
		( field ) => ! field.required || ( values[ field.name ] ?? '' ).trim()
	);
}

/**
 * Checks what can be checked before anything is sent: a web address must be a web address.
 *
 * @param fields Fields of a connector.
 * @param values Typed values by field name.
 * @return A message by field name, null for a field that is fine.
 */
export function validateValues(
	fields: ConnectorField[],
	values: Record< string, string >
): Record< string, string | null > {
	const errors: Record< string, string | null > = {};
	inputFields( fields ).forEach( ( field ) => {
		const value = ( values[ field.name ] ?? '' ).trim();
		errors[ field.name ] = null;
		if ( field.required && value === '' ) {
			errors[ field.name ] = sprintf(
				/* translators: %s: name of a field such as "Client ID" */
				__( '%s is needed.', 'estasoft-erp-crm-sync-engine' ),
				field.label
			);
			return;
		}
		if ( fieldKind( field ) === 'url' && value !== '' ) {
			let valid = false;
			try {
				const url = new URL( value );
				valid = url.protocol === 'https:' || url.protocol === 'http:';
			} catch {
				valid = false;
			}
			if ( ! valid ) {
				errors[ field.name ] = __(
					'Enter a web address that starts with https://.',
					'estasoft-erp-crm-sync-engine'
				);
			}
		}
	} );
	return errors;
}

/**
 * What the person calls the thing they paste: key, secret or password.
 *
 * @param fields Fields of a connector.
 * @return The word, in the person's language.
 */
export function secretWord( fields: ConnectorField[] ): string {
	const secrets = fields.filter( ( field ) => field.secret );
	if ( secrets.some( ( field ) => /password/i.test( field.name ) ) ) {
		return __( 'password', 'estasoft-erp-crm-sync-engine' );
	}
	if (
		secrets.some( ( field ) => /secret|private/i.test( field.name ) ) &&
		! secrets.some( ( field ) => /(^|_)(api_)?key$/i.test( field.name ) )
	) {
		return __( 'secret', 'estasoft-erp-crm-sync-engine' );
	}
	return __( 'key', 'estasoft-erp-crm-sync-engine' );
}

/**
 * Plain text of the steps for the "copy these steps for my administrator" button.
 *
 * @param system Name of the system.
 * @param steps  Steps as shown.
 * @return Numbered lines.
 */
export function stepsAsText( system: string, steps: string[] ): string {
	return [
		sprintf(
			/* translators: %s: system name */
			__(
				'Steps to connect %s to a WooCommerce store:',
				'estasoft-erp-crm-sync-engine'
			),
			system
		),
		...steps.map( ( step, index ) => `${ index + 1 }. ${ step }` ),
	].join( '\n' );
}

/* ---------------------------------------------------------------- the checks of a test */

export type CheckId =
	'reach' | 'certificate' | 'sign_in' | 'plan' | 'app' | 'permissions';

export type CheckState = 'passed' | 'failed' | 'not_tried';

export interface TestCheck {
	id: CheckId;
	state: CheckState;
	label: string;
}

const ORDER: CheckId[] = [
	'reach',
	'certificate',
	'sign_in',
	'plan',
	'app',
	'permissions',
];

/** The check each error code belongs to (connection-methods spec, section 2.1). */
const CODE_CHECK: Record< string, CheckId > = {
	'SYNC-NET-001': 'reach',
	'SYNC-NET-003': 'certificate',
	'SYNC-AUTH-001': 'sign_in',
	'SYNC-AUTH-005': 'sign_in',
	'SYNC-CFG-003': 'sign_in',
	'SYNC-AUTH-006': 'plan',
	'SYNC-AUTH-007': 'app',
	'SYNC-AUTH-004': 'permissions',
};

function checkLabel( id: CheckId, state: CheckState, system: string ): string {
	const labels: Record< CheckId, [ string, string, string ] > = {
		reach: [
			__( 'Reached the server', 'estasoft-erp-crm-sync-engine' ),
			__( 'Could not reach the server', 'estasoft-erp-crm-sync-engine' ),
			__( 'Server: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
		certificate: [
			__( 'Certificate trusted', 'estasoft-erp-crm-sync-engine' ),
			__( 'Certificate not trusted', 'estasoft-erp-crm-sync-engine' ),
			__( 'Certificate: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
		sign_in: [
			__( 'Signed in', 'estasoft-erp-crm-sync-engine' ),
			__( 'Sign-in refused', 'estasoft-erp-crm-sync-engine' ),
			__( 'Signed in: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
		plan: [
			__(
				'Plan allows outside programs',
				'estasoft-erp-crm-sync-engine'
			),
			__(
				'Plan does not allow outside programs',
				'estasoft-erp-crm-sync-engine'
			),
			__( 'Plan: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
		app: [
			sprintf(
				/* translators: %s: system name */
				__( '%s knows this app', 'estasoft-erp-crm-sync-engine' ),
				system
			),
			sprintf(
				/* translators: %s: system name */
				__(
					'%s does not know this app yet',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
			__( 'App: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
		permissions: [
			__(
				'Can read items and create orders',
				'estasoft-erp-crm-sync-engine'
			),
			__( 'Some access is missing', 'estasoft-erp-crm-sync-engine' ),
			__( 'Permissions: not tried', 'estasoft-erp-crm-sync-engine' ),
		],
	};
	const row = labels[ id ];
	return state === 'passed'
		? row[ 0 ]
		: state === 'failed'
			? row[ 1 ]
			: row[ 2 ];
}

/**
 * The checks a test runs, in order, each passed, failed or not tried, worked out from the answer.
 * The order is fixed by the connection-methods spec; the test stops at the first failure.
 *
 * @param shape     Way to connect (the app check only applies to a cloud app).
 * @param result    The test answer, or null.
 * @param errorCode The code of a failure that came as an error, not as a result.
 * @param system    Name of the system.
 * @return The checks, or an empty list when there is nothing to show yet.
 */
export function testChecks(
	shape: ConnectShape,
	result: ConnectionTestResult | null,
	errorCode: string,
	system: string
): TestCheck[] {
	const code =
		result && ! result.ok ? ( result.error?.code ?? '' ) : errorCode;
	const failedAt = CODE_CHECK[ code ];
	if ( ! result && ! failedAt ) {
		return [];
	}
	if ( result && ! result.ok && ! failedAt && code === '' ) {
		return [];
	}
	const partial = Boolean(
		result && result.ok && result.missing_permissions.length > 0
	);
	const ids = ORDER.filter( ( id ) => id !== 'app' || shape === 'cloud_app' );
	const stopAt = failedAt ?? ( partial ? 'permissions' : null );
	let passed = true;
	return ids.map( ( id ) => {
		let state: CheckState;
		if ( id === stopAt ) {
			state = 'failed';
			passed = false;
		} else {
			state = passed ? 'passed' : 'not_tried';
		}
		return { id, state, label: checkLabel( id, state, system ) };
	} );
}

/* ---------------------------------------------------------------- the way back from a vendor's sign-in page */

/**
 * What the address says after the vendor's sign-in page sent the browser back: `signin=ok`,
 * `cancelled` or `failed`, and which connection it was (`connection=` or the id in the route).
 *
 * @param params   Query of the route.
 * @param segments Path segments of the route.
 * @return The result and connection id, each null when the address does not say.
 */
export function readSignIn(
	params: URLSearchParams,
	segments: string[]
): { result: SignInResult | null; connectionId: number | null } {
	const value = params.get( 'signin' );
	const result =
		value === 'ok' || value === 'cancelled' || value === 'failed'
			? value
			: null;
	const fromQuery = Number( params.get( 'connection' ) );
	const fromRoute =
		segments[ 0 ] === 'connections' ? Number( segments[ 1 ] ) : NaN;
	const id = [ fromQuery, fromRoute ].find(
		( candidate ) => Number.isInteger( candidate ) && candidate > 0
	);
	return { result, connectionId: id ?? null };
}

/**
 * Finds the row (and product) a connector id belongs to, for a screen that starts from a connection.
 *
 * @param catalog     The catalog.
 * @param connectorId Connector id of a connection.
 * @return The row as it reads for that product, or undefined when the catalog does not know it.
 */
export function entryForConnector(
	catalog: SystemEntry[] | undefined,
	connectorId: string
): SystemEntry | undefined {
	for ( const row of catalog ?? [] ) {
		if ( row.connector === connectorId ) {
			return row;
		}
		const variant = variantsOf( row ).find(
			( item ) => item.id === connectorId
		);
		if ( variant ) {
			return resolveSystem( row, variant.id );
		}
	}
	return undefined;
}

/**
 * Whether a connection is signed in and usable: not waiting for a sign-in and not removed.
 *
 * @param connection A connection, or null.
 * @return True when orders can be sent through it.
 */
export function isConnectedNow(
	connection: { status: string } | null | undefined
): boolean {
	return (
		!! connection &&
		connection.status !== 'needs_reconnect' &&
		connection.status !== 'disconnected'
	);
}
