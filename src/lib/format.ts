/**
 * Formatting for money, times, durations and counts. Pure functions; the components read the
 * configuration (locale, store time zone, currency) from the boot data through FormatContext.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Money } from '../api/types';

export interface FormatConfig {
	/** WordPress locale such as `en_US` or `ar`. */
	locale: string;
	/** IANA zone used to show times. */
	timezone: string;
	/** Store currency (used when a value carries none). */
	currency: string;
	/**
	 * Minor-unit exponent of the store currency, from the boot data. It wins over the table for
	 * that currency, because the server decides how many places an amount has.
	 */
	currencyDecimals?: number;
}

/**
 * Turns a WordPress locale (`de_DE_formal`) into a BCP 47 tag (`de-DE`).
 *
 * @param locale WordPress locale.
 * @return Tag for Intl.
 */
export function toLocaleTag( locale: string ): string {
	const parts = locale.replace( /-/g, '_' ).split( '_' ).filter( Boolean );
	if ( parts.length === 0 ) {
		return 'en-US';
	}
	if ( parts.length === 1 ) {
		return parts[ 0 ];
	}
	return `${ parts[ 0 ] }-${ parts[ 1 ] }`;
}

const cache = new Map< string, Intl.DateTimeFormat | Intl.NumberFormat >();

function dateFormatter(
	tag: string,
	zone: string,
	options: Intl.DateTimeFormatOptions
): Intl.DateTimeFormat {
	const key = `d|${ tag }|${ zone }|${ JSON.stringify( options ) }`;
	let formatter = cache.get( key ) as Intl.DateTimeFormat | undefined;
	if ( ! formatter ) {
		try {
			formatter = new Intl.DateTimeFormat( tag, {
				...options,
				timeZone: zone,
			} );
		} catch {
			formatter = new Intl.DateTimeFormat( 'en-US', {
				...options,
				timeZone: 'UTC',
			} );
		}
		cache.set( key, formatter );
	}
	return formatter;
}

/**
 * Clock time such as `11:40` in the configured zone.
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @return Text, or an em dash for empty input.
 */
export function formatTime(
	iso: string | null | undefined,
	cfg: FormatConfig
): string {
	if ( ! iso ) {
		return '—';
	}
	return dateFormatter( toLocaleTag( cfg.locale ), cfg.timezone, {
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
	} ).format( new Date( iso ) );
}

/**
 * Clock time with seconds, `11:40:12`.
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @return Text.
 */
export function formatTimeSeconds(
	iso: string | null | undefined,
	cfg: FormatConfig
): string {
	if ( ! iso ) {
		return '—';
	}
	return dateFormatter( toLocaleTag( cfg.locale ), cfg.timezone, {
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	} ).format( new Date( iso ) );
}

/**
 * Day and month, `Mon 2 Nov`, with the year when it is not the year of `now`.
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @param now Reference time (ISO), defaults to the current time.
 * @return Text.
 */
export function formatDate(
	iso: string | null | undefined,
	cfg: FormatConfig,
	now?: string
): string {
	if ( ! iso ) {
		return '—';
	}
	const tag = toLocaleTag( cfg.locale );
	const date = new Date( iso );
	const reference = now ? new Date( now ) : new Date();
	const year = ( d: Date ) =>
		dateFormatter( tag, cfg.timezone, { year: 'numeric' } ).format( d );
	const withYear = year( date ) !== year( reference );
	const weekday = dateFormatter( tag, cfg.timezone, {
		weekday: 'short',
	} ).format( date );
	const rest = dateFormatter( tag, cfg.timezone, {
		day: 'numeric',
		month: 'short',
		...( withYear ? { year: 'numeric' } : {} ),
	} ).format( date );
	return `${ weekday } ${ rest }`;
}

/**
 * A calendar day with the year, `14 Nov 2026`.
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @return Text.
 */
export function formatDay(
	iso: string | null | undefined,
	cfg: FormatConfig
): string {
	if ( ! iso ) {
		return '\u2014';
	}
	return dateFormatter( toLocaleTag( cfg.locale ), cfg.timezone, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} ).format( new Date( iso ) );
}

/**
 * Full date for page headers, `Mon 2 Nov 2026, 11:40`.
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @return Text.
 */
