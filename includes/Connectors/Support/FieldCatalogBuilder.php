<?php
/**
 * Builds a field catalog with a fluent syntax.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * FieldCatalogBuilder::make()->group( 'Customer' )->text( 'contact.name', 'Name', required: true )->build().
 * The current group applies to every field added after it.
 */
final class FieldCatalogBuilder {

	/**
	 * Fields added so far.
	 *
	 * @var array<int,FieldDef>
	 */
	private array $fields = array();

	/**
	 * The group of the next fields.
	 *
	 * @var string
	 */
	private string $group = '';

	/**
	 * Start a catalog.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Set the group heading for the fields that follow.
	 *
	 * @param string $group Heading.
	 */
	public function group( string $group ): self {
		$this->group = $group;
		return $this;
	}

	/**
	 * A field of any type.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param string $type     One of FieldDef::TYPES.
	 * @param bool   $required Whether the system needs it.
	 */
	public function field( string $path, string $label, string $type = 'string', bool $required = false ): self {
		$this->fields[] = new FieldDef( $path, $label, $type, $required, $this->group );
		return $this;
	}

	/**
	 * A one-line text.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function text( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'string', $required );
	}

	/**
	 * A multi-line text.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function longText( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'text', $required );
	}

	/**
	 * A number (quantity, percent).
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function number( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'number', $required );
	}

	/**
	 * A whole number.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function integer( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'integer', $required );
	}

	/**
	 * An amount in minor units.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function money( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'money', $required );
	}

	/**
	 * A yes/no value.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function boolean( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'boolean', $required );
	}

	/**
	 * A date and time.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function datetime( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'datetime', $required );
	}

	/**
	 * A date.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function date( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'date', $required );
	}

	/**
	 * A product matched by SKU.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function sku( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'sku', $required );
	}

	/**
	 * A tax code chosen through the tax mapping.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function taxCode( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'tax_code', $required );
	}

	/**
	 * A value chosen from a fixed list.
	 *
	 * @param string $path     Payload path.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 */
	public function choice( string $path, string $label, bool $required = false ): self {
		return $this->field( $path, $label, 'choice', $required );
	}

	/**
	 * The catalog.
	 *
	 * @return array<int,FieldDef>
	 */
	public function build(): array {
		return $this->fields;
	}

	/**
	 * The catalog in the shape describeFields() returns.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function toArray(): array {
		return array_map( static fn( FieldDef $field ): array => $field->toArray(), $this->fields );
	}
}
