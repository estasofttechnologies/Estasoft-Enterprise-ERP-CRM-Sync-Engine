/**
 * Reads a dotted path such as `billing.first_name` or `lines[0].sku` from nested data.
 *
 * @param data Object to read from.
 * @param path Dotted path with optional `[n]` indexes.
 * @return The value, or undefined.
 */
export function getPath( data: unknown, path: string ): unknown {
	const parts = path
		.replace( /\[(\d+)\]/g, '.$1' )
		.split( '.' )
		.filter( Boolean );
	let current: unknown = data;
	for ( const part of parts ) {
		if ( current === null || typeof current !== 'object' ) {
			return undefined;
		}
		current = ( current as Record< string, unknown > )[ part ];
	}
	return current;
}

/**
 * @param value Any value.
 * @return The value as text, or an empty string for null and undefined.
 */
export function asText( value: unknown ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}