export function formatFullDateTime(
	iso: string | null | undefined,
	cfg: FormatConfig
): string {
	if ( ! iso ) {
		return '\u2014';
	}
	const tag = toLocaleTag( cfg.locale );
	const date = new Date( iso );
	const weekday = dateFormatter( tag, cfg.timezone, {
		weekday: 'short',
	} ).format( date );
	const rest = dateFormatter( tag, cfg.timezone, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} ).format( date );
	return `${ weekday } ${ rest }, ${ formatTime( iso, cfg ) }`;
}

/**
 * A time that says "Today 11:40", "Yesterday 18:00" or "31 Oct 18:00".
 *
 * @param iso ISO 8601 time (UTC).
 * @param cfg Format configuration.
 * @param now Reference time (ISO).
 * @return Text.
 */
export function formatSmartTime(
	iso: string | null | undefined,
	cfg: FormatConfig,
	now: string
): string {
	if ( ! iso ) {
		return '—';
	}
	const tag = toLocaleTag( cfg.locale );
	const dayKey = ( value: string | Date ) =>
		dateFormatter( tag, cfg.timezone, {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		} ).format( typeof value === 'string' ? new Date( value ) : value );
	const time = formatTime( iso, cfg );
	if ( dayKey( iso ) === dayKey( now ) ) {
		return sprintf(
			/* translators: %s: clock time such as 11:40 */
			__( 'Today %s', 'estasoft-erp-crm-sync-engine' ),
			time
		);
	}
	const yesterday = new Date( new Date( now ).getTime() - 86400000 );
	if ( dayKey( iso ) === dayKey( yesterday ) ) {
		return sprintf(
			/* translators: %s: clock time such as 18:00 */
			__( 'Yesterday %s', 'estasoft-erp-crm-sync-engine' ),
			time
		);
	}
	const short = dateFormatter( tag, cfg.timezone, {
		day: 'numeric',
		month: 'short',
	} ).format( new Date( iso ) );
	return `${ short } ${ time }`;
}

/**
 * Currencies whose minor unit is not 1/100. A copy of `EXPONENTS` in
 * `includes/Core/Support/Money.php`; keep the two in step. Intl is not used for this: it says 0
 * for IQD while the server stores 3 places.
 */
const EXPONENTS: Readonly< Record< string, number > > = {
	KWD: 3,
	BHD: 3,
	OMR: 3,
	JOD: 3,
	TND: 3,
	LYD: 3,
	IQD: 3,
	JPY: 0,
	KRW: 0,
	VND: 0,
	CLP: 0,
	ISK: 0,
	UGX: 0,
	PYG: 0,
	RWF: 0,
	KMF: 0,
	GNF: 0,
	DJF: 0,
	BIF: 0,
	VUV: 0,
	XAF: 0,
	XOF: 0,
	XPF: 0,
};

/**
 * Number of decimal places in the minor unit of a currency (2 for AED, 3 for KWD, 0 for JPY).
 * The store currency uses the exponent the server sent in the boot data; other currencies use
 * the same table as the server.
 *
 * @param currency ISO 4217 code.
 * @param cfg      Format configuration, for the store currency and its exponent.
 * @return Digits.
 */
export function currencyExponent(
	currency: string,
	cfg?: Pick< FormatConfig, 'currency' | 'currencyDecimals' >
): number {
	const code = currency.toUpperCase();
	if (
		cfg &&
		cfg.currencyDecimals !== undefined &&
		cfg.currency.toUpperCase() === code
	) {
		return cfg.currencyDecimals;
	}
	return EXPONENTS[ code ] ?? 2;
}

/**
 * Money from integer minor units, `AED 438.00`.
 *
 * @param money Amount in minor units and currency code.
 * @param cfg   Format configuration.
 * @return Text.
 */
export function formatMoney(
	money: Money | null | undefined,
	cfg: FormatConfig
): string {
	if ( ! money ) {
		return '—';
	}
	const currency = money.currency || cfg.currency;
	const digits = currencyExponent( currency, cfg );
	const major = money.minor / Math.pow( 10, digits );
	const key = `n|${ cfg.locale }|${ currency }|${ digits }`;
	let formatter = cache.get( key ) as Intl.NumberFormat | undefined;
	if ( ! formatter ) {
		try {
			formatter = new Intl.NumberFormat( toLocaleTag( cfg.locale ), {
				style: 'currency',
				currency,
				currencyDisplay: 'code',
				minimumFractionDigits: digits,
				maximumFractionDigits: digits,
			} );
		} catch {
			formatter = new Intl.NumberFormat( 'en-US' );
		}
		cache.set( key, formatter );
	}
	return formatter.format( major );
}

