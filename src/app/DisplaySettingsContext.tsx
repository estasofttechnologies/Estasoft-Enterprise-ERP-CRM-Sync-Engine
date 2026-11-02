import { createContext, useContext } from '@wordpress/element';
import type { Settings } from '../api/types';

export interface DisplaySettingsApi {
	/** The saved settings, once loaded (managers can read them, only administrators change them). */
	settings: Settings | null;
	/** Loads the settings again after they were saved. */
	refresh: () => void;
}

export const DisplaySettingsContext = createContext< DisplaySettingsApi >( {
	settings: null,
	refresh: () => undefined,
} );

/**
 * @return The loaded settings and a function that makes the frame pick up changed ones.
 */
export function useDisplaySettings(): DisplaySettingsApi {
	return useContext( DisplaySettingsContext );
}
