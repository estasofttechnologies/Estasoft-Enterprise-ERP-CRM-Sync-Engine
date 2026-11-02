<?php
/**
 * Tax mapping: WooCommerce tax rates to the connected system's tax codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Mapping\Ports\EventLog;
use Estasoft\SyncEngine\Mapping\Ports\MappingStore;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Stored through the mapping repository as type "tax": a flat list of entries, each either
 * {kind: tax, country, tax_class, tax_name, rate_percent, system_code} or {kind: buyer_tax, country, digits, required_for_business}.
 * Unlike order and customer rules, tax entries have no drafts or versions: a save replaces the live entries at once and is logged. A rate with no code stops the order with SYNC-MAP-002 (see the tax_lookup transform).
 */
final class TaxMap implements TaxResolver {

	/**
	 * Mapping type used in the repository.
	 */
	public const TYPE = 'tax';

	/**
	 * Live version read so far, by connection (kept for the length of the request or worker run): whether one exists and its entries.
	 *
	 * @var array<int,array{live:bool,entries:array<int,array<string,mixed>>}>
	 */
	private array $cache = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param MappingStore  $store Mapping versions.
	 * @param EventLog      $log   Event log.
	 * @param BuyerTaxRules $buyer Buyer tax number rules.
	 */
	public function __construct(
		private readonly MappingStore $store,
		private readonly EventLog $log,
		private readonly BuyerTaxRules $buyer = new BuyerTaxRules(),
	) {
	}

	/**
	 * The tax rows of a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<int,array{country:string,tax_class:string,tax_name:string,rate_percent:float|int,system_code:?string}>
	 */
	public function rows( int $connectionId ): array {
		$rows = array();
		foreach ( $this->entries( $connectionId ) as $entry ) {
			if ( 'tax' !== ( $entry['kind'] ?? '' ) ) {
				continue;
			}
			$code   = Text::toString( $entry['system_code'] ?? '' );
			$rows[] = array(
				'country'      => strtoupper( Text::toString( $entry['country'] ?? '' ) ),
				'tax_class'    => Text::toString( $entry['tax_class'] ?? '' ),
				'tax_name'     => Text::toString( $entry['tax_name'] ?? '' ),
				'rate_percent' => is_numeric( $entry['rate_percent'] ?? null ) ? $entry['rate_percent'] + 0 : 0,
				'system_code'  => '' === $code ? null : $code,
			);
		}
		return $rows;
	}

	/**
	 * Buyer tax number rules of a connection: the saved ones, or the defaults when none were saved.
	 *
	 * @param int $connectionId Connection.
	 * @return array<int,array{country:string,digits:?int,required_for_business:bool}>
	 */
	public function buyerRules( int $connectionId ): array {
		$rules = array();
		foreach ( $this->entries( $connectionId ) as $entry ) {
			if ( 'buyer_tax' !== ( $entry['kind'] ?? '' ) ) {
				continue;
			}
			$rules[] = array(
				'country'               => strtoupper( Text::toString( $entry['country'] ?? '' ) ),
				'digits'                => isset( $entry['digits'] ) && is_numeric( $entry['digits'] ) && (int) $entry['digits'] > 0 ? (int) $entry['digits'] : null,
				'required_for_business' => ! empty( $entry['required_for_business'] ),
			);
		}
		return array() === $rules && ! $this->hasLive( $connectionId ) ? $this->buyer->defaults() : $rules;
	}

