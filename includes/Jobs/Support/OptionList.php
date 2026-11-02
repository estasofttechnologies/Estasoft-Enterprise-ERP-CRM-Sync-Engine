<?php
/**
 * A short list kept in one option.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;

/**
 * Recent stock changes, recent tracking and similar lists: newest first, with a hard cap so an option never grows without
 * limit, and a counter that gives every entry a stable id.
 */
final class OptionList {

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options Options.
	 * @param string      $name    Option name (starts with estasoft_sync_ so uninstall removes it).
	 * @param int         $cap     Most entries kept.
	 */
	public function __construct(
		private readonly OptionStore $options,
		private readonly string $name,
		private readonly int $cap
	) {
	}

	/**
	 * The entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$stored = $this->options->get( $this->name, array() );
		$items  = is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : array();
		return array_values( array_filter( $items, 'is_array' ) );
	}

	/**
	 * Add an entry at the front and give it an id.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return int The id given to it.
	 */
	public function prepend( array $entry ): int {
		$stored = $this->options->get( $this->name, array() );
		$next   = is_array( $stored ) && isset( $stored['next_id'] ) ? max( 1, (int) $stored['next_id'] ) : 1;
		$entry['id'] = $next;
		$items       = $this->all();
		array_unshift( $items, $entry );
		$this->options->set(
			$this->name,
			array(
				'next_id' => $next + 1,
				'items'   => array_slice( $items, 0, $this->cap ),
			)
		);
		return $next;
	}

	/**
	 * Replace the entries (the counter is kept).
	 *
	 * @param array<int,array<string,mixed>> $items New list, newest first.
	 */
	public function replace( array $items ): void {
		$stored = $this->options->get( $this->name, array() );
		$next   = is_array( $stored ) && isset( $stored['next_id'] ) ? max( 1, (int) $stored['next_id'] ) : 1;
		$this->options->set(
			$this->name,
			array(
				'next_id' => $next,
				'items'   => array_slice( array_values( $items ), 0, $this->cap ),
			)
		);
	}
}
