<?php
/**
 * The deal property that carries the order reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\HubSpot;

defined( 'ABSPATH' ) || exit;

/**
 * A deal's name can be edited by anyone, so the reference (WC-123) lives in a property of its own. Test connection reads it and, when it is
 * missing, creates it as a text property whose value must be unique (a second deal with the same reference is then refused by HubSpot
 * instead of silently made). Reading or creating needs the schema scopes of the service key; without them this returns null and the deal
 * name stays the key.
 */
final class HubSpotSetup {

	/**
	 * Internal name of the property.
	 */
	public const PROPERTY = 'estasoft_order_ref';

	/**
	 * Create the object.
	 *
	 * @param HubSpotRecords $records Records.
	 */
	public function __construct( private readonly HubSpotRecords $records ) {
	}

	/**
	 * The property on deals, created when it is missing; null when it is missing and cannot be read or made with this key.
	 *
	 * @return array{field:string,created:bool}|null
	 */
	public function referenceProperty(): ?array {
		$read = $this->records->property( 'deals', self::PROPERTY );
		if ( $read->isSuccess() ) {
			return array(
				'field'   => self::PROPERTY,
				'created' => false,
			);
		}
		if ( 404 !== $read->status() ) {
			return null;
		}
		$made = $this->records->createProperty(
			'deals',
			array(
				'name'           => self::PROPERTY,
				'label'          => __( 'WooCommerce order reference', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'description'    => __( 'The order number the WooCommerce sync found this deal by. Please leave it as it is.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'type'           => 'string',
				'fieldType'      => 'text',
				'groupName'      => 'dealinformation',
				'hasUniqueValue' => true,
			)
		);
		if ( $made->isSuccess() || 409 === $made->status() ) {
			return array(
				'field'   => self::PROPERTY,
				'created' => $made->isSuccess(),
			);
		}
		return null;
	}
}
