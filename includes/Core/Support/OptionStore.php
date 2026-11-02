<?php
/**
 * Where plugin options are kept.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A narrow view of the WordPress options API, so settings can be tested without WordPress.
 */
interface OptionStore {

	/**
	 * Read an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value when the option does not exist.
	 * @return mixed
	 */
	public function get( string $name, mixed $fallback = null ): mixed;

	/**
	 * Write an option. Plugin options are not autoloaded unless a small, always-needed value asks for it.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 */
	public function set( string $name, mixed $value, bool $autoload = false ): void;

	/**
	 * Remove an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): void;
}