/**
 * Plain number with the locale's separators.
 *
 * @param value  Number.
 * @param locale WordPress locale.
 * @return Text.
 */
export function formatNumber( value: number, locale: string ): string {
	return new Intl.NumberFormat( toLocaleTag( locale ) ).format( value );
}

/**
 * Length of a request, `840 ms`, `1.1 s`, `30 s`.
 *
 * @param ms Milliseconds.
 * @return Text.
 */
export function formatDuration( ms: number | null | undefined ): string {
	if ( ms === null || ms === undefined ) {
		return '—';
	}
	if ( ms < 1000 ) {
		return sprintf(
			/* translators: %d: milliseconds */
			__( '%d ms', 'estasoft-erp-crm-sync-engine' ),
			Math.round( ms )
		);
	}
	const seconds = ms / 1000;
	return sprintf(
		/* translators: %s: seconds, for example 1.1 */
		__( '%s s', 'estasoft-erp-crm-sync-engine' ),
		seconds >= 10 ? String( Math.round( seconds ) ) : seconds.toFixed( 1 )
	);
}

/**
 * A span of time in words, `10 min`, `2 h 5 min`, `35 min`.
 *
 * @param seconds Seconds (absolute value is used).
 * @return Text.
 */
export function formatSpan( seconds: number ): string {
	const total = Math.max( 0, Math.round( Math.abs( seconds ) ) );
	if ( total < 60 ) {
		return sprintf(
			/* translators: %d: seconds */
			_n(
				'%d second',
				'%d seconds',
				total,
				'estasoft-erp-crm-sync-engine'
			),
			total
		);
	}
	const minutes = Math.floor( total / 60 );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: minutes */
			__( '%d min', 'estasoft-erp-crm-sync-engine' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;
	if ( hours >= 48 ) {
		return sprintf(
			/* translators: %d: days */
			_n(
				'%d day',
				'%d days',
				Math.floor( hours / 24 ),
				'estasoft-erp-crm-sync-engine'
			),
			Math.floor( hours / 24 )
		);
	}
	if ( rest === 0 ) {
		/* translators: %d: hours */
		return sprintf( __( '%d h', 'estasoft-erp-crm-sync-engine' ), hours );
	}
	return sprintf(
		/* translators: 1: hours, 2: minutes */
		__( '%1$d h %2$d min', 'estasoft-erp-crm-sync-engine' ),
		hours,
		rest
	);
}

/**
 * Time left to report an invoice: `5 h 40 min left`, or `Late by 2 h`.
 *
 * @param seconds Seconds left; negative when late.
 * @return Text.
 */
export function formatTimeLeft( seconds: number ): string {
	if ( seconds < 0 ) {
		return sprintf(
			/* translators: %s: span such as 2 h */
			__( 'Late by %s', 'estasoft-erp-crm-sync-engine' ),
			formatSpan( seconds )
		);
	}
	return sprintf(
		/* translators: %s: span such as 5 h 40 min */
		__( '%s left', 'estasoft-erp-crm-sync-engine' ),
		formatSpan( seconds )
	);
}

/**
 * Tone for the invoice countdown: neutral above 12 h, warning from 3 to 12 h, danger under 3 h.
 *
 * @param seconds Seconds left; negative when late.
 * @return Tone.
 */
export function timeLeftTone(
	seconds: number
): 'neutral' | 'warning' | 'danger' {
	if ( seconds < 3 * 3600 ) {
		return 'danger';
	}
	if ( seconds <= 12 * 3600 ) {
		return 'warning';
	}
	return 'neutral';
}

/**
 * Formats a byte count for the log detail note, `2 KB`.
 *
 * @param bytes Bytes.
 * @return Text.
 */
export function formatKilobytes( bytes: number ): string {
	return sprintf(
		/* translators: %d: kilobytes */
		__( '%d KB', 'estasoft-erp-crm-sync-engine' ),
		Math.round( bytes / 1024 )
	);
}
