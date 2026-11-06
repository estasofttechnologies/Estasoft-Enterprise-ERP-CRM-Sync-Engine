<?php
/**
 * The fixed library of transforms.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

defined( 'ABSPATH' ) || exit;

/**
 * Transforms by name. The library is fixed: rules choose from it, they cannot bring code.
 */
final class TransformRegistry {

	/**
	 * Registered transforms.
	 *
	 * @var array<string,Transform>
	 */
	private array $transforms = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<int,Transform> $transforms Transforms to register.
	 */
	public function __construct( array $transforms = array() ) {
		foreach ( $transforms as $transform ) {
			$this->register( $transform );
		}
	}

	/**
	 * The full library: prefix suffix trim case phone_e164 iso_country iso_state money_minor date_utc value_map if_then default
	 * required join split tax_lookup sku_match.
	 */
	public static function standard(): self {
		return new self(
			array(
				new Prefix(),
				new Suffix(),
				new Trim(),
				new CaseConvert(),
				new PhoneE164(),
				new IsoCountry(),
				new IsoState(),
				new MoneyMinor(),
				new DateUtc(),
				new ValueMap(),
				new IfThen(),
				new DefaultValue(),
				new Required(),
				new Join(),
				// The Split transform class, not the removed split() function.
				new Split(), // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved
				new TaxLookup(),
				new SkuMatch(),
			)
		);
	}

	/**
	 * Add a transform (a later one with the same name replaces an earlier one).
	 *
	 * @param Transform $transform Transform.
	 */
	public function register( Transform $transform ): void {
		$this->transforms[ $transform->name() ] = $transform;
	}

	/**
	 * Whether a transform exists.
	 *
	 * @param string $name Name.
	 */
	public function has( string $name ): bool {
		return isset( $this->transforms[ $name ] );
	}

	/**
	 * A transform by name.
	 *
	 * @param string $name Name.
	 *
	 * @throws TransformArgumentException When there is no such transform.
	 */
	public function get( string $name ): Transform {
		if ( ! isset( $this->transforms[ $name ] ) ) {
			/* translators: %s: transform name. */
			throw new TransformArgumentException( esc_html( sprintf( __( 'There is no transform called ‘%s’.', 'estasoft-enterprise-erpcrm-sync-engine' ), $name ) ) );
		}
		return $this->transforms[ $name ];
	}

	/**
	 * Names of all transforms, in registration order.
	 *
	 * @return array<int,string>
	 */
	public function names(): array {
		return array_keys( $this->transforms );
	}
}
