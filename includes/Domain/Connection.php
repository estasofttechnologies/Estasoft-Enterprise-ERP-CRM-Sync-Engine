<?php
/**
 * A connection to one external system. Secrets are decrypted only for the length of a request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of a row in {prefix}estasoft_sync_connections.
 */
final class Connection {

	/**
	 * @param int                  $id        Row id.
	 * @param string               $connector Connector id, for example odoo.
	 * @param ConnectorType        $type      erp, accounting or crm.
	 * @param string               $label     Name shown to the person.
	 * @param ConnectionStatus     $status    Current state.
	 * @param array<string,mixed>  $config    Non-secret settings: address, database, company, warehouses, options.
	 * @param array<string,mixed>  $settings  Reliability settings: retries, jitter, breaker, rate, concurrency.
	 * @param array<string,string> $secrets   Decrypted secrets (never logged, never serialised).
	 * @param int                  $breakerFailures Consecutive retryable failures.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $connector,
		public readonly ConnectorType $type,
		public readonly string $label,
		public readonly ConnectionStatus $status,
		public readonly array $config = array(),
		public readonly array $settings = array(),
		private readonly array $secrets = array(),
		public readonly int $breakerFailures = 0,
	) {
	}

	/**
	 * Read one secret, or an empty string when it is not set.
	 *
	 * @param string $name Secret name, for example api_key.
	 */
	public function secret( string $name ): string {
		return $this->secrets[ $name ] ?? '';
	}

	/**
	 * Read a config value with a default.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Value when absent.
	 * @return mixed
	 */
	public function config( string $key, mixed $default = null ): mixed {
		return $this->config[ $key ] ?? $default;
	}

	/**
	 * Never expose secrets through var_export, print_r or JSON.
	 *
	 * @return array<string,mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'id'        => $this->id,
			'connector' => $this->connector,
			'status'    => $this->status->value,
		);
	}
}
