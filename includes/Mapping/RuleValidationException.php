<?php
/**
 * A mapping cannot be published because its rules have problems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Carries every problem found, so the screen can show them all at once.
 */
final class RuleValidationException extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<int,RuleError> $errors Problems found.
	 */
	public function __construct( public readonly array $errors ) {
		parent::__construct( __( 'The mapping has problems and cannot be published.', 'estasoft-erp-crm-sync-engine' ) );
	}
}
