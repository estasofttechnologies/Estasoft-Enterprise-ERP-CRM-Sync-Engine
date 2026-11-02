<?php
/**
 * The field that carries the WooCommerce reference of a CRM record.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * A deal's name can be edited by anyone in the CRM, and a renamed deal is a deal the connector can no longer find: a refund would fail and
 * a replayed order would make a second deal. So the reference (WC-123) is kept in a dedicated custom field that nobody has a reason to touch,
 * and the name is the documented fallback. Which one applies is decided from, in this order:
 *
 * 1. the connector option (a field the merchant made, or `name` to use the name on purpose);
 * 2. the field Test connection found or created, which it keeps under `detected.reference_field` of the connection;
 * 3. the name field.
 *
 * A search tries the dedicated field first and then the name, so a record made while only the name was in use is still found after the
 * dedicated field appears; a new record carries the reference in both.
 */
final class ReferenceField {

	/**
	 * Key under `detected` where Test connection leaves the field it found or created.
	 */
	public const DETECTED = 'reference_field';

	/**
	 * Create the object.
	 *
	 * @param string $field     API name of the field that is searched first.
	 * @param string $name      API name of the name field, the fallback.
	 * @param bool   $dedicated Whether $field is a field of its own (false: the name is the only key).
	 */
	public function __construct( public readonly string $field, public readonly string $name, public readonly bool $dedicated ) {
	}

	/**
	 * Decide which field carries the reference for a connection.
	 *
	 * @param Connection $connection Connection.
	 * @param CrmOptions $options    Options.
	 * @param string     $optionKey  Option that names the field (reference_field or reference_property).
	 * @param string     $nameField  API name of the record's name field (Deal_Name, dealname, title, Name).
	 */
	public static function choose( Connection $connection, CrmOptions $options, string $optionKey, string $nameField ): self {
		$typed = $options->text( $optionKey );
		if ( '' !== $typed ) {
			if ( 'name' === Text::lower( $typed ) || Text::same( $typed, $nameField ) ) {
				return new self( $nameField, $nameField, false );
			}
			if ( self::valid( $typed ) ) {
				return new self( $typed, $nameField, true );
			}
		}
		$detected = $connection->config( 'detected' );
		$found    = is_array( $detected ) && is_scalar( $detected[ self::DETECTED ] ?? null ) ? trim( (string) $detected[ self::DETECTED ] ) : '';
		if ( self::valid( $found ) ) {
			return new self( $found, $nameField, true );
		}
		return new self( $nameField, $nameField, false );
	}

	/**
	 * Whether a text can be a field API name.
	 *
	 * @param string $name Text.
	 */
	public static function valid( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_]{0,63}$/', $name );
	}

	/**
	 * The fields to search, in order: the dedicated one, then the name.
	 *
	 * @return array<int,string>
	 */
	public function searchFields(): array {
		return $this->dedicated ? array( $this->field, $this->name ) : array( $this->name );
	}

	/**
	 * The reference a record row carries: the dedicated value when it is a WooCommerce reference, else the name.
	 *
	 * @param array<string,mixed> $values Field values of the record by API name.
	 */
	public function readFrom( array $values ): string {
		foreach ( $this->searchFields() as $field ) {
			$value = is_scalar( $values[ $field ] ?? null ) ? trim( (string) $values[ $field ] ) : '';
			if ( 1 === preg_match( '/^WC-\d+$/', $value ) ) {
				return $value;
			}
		}
		return '';
	}
}
