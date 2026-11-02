/**
 * Saves text as a file in the browser. Nothing leaves the page: the file is made from a Blob.
 *
 * @param filename Name of the file.
 * @param text     Content.
 * @param mime     MIME type.
 */
export function downloadText(
	filename: string,
	text: string,
	mime = 'text/plain'
): void {
	const blob = new Blob( [ text ], { type: `${ mime };charset=utf-8` } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.rel = 'noopener';
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
