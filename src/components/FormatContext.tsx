/**
 * Provides the formatting configuration (locale, display time zone, currency) to the components.
 */
import { createContext, useContext } from '@wordpress/element';
import type { FormatConfig } from '../lib/format';

export const FormatContext = createContext< FormatConfig >( {
	locale: 'en_US',
	timezone: 'UTC',
	currency: 'USD',
} );

/**
 * @return The current format configuration.
 */
export function useFormat(): FormatConfig {
	return useContext( FormatContext );
}
