<?php
/**
 * Transform: country name or code to an ISO 3166-1 code.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\CountryIndex;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: output ("alpha2", the default, or "alpha3"). Reads codes and English or Arabic names. An unknown country becomes empty with a warning.
 */
final class IsoCountry extends BaseTransform {

	/**
	 * Set up with what it depends on.
	 *
	 * @param CountryIndex $countries Country lookup.
	 */
	public function __construct( private readonly CountryIndex $countries = new CountryIndex() ) {
	}

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'iso_country';
	}

	/**
	 * Find the code.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$text = $this->scalarText( $value );
		if ( null === $text || '' === Text::trim( $text ) ) {
			return $value;
		}
		$alpha2 = $this->countries->alpha2( $text );
		if ( null === $alpha2 ) {
			$context->warn( __( 'A country was not recognised and was left empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			return '';
		}
		return 'alpha3' === $this->text( $args, 'output', 'alpha2' ) ? (string) $this->countries->alpha3( $alpha2 ) : $alpha2;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		return in_array( $this->text( $args, 'output', 'alpha2' ), array( 'alpha2', 'alpha3' ), true ) ? array() : array( $this->missing( 'output' ) );
	}
}
