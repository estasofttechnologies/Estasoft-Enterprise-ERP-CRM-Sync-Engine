/**
 * Boot data: the `window.estasoftSync` object that PHP prints with wp_add_inline_script.
 */
import type { BootData } from './types';

declare global {
	interface Window {
		estasoftSync?: Partial< BootData >;
		wpApiSettings?: { root?: string; nonce?: string };
	}
}

const NAMESPACE_PATH = 'estasoft-sync/v1/';

/**
 * Builds a complete BootData object, filling gaps with safe defaults.
 *
 * The app also works when only WordPress' own `wpApiSettings` is present (for example behind a
 * wp.apiFetch middleware): the REST address is then derived from it.
 *
 * @param source Raw object from the page, if any.
 * @param wpApi  WordPress' own REST settings, if any.
 * @return A complete boot object.
 */
export function normaliseBootData(
	source: Partial< BootData > | undefined,
	wpApi: Window[ 'wpApiSettings' ] | undefined
): BootData {
	const root = wpApi?.root ?? '/wp-json/';
	return {
		restUrl:
			source?.restUrl ??
			`${ root.replace( /\/?$/, '/' ) }${ NAMESPACE_PATH }`,
		nonce: source?.nonce ?? wpApi?.nonce ?? '',
		adminUrl: source?.adminUrl ?? '/wp-admin/',
		pluginUrl: source?.pluginUrl ?? '',
		version: source?.version ?? '',
		// Least privilege when the page gives no answer. The server still checks every route.
		capabilities: source?.capabilities ?? {
			manage: true,
			configure: false,
		},
		storeTimezone: source?.storeTimezone ?? 'UTC',
		currency: source?.currency ?? 'USD',
		currencyDecimals: source?.currencyDecimals ?? 2,
		locale: source?.locale ?? 'en_US',
		wpAdminColorScheme: source?.wpAdminColorScheme ?? 'fresh',
		routes: source?.routes ?? [],
		notices: source?.notices ?? [],
		encryptionKeyLine: source?.encryptionKeyLine ?? null,
	};
}

/**
 * Reads the boot data from the page.
 *
 * @return A complete boot object.
 */
export function readBootData(): BootData {
	return normaliseBootData( window.estasoftSync, window.wpApiSettings );
}
