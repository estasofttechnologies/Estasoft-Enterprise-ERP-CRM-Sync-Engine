<?php
/**
 * Builds the `TaxMap` of src/api/types.ts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Tax;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\TaxMap;

/**
 * Puts together what the tax screen shows: the store's tax rates with the code chosen for each (Mapping\TaxMap), how many recent
 * orders used them (the port), the system's own codes (a closure over the connector) and the buyer tax number rules. On save it
 * carries the tax class of each row over, because the screen identifies a row by country, name and rate and does not send the class.
 */
final class TaxMapAssembler {

	/**
	 * Returns the system's tax codes for a connection.
	 *
	 * @var \Closure(int):array<int,array{code:string,label:string,rate_percent:int|float}>
	 */
	private \Closure $systemCodes;

	/**
	 * @param TaxMap    $taxes       Tax mapping store (live edit, logged).
	 * @param TaxSource $source      The store's tax rates and recent use.
	 * @param callable  $systemCodes Lists the system's tax codes for a connection id.
	 */
	public function __construct(
		private readonly TaxMap $taxes,
		private readonly TaxSource $source,
		callable $systemCodes
	) {
		$this->systemCodes = \Closure::fromCallable( $systemCodes );
	}

	/**
	 * The `TaxMap` for a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>
	 */
	public function build( int $connectionId ): array {
		$usage = $this->source->ordersLast30Days();
		$rows  = array();
		foreach ( $this->taxes->overview( $connectionId, $this->source->shopRates() ) as $row ) {
			$rows[] = array(
				'country'      => $row['country'],
				'tax_name'     => $row['tax_name'],
				'rate_percent' => $row['rate_percent'],
				'system_code'  => $row['system_code'],
				'orders_30d'   => (int) ( $usage[ $this->usageKey( $row['country'], $row['rate_percent'] ) ] ?? 0 ),
			);
		}
		return array(
			'rows'            => $rows,
			'system_codes'    => ( $this->systemCodes )( $connectionId ),
			'buyer_tax_rules' => $this->taxes->buyerRules( $connectionId ),
		);
	}

	/**
	 * Save the rows the screen sends and return the new `TaxMap`.
	 *
	 * @param int                                 $connectionId Connection.
	 * @param array<int,array<string,mixed>>      $rows         Rows with country, tax_name, rate_percent and system_code.
	 * @param array<int,array<string,mixed>>|null $buyerRules   Buyer tax number rules, or null to keep them.
	 * @param string                              $by           Display name of the person.
	 * @return array<string,mixed>
	 *
	 * @throws ValidationException With one message per wrong row when a row has no country code or a rate outside 0 to 100, or a
	 *                             buyer tax rule has no country code. Nothing is saved then.
	 */
	public function save( int $connectionId, array $rows, ?array $buyerRules, string $by ): array {
		$this->check( $rows, $buyerRules );
		$known = $this->taxes->overview( $connectionId, $this->source->shopRates() );
		$merge = array();
		foreach ( $rows as $row ) {
			$row['tax_class'] = $row['tax_class'] ?? $this->classOf( $known, $row );
			$merge[]          = $row;
		}
		$this->taxes->save( $connectionId, $merge, $buyerRules, $by );
		return $this->build( $connectionId );
	}

	/**
	 * The same checks as Mapping\TaxMap makes on save, made first so that the person gets a message per row from here and nothing
	 * unexpected can come out of the save for a mistake they made.
	 *
	 * @param array<int,array<string,mixed>>      $rows       Rows from the screen.
	 * @param array<int,array<string,mixed>>|null $buyerRules Buyer tax rules.
	 *
	 * @throws ValidationException When a row is wrong.
	 */
	private function check( array $rows, ?array $buyerRules ): void {
		$problems = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! $this->isCountry( $row['country'] ?? null ) ) {
				$problems[ 'rows.' . $index . '.country' ] = __( 'Enter a two-letter country code, for example SA.', 'estasoft-erp-crm-sync-engine' );
			}
			$key = MinorUnits::rateKey( $row['rate_percent'] ?? null );
			if ( null === $key || (float) $key < 0 || (float) $key > 100 ) {
				$problems[ 'rows.' . $index . '.rate_percent' ] = __( 'Enter a rate from 0 to 100.', 'estasoft-erp-crm-sync-engine' );
			}
		}
		foreach ( array_values( $buyerRules ?? array() ) as $index => $rule ) {
			if ( ! $this->isCountry( $rule['country'] ?? null ) ) {
				$problems[ 'buyer_tax_rules.' . $index . '.country' ] = __( 'Enter a two-letter country code, for example SA.', 'estasoft-erp-crm-sync-engine' );
			}
		}
		if ( array() !== $problems ) {
			throw new ValidationException( $problems );
		}
	}

	/**
	 * Whether a value is a two-letter country code.
	 *
	 * @param mixed $value Candidate.
	 */
	private function isCountry( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z]{2}$/', trim( $value ) );
	}

	/**
	 * The tax class of the known row that the screen's row stands for.
	 *
	 * @param array<int,array<string,mixed>> $known Rows now.
	 * @param array<string,mixed>            $row   Row from the screen.
	 */
	private function classOf( array $known, array $row ): string {
		$key   = $this->usageKey( (string) ( $row['country'] ?? '' ), $row['rate_percent'] ?? null );
		$match = '';
		foreach ( $known as $candidate ) {
			if ( $this->usageKey( (string) $candidate['country'], $candidate['rate_percent'] ) !== $key ) {
				continue;
			}
			if ( (string) ( $row['tax_name'] ?? '' ) === (string) $candidate['tax_name'] ) {
				return (string) $candidate['tax_class'];
			}
			$match = '' === $match ? (string) $candidate['tax_class'] : $match;
		}
		return $match;
	}

	/**
	 * Key of a row in the usage counts.
	 *
	 * @param string $country Country code.
	 * @param mixed  $rate    Rate in percent.
	 */
	private function usageKey( string $country, mixed $rate ): string {
		return strtoupper( $country ) . '|' . (string) MinorUnits::rateKey( $rate );
	}
}
