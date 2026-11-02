<?php
/**
 * Sales Taxes and Charges Templates: reading them and making prices agree with them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\TaxPricing;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * The tax code of an order is the name of a Sales Taxes and Charges Template. The template's rows are copied into the document's
 * `taxes`, and its `included_in_print_rate` flags tell whether ERPNext reads the unit price as tax-inclusive.
 */
final class ErpnextTaxes {

	/**
	 * Fields of a tax row that are copied from the template into a document.
	 *
	 * @var array<int,string>
	 */
	private const ROW_FIELDS = array( 'charge_type', 'row_id', 'account_head', 'description', 'included_in_print_rate', 'cost_center', 'rate', 'tax_amount' );

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient $client Client.
	 * @param string        $system System name.
	 */
	public function __construct( private readonly ErpnextClient $client, private readonly string $system ) {
	}

	/**
	 * Read a template and check that it can be used for a company.
	 *
	 * @param string $name    Template name.
	 * @param string $company Company of the order.
	 * @return array{name:string,rows:array<int,array<string,mixed>>,included:bool,rate:int|null}|ConnectorError rate is the sum of the rates in ten-thousandths of a percent when every row is On Net Total, else null.
	 *
	 * @throws ApiException On failure.
	 */
	public function template( string $name, string $company ): array|ConnectorError {
		$doc = $this->client->get( 'Sales Taxes and Charges Template', $name );
		if ( null === $doc ) {
			return Problems::missingTax( $this->system, $name );
		}
		if ( 1 === (int) ( $doc['disabled'] ?? 0 ) || ( '' !== (string) ( $doc['company'] ?? '' ) && (string) $doc['company'] !== $company ) ) {
			return Problems::refused(
				$this->system,
				'taxes_and_charges',
				sprintf(
					/* translators: %s: template name. */
					__( 'The tax template "%s" is disabled or belongs to another company.', 'estasoft-erp-crm-sync-engine' ),
					$name
				)
			);
		}
		$rows     = array();
		$included = array();
		$sum      = 0;
		$simple   = true;
		foreach ( (array) ( $doc['taxes'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$copy = array();
			foreach ( self::ROW_FIELDS as $field ) {
				if ( isset( $row[ $field ] ) && '' !== $row[ $field ] && null !== $row[ $field ] ) {
					$copy[ $field ] = $row[ $field ];
				}
			}
			$rows[]     = $copy;
			$included[] = 1 === (int) ( $row['included_in_print_rate'] ?? 0 );
			$rate       = TaxPricing::rate( isset( $row['rate'] ) ? (float) $row['rate'] : 0 );
			if ( 'On Net Total' === ( $row['charge_type'] ?? '' ) && null !== $rate ) {
				$sum += $rate;
			} else {
				$simple = false;
			}
		}
		if ( array() === $rows ) {
			return Problems::refused(
				$this->system,
				'taxes_and_charges',
				sprintf(
					/* translators: %s: template name. */
					__( 'The tax template "%s" has no rows.', 'estasoft-erp-crm-sync-engine' ),
					$name
				)
			);
		}
		if ( in_array( true, $included, true ) && in_array( false, $included, true ) ) {
			return Problems::refused(
				$this->system,
				'taxes_and_charges',
				sprintf(
					/* translators: %s: template name. */
					__( 'The tax template "%s" mixes rows that are included in the price and rows that are not.', 'estasoft-erp-crm-sync-engine' ),
					$name
				)
			);
		}
		return array(
			'name'     => (string) ( $doc['name'] ?? $name ),
			'rows'     => $rows,
			'included' => in_array( true, $included, true ),
			'rate'     => $simple ? $sum : null,
		);
	}

	/**
	 * A price made to agree with the template.
	 *
	 * @param int                                                 $minor              Price in minor units as the payload gives it.
	 * @param array{name:string,included:bool,rate:int|null}|null $template           The order's template, or null for no tax.
	 * @param bool                                                $payloadIncludesTax True when the payload's prices include tax.
	 * @return int|ConnectorError
	 */
	public function price( int $minor, ?array $template, bool $payloadIncludesTax ): int|ConnectorError {
		if ( null === $template || $template['included'] === $payloadIncludesTax || 0 === $minor ) {
			return $minor;
		}
		if ( null === $template['rate'] ) {
			return Problems::refused(
				$this->system,
				'taxes_and_charges',
				sprintf(
					/* translators: %s: template name. */
					__( 'The tax template "%s" treats prices differently from the shop and has rows that are not plain percentages on the net total, so prices cannot be converted exactly.', 'estasoft-erp-crm-sync-engine' ),
					$template['name']
				)
			);
		}
		return TaxPricing::convert( $minor, $template['rate'], $payloadIncludesTax, $template['included'] );
	}

	/**
	 * The enabled templates of a company for the tax mapping screen.
	 *
	 * @param string $company Company.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function codes( string $company ): array {
		$codes = array();
		$rows  = $this->client->rows( 'Sales Taxes and Charges Template', array( array( 'disabled', '=', 0 ), array( 'company', '=', $company ) ), array( 'name', 'title' ), 'name asc', 100 );
		foreach ( $rows as $row ) {
			$template = $this->template( (string) $row['name'], $company );
			$percent  = '0';
			if ( is_array( $template ) && null !== $template['rate'] ) {
				$percent = rtrim( rtrim( number_format( $template['rate'] / 10000, 4, '.', '' ), '0' ), '.' );
			}
			$codes[] = array(
				'code'         => (string) $row['name'],
				'name'         => (string) ( $row['title'] ?? $row['name'] ),
				'rate_percent' => '' === $percent ? '0' : $percent,
			);
		}
		return $codes;
	}
}
