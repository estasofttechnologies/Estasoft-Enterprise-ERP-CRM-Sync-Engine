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
	 * @param string $name     Machine name (address, database, user, api_key).
	 * @param string $label    Label shown to the person (translated by the connector).
	 * @param bool   $secret   True for values that are encrypted and never shown again.
	 * @param bool   $required Whether the field must be filled.
	 * @param string $hint     Short helper text.
	 * @param string $default  Prefilled value.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly bool $secret = false,
		public readonly bool $required = true,
		public readonly string $hint = '',
		public readonly string $default = '',
	) {
	}
}
