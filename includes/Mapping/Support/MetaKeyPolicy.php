<?php
/**
 * Which store fields (meta.<key>) a mapping may read.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A mapping can read any order meta key as meta.<key>. Keys that look like payment tokens, card data, passwords, nonces or visitor
 * addresses are refused: they can be saved in no rule, and the reader never returns them. The word list can be changed with the filter
 * estasoft_sync_blocked_meta_words and the prefix list with estasoft_sync_blocked_meta_prefixes (each gets and returns a list of text).
 */
final class MetaKeyPolicy {

	/**
	 * Whole words (parts of a key between anything that is not a letter) that mark a key as sensitive. A plural "s" is allowed.
	 */
	private const WORDS = array(
		'token',
		'secret',
		'password',
		'passwd',
		'nonce',
		'card',
		'cvv',
		'cvc',
		'cvn',
		'iban',
		'ip_address',
		'ip',
		'user_agent',
		'api_key',
		'apikey',
		'private_key',
		'auth',
		'authorization',
		'signature',
		'session',
		'cookie',
	);

	/**
	 * Gateway key prefixes (after an optional leading underscore and wc_).
	 */
	private const PREFIXES = array(
		'stripe',
		'paypal',
		'ppcp',
		'wcpay',
		'square',
		'braintree',
		'authorize_net',
		'adyen',
		'mollie',
		'klarna',
		'payfast',
	);

	/**
	 * Whether a meta key may be read by a mapping.
	 *
	 * @param string $key Key without the "meta." prefix.
	 */
	public static function isAllowed( string $key ): bool {
		return ! self::isBlocked( $key );
	}

	/**
	 * Whether a meta key looks sensitive.
	 *
	 * @param string $key Key without the "meta." prefix.
	 */
	public static function isBlocked( string $key ): bool {
		$lower = strtolower( $key );
		foreach ( self::prefixes() as $prefix ) {
			if ( 1 === preg_match( '/^_?(?:wc_)?' . preg_quote( $prefix, '/' ) . '/', $lower ) ) {
				return true;
			}
		}
		foreach ( self::words() as $word ) {
			if ( 1 === preg_match( '/(?<![a-z])' . preg_quote( $word, '/' ) . 's?(?![a-z])/', $lower ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The words in force.
	 *
	 * @return array<int,string>
	 */
	private static function words(): array {
		return self::list( apply_filters( 'estasoft_sync_blocked_meta_words', self::WORDS ), self::WORDS );
	}

	/**
	 * The prefixes in force.
	 *
	 * @return array<int,string>
	 */
	private static function prefixes(): array {
		return self::list( apply_filters( 'estasoft_sync_blocked_meta_prefixes', self::PREFIXES ), self::PREFIXES );
	}

	/**
	 * A filtered list as plain lower-case text, or the default when the filter returned something else.
	 *
	 * @param mixed             $filtered Filter result.
	 * @param array<int,string> $builtIn  Built-in list.
	 * @return array<int,string>
	 */
	private static function list( mixed $filtered, array $builtIn ): array {
		if ( ! is_array( $filtered ) ) {
			return $builtIn;
		}
		$clean = array();
		foreach ( $filtered as $entry ) {
			if ( is_string( $entry ) && '' !== trim( $entry ) ) {
				$clean[] = strtolower( trim( $entry ) );
			}
		}
		return $clean;
	}
}
