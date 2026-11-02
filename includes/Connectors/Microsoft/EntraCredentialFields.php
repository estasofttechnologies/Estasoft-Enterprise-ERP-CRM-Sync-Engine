<?php
/**
 * The connect form fields every Microsoft cloud app shares.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Contract\CredentialField;

/**
 * Tenant, client id and client secret of the merchant's own Microsoft Entra app registration.
 */
final class EntraCredentialFields {

	/**
	 * The directory (tenant) of the Entra app.
	 */
	public static function tenant(): CredentialField {
		return new CredentialField(
			'tenant_id',
			__( 'Directory (tenant) ID', 'estasoft-erp-crm-sync-engine' ),
			false,
			true,
			__( 'On the Overview page of your app in the Microsoft Entra admin center. A GUID, or your domain name.', 'estasoft-erp-crm-sync-engine' ),
			'',
			'text',
			array(),
			'app'
		);
	}

	/**
	 * The three app fields in the order the form shows them.
	 *
	 * @return array<int,CredentialField>
	 */
	public static function app(): array {
		return array(
			self::tenant(),
			CredentialFields::clientId( 'client_id', __( 'Application (client) ID from the Overview page of your app.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret( 'client_secret', __( 'The secret value, not its ID. Saved encrypted and never shown again. It ends on the date you chose in Microsoft Entra.', 'estasoft-erp-crm-sync-engine' ) ),
		);
	}

	/**
	 * A choice shown under Advanced.
	 *
	 * @param string               $name    Option key.
	 * @param string               $label   Label.
	 * @param array<string,string> $options Value => label.
	 * @param string               $fallback Selected value.
	 * @param string               $hint    Hint.
	 */
	public static function option( string $name, string $label, array $options, string $fallback, string $hint = '' ): CredentialField {
		return new CredentialField( $name, $label, false, false, $hint, $fallback, 'select', $options, 'advanced' );
	}

	/**
	 * A text option shown under Advanced.
	 *
	 * @param string $name  Option key.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	public static function text( string $name, string $label, string $hint = '' ): CredentialField {
		return new CredentialField( $name, $label, false, false, $hint, '', 'text', array(), 'advanced' );
	}
}
