<?php
/**
 * One page of a list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * The `Paged<T>` shape of src/api/types.ts.
 */
final class Paged {

	/**
	 * Describe one page of rows.
	 *
	 * @param array<int,mixed> $items   Rows of this page.
	 * @param int              $total   Rows over all pages.
	 * @param int              $page    This page, from 1.
	 * @param int              $perPage Rows per page.
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $perPage
	) {
	}

	/**
	 * Number of pages.
	 */
	public function totalPages(): int {
		return $this->perPage > 0 ? (int) ceil( $this->total / $this->perPage ) : 0;
	}

	/**
	 * The shape returned by the REST API.
	 *
	 * @return array{items:array<int,mixed>,total:int,total_pages:int,page:int,per_page:int}
	 */
	public function toArray(): array {
		return array(
			'items'       => $this->items,
			'total'       => $this->total,
			'total_pages' => $this->totalPages(),
			'page'        => $this->page,
			'per_page'    => $this->perPage,
		);
	}
}