	/**
	 * The system's tax code for a country, tax class and rate. An exact tax class match wins; otherwise a row for the same country
	 * and rate with any class is used when all such rows agree.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $country      Alpha-2 code.
	 * @param string $taxClass     Tax class slug, empty for standard.
	 * @param string $rateKey      Rate from MinorUnits::rateKey().
	 */
	public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
		$country  = strtoupper( $country );
		$anyClass = array();
		foreach ( $this->rows( $connectionId ) as $row ) {
			if ( $row['country'] !== $country || MinorUnits::rateKey( $row['rate_percent'] ) !== $rateKey || null === $row['system_code'] ) {
				continue;
			}
			if ( $row['tax_class'] === $taxClass ) {
				return $row['system_code'];
			}
			$anyClass[ $row['system_code'] ] = true;
		}
		return 1 === count( $anyClass ) ? (string) array_key_first( $anyClass ) : null;
	}

	/**
	 * Add the WooCommerce tax rates that have no row yet, with no code, so the screen can show what still needs one.
	 *
	 * @param int                                                                                      $connectionId Connection.
	 * @param array<int,array{country:string,tax_class:string,tax_name:string,rate_percent:float|int}> $wooRates     Rates configured in WooCommerce.
	 * @return array<int,array{country:string,tax_class:string,tax_name:string,rate_percent:float|int,system_code:?string}>
	 */
	public function overview( int $connectionId, array $wooRates ): array {
		$rows = $this->rows( $connectionId );
		foreach ( $wooRates as $rate ) {
			$country = strtoupper( $rate['country'] );
			$key     = MinorUnits::rateKey( $rate['rate_percent'] );
			foreach ( $rows as $row ) {
				if ( $row['country'] === $country && MinorUnits::rateKey( $row['rate_percent'] ) === $key && $row['tax_class'] === $rate['tax_class'] ) {
					continue 2;
				}
			}
			$rows[] = array(
				'country'      => $country,
				'tax_class'    => $rate['tax_class'],
				'tax_name'     => $rate['tax_name'],
				'rate_percent' => $rate['rate_percent'],
				'system_code'  => null,
			);
		}
		return $rows;
	}

	/**
	 * Save the tax mapping. It is live at once and each changed row is written to the log.
	 *
	 * @param int                                 $connectionId Connection.
	 * @param array<int,array<string,mixed>>      $rows         Rows: country, tax_class (optional), tax_name, rate_percent, system_code (empty for none).
	 * @param array<int,array<string,mixed>>|null $buyerRules   Buyer tax rules; null keeps the saved ones.
	 * @param string                              $by           Display name of the person saving.
	 *
	 * @throws \InvalidArgumentException When a row has no country or a rate that is not a number from 0 to 100.
	 */
	public function save( int $connectionId, array $rows, ?array $buyerRules, string $by ): void {
		$before  = $this->rows( $connectionId );
		$entries = array();
		foreach ( $rows as $row ) {
			$entries[] = $this->cleanRow( $row );
		}
		foreach ( $buyerRules ?? $this->buyerRules( $connectionId ) as $rule ) {
			$entries[] = $this->cleanBuyerRule( $rule );
		}

		$this->store->replaceLive( $connectionId, self::TYPE, $entries, $by );
		unset( $this->cache[ $connectionId ] );

		$this->logChanges( $connectionId, $by, $before, $this->rows( $connectionId ) );
	}

	/**
	 * Check the buyer's tax number for the order's billing country.
	 *
	 * @param int            $connectionId Connection.
	 * @param CanonicalOrder $order        Order.
	 *
	 * @throws MappingException SYNC-VAL-001 when the number is missing or wrong for a country that has a rule.
	 */
	public function assertBuyerTaxNumber( int $connectionId, CanonicalOrder $order ): void {
		$failure = $this->buyer->check( $this->buyerRules( $connectionId ), $order );
		if ( null !== $failure ) {
			throw $failure;
		}
	}

	/**
	 * Entries of the live version.
	 *
	 * @param int $connectionId Connection.
	 * @return array<int,array<string,mixed>>
	 */
	private function entries( int $connectionId ): array {
		return $this->load( $connectionId )['entries'];
	}

	/**
	 * Whether a tax version has ever been published for the connection.
	 *
	 * @param int $connectionId Connection.
	 */
	private function hasLive( int $connectionId ): bool {
		return $this->load( $connectionId )['live'];
	}

	/**
	 * Read the live version once.
	 *
	 * @param int $connectionId Connection.
	 * @return array{live:bool,entries:array<int,array<string,mixed>>}
	 */
	private function load( int $connectionId ): array {
		if ( ! isset( $this->cache[ $connectionId ] ) ) {
			$live  = $this->store->live( $connectionId, self::TYPE );
			$rules = is_array( $live ) && is_array( $live['rules'] ?? null ) ? $live['rules'] : array();

			$this->cache[ $connectionId ] = array(
				'live'    => null !== $live,
				'entries' => array_values( array_filter( $rules, 'is_array' ) ),
			);
		}
		return $this->cache[ $connectionId ];
	}

	/**
	 * A tax row cleaned for storage.
	 *
	 * @param array<string,mixed> $row Row as received.
	 * @return array<string,mixed>
	 *
	 * @throws \InvalidArgumentException When the country or rate is unusable.
	 */
	private function cleanRow( array $row ): array {
		$country = strtoupper( Text::trim( Text::toString( $row['country'] ?? '' ) ) );
		$rate    = $row['rate_percent'] ?? null;
		$key     = MinorUnits::rateKey( $rate );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) || null === $key || (float) $key < 0 || (float) $key > 100 ) {
			throw new \InvalidArgumentException( esc_html__( 'Each tax row needs a country code and a rate from 0 to 100.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return array(
			'kind'         => 'tax',
			'country'      => $country,
			'tax_class'    => $this->plain( Text::toString( $row['tax_class'] ?? '' ), 100 ),
			'tax_name'     => $this->plain( Text::toString( $row['tax_name'] ?? '' ), 191 ),
			'rate_percent' => $this->number( $key ),
			'system_code'  => $this->plain( Text::toString( $row['system_code'] ?? '' ), 191 ),
		);
	}

	/**
	 * A rate key as a number: 5 stays an integer, 7.5 a float.
	 *
	 * @param string $key Rate from MinorUnits::rateKey().
	 * @return int|float
	 */
	private function number( string $key ): int|float {
		$float = (float) $key;
		return floor( $float ) === $float ? (int) $float : $float;
	}

	/**
	 * A buyer tax rule cleaned for storage.
	 *
	 * @param array<string,mixed> $rule Rule as received.
	 * @return array<string,mixed>
	 *
	 * @throws \InvalidArgumentException When the country is unusable.
	 */
	private function cleanBuyerRule( array $rule ): array {
		$country = strtoupper( Text::trim( Text::toString( $rule['country'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Each buyer tax rule needs a country code.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$digits = $rule['digits'] ?? null;
		return array(
			'kind'                  => 'buyer_tax',
			'country'               => $country,
			'digits'                => is_numeric( $digits ) && (int) $digits > 0 && (int) $digits <= 40 ? (int) $digits : null,
			'required_for_business' => ! empty( $rule['required_for_business'] ),
		);
	}

	/**
	 * Text without control characters or angle brackets, cut to a length.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 */
	private function plain( string $text, int $length ): string {
		$text = Text::trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', '', $text ) );
		return Text::trim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length ) );
	}

	/**
	 * Write one log line for each row that was added, changed or removed.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $by           Person.
	 * @param array<int,array<string,mixed>> $before       Rows before.
	 * @param array<int,array<string,mixed>> $after        Rows after.
	 */
	private function logChanges( int $connectionId, string $by, array $before, array $after ): void {
		$old = array();
		foreach ( $before as $row ) {
			$old[ $this->rowKey( $row ) ] = $row['system_code'];
		}
		$new = array();
		foreach ( $after as $row ) {
			$new[ $this->rowKey( $row ) ] = $row['system_code'];
		}
		foreach ( $new + $old as $key => $unused ) {
			$from = $old[ $key ] ?? null;
			$to   = $new[ $key ] ?? null;
			if ( $from === $to ) {
				continue;
			}
			$this->log->info(
				__( 'Tax mapping changed.', 'estasoft-erp-crm-sync-engine' ),
				array(
					'connection_id' => $connectionId,
					'by'            => $by,
					'row'           => $key,
					'from'          => $from ?? '',
					'to'            => $to ?? '',
				)
			);
		}
	}

	/**
	 * Stable key of a row for comparing before and after.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function rowKey( array $row ): string {
		return sprintf( '%1$s|%2$s|%3$s', $row['country'], $row['tax_class'], (string) MinorUnits::rateKey( $row['rate_percent'] ) );
	}
}
