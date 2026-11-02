<?php
/**
 * What Test connection learns about a Zoho CRM account: its deal pipelines and the field that carries the order reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The Deals module has a mandatory Pipeline field when pipelines are on (insert records page), and the order reference belongs in a field of
 * its own so that renaming a deal cannot lose it. Both are read, and the field is created when it is missing, only when the user may: a
 * refusal for lack of rights is an answer ("could not"), any other failure (network, rate limit) is thrown so the test fails loudly.
 */
final class ZohoCrmSetup {

	/**
	 * Label of the field the connector makes; Zoho derives the API name from it and the connector reads the real one back.
	 */
	public const FIELD_LABEL = 'Woo Order Ref';

	/**
	 * API name expected for that label.
	 */
	public const FIELD_NAME = 'Woo_Order_Ref';

	/**
	 * Create the object.
	 *
	 * @param ZohoCrmApi $zoho API.
	 */
	public function __construct( private readonly ZohoCrmApi $zoho ) {
	}

	/**
	 * The pipelines of the Deals module and which one new orders go to.
	 *
	 * Statuses: `none` (no pipelines, the Pipeline field is not needed), `unreadable` (the sign-in lacks the layouts or pipeline scope),
	 * `chosen` (the named pipeline exists, or the only or default one was picked), `unknown` (the named pipeline does not exist),
	 * `ambiguous` (several pipelines and none is the default, so the merchant has to choose).
	 *
	 * @param string $wanted The pipeline the merchant named; empty when none.
	 * @return array{status:string,pipeline:string,names:array<int,string>}
	 * @throws ApiException For a failure that is not a refusal of rights.
	 */
	public function pipelines( string $wanted ): array {
		try {
			$rows = $this->pipelineRows();
		} catch ( ApiException $exception ) {
			$code = $exception->error()->code;
			if ( ErrorCode::Auth004 === $code ) {
				return array(
					'status'   => 'unreadable',
					'pipeline' => '',
					'names'    => array(),
				);
			}
			if ( ErrorCode::Val002 === $code ) {
				$rows = array(); // Zoho answers a plain refusal when the module has no pipelines to list.
			} else {
				throw $exception;
			}
		}
		$names = array_values( array_filter( array_map( static fn( array $row ): string => (string) ( $row['display_value'] ?? $row['actual_value'] ?? '' ), $rows ), static fn( string $name ): bool => '' !== $name ) );
		if ( array() === $rows ) {
			return array(
				'status'   => 'none',
				'pipeline' => '',
				'names'    => array(),
			);
		}
		if ( '' !== $wanted ) {
			foreach ( $rows as $row ) {
				if ( Text::same( (string) ( $row['display_value'] ?? '' ), $wanted ) || Text::same( (string) ( $row['actual_value'] ?? '' ), $wanted ) ) {
					return array(
						'status'   => 'chosen',
						'pipeline' => $wanted,
						'names'    => $names,
					);
				}
			}
			return array(
				'status'   => 'unknown',
				'pipeline' => '',
				'names'    => $names,
			);
		}
		$pick = 1 === count( $rows ) ? $rows[0] : null;
		foreach ( $rows as $row ) {
			if ( null === $pick && ! empty( $row['default'] ) ) {
				$pick = $row;
			}
		}
		if ( null === $pick ) {
			return array(
				'status'   => 'ambiguous',
				'pipeline' => '',
				'names'    => $names,
			);
		}
		return array(
			'status'   => 'chosen',
			'pipeline' => (string) ( $pick['actual_value'] ?? $pick['display_value'] ?? '' ),
			'names'    => $names,
		);
	}

	/**
	 * The API name of the reference field on Deals, made when it is missing; null when it is missing and could not be made.
	 *
	 * @return array{field:string,created:bool}|null
	 * @throws ApiException For a failure that is not a refusal of rights or of the field itself.
	 */
	public function referenceField(): ?array {
		try {
			$found = $this->findField();
			if ( null !== $found ) {
				return array(
					'field'   => $found,
					'created' => false,
				);
			}
			$this->zoho->createField(
				'Deals',
				array(
					'field_label' => self::FIELD_LABEL,
					'data_type'   => 'text',
					'length'      => 40,
				)
			);
			$found = $this->findField();
			return null === $found ? null : array(
				'field'   => $found,
				'created' => true,
			);
		} catch ( ApiException $exception ) {
			if ( in_array( $exception->error()->code, array( ErrorCode::Auth004, ErrorCode::Val002 ), true ) ) {
				return null;
			}
			throw $exception;
		}
	}

	/**
	 * Pipelines of the first active layout of Deals.
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws ApiException When Zoho refuses a call.
	 */
	private function pipelineRows(): array {
		$layoutId = '';
		foreach ( $this->zoho->layouts( 'Deals' ) as $layout ) {
			$active = '' === (string) ( $layout['status'] ?? '' ) || 'active' === strtolower( (string) $layout['status'] );
			if ( $active && ( '' === $layoutId || Text::same( (string) ( $layout['name'] ?? '' ), 'Standard' ) ) ) {
				$layoutId = (string) ( $layout['id'] ?? '' );
			}
		}
		return '' === $layoutId ? array() : $this->zoho->pipelines( $layoutId );
	}

	/**
	 * The API name of the existing reference field, or null.
	 *
	 * @throws ApiException When Zoho refuses the call.
	 */
	private function findField(): ?string {
		foreach ( $this->zoho->fields( 'Deals' ) as $field ) {
			$name = (string) ( $field['api_name'] ?? '' );
			if ( '' !== $name && ( Text::same( $name, self::FIELD_NAME ) || Text::same( (string) ( $field['field_label'] ?? '' ), self::FIELD_LABEL ) ) ) {
				return $name;
			}
		}
		return null;
	}
}
