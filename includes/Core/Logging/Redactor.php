<?php
/**
 * Removes secrets and personal data from text and payloads before they reach the log.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Logging;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;

/**
 * Two layers. Secrets (tokens, passwords, Authorization headers, API keys) are always removed, even when
 * the person turned redaction off. Personal data (emails, phone numbers, tax numbers, address-like text, and
 * every field whose name marks it as personal) is removed while the setting "Remove names, emails and addresses"
 * is on. Fields are found by name first (names taken from the mapping's source paths plus a built-in list), and
 * a pattern pass catches what is left inside free text. Add-ons can extend both through the filter
 * `estasoft_sync_log_redact`.
 */
final class Redactor {

	/**
	 * Placeholder for a removed secret.
	 */
	public const SECRET = '[secret]';

	/**
	 * Placeholder for removed personal data.
	 */
	public const PERSONAL = '****';

	/**
	 * Field names (lower case) whose value is a secret.
	 *
	 * @var array<int,string>
	 */
	private const SECRET_KEYS = array(
		'password',
		'passwd',
		'secret',
		'client_secret',
		'api_key',
		'apikey',
		'x-api-key',
		'access_token',
		'refresh_token',
		'id_token',
		'token',
		'authorization',
		'cookie',
		'set-cookie',
		'private_key',
		'privatekey',
		'bearer',
		'signature',
		'hmac',
		'card_number',
		'cvv',
		'cvc',
	);

	/**
	 * Field names (lower case) whose value is personal data.
	 *
	 * @var array<int,string>
	 */
	private const PERSONAL_KEYS = array(
		'email',
		'e_mail',
		'email_address',
		'phone',
		'phone_number',
		'mobile',
		'mobile_number',
		'contact_number',
		'telephone',
		'telephone_number',
		'tel',
		'cell',
		'whatsapp',
		'fax',
		'name',
		'nickname',
		'username',
		'user_name',
		'user_login',
		'recipient',
		'recipient_name',
		'receiver',
		'sender',
		'ship_to',
		'bill_to',
		'attention',
		'contact_person',
		'cardholder',
		'holder_name',
		'date_of_birth',
		'birth_date',
		'birthday',
		'dob',
		'ip_address',
		'customer_ip',
		'iban',
		'bank_account',
		'account_number',
		'national_id',
		'passport',
		'passport_number',
		'ssn',
		'first_name',
		'last_name',
		'firstname',
		'lastname',
		'full_name',
		'fullname',
		'display_name',
		'customer',
		'customer_name',
		'partner',
		'partner_name',
		'partner_id',
		'billing',
		'shipping',
		'contact',
		'contact_name',
		'buyer',
		'company',
		'company_name',
		'street',
		'street1',
		'street2',
		'address',
		'address_1',
		'address_2',
		'addr',
		'city',
		'zip',
		'postcode',
		'postal_code',
		'vat',
		'vat_number',
		'tax_number',
		'tax_id',
		'buyer_tax_number',
		'trn',
		'note',
		'customer_note',
		'comment',
	);

	/**
	 * Redact one string.
	 *
	 * @param string $text            Any text.
	 * @param bool   $redactPersonal  Whether personal data is removed too.
	 * @return string
	 */
	public function message( string $text, bool $redactPersonal = true ): string {
		$text = $this->secrets( $text );
		if ( $redactPersonal ) {
			$text = $this->personal( $text );
		}
		/**
		 * Filter a log message after the built-in redaction.
		 *
		 * @param string              $text    The redacted text.
		 * @param array<string,bool>  $context personal: whether personal-data redaction is on.
		 */
		return (string) apply_filters( 'estasoft_sync_log_redact', $text, array( 'personal' => $redactPersonal ) );
	}

