import type { Money as MoneyValue } from '../api/types';
import { formatMoney } from '../lib/format';
import { useFormat } from './FormatContext';

/**
 * An amount from integer minor units, for example `AED 438.00`. Never uses floats for storage;
 * the division only happens for display.
 *
 * @param props       Amount.
 * @param props.value
 * @return The text.
 */
export function Money( { value }: { value: MoneyValue | null | undefined } ) {
	const cfg = useFormat();
	return (
		<span className="estasoft-sync-money">
			{ formatMoney( value, cfg ) }
		</span>
	);
}
