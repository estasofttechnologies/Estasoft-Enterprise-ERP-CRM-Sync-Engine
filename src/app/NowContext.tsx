/**
 * The server's idea of "now" (from GET /status), so "Today" and "Yesterday" follow the store's
 * clock and not the browser's.
 */
import { createContext, useContext } from '@wordpress/element';

export const NowContext = createContext< string >( new Date().toISOString() );

/**
 * @return Current server time as ISO 8601.
 */
export function useNow(): string {
	return useContext( NowContext );
}