	/**
	 * Remove secrets from text: Authorization headers, bearer tokens, JWTs, key=value pairs for secret names,
	 * well-known key formats, and long opaque tokens.
	 *
	 * @param string $text Any text.
	 */
	public function secrets( string $text ): string {
		$names = implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '/' ), self::SECRET_KEYS ) );

		// An Authorization header or field: the whole value, whatever scheme it uses.
		$text = (string) preg_replace( '/(authorization["\']?\s*[:=]\s*)(?!\[secret\])(?:"[^"]*"|\'[^\']*\'|(?:(?:bearer|basic|digest|token)\s+)?[^\s,;}"\']+)/i', '$1' . self::SECRET, $text );
		// key=value and "key": "value" for secret names.
		$text = (string) preg_replace( '/((?<![A-Za-z0-9])["\']?(?:' . $names . ')["\']?\s*[:=]\s*)(?!"?\[secret\])("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s,;&}\]]+)/i', '$1"' . self::SECRET . '"', $text );
		// Credentials inside an address: scheme://user:password@host. The whole user information part goes.
		$text = (string) preg_replace( '/\b([a-z][a-z0-9+.\-]*:\/\/)[^\s\/@"\'<>]*:[^\s\/@"\'<>]*@/i', '$1' . self::SECRET . '@', $text );
		// Bearer and Basic credentials anywhere.
		$text = (string) preg_replace( '/\b(bearer|basic)\s+[A-Za-z0-9\-._~+\/]{6,}=*/i', '$1 ' . self::SECRET, $text );
		// JSON web tokens.
		$text = (string) preg_replace( '/\beyJ[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]*/', self::SECRET, $text );
		// Well-known key formats.
		$text = (string) preg_replace( '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}\b|\bgh[pousr]_[A-Za-z0-9]{20,}\b|\bxox[abprs]-[A-Za-z0-9\-]{10,}\b|\bAKIA[0-9A-Z]{16}\b/', self::SECRET, $text );
		// Long opaque tokens: 32 or more letters, digits, dashes and underscores, with at least one letter and one digit.
		$text = (string) preg_replace_callback(
			'/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{32,}(?![A-Za-z0-9_\-])/',
			static fn( array $match ): string => ( 1 === preg_match( '/[A-Za-z]/', $match[0] ) && 1 === preg_match( '/\d/', $match[0] ) ) ? self::SECRET : $match[0],
			$text
		);
		return $text;
	}

	/**
	 * Remove personal data from free text: emails, tax numbers, phone numbers and address-like text.
	 *
	 * A bare run of 9 to 15 digits (separators allowed) is taken for a phone number such as `971501234567` or
	 * `(212) 555-0199`, unless it looks like something else: money (a decimal tail or a currency next to it), a date, an
	 * IP address, an id or total named by the words before it, or a JSON number. Short numbers (order ids, 6 digit tax
	 * values) and codes with letters in them (`IT00123456`) are never taken for phones.
	 *
	 * @param string $text       Any text.
	 * @param bool   $barePhones Whether the pattern for phone numbers without a plus or leading zero is applied.
	 */
	public function personal( string $text, bool $barePhones = true ): string {
		// Emails.
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', '[email]', $text );

		// Tax numbers: labelled values, 15 digit numbers (Saudi Arabia, UAE), and European VAT numbers.
		// A bare "tax" followed by a JSON number ("tax":123456) is an amount, not a tax number.
		$text = (string) preg_replace( '/\b((?i:vat|tax(?:[ _\-]?(?:number|id|no\.?))?|trn|tin|gst|abn|ein))\b(?!(?<=[Tt][Aa][Xx])"\s*:\s*\d)([\s:#=\-"\']{0,6})([A-Z]{0,3} ?\d(?:[ \-.]?\d){5,17}[A-Z]?)(?![\w])/', '$1$2[tax number]', $text );
		$text = (string) preg_replace( '/(?<![\d.])\d{15}(?![\d])/', '[tax number]', $text );
		// The body after the country code is 8 characters only in a few countries; elsewhere it is 9 to 12, so a short code
		// such as IT00123456 (not a valid Italian number, which has 11 digits) is left alone.
		$text = (string) preg_replace( '/\b(?:(?:DK|FI|HU|LU|MT|SI|IE|CZ)(?=[A-Z0-9]*\d{6})[A-Z0-9]{8,12}|(?:AT|BE|BG|CY|DE|EE|EL|ES|FR|HR|IT|LT|LV|NL|PL|PT|RO|SE|SK|GB|XI)(?=[A-Z0-9]*\d{6})[A-Z0-9]{9,12})\b/', '[tax number]', $text );

		// Phone numbers: international with a plus or 00, then local numbers with separators or a leading zero.
		$text = (string) preg_replace( '/(?<![\w])\+\d[\d\s().\-]{7,17}\d/', '[phone]', $text );
		$text = (string) preg_replace( '/(?<![\w])00\d{1,3}[\d\s().\-]{7,15}\d/', '[phone]', $text );
		$text = (string) preg_replace( '/(?<![\w.\-:])\(?0\d{1,4}\)?[\s.\-]\d{3,4}[\s.\-]?\d{3,4}(?![\w])/', '[phone]', $text );
		$text = (string) preg_replace( '/(?<![\w.\-:])0\d{8,10}(?![\w.])/', '[phone]', $text );
		if ( $barePhones ) {
			$text = $this->barePhones( $text );
		}

		// Address-like text: a house number and street, a named street, and PO boxes.
		$suffix = 'street|st|road|rd|avenue|ave|boulevard|blvd|lane|ln|drive|dr|court|ct|way|place|pl|square|sq|highway|hwy|building|bldg|tower|floor|apt|apartment|suite|ste';
		$text   = (string) preg_replace( '/\b\d{1,5}[A-Za-z]?\s+(?:[A-Za-z0-9.\'\-]+\s+){0,4}(?:' . $suffix . ')\b\.?/i', '[address]', $text );
		$text   = (string) preg_replace( '/\b(?:\p{Lu}[\p{L}\'\-]+\s+){1,4}(?:Street|St|Road|Rd|Avenue|Ave|Boulevard|Blvd|Lane|Drive)\b\.?/u', '[address]', $text );
		$text   = (string) preg_replace( '/\bP\.?\s?O\.?\s?Box\s+\w+/i', '[address]', $text );
		return $text;
	}

	/**
	 * Phone numbers with no plus, 00 or leading zero. See personal() for what is left alone.
	 *
	 * @param string $text Text.
	 */
	private function barePhones( string $text ): string {
		$currency = '(?:AED|USD|EUR|GBP|SAR|EGP|JOD|KWD|QAR|BHD|OMR|INR|CHF|CAD|AUD|NGN|KES|ZAR|TRY)';
		$label    = '(?:order|invoice|inv|ref|reference|sku|total|amount|price|qty|quantity|balance|ticket|transaction|txn|barcode|ean|gtin|upc|tracking|shipment|serial|ids?)';
		return (string) preg_replace_callback(
			'/(?<![\w#@$\x{20AC}\x{00A3}\x{00A5}\/:=.,\-+])\(?\d[\d\s().\-]{6,24}\d(?![\w:]|[.,]\d)/u',
			static function ( array $match ) use ( $text, $currency, $label ): string {
				$raw    = (string) $match[0][0];
				$offset = (int) $match[0][1];
				$digits = strlen( (string) preg_replace( '/\D/', '', $raw ) );
				if ( $digits < 9 || $digits > 15 ) {
					return $raw;
				}
				$before = substr( $text, max( 0, $offset - 40 ), min( 40, $offset ) );
				$after  = substr( $text, $offset + strlen( $raw ), 12 );
				if (
					1 === preg_match( '/^\d{1,3}(?:\.\d{1,3}){3}$/', $raw )                                                      // IPv4 address.
					|| 1 === preg_match( '/^\(?(?:\d{4}[\-.\/]\d{1,2}[\-.\/]\d{1,2}|\d{1,2}[\-.\/]\d{1,2}[\-.\/]\d{2,4})(?!\d)/', $raw ) // Date.
					|| 1 === preg_match( '/^\d+\.\d+$|\.\d{1,2}$/', $raw )                                                    // Decimal amount.
					|| 1 === preg_match( '/"\s*:\s*$/', $before )                                                              // JSON number.
					|| 1 === preg_match( '/(?:' . $currency . '|[$\x{20AC}\x{00A3}\x{00A5}])\s*$/iu', $before )                  // Currency before.
					|| 1 === preg_match( '/^\s*(?:' . $currency . '\b|%)/i', $after )                                            // Currency after.
					|| 1 === preg_match( '/(?:^|[^A-Za-z])' . $label . '(?:[\s_\-]*(?:' . $label . '|no|nr|number|num))*[\s:#=.\-"\'_\[]*$/i', $before ) // Named as an id or total.
				) {
					return $raw;
				}
				return '[phone]';
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Whether a field name stands for an identifier, a code, an amount or a time rather than for a person's phone.
	 *
	 * @param string $name Lower-case field name.
	 */
	private static function numericField( string $name ): bool {
		return 1 === preg_match( '/(?:^|_)(?:id|ids|ref|reference|sku|number|no|nr|total|amount|price|qty|quantity|balance|barcode|ean|gtin|upc|tracking|code|ts|timestamp|time|date|created|updated)$/', $name );
	}

	/**
	 * Redact a value of any shape: arrays are walked field by field; strings go through the text rules.
	 *
	 * @param mixed             $data            Array, string or scalar.
	 * @param bool              $redactPersonal  Whether personal data is removed too.
	 * @param array<int,string> $sensitivePaths  Extra field paths whose value is personal, for example `partner.vat`. A path
	 *                                           of one segment is a field name. A longer path matches a field whose names
	 *                                           from the top, ending in the same segments, are those of the path (so
	 *                                           `partner.ref` does not hide an unrelated `ref`).
	 * @return mixed
	 */
	public function structure( mixed $data, bool $redactPersonal = true, array $sensitivePaths = array() ): mixed {
		if ( ! $redactPersonal ) {
			return $this->walk( $data, false, array(), array(), array() );
		}
		list( $names, $paths ) = $this->personalKeys( $sensitivePaths );
		return $this->walk( $data, true, $names, $paths, array() );
	}

	/**
	 * A short redacted text for the log: the payload encoded as JSON, redacted, and cut to a size.
	 *
	 * @param mixed             $payload         Array or string.
	 * @param int               $maxBytes        Largest size in bytes; 0 or less keeps nothing.
	 * @param bool              $redactPersonal  Whether personal data is removed too.
	 * @param array<int,string> $sensitivePaths  Extra personal field paths.
	 * @param int               $capBytes        A payload that encodes to more than this is not processed at all; a short note
	 *                                           replaces it. 0 or less means no cap.
	 * @return string|null Null when nothing is kept.
	 */
	public function excerpt( mixed $payload, int $maxBytes, bool $redactPersonal = true, array $sensitivePaths = array(), int $capBytes = 0 ): ?string {
		if ( $maxBytes <= 0 || null === $payload || '' === $payload ) {
			return null;
		}
		if ( is_string( $payload ) ) {
			if ( $capBytes > 0 && strlen( $payload ) > $capBytes ) {
				return self::tooLarge( $capBytes, $maxBytes );
			}
			$decoded = json_decode( $payload, true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		if ( is_array( $payload ) ) {
			if ( $capBytes > 0 && strlen( Json::encode( $payload ) ) > $capBytes ) {
				return self::tooLarge( $capBytes, $maxBytes );
			}
			$text = Json::encode( $this->structure( $payload, $redactPersonal, $sensitivePaths ) );
			$text = $this->message( $text, $redactPersonal );
		} else {
			$text = $this->message( (string) $payload, $redactPersonal );
		}
		return self::cut( $text, $maxBytes );
	}

	/**
	 * Field names taken from mapping paths, for example `billing.email` gives `email`.
	 *
	 * Only paths under billing, shipping or customer, plus order.buyer_tax_number and order.note, are personal. Any other
	 * path (a target path the caller already knows to be personal) can be passed through `$sensitivePaths` of
	 * structure() and excerpt(), and its last segment is used as it is.
	 *
	 * @param array<int,string> $sourcePaths Canonical source paths.
	 * @return array<int,string> Lower-case field names.
	 */
	public static function namesFromSourcePaths( array $sourcePaths ): array {
		$names = array();
		foreach ( $sourcePaths as $path ) {
			$path  = str_replace( '[]', '', (string) $path );
			$parts = explode( '.', strtolower( $path ) );
			$first = $parts[0];
			$last  = end( $parts );
			if ( in_array( $first, array( 'billing', 'shipping', 'customer' ), true ) && count( $parts ) > 1 ) {
				$names[] = $last;
			} elseif ( 'order.buyer_tax_number' === strtolower( $path ) || 'order.note' === strtolower( $path ) ) {
				$names[] = $last;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The personal field names and field paths for a call.
	 *
	 * @param array<int,string> $paths Extra paths.
	 * @return array{0:array<string,bool>,1:array<int,array<int,string>>} Names (name to true) and paths (lists of segments).
	 */
	private function personalKeys( array $paths ): array {
		$names = self::PERSONAL_KEYS;
		$deep  = array();
		foreach ( $paths as $path ) {
			$parts = array_values( array_filter( explode( '.', strtolower( str_replace( '[]', '', trim( (string) $path ) ) ) ), static fn( string $part ): bool => '' !== $part ) );
			if ( 1 === count( $parts ) ) {
				$names[] = $parts[0];
			} elseif ( count( $parts ) > 1 ) {
				$deep[] = $parts;
			}
		}
		return array( array_fill_keys( array_filter( $names ), true ), $deep );
	}

	/**
	 * Whether the names leading to a field end with the segments of one of the paths.
	 *
	 * @param array<int,array<int,string>> $paths Paths as lists of segments.
	 * @param array<int,string>            $trail Names from the top to the field.
	 */
	private static function pathMatches( array $paths, array $trail ): bool {
		foreach ( $paths as $segments ) {
			$length = count( $segments );
			if ( $length <= count( $trail ) && array_slice( $trail, -$length ) === $segments ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk a value and redact it.
	 *
	 * @param mixed                        $data         Value.
	 * @param bool                         $personal     Whether personal data is removed.
	 * @param array<string,bool>           $personalKeys Personal field names.
	 * @param array<int,array<int,string>> $paths        Personal field paths.
	 * @param array<int,string>            $trail        Names from the top down to this value.
	 * @param bool                         $barePhones   Whether strings are checked for phone numbers with no plus or zero.
	 * @return mixed
	 */
	private function walk( mixed $data, bool $personal, array $personalKeys, array $paths, array $trail, bool $barePhones = true ): mixed {
		if ( is_string( $data ) ) {
			return $this->secrets( $personal ? $this->personal( $data, $barePhones ) : $data );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$name  = is_string( $key ) ? strtolower( $key ) : '';
			$where = '' === $name ? $trail : array_merge( $trail, array( $name ) );
			if ( '' !== $name && in_array( $name, self::SECRET_KEYS, true ) ) {
				$out[ $key ] = self::SECRET;
			} elseif ( '' !== $name && ( isset( $personalKeys[ $name ] ) || self::pathMatches( $paths, $where ) ) && ( is_array( $value ) || is_scalar( $value ) ) && '' !== $value && null !== $value ) {
				$out[ $key ] = self::PERSONAL;
			} else {
				$out[ $key ] = $this->walk( $value, $personal, $personalKeys, $paths, $where, '' === $name ? $barePhones : ! self::numericField( $name ) );
			}
		}
		return $out;
	}

	/**
	 * The note that stands in for a payload over the size cap.
	 *
	 * @param int $capBytes Cap in bytes.
	 * @param int $maxBytes Excerpt size in bytes.
	 */
	private static function tooLarge( int $capBytes, int $maxBytes ): string {
		/* translators: %s: size limit, for example 256 KB. */
		$note = sprintf( __( '[The payload is larger than %s and was not kept.]', 'estasoft-erp-crm-sync-engine' ), (string) max( 1, (int) round( $capBytes / 1024 ) ) . ' KB' );
		return self::cut( $note, $maxBytes );
	}

	/**
	 * Cut text to a number of bytes without splitting a character, adding an ellipsis when it was cut.
	 *
	 * @param string $text     Text.
	 * @param int    $maxBytes Largest size in bytes, ellipsis included.
	 */
	private static function cut( string $text, int $maxBytes ): string {
		if ( strlen( $text ) <= $maxBytes ) {
			return $text;
		}
		$ellipsis = "\u{2026}";
		$room     = max( 0, $maxBytes - strlen( $ellipsis ) );
		$cut      = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $room, 'UTF-8' ) : substr( $text, 0, $room );
		if ( ! function_exists( 'mb_strcut' ) ) {
			$cut = (string) preg_replace( '/(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})$/', '', $cut );
		}
		return $cut . $ellipsis;
	}
}
