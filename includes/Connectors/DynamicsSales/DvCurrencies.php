<?php
/**
 * Currencies of the environment.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Money columns in Dataverse are in the record's currency, a row of `transactioncurrencies`, and are stored with that currency's decimal
 * places. An order is therefore always pinned to a currency row with the order's ISO code, and refused when that row keeps fewer
 * decimals than the currency has (a KWD row set up with two places would round the third digit away).
 */
final class DvCurrencies {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient $dv     Client.
	 * @param string   $system System name for messages.
	 */
	public function __construct( private readonly DvClient $dv, private readonly string $system ) {
	}

	/**
	 * The currency row for an ISO code.
	 *
	 * @param string $iso ISO 4217 code.
	 * @return array{id:string,precision:int}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( string $iso ): array|ConnectorError {
		$rows = $this->dv->rows(
			'transactioncurrencies',
			array(
				'$filter' => OData::all( array( OData::eq( 'isocurrencycode', $iso ), 'statecode eq 0' ) ),
				'$select' => 'transactioncurrencyid,isocurrencycode,currencyprecision',
			)
		);
		foreach ( $rows as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['isocurrencycode'] ?? '' ), $iso ) && null !== OData::guid( $row['transactioncurrencyid'] ?? null ) ) {
				$precision = (int) ( $row['currencyprecision'] ?? 2 );
				if ( $precision < Amounts::exponent( $iso ) ) {
					return Failures::invalid(
						$this->system,
						'currency',
						sprintf(
							/* translators: 1: currency code, 2: decimal places the currency row keeps, 3: decimal places the currency has. */
							__( 'The currency %1$s is set up with %2$d decimal places in Dynamics 365 but has %3$d. Change its precision, then try again.', 'estasoft-erp-crm-sync-engine' ),
							$iso,
							$precision,
							Amounts::exponent( $iso )
						),
						'currency precision'
					);
				}
				return array(
					'id'        => (string) OData::guid( $row['transactioncurrencyid'] ),
					'precision' => $precision,
				);
			}
		}
		return Failures::invalid(
			$this->system,
			'currency',
			sprintf(
				/* translators: %s: currency code. */
				__( 'The currency %s is not set up in Dynamics 365 (Advanced settings, Business management, Currencies).', 'estasoft-erp-crm-sync-engine' ),
				$iso
			),
			'currency missing'
		);
	}

	/**
	 * The ISO code of a currency row.
	 *
	 * @param string $id Row GUID.
	 *
	 * @throws ApiException On failure.
	 */
	public function codeOf( string $id ): string {
		$guid = OData::guid( $id );
		if ( null === $guid ) {
			return '';
		}
		$row = $this->dv->one( 'transactioncurrencies(' . $guid . ')', array( '$select' => 'isocurrencycode' ) );
		return strtoupper( (string) ( $row['isocurrencycode'] ?? '' ) );
	}
}
