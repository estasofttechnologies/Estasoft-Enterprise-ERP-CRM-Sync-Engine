<?php
/**
 * What a connector is, in one place.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Identity, capability flags, sign-in style, regions, limits and the hosts a connector may contact. AbstractApiConnector derives
 * id(), label(), type(), capabilities(), supportedEntities(), rateLimitHints() and contractVersion() from it.
 */
final class ConnectorMeta {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                $id              Connector id, lower case [a-z0-9-].
	 * @param string                $label           Name shown to the person.
	 * @param ConnectorType         $type            Erp, accounting or crm.
	 * @param array<int,Capability> $capabilities The flags that are true. Everything else is false.
	 * @param array<int,EntityType> $entities     Entity types send() accepts.
	 * @param AuthStyle             $auth            Sign-in style.
	 * @param array<int,string>     $regions         ISO country codes the system serves; empty means everywhere.
	 * @param RateLimitHints|null   $rateLimit      Documented limits; null means one call a second.
	 * @param array<int,string>     $hosts           Fixed hosts the plugin may contact for this system. A leading `*.` matches any subdomain.
	 *                                               Leave empty when every host comes from the merchant (see $merchantHost).
	 * @param bool                  $merchantHost    True when the merchant types the address (ERPNext, SuiteCRM, SAP B1, Sage 300, Acumatica, Odoo-style).
	 *                                               Private and loopback addresses are then refused unless the filter estasoft_sync_allow_private_hosts allows them.
	 * @param string                $contractVersion Contract version the connector was written for.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly ConnectorType $type,
		public readonly array $capabilities = array(),
		public readonly array $entities = array(),
		public readonly AuthStyle $auth = AuthStyle::Key,
		public readonly array $regions = array(),
		public readonly ?RateLimitHints $rateLimit = null,
		public readonly array $hosts = array(),
		public readonly bool $merchantHost = false,
		public readonly string $contractVersion = ConnectorInterface::CONTRACT_VERSION,
	) {
	}

	/**
	 * Every capability as a flag: true for the listed ones, false for the rest.
	 *
	 * @return array<string,bool>
	 */
	public function flags(): array {
		$flags = array();
		foreach ( Capability::cases() as $capability ) {
			$flags[ $capability->value ] = in_array( $capability, $this->capabilities, true );
		}
		return $flags;
	}

	/**
	 * Whether a capability is flagged.
	 *
	 * @param Capability $capability Capability.
	 */
	public function has( Capability $capability ): bool {
		return in_array( $capability, $this->capabilities, true );
	}

	/**
	 * The documented limits, never null.
	 */
	public function limits(): RateLimitHints {
		return $this->rateLimit ?? new RateLimitHints();
	}
}
