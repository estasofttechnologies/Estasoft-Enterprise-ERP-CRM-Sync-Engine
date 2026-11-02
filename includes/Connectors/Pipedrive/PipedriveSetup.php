<?php
/**
 * The deal field that carries the order reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pipedrive;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\ReferenceField;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;

/**
 * A deal's title can be edited by anyone, so the reference (WC-123) lives in a custom text field of its own. Test connection looks for it
 * by name and creates it when it is missing; Pipedrive names a custom field by a generated key, which is read from the answer and kept.
 * Creating a custom field needs the right to manage them; without it this returns null and the title stays the key.
 */
final class PipedriveSetup {

	/**
	 * Name of the field as people see it in Pipedrive.
	 */
	public const FIELD_NAME = 'WooCommerce order reference';

	/**
	 * Create the object.
	 *
	 * @param PipedriveApi $api API.
	 */
	public function __construct( private readonly PipedriveApi $api ) {
	}

	/**
	 * The key of the reference field, made when it is missing; null when it is missing and could not be made.
	 *
	 * @return array{field:string,created:bool}|null
	 */
	public function referenceField(): ?array {
		$fields = $this->api->dealFields();
		if ( null === $fields ) {
			return null;
		}
		foreach ( $fields as $field ) {
			$key = $this->keyOf( $field );
			if ( '' !== $key && Text::same( (string) ( $field['field_name'] ?? $field['name'] ?? '' ), self::FIELD_NAME ) ) {
				return array(
					'field'   => $key,
					'created' => false,
				);
			}
		}
		$made = $this->api->createDealField( self::FIELD_NAME, 'varchar' );
		$key  = null === $made ? '' : $this->keyOf( $made );
		return '' === $key ? null : array(
			'field'   => $key,
			'created' => true,
		);
	}

	/**
	 * The API key of a field: `field_code` in API v2, `key` in API v1.
	 *
	 * @param array<string,mixed> $field Field.
	 */
	private function keyOf( array $field ): string {
		foreach ( array( 'field_code', 'key' ) as $name ) {
			$value = is_scalar( $field[ $name ] ?? null ) ? trim( (string) $field[ $name ] ) : '';
			if ( ReferenceField::valid( $value ) ) {
				return $value;
			}
		}
		return '';
	}
}
