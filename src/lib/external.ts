/**
 * Leaving the admin page for a vendor's sign-in page. One function, so a test can see where the
 * browser would go without leaving the test page.
 */
import { safeHttpUrl } from './url';

type Leaver = ( url: string ) => void;

const defaultLeaver: Leaver = ( url ) => {
	window.location.assign( url );
};

let leaver: Leaver = defaultLeaver;

/**
 * Replaces the way the page is left (tests).
 *
 * @param next New function, or null to restore the browser's own.
 */
export function setLeaver( next: Leaver | null ): void {
	leaver = next ?? defaultLeaver;
}

/**
 * Sends the browser to an address that came from the server, after checking that it is a web address.
 *
 * @param url Address of the vendor's sign-in page.
 * @return False when the address is not a web address and nothing happened.
 */
export function leaveTo( url: string ): boolean {
	const safe = safeHttpUrl( url );
	if ( ! safe ) {
		return false;
	}
	leaver( safe );
	return true;
}
