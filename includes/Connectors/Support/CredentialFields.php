<?php
/**
 * Ready-made connect form fields.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Contract\CredentialField;

/**
 * The same few fields appear on nearly every connect form. A connector overrides credentialDefinition() and builds its list from these,
 * adding its own (data centre, tenant, region). Secret fields are encrypted when saved and never shown again.
 */
final class CredentialFields {

	/**
	 * The default fields of a sign-in style.
	 *
	 * @param AuthStyle $style Sign-in style.
	 * @return array<int,CredentialField>
	 */
	public static function forStyle( AuthStyle $style ): array {
		return match ( $style ) {
			AuthStyle::Key, AuthStyle::ServerLogin    => array( self::apiKey() ),
			AuthStyle::Basic                          => array( self::username(), self::password() ),
			AuthStyle::OAuthAuthorizationCode, AuthStyle::OAuthClientCredentials => array( self::clientId(), self::clientSecret() ),
			AuthStyle::OAuthJwtBearer                 => array( self::clientId(), self::privateKey() ),
			AuthStyle::ExportFile, AuthStyle::None    => array(),
		};
	}

	/**
	 * A single API key or token.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	public static function apiKey( string $name = 'api_key', string $label = '', string $hint = '' ): CredentialField {
		return new CredentialField(
			$name,
			'' !== $label ? $label : __( 'API key', 'estasoft-erp-crm-sync-engine' ),
			true,
			true,
			'' !== $hint ? $hint : __( 'Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ),
			'',
			'password'
		);
	}

	/**
	 * A user name or email.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	public static function username( string $name = 'username', string $label = '', string $hint = '' ): CredentialField {
		return new CredentialField( $name, '' !== $label ? $label : __( 'User name', 'estasoft-erp-crm-sync-engine' ), false, true, $hint );
	}

	/**
	 * A password.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	public static function password( string $name = 'password', string $label = '', string $hint = '' ): CredentialField {
		return new CredentialField(
			$name,
			'' !== $label ? $label : __( 'Password', 'estasoft-erp-crm-sync-engine' ),
			true,
			true,
			'' !== $hint ? $hint : __( 'Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ),
			'',
			'password'
		);
	}

	/**
	 * The client id of the merchant's own app.
	 *
	 * @param string $name Field name.
	 * @param string $hint Hint.
	 */
	public static function clientId( string $name = 'client_id', string $hint = '' ): CredentialField {
		return new CredentialField( $name, __( 'Client ID', 'estasoft-erp-crm-sync-engine' ), false, true, $hint, '', 'text', array(), 'app' );
	}

	/**
	 * The client secret of the merchant's own app.
	 *
	 * @param string $name Field name.
	 * @param string $hint Hint.
	 */
	public static function clientSecret( string $name = 'client_secret', string $hint = '' ): CredentialField {
		return new CredentialField(
			$name,
			__( 'Client secret', 'estasoft-erp-crm-sync-engine' ),
			true,
			true,
			'' !== $hint ? $hint : __( 'Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ),
			'',
			'password',
			array(),
			'app'
		);
	}

	/**
	 * A private key the merchant pastes (PEM text) for a signed token request.
	 *
	 * @param string $name Field name.
	 * @param string $hint Hint.
	 */
	public static function privateKey( string $name = 'private_key', string $hint = '' ): CredentialField {
		return new CredentialField(
			$name,
			__( 'Private key (PEM)', 'estasoft-erp-crm-sync-engine' ),
			true,
			true,
			'' !== $hint ? $hint : __( 'Paste the whole key, including the BEGIN and END lines. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ),
			'',
			'textarea',
			array(),
			'app'
		);
	}

	/**
	 * The address of a system the merchant hosts or that has its own address per customer.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	public static function address( string $name = 'address', string $label = '', string $hint = '' ): CredentialField {
		return new CredentialField( $name, '' !== $label ? $label : __( 'Address', 'estasoft-erp-crm-sync-engine' ), false, true, $hint, '', 'url' );
	}

	/**
	 * A data centre, region or environment chosen from a list.
	 *
	 * @param string               $name    Field name (data_center, region, environment).
	 * @param string               $label   Label.
	 * @param array<string,string> $options Value => label.
	 * @param string               $selected Selected value.
	 * @param string               $hint    Hint.
	 */
	public static function choice( string $name, string $label, array $options, string $selected = '', string $hint = '' ): CredentialField {
		return new CredentialField( $name, $label, false, true, $hint, '' !== $selected ? $selected : (string) array_key_first( $options ), 'select', $options );
	}

	/**
	 * The redirect address of the plugin, shown to the merchant to register in the vendor's app settings. Read-only.
	 */
	public static function redirectUri(): CredentialField {
		return new CredentialField(
			'redirect_uri',
			__( 'Redirect address', 'estasoft-erp-crm-sync-engine' ),
			false,
			false,
			__( 'Add this address to the redirect addresses of your app in the system. It must match exactly.', 'estasoft-erp-crm-sync-engine' ),
			OAuthRedirect::uri(),
			'display',
			array(),
			'app'
		);
	}
}
