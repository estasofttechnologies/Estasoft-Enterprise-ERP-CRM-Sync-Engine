<?php
/**
 * Field errors from validation.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when input fails validation. Carries one message per field so the screen can show it next to the field. The code that
 * throws it escapes each message with `esc_html()`; the REST layer turns the text back with `PlainText::fromEscaped()`.
 */
final class ValidationException extends \InvalidArgumentException {

	/**
	 * Field name (dotted for nested fields) to message.
	 *
	 * @var array<string,string>
	 */
	private array $errors;

	/**
	 * Create the exception from the field errors.
	 *
	 * @param array<string,string> $errors Field name to message.
	 */
	public function __construct( array $errors ) {
		parent::__construct( 'Validation failed.' );
		$this->errors = $errors;
	}

	/**
	 * The field messages.
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
