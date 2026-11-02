/**
 * Returns a web address only when it is safe to put in a link: `http:` or `https:`.
 * Addresses from a connected system (`external_url`, `pdf_url`) are data from outside; a
 * `javascript:` or `data:` address must never reach an `href`.
 *
 * @param value An address, absolute or relative to this page.
 * @return The address as a string, or null when it is empty, unreadable or another scheme.
 */
export function safeHttpUrl( value: string | null | undefined ): string | null {
	if ( ! value ) {
		return null;
	}
	try {
		const base =
			typeof window !== 'undefined' && window.location
				? window.location.href
				: 'https://localhost/';
		const url = new URL( value.trim(), base );
		return url.protocol === 'http:' || url.protocol === 'https:'
			? url.href
			: null;
	} catch {
		return null;
	}
}
