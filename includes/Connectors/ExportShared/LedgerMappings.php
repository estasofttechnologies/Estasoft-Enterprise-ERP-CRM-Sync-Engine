<?php
/**
 * Version 1 mapping rules shared by the ledger-based export connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * The rules a merchant sees before editing anything. They follow the rule model of docs/ARCHITECTURE.md and fill exactly the paths
 * of LedgerFields. Tax codes come from the tax mapping through the `tax_lookup` transform.
 */
final class LedgerMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'  => $this->orders(),
			'refunds' => $this->refunds(),
			default   => array(),
		};
	}

	/**
	 * Orders.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function orders(): array {
		return array_merge(
			array(
				$this->rule( 'document-number', 'document.number', 'order.number', array( $this->transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-reference', 'document.reference', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-date', 'document.date', 'order.created_at', array( $this->shopDate() ), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-currency', 'document.currency', 'order.currency' ),
				$this->rule( 'document-total', 'document.total', 'order.total' ),
				$this->rule( 'document-excluded-total', 'document.excluded_total', 'order.excluded_total' ),
				$this->rule( 'document-note', 'document.note', 'order.note', array( $this->transform( 'trim' ) ) ),
			),
			$this->customer(),
			array(
				$this->rule( 'line-sku', 'line[].sku', 'lines[].sku', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-name', 'line[].name', 'lines[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-quantity', 'line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-unit-price', 'line[].unit_price', 'lines[].unit_price' ),
				$this->rule( 'line-net', 'line[].net', 'lines[].line_total', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-tax', 'line[].tax', 'lines[].line_tax' ),
				$this->rule( 'line-tax-code', 'line[].tax_code', 'lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'line-tax-rate', 'line[].tax_rate', 'lines[].tax_rate' ),
				$this->rule( 'shipping-name', 'shipping[].name', 'shipping_lines[].method_title', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'shipping-net', 'shipping[].net', 'shipping_lines[].total' ),
				$this->rule( 'shipping-tax', 'shipping[].tax', 'shipping_lines[].tax' ),
				$this->rule( 'shipping-tax-code', 'shipping[].tax_code', 'shipping_lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'shipping-tax-rate', 'shipping[].tax_rate', 'shipping_lines[].tax_rate' ),
				$this->rule( 'fee-name', 'fee[].name', 'fees[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'fee-net', 'fee[].net', 'fees[].total' ),
				$this->rule( 'fee-tax', 'fee[].tax', 'fees[].tax' ),
				$this->rule( 'payment-method', 'payment.method', 'order.payment.method_id' ),
				$this->rule( 'payment-title', 'payment.title', 'order.payment.method_title', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'payment-paid-at', 'payment.paid_at', 'order.paid_at', array( $this->shopDate() ) ),
				$this->rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( $this->transform( 'trim' ) ) ),
			)
		);
	}

	/**
	 * Refunds to credit notes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function refunds(): array {
		return array_merge(
			array(
				$this->rule( 'document-number', 'document.number', 'order.number', array( $this->transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-reference', 'document.reference', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-refund-id', 'document.refund_id', 'refund.id', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-date', 'document.date', 'refund.created_at', array( $this->shopDate() ), true, 'SYNC-VAL-002' ),
				$this->rule( 'document-currency', 'document.currency', 'order.currency' ),
				$this->rule( 'document-total', 'document.total', 'refund.amount' ),
				$this->rule( 'document-excluded-total', 'document.excluded_total', 'refund.excluded_amount' ),
				$this->rule( 'document-note', 'document.note', 'refund.reason', array( $this->transform( 'trim' ) ) ),
			),
			$this->customer(),
			array(
				$this->rule( 'refund-line-sku', 'refund_line[].sku', 'refund.lines[].sku', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
				$this->rule( 'refund-line-net', 'refund_line[].net', 'refund.lines[].amount', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'refund-line-tax', 'refund_line[].tax', 'refund.lines[].tax' ),
				$this->rule( 'refund-shipping', 'refund_shipping.gross', 'refund.shipping_amount' ),
				$this->rule( 'refund-other', 'refund_other.gross', 'refund.other_amount' ),
				$this->rule( 'original-line-sku', 'original_line[].sku', 'lines[].sku', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'original-line-tax-code', 'original_line[].tax_code', 'lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'original-line-tax-rate', 'original_line[].tax_rate', 'lines[].tax_rate' ),
				$this->rule( 'original-shipping-tax-code', 'original_shipping[].tax_code', 'shipping_lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'original-shipping-tax-rate', 'original_shipping[].tax_rate', 'shipping_lines[].tax_rate' ),
			)
		);
	}

	/**
	 * The customer rules: a company name wins over the first and last name when both exist (a later rule overwrites an earlier one).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function customer(): array {
		return array(
			$this->rule( 'customer-name-person', 'customer.name', array( 'billing.first_name', 'billing.last_name' ), array( $this->transform( 'join', array( 'separator' => ' ' ) ), $this->transform( 'trim' ) ) ),
			$this->rule( 'customer-name-company', 'customer.name', 'billing.company', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'customer-id', 'customer.id', 'customer.id' ),
		);
	}

	/**
	 * The date in the shop's own time zone, which is the date the accountant expects.
	 *
	 * @return array<string,mixed>
	 */
	private function shopDate(): array {
		return $this->transform(
			'date_utc',
			array(
				'format'          => 'date',
				'output_timezone' => 'store',
			)
		);
	}

	/**
	 * One transform step.
	 *
	 * @param string              $type Transform name.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	private function transform( string $type, array $args = array() ): array {
		return array() === $args ? array( 'type' => $type ) : array(
			'type' => $type,
			'args' => $args,
		);
	}

	/**
	 * One rule.
	 *
	 * @param string                                      $id         Stable id inside the rule list.
	 * @param string                                      $target     Target path.
	 * @param string|array<int,string>|array{const:mixed} $source     Source path, list of paths, or a constant.
	 * @param array<int,array<string,mixed>>              $transforms Transforms in order.
	 * @param bool                                        $required   Whether an empty result stops the item.
	 * @param string|null                                 $errorCode  Catalog code raised when a required value is empty.
	 * @return array<string,mixed>
	 */
	private function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
		$rule = array(
			'id'         => $id,
			'target'     => $target,
			'source'     => $source,
			'transforms' => $transforms,
			'required'   => $required,
		);
		if ( null !== $errorCode ) {
			$rule['error_code'] = $errorCode;
		}
		return $rule;
	}
}
