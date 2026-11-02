<?php
/**
 * Draft, publish, roll back and list mapping versions.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Mapping\Ports\ConnectionSource;
use Estasoft\SyncEngine\Mapping\Ports\EventLog;
use Estasoft\SyncEngine\Mapping\Ports\MappingStore;

defined( 'ABSPATH' ) || exit;

/**
 * The editing workflow of the mapping screens. The history is linear: a draft is numbered after the newest version; publishing makes
 * the draft the live version and retires the old one; rolling back copies an old version into a new live version and leaves the draft
 * alone. Nothing is deleted. Rules are cleaned on the way in, and publishing refuses a rule set with problems.
 */
final class MappingService {

	/**
	 * Types that keep versions; the others are edited live.
	 */
	private const VERSIONED = array( 'orders', 'customers', 'refunds', 'stock', 'tracking' );

	/**
	 * Types whose rules read from the connected system and write to WooCommerce.
	 */
	private const INBOUND = array( 'stock', 'tracking' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param MappingStore      $store       Mapping versions.
	 * @param RuleEngine        $engine      Rule engine (for validation).
	 * @param RuleSanitizer     $sanitizer   Cleans incoming rules.
	 * @param ConnectionSource  $connections Connections.
	 * @param ConnectorRegistry $connectors  Installed connectors.
	 * @param CanonicalFields   $fields      Canonical fields.
	 * @param EventLog          $log         Event log.
	 */
	public function __construct(
		private readonly MappingStore $store,
		private readonly RuleEngine $engine,
		private readonly RuleSanitizer $sanitizer,
		private readonly ConnectionSource $connections,
		private readonly ConnectorRegistry $connectors,
		private readonly CanonicalFields $fields,
		private readonly EventLog $log
	) {
	}

	/**
	 * Everything a mapping screen needs for one type (the MappingSet of src/api/types.ts).
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<string,mixed>
	 */
	public function set( int $connectionId, string $type ): array {
		$live   = $this->store->live( $connectionId, $type );
		$draft  = $this->store->draft( $connectionId, $type );
		$fields = $this->fieldLists( $connectionId, $type );

		if ( null !== $draft ) {
			$draft['rules'] = $this->flagged( $this->rulesOf( $draft ), $live );
		}
		return array(
			'type'          => $type,
			'versioned'     => in_array( $type, self::VERSIONED, true ),
			'live'          => $live,
			'draft'         => $draft,
			'versions'      => $this->store->versions( $connectionId, $type ),
			'target_fields' => $fields['target'],
			'source_fields' => $fields['source'],
		);
	}

	/**
	 * Save rules as the draft.
	 *
	 * @param int                     $connectionId Connection.
	 * @param string                  $type         Mapping type.
	 * @param array<int|string,mixed> $rules        Rules as received.
	 * @param string|null             $note         Note for the team.
	 * @param string                  $by           Display name of the person.
	 * @return array<string,mixed> The draft.
	 */
	public function saveDraft( int $connectionId, string $type, array $rules, ?string $note, string $by ): array {
		$draft = $this->store->saveDraft( $connectionId, $type, $this->sanitizer->clean( $rules ), $note, $by );
		$this->log->info(
			__( 'Mapping draft saved.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id' => $connectionId,
				'type'          => $type,
				'version'       => $draft['version'] ?? null,
				'by'            => $by,
			)
		);
		return $draft;
	}

	/**
	 * Problems in a rule set, checked against what the connector offers.
	 *
	 * @param int                     $connectionId Connection.
	 * @param string                  $type         Mapping type.
	 * @param array<int|string,mixed> $rules        Rules as stored or received.
	 * @return array<int,RuleError>
	 */
	public function validate( int $connectionId, string $type, array $rules ): array {
		if ( in_array( $type, self::INBOUND, true ) ) {
			return $this->engine->validate( $this->sanitizer->clean( $rules ), array(), false );
		}
		$connector = $this->connectorFor( $connectionId );
		$targets   = null === $connector ? array() : $connector->describeFields( $type );
		$rules     = $this->sanitizer->clean( $rules );
		if ( array() === $targets ) {
			// Without the other system's field list nothing can be checked, so nothing may be published: the problems list says why.
			return array_merge(
				array(
					new RuleError(
						'',
						'target',
						'fields_unavailable',
						__( 'The fields of the connected system could not be read, so this mapping cannot be checked. Check the connection, then try again.', 'estasoft-erp-crm-sync-engine' )
					),
				),
				$this->engine->validate( $rules, array() )
			);
		}
		return $this->engine->validate( $rules, $targets );
	}

	/**
	 * What publishing the draft would change: rules added, changed and removed compared with the live version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array{kind:string,rule_id:string,target:string,source:string,before:?array<string,mixed>,after:?array<string,mixed>}>
	 */
	public function changes( int $connectionId, string $type ): array {
		$draft = $this->store->draft( $connectionId, $type );
		if ( null === $draft ) {
			return array();
		}
		$before = $this->byId( $this->rulesOf( $this->store->live( $connectionId, $type ) ) );
		$after  = $this->byId( $this->rulesOf( $draft ) );

		$changes = array();
		foreach ( $after as $id => $rule ) {
			if ( ! isset( $before[ $id ] ) ) {
				$changes[] = $this->change( 'added', (string) $id, null, $rule );
			} elseif ( $this->differs( $before[ $id ], $rule ) ) {
				$changes[] = $this->change( 'changed', (string) $id, $before[ $id ], $rule );
			}
		}
		foreach ( $before as $id => $rule ) {
			if ( ! isset( $after[ $id ] ) ) {
				$changes[] = $this->change( 'removed', (string) $id, $rule, null );
			}
		}
		return $changes;
	}

	/**
	 * Publish the draft as the new live version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param string $by           Display name of the person.
	 * @return array<string,mixed> The new live version.
	 *
	 * @throws \LogicException When there is no draft. A draft with problems raises RuleValidationException, which lists every problem.
	 */
	public function publish( int $connectionId, string $type, string $by ): array {
		$draft = $this->store->draft( $connectionId, $type );
		if ( null === $draft ) {
			throw new \LogicException( esc_html__( 'There is no draft to publish.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$errors = $this->validate( $connectionId, $type, $this->rulesOf( $draft ) );
		if ( array() !== $errors ) {
			$invalid = new RuleValidationException( $errors );
			throw $invalid;
		}
		$live = $this->store->publish( $connectionId, $type, $by );
		$this->log->info(
			__( 'Mapping version published.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id' => $connectionId,
				'type'          => $type,
				'version'       => $live['version'] ?? null,
				'by'            => $by,
			)
		);
		return $live;
	}

	/**
	 * Throw the draft away.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param string $by           Display name of the person.
	 * @return bool Whether a draft existed.
	 */
	public function discardDraft( int $connectionId, string $type, string $by ): bool {
		$discarded = $this->store->discardDraft( $connectionId, $type );
		if ( $discarded ) {
			$this->log->info(
				__( 'Mapping draft discarded.', 'estasoft-erp-crm-sync-engine' ),
				array(
					'connection_id' => $connectionId,
					'type'          => $type,
					'by'            => $by,
				)
			);
		}
		return $discarded;
	}

	/**
	 * Make an older version the live rules again, as a new version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version to copy.
	 * @param string $by           Display name of the person.
	 * @return array<string,mixed> The new live version.
	 *
	 * @throws \OutOfBoundsException When that version does not exist.
	 */
	public function rollback( int $connectionId, string $type, int $version, string $by ): array {
		$live = $this->store->rollback( $connectionId, $type, $version, $by );
		$this->log->info(
			__( 'Mapping rolled back.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id' => $connectionId,
				'type'          => $type,
				'copied'        => $version,
				'version'       => $live['version'] ?? null,
				'by'            => $by,
			)
		);
		return $live;
	}

	/**
	 * All versions of a type, newest first, without their rules.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( int $connectionId, string $type ): array {
		return $this->store->versions( $connectionId, $type );
	}

	/**
	 * One version with its rules, for the View button.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version number.
	 * @return array<string,mixed>|null
	 */
	public function version( int $connectionId, string $type, int $version ): ?array {
		return $this->store->find( $connectionId, $type, $version );
	}

	/**
	 * The connector of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	private function connectorFor( int $connectionId ): ?ConnectorInterface {
		$connection = $this->connections->find( $connectionId );
		return null === $connection ? null : $this->connectors->get( $connection->connector );
	}

	/**
	 * Source and target field lists for a screen.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array{source:array<int,array<string,mixed>>,target:array<int,array<string,mixed>>}
	 */
	private function fieldLists( int $connectionId, string $type ): array {
		$connector = $this->connectorFor( $connectionId );
		$described = null === $connector ? array() : $connector->describeFields( $type );

		if ( in_array( $type, self::INBOUND, true ) ) {
			return array(
				'source' => array_map(
					static fn( array $field ): array => array(
						'path'  => $field['path'],
						'label' => $field['label'],
						'type'  => $field['type'],
						'group' => $field['group'],
					),
					$described
				),
				'target' => $this->wooTargets( $type ),
			);
		}
		return array(
			'source' => $this->fields->all(),
			'target' => $described,
		);
	}

	/**
	 * WooCommerce fields that stock and tracking rules write to.
	 *
	 * @param string $type stock or tracking.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function wooTargets( string $type ): array {
		$group = __( 'WooCommerce', 'estasoft-erp-crm-sync-engine' );
		if ( 'stock' === $type ) {
			return array(
				array(
					'path'     => 'product.stock_quantity',
					'label'    => __( 'Product stock quantity', 'estasoft-erp-crm-sync-engine' ),
					'type'     => 'number',
					'required' => false,
					'group'    => $group,
				),
				array(
					'path'     => 'product.stock_status',
					'label'    => __( 'Stock status', 'estasoft-erp-crm-sync-engine' ),
					'type'     => 'text',
					'required' => false,
					'group'    => $group,
				),
			);
		}
		return array(
			array(
				'path'     => 'order.status',
				'label'    => __( 'Order status', 'estasoft-erp-crm-sync-engine' ),
				'type'     => 'text',
				'required' => false,
				'group'    => $group,
			),
			array(
				'path'     => 'order.tracking.carrier',
				'label'    => __( 'Carrier', 'estasoft-erp-crm-sync-engine' ),
				'type'     => 'text',
				'required' => false,
				'group'    => $group,
			),
			array(
				'path'     => 'order.tracking.number',
				'label'    => __( 'Tracking number', 'estasoft-erp-crm-sync-engine' ),
				'type'     => 'text',
				'required' => false,
				'group'    => $group,
			),
			array(
				'path'     => 'order.note',
				'label'    => __( 'Order note', 'estasoft-erp-crm-sync-engine' ),
				'type'     => 'text',
				'required' => false,
				'group'    => $group,
			),
		);
	}

	/**
	 * Rules of a version.
	 *
	 * @param array<string,mixed>|null $version Version.
	 * @return array<int,array<string,mixed>>
	 */
	private function rulesOf( ?array $version ): array {
		$rules = is_array( $version ) && is_array( $version['rules'] ?? null ) ? $version['rules'] : array();
		return array_values( array_filter( $rules, 'is_array' ) );
	}

	/**
	 * Rules keyed by id.
	 *
	 * @param array<int,array<string,mixed>> $rules Rules.
	 * @return array<string,array<string,mixed>>
	 */
	private function byId( array $rules ): array {
		$keyed = array();
		foreach ( $rules as $rule ) {
			$keyed[ (string) ( $rule['id'] ?? '' ) ] = $rule;
		}
		return $keyed;
	}

	/**
	 * Mark draft rules as added or changed compared with the live version.
	 *
	 * @param array<int,array<string,mixed>> $rules Draft rules.
	 * @param array<string,mixed>|null       $live  Live version.
	 * @return array<int,array<string,mixed>>
	 */
	private function flagged( array $rules, ?array $live ): array {
		$before = $this->byId( $this->rulesOf( $live ) );
		foreach ( $rules as $position => $rule ) {
			$id = (string) ( $rule['id'] ?? '' );
			unset( $rule['added'], $rule['changed'] );
			if ( null !== $live && ! isset( $before[ $id ] ) ) {
				$rule['added'] = true;
			} elseif ( null !== $live && $this->differs( $before[ $id ], $rule ) ) {
				$rule['changed'] = true;
			}
			$rules[ $position ] = $rule;
		}
		return $rules;
	}

	/**
	 * Whether two rules differ in anything that matters.
	 *
	 * @param array<string,mixed> $left  Rule.
	 * @param array<string,mixed> $right Rule.
	 */
	private function differs( array $left, array $right ): bool {
		unset( $left['added'], $left['changed'], $right['added'], $right['changed'] );
		return $this->normal( $left ) !== $this->normal( $right );
	}

	/**
	 * A rule reduced to its model keys with defaults filled in, so that a missing "required" and a false one compare equal,
	 * and so do a step without "args" and one with an empty list (a connector's starting rules leave "args" out; a saved
	 * draft has been cleaned to carry it).
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function normal( array $rule ): array {
		$steps = array();
		foreach ( is_array( $rule['transforms'] ?? null ) ? $rule['transforms'] : array() as $step ) {
			if ( is_array( $step ) && array() === ( $step['args'] ?? array() ) ) {
				unset( $step['args'] );
			}
			$steps[] = $step;
		}
		return array(
			'id'         => (string) ( $rule['id'] ?? '' ),
			'target'     => (string) ( $rule['target'] ?? '' ),
			'source'     => $rule['source'] ?? '',
			'transforms' => $steps,
			'required'   => ! empty( $rule['required'] ),
			'error_code' => (string) ( $rule['error_code'] ?? '' ),
			'when'       => $rule['when'] ?? null,
		);
	}

	/**
	 * One entry of the change list.
	 *
	 * @param string                   $kind   added, changed or removed.
	 * @param string                   $id     Rule id.
	 * @param array<string,mixed>|null $before Rule before.
	 * @param array<string,mixed>|null $after  Rule after.
	 * @return array{kind:string,rule_id:string,target:string,source:string,before:?array<string,mixed>,after:?array<string,mixed>}
	 */
	private function change( string $kind, string $id, ?array $before, ?array $after ): array {
		$rule = Rule::fromArray( $after ?? $before ?? array() );
		return array(
			'kind'    => $kind,
			'rule_id' => $id,
			'target'  => $rule->target,
			'source'  => $rule->isConstant ? '' : $rule->firstSource(),
			'before'  => $before,
			'after'   => $after,
		);
	}
}
