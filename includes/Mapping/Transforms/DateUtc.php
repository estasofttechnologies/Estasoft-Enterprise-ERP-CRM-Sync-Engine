<?php
/**
 * Transform: a date and time to UTC.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: format ("mysql" gives 2026-11-02 07:40:00, "iso" gives 2026-11-02T07:40:00Z, "date" gives 2026-11-02; default mysql),
 * timezone (the zone of text that carries no offset; default UTC). Reads ISO 8601, MySQL dates and Unix timestamps.
 */
final class DateUtc extends BaseTransform {

	/**
	 * Output formats by name.
	 */
	private const FORMATS = array(
		'mysql' => 'Y-m-d H:i:s',
		'iso'   => 'Y-m-d\TH:i:s\Z',
		'date'  => 'Y-m-d',
	);

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'date_utc';
	}

	/**
	 * Convert the date.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws TransformArgumentException When the format or time zone is not one the transform knows.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$text = $this->scalarText( $value );
		if ( null === $text || '' === Text::trim( $text ) ) {
			return $value;
		}
		$format = self::FORMATS[ $this->text( $args, 'format', 'mysql' ) ] ?? null;
		$zone   = $this->zone( $this->text( $args, 'timezone', 'UTC' ) );
		if ( null === $format || null === $zone ) {
			throw new TransformArgumentException( esc_html( $this->missing( null === $format ? 'format' : 'timezone' ) ) );
		}
		$date = $this->parse( Text::asciiDigits( Text::trim( $text ) ), $zone );
		if ( null === $date ) {
			$context->warn( __( 'A date could not be read and was left empty.', 'estasoft-erp-crm-sync-engine' ) );
			return '';
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( $format );
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$problems = array();
		if ( ! isset( self::FORMATS[ $this->text( $args, 'format', 'mysql' ) ] ) ) {
			$problems[] = $this->missing( 'format' );
		}
		if ( null === $this->zone( $this->text( $args, 'timezone', 'UTC' ) ) ) {
			$problems[] = $this->missing( 'timezone' );
		}
		return $problems;
	}

	/**
	 * A time zone by name, or null when the name is not a zone.
	 *
	 * @param string $name Zone name such as Asia/Dubai or UTC.
	 */
	private function zone( string $name ): ?\DateTimeZone {
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Read a date from text.
	 *
	 * @param string        $text Text.
	 * @param \DateTimeZone $zone Zone for text without an offset.
	 */
	private function parse( string $text, \DateTimeZone $zone ): ?\DateTimeImmutable {
		try {
			if ( 1 === preg_match( '/^\d{10}$/', $text ) ) {
				return new \DateTimeImmutable( '@' . $text );
			}
			if ( 1 === preg_match( '/^\d{13}$/', $text ) ) {
				return new \DateTimeImmutable( '@' . substr( $text, 0, 10 ) );
			}
			return new \DateTimeImmutable( $text, $zone );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
