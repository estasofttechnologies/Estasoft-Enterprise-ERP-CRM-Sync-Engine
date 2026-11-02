import { createContext, useContext } from '@wordpress/element';
import type { BootData } from '../api/types';

export interface AppContextValue {
	boot: BootData;
	/** May view and operate (retry, skip, pause). */
	canManage: boolean;
	/** May configure (connections, mapping, settings, tools). */
	canConfigure: boolean;
}

export const AppContext = createContext< AppContextValue | null >( null );

/**
 * @return Boot data and the person's capabilities.
 * @throws {Error} When used outside the provider.
 */
export function useApp(): AppContextValue {
	const value = useContext( AppContext );
	if ( ! value ) {
		throw new Error( 'useApp() needs <AppContext.Provider>.' );
	}
	return value;
}
