<?php
/**
 * Connections in the shapes of src/api/types.ts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Connections;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;

/**
 * Turns what `ConnectionRepository::findPublic()` returns into a `ConnectionSummary` or a `Connection`. Nothing secret goes in:
 * a secret credential field shows only whether it has a value, never the value.
 */
final class ConnectionPresenter {

	/**
	 * The `ConnectionSummary`.
	 *
	 * @param array<string,mixed>     $public    A row from ConnectionRepository::findPublic() or all().
	 * @param ConnectorInterface|null $connector Its connector, for the live capability flags.
	 * @return array<string,mixed>
	 */
	public static function summary( array $public, ?ConnectorInterface $connector ): array {
		$capabilities = null === $connector ? (array) ( $public['capabilities'] ?? array() ) : $connector->capabilities();
		return array(
			'id'                    => (int) $public['id'],
			'connector'             => (string) $public['connector'],
			'label'                 => (string) $public['label'],
			'type'                  => (string) $public['type'],
			'status'                => (string) $public['status'],
			'version'               => (string) $public['version'],
			'address'               => (string) $public['address'],
			'paused_until'          => $public['paused_until'] ?? null,
			'paused_reason'         => $public['paused_reason'] ?? null,
			'last_success_at'       => $public['last_success_at'] ?? null,
			'last_failure_at'       => $public['last_failure_at'] ?? null,
			'failures_in_a_row'     => (int) ( $public['failures_in_a_row'] ?? 0 ),
			'typical_response_ms'   => $public['typical_response_ms'] ?? null,
			'credential_expires_at' => $public['credential_expires_at'] ?? null,
			'capabilities'          => array_map( 'boolval', $capabilities ),
		);
	}

	/**
	 * The `Connection`.
	 *
	 * @param array<string,mixed>     $public    A row from ConnectionRepository::findPublic().
	 * @param ConnectorInterface|null $connector Its connector, for the credential fields and capability flags.
	 * @return array<string,mixed>
	 */
	public static function full( array $public, ?ConnectorInterface $connector ): array {
		$config  = is_array( $public['config'] ?? null ) ? $public['config'] : array();
		$hints   = is_array( $public['secret_hints'] ?? null ) ? $public['secret_hints'] : array();
		$company = trim( (string) ( $config['company'] ?? '' ) );
		return self::summary( $public, $connector ) + array(
			'company'         => '' !== $company ? $company : (string) ( $config['detected_company'] ?? '' ),
			'warehouses'      => self::warehouses( $config ),
			'settings'        => $public['settings'] ?? array(),
			'credentials'     => null === $connector ? array() : self::credentials( $connector->credentialFields(), $config, $hints ),
			'inbound_enabled' => (bool) ( $public['inbound_enabled'] ?? false ),
			'created_at'      => (string) ( $public['created_at'] ?? '' ),
		);
	}

	/**
	 * The warehouses of a connection with the person's choices (counting by default).
	 *
	 * @param array<string,mixed> $config Connection config.
	 * @return array<int,array{id:string,name:string,counts_toward_stock:bool,countries:array<int,string>}>
	 */
	public static function warehouses( array $config ): array {
		$list = array();
		foreach ( (array) ( $config['warehouses'] ?? array() ) as $warehouse ) {
			if ( ! is_array( $warehouse ) || ! isset( $warehouse['id'] ) ) {
				continue;
			}
			$list[] = array(
				'id'                  => (string) $warehouse['id'],
				'name'                => (string) ( $warehouse['name'] ?? $warehouse['id'] ),
				'counts_toward_stock' => ! isset( $warehouse['counts_toward_stock'] ) || (bool) $warehouse['counts_toward_stock'],
				'countries'           => array_values( array_map( 'strval', (array) ( $warehouse['countries'] ?? array() ) ) ),
			);
		}
		return $list;
	}

	/**
	 * The credential fields with their values: text for ordinary fields, only "has a value" for secrets.
	 *
	 * @param array<int,CredentialField> $fields Fields the connector declares.
	 * @param array<string,mixed>        $config Connection config.
	 * @param array<string,mixed>        $hints  Which secrets exist, from the repository (name to true).
	 * @return array<int,array<string,mixed>>
	 */
	private static function credentials( array $fields, array $config, array $hints ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$value = $field->secret || ! isset( $config[ $field->name ] ) || ! is_scalar( $config[ $field->name ] ) ? null : (string) $config[ $field->name ];
			$out[] = self::definition( $field ) + array(
				'value'     => 'display' === $field->type ? $field->default : $value,
				'has_value' => $field->secret ? ! empty( $hints[ $field->name ] ) : ( 'display' === $field->type || ( null !== $value && '' !== $value ) ),
			);
		}
		return $out;
	}

	/**
	 * Credential fields as `CredentialFieldDef` list. A secret field (and a password input) never carries a default.
	 *
	 * @param array<int,CredentialField> $fields Fields a connector declares.
	 * @return array<int,array{name:string,label:string,secret:bool,required:bool,hint:string,default:string,type:string,options:array<string,string>,group:string}>
	 */
	public static function definitions( array $fields ): array {
		return array_values( array_map( array( self::class, 'definition' ), $fields ) );
	}

	/**
	 * One `CredentialFieldDef`.
	 *
	 * @param CredentialField $field The field.
	 * @return array{name:string,label:string,secret:bool,required:bool,hint:string,default:string,type:string,options:array<string,string>,group:string}
	 */
	public static function definition( CredentialField $field ): array {
		$secret = $field->secret || 'password' === $field->type;
		return array(
			'name'     => $field->name,
			'label'    => $field->label,
			'secret'   => $field->secret,
			'required' => $field->required,
			'hint'     => $field->hint,
			'default'  => $secret ? '' : $field->default,
			'type'     => $field->type,
			'options'  => $field->options,
			'group'    => $field->group,
		);
	}
}
