import { useEffect, useState } from '@wordpress/element';

/**
 * Returns `value` after it has stopped changing for `delay` milliseconds.
 *
 * @param value Fast-changing value such as a search box.
 * @param delay Delay in milliseconds.
 * @return The settled value.
 */
export function useDebouncedValue< T >( value: T, delay = 300 ): T {
	const [ settled, setSettled ] = useState( value );
	useEffect( () => {
		const id = setTimeout( () => setSettled( value ), delay );
		return () => clearTimeout( id );
	}, [ value, delay ] );
	return settled;
}
