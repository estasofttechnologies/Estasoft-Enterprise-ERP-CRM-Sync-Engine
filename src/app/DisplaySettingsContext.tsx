import { createContext, useContext } from '@wordpress/element';

export interface DisplaySettingsApi {
	/** Loads the display settings (time zone choice) again after they were saved. */
	refresh: () => void;
}

export const DisplaySettingsContext = createContext< DisplaySettingsApi >( {
	refresh: () => undefined,
} );

/**
 * @return A function that makes the frame pick up changed display settings.
 */
export function useDisplaySettings(): DisplaySettingsApi {
	return useContext( DisplaySettingsContext );
}
