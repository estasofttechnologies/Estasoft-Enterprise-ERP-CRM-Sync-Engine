<?php
/**
 * One target field of a mapping screen.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A field the person can map to: its payload path, label, value type, whether it is required and the group it is listed under.
 */
final class FieldDef {

	/**
	 * Value types the mapping screens understand.
	 */
	public const TYPES = array( 'string', 'text', 'number', 'integer', 'money', 'boolean', 'datetime', 'date', 'sku', 'tax_code', 'choice', 'email', 'phone', 'country', 'currency' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $path     Payload path; arrays use [] (order_line[].quantity).
	 * @param string $label    Label shown to the person (already translated).
	 * @param string $type     One of self::TYPES.
	 * @param bool   $required Whether the system needs it.
	 * @param string $group    Heading the field is listed under.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $label,
		public readonly string $type = 'string',
		public readonly bool $required = false,
		public readonly string $group = '',
	) {
	}

	/**
	 * The shape describeFields() returns.
	 *
	 * @return array{path:string,label:string,type:string,required:bool,group:string}
	 */
	public function toArray(): array {
		return array(
			'path'     => $this->path,
			'label'    => $this->label,
			'type'     => $this->type,
			'required' => $this->required,
			'group'    => $this->group,
		);
	}
}
