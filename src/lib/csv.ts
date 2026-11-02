/**
 * A safe CSV writer. Every cell is quoted when it needs to be (comma, quote, line break), and a
 * text cell that a spreadsheet could run as a formula (it starts with `=`, `+`, `-`, `@`, a tab
 * or a carriage return) gets a leading apostrophe, as the server does for the log download.
 */

export type CsvCell = string | number | boolean | null | undefined;

const FORMULA_START = /^[=+\-@\t\r]/;

/**
 * Makes one cell safe.
 *
 * @param value Cell value. Numbers are written as they are, text is guarded.
 * @return The cell as it goes into the file.
 */
export function csvCell( value: CsvCell ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? String( value ) : '';
	}
	if ( typeof value === 'boolean' ) {
		return value ? 'true' : 'false';
	}
	const guarded = FORMULA_START.test( value ) ? `'${ value }` : value;
	return /[",\r\n]/.test( guarded )
		? `"${ guarded.replace( /"/g, '""' ) }"`
		: guarded;
}

/**
 * Writes rows as CSV with CRLF line ends (RFC 4180).
 *
 * @param rows Rows of cells. The first row is usually the header.
 * @return The file text.
 */
export function toCsv( rows: CsvCell[][] ): string {
	return rows.map( ( row ) => row.map( csvCell ).join( ',' ) ).join( '\r\n' );
}
