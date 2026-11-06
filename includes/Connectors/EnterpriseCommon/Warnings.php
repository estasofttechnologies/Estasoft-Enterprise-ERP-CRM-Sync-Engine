<?php
/**
 * Texts the connector shortened or accepted with a note.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the warnings of one send (a cut note, an accepted total difference) so they can be carried in the result's `extra`, and cuts
 * text to a system's field length with an ellipsis. The reference field is never cut: callers refuse a reference that is too long.
 */
final class Warnings {

	/**
	 * The warnings so far.
	 *
	 * @var array<int,string>
	 */
	private array $items = array();

	/**
	 * Add a warning.
	 *
	 * @param string $text Warning text.
	 */
	public function add( string $text ): void {
		if ( ! in_array( $text, $this->items, true ) ) {
			$this->items[] = $text;
		}
	}

	/**
	 * Cut text to a number of characters, ending with an ellipsis, and note it.
	 *
	 * @param string $field Field name, for the warning.
	 * @param string $text  Text.
	 * @param int    $max   Largest number of characters the system accepts.
	 */
	public function fit( string $field, string $text, int $max ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $length <= $max ) {
			return $text;
		}
		$this->add(
			sprintf(
				/* translators: 1: field name, 2: number of characters kept. */
				__( '%1$s was longer than %2$d characters and was shortened.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$field,
				$max
			)
		);
		$keep = max( 0, $max - 1 );
		return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $keep, 'UTF-8' ) : substr( $text, 0, $keep ) ) . "\u{2026}";
	}

	/**
	 * Whether any warning was added.
	 */
	public function any(): bool {
		return array() !== $this->items;
	}

	/**
	 * The warnings.
	 *
	 * @return array<int,string>
	 */
	public function all(): array {
		return $this->items;
	}
}
