<?php
/**
 * A field on the connect screen.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one input on the connect screen for key-style connectors.
 */
final class CredentialField {

	/**
	 * Input types the connect screen draws. `display` is a read-only value the person copies (the redirect address of a sign-in app).
	 */
	public const TYPES = array( 'text', 'password', 'select', 'textarea', 'url', 'number', 'display' );

	/**
	 * Groups the connect screen sorts the fields into.
	 */
	public const GROUPS = array( 'connection', 'app', 'advanced' );

	/**
	 * @param string               $name     Machine name (address, database, user, api_key).
	 * @param string               $label    Label shown to the person (translated by the connector).
	 * @param bool                 $secret   True for values that are encrypted and never shown again.
	 * @param bool                 $required Whether the field must be filled.
	 * @param string               $hint     Short helper text.
	 * @param string               $default  Prefilled value.
	 * @param string               $type     One of self::TYPES: text (default), password, select, textarea, url, number or display.
	 * @param array<string,string> $options  Value => label pairs for a select.
	 * @param string               $group    One of self::GROUPS: connection (default), app (client id and secret of the merchant's own app) or advanced.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly bool $secret = false,
		public readonly bool $required = true,
		public readonly string $hint = '',
		public readonly string $default = '',
		public readonly string $type = 'text',
		public readonly array $options = array(),
		public readonly string $group = 'connection',
	) {
	}
}
