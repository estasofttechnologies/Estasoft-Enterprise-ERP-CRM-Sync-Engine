import { __ } from '@wordpress/i18n';
import { useFormat } from './FormatContext';
import {
	formatDate,
	formatSmartTime,
	formatTime,
	formatTimeSeconds,
} from '../lib/format';
import { useNow } from '../app/NowContext';

export interface DateTimeProps {
	value: string | null | undefined;
	/** `time` (11:40), `seconds` (11:40:12), `date` (Mon 2 Nov), `smart` (Today 11:40). */
	kind?: 'time' | 'seconds' | 'date' | 'smart';
}

/**
 * A time in the store's time zone (or UTC, per the setting), as a `<time>` element with the
 * original value for assistive technology and copy-paste.
 *
 * @param props       Value and format.
 * @param props.value
 * @param props.kind
 * @return The element.
 */
export function DateTime( { value, kind = 'time' }: DateTimeProps ) {
	const cfg = useFormat();
	const now = useNow();
	if ( ! value ) {
		return (
			<span
				aria-label={ __(
					'Not available',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				{ '—' }
			</span>
		);
	}
	let text: string;
	switch ( kind ) {
		case 'seconds':
			text = formatTimeSeconds( value, cfg );
			break;
		case 'date':
			text = formatDate( value, cfg, now );
			break;
		case 'smart':
			text = formatSmartTime( value, cfg, now );
			break;
		default:
			text = formatTime( value, cfg );
	}
	return <time dateTime={ value }>{ text }</time>;
}
