<?php
/**
 * Stock in: the system's quantities become the shop's quantities.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Stock;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Ports\ShopStock;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;
use Estasoft\SyncEngine\Jobs\Support\JobConfig;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingLog;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingSettings;

/**
 * STK-1 to STK-8.
 *
 * The system is the source of truth (STK-2): the job reads quantities and writes them to WooCommerce through the product
 * objects, and never writes stock to the system. A full pass walks the products that have a SKU in pages of 100, asks the
 * connector for those SKUs in one call per page, and sets the shop quantity only where it differs:
 *
 *     shop quantity = system quantity - buffer - held - sold-not-yet-synced      (never below 0)
 *
 * - buffer: the product's own buffer (`_estasoft_sync_stock_buffer`) or the connection's safety buffer.
 * - held: when the option is on, units unpaid orders hold in WooCommerce plus units the system has reserved for other documents.
 * - sold-not-yet-synced: units in paid orders the system has not received yet, so a stock pull between a sale and its sync
 *   cannot raise the shop quantity again.
 * When the system quantity is lower than the units sold-not-yet-synced the product is a conflict (SYNC-STK-001) and nothing
 * changes until a person accepts the system quantity or holds.
 *
 * A product is matched when the system reports its SKU (the SKU of the person's own match, when there is one in the id map).
 * Products with no SKU, excluded products and products that do not manage stock are skipped; products the system does not know
 * are counted as unmatched. During the shadow window the job reads and counts but writes nothing.
 */
final class StockService {

	/**
	 * SKUs per page of the pass and per connector call.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Default time one call may spend, in seconds.
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * Setting defaults. Stock sync is off until the person turns it on.
	 */
	public const DEFAULTS = array(
		'enabled'         => false,
		'interval_minutes' => 5,
		'safety_buffer'   => 2,
		'subtract_held'   => true,
	);

	/**
	 * Area name inside the connection's job settings.
	 */
	private const AREA = 'stock';

	/**
	 * Set up with what it depends on.
	 *
	 * @param JobConfig            $config      Per-connection settings.
	 * @param TrackingSettings     $tracking    Tracking settings, shown with the stock rules.
	 * @param ConnectionRepository $connections Connections (warehouses live in the connection's config).
	 * @param ConnectorGateway     $gateway     The door to connectors.
	 * @param ShopStock            $shop        WooCommerce stock.
	 * @param IdMapRepository      $idMap       A person's own SKU matches.
	 * @param StockStore           $store       Changes, conflicts and pass state.
	 * @param TrackingLog          $trackingLog Recent tracking, shown on the Stock screen.
	 * @param OnboardingState      $onboarding  Shadow window.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param int                  $budget      Seconds one call may spend before it stops and continues next time.
	 */
	public function __construct(
		private readonly JobConfig $config,
		private readonly TrackingSettings $tracking,
		private readonly ConnectionRepository $connections,
		private readonly ConnectorGateway $gateway,
		private readonly ShopStock $shop,
		private readonly IdMapRepository $idMap,
		private readonly StockStore $store,
		private readonly TrackingLog $trackingLog,
		private readonly OnboardingState $onboarding,
		private readonly Logger $logger,
		private readonly Clock $clock,
		private readonly int $budget = self::BUDGET_SECONDS
	) {
	}

	/**
	 * The stock rules of a connection (the `StockRules` of src/api/types.ts).
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404 when the connection does not exist.
	 */
	public function rules( int $connectionId ): array {
		$public = $this->connections->findPublic( $connectionId );
		if ( null === $public ) {
			throw new JobException( __( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		$stored = $this->config->get( $connectionId, self::AREA, self::DEFAULTS );
		return array(
			'enabled'          => (bool) $stored['enabled'],
			'interval_minutes' => (int) $stored['interval_minutes'],
			'safety_buffer'    => (int) $stored['safety_buffer'],
			'subtract_held'    => (bool) $stored['subtract_held'],
			'system_wins'      => true,
			'warehouses'       => $this->warehouses( $public ),
		) + $this->tracking->get( $connectionId );
	}

	/**
	 * Save the stock rules. Fields that are not supplied keep their value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $rules        Rules to change.
	 * @return array<string,mixed> The rules after saving.
	 *
	 * @throws ValidationException With one message per bad field.
	 * @throws JobException        With status 404 when the connection does not exist.
	 */
	public function saveRules( int $connectionId, array $rules ): array {
		$current = $this->rules( $connectionId );
		$errors  = array();
		$values  = array();

		foreach ( array( 'enabled', 'subtract_held' ) as $key ) {
			if ( array_key_exists( $key, $rules ) ) {
				$bool = is_bool( $rules[ $key ] ) ? $rules[ $key ] : filter_var( $rules[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $bool ) {
					$errors[ $key ] = __( 'Choose on or off.', 'estasoft-erp-crm-sync-engine' );
				} else {
					$values[ $key ] = $bool;
				}
			}
		}
		if ( array_key_exists( 'interval_minutes', $rules ) ) {
			$minutes = Ranges::read( $rules['interval_minutes'], array( 5, 1440, 5 ) );
			if ( null === $minutes ) {
				$errors['interval_minutes'] = Ranges::message( array( 5, 1440, 5 ) );
			} else {
				$values['interval_minutes'] = $minutes;
			}
		}
		if ( array_key_exists( 'safety_buffer', $rules ) ) {
			$buffer = Ranges::read( $rules['safety_buffer'], Ranges::SAFETY_BUFFER );
			if ( null === $buffer ) {
				$errors['safety_buffer'] = Ranges::message( Ranges::SAFETY_BUFFER );
			} else {
				$values['safety_buffer'] = $buffer;
			}
		}
		if ( array_key_exists( 'system_wins', $rules ) && true !== $rules['system_wins'] && 'true' !== $rules['system_wins'] && 1 !== $rules['system_wins'] && '1' !== $rules['system_wins'] ) {
			$errors['system_wins'] = __( 'The system is always the source of truth for stock.', 'estasoft-erp-crm-sync-engine' );
		}
		$warehouses = null;
		if ( array_key_exists( 'warehouses', $rules ) ) {
			$checked = $this->checkWarehouses( $rules['warehouses'], $current['warehouses'] );
			if ( null === $checked['list'] ) {
				$errors['warehouses'] = $checked['error'];
			} else {
				$warehouses = $checked['list'];
			}
		}
		$tracking = $this->tracking->check( array_intersect_key( $rules, TrackingSettings::DEFAULTS ) );
		$errors  += $tracking['errors'];
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$this->config->save( $connectionId, self::AREA, array_replace( array_intersect_key( $current, self::DEFAULTS ), $values ) );
		if ( array() !== $tracking['values'] ) {
			$this->tracking->save( $connectionId, $tracking['values'] );
		}
		if ( null !== $warehouses ) {
			$this->connections->update( $connectionId, array( 'config' => array( 'warehouses' => $warehouses ) ) );
		}
		return $this->rules( $connectionId );
	}

	/**
	 * What the Stock screen shows (the `StockOverview` of src/api/types.ts).
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed>
	 */
	public function overview( ?int $connectionId = null ): array {
		$last      = null === $connectionId ? $this->store->last() : array( (string) $connectionId => $this->store->last( $connectionId ) );
		$lastRun   = null;
		$matched   = 0;
		$unmatched = 0;
		foreach ( $last as $state ) {
			if ( ! is_array( $state ) ) {
				continue;
			}
			$matched   += (int) ( $state['matched'] ?? 0 );
			$unmatched += (int) ( $state['unmatched'] ?? 0 );
			if ( ! empty( $state['at'] ) && ( null === $lastRun || (string) $state['at'] > $lastRun ) ) {
				$lastRun = (string) $state['at'];
			}
		}
		$changes = array();
		foreach ( $this->store->changes( $connectionId ) as $change ) {
			$changes[] = array(
				'id'              => (int) $change['id'],
				'sku'             => (string) $change['sku'],
				'product_name'    => (string) $change['product_name'],
				'system_quantity' => (int) $change['system_quantity'],
				'shop_before'     => (int) $change['shop_before'],
				'shop_after'      => (int) $change['shop_after'],
				'at'              => (string) $change['at'],
			);
		}
		$conflicts = array();
		foreach ( $this->store->conflicts( $connectionId ) as $conflict ) {
			$conflicts[] = array(
				'id'              => (int) $conflict['id'],
				'sku'             => (string) $conflict['sku'],
				'product_name'    => (string) $conflict['product_name'],
				'system_quantity' => (int) $conflict['system_quantity'],
				'sold_unsynced'   => (int) $conflict['sold_unsynced'],
				'detected_at'     => (string) $conflict['detected_at'],
				'code'            => 'SYNC-STK-001',
			);
		}
		return array(
			'last_run_at'     => $lastRun,
			'matched'         => $matched,
			'unmatched'       => $unmatched,
			'changes'         => $changes,
			'conflicts'       => $conflicts,
			'tracking_recent' => $this->trackingRecent( $connectionId ),
		);
	}

	/**
	 * Accept the system's quantity for a conflict (STK-6): the shop quantity is set from it now.
	 *
	 * @param string $id Conflict id.
	 *
	 * @throws JobException With status 404 when there is no such open conflict, 409 during the shadow window.
	 */
	public function acceptConflict( string $id ): void {
		$conflict = $this->openConflict( $id );
		if ( $this->onboarding->isShadowActive( $this->clock->now() ) ) {
			throw new JobException( __( 'Shadow mode changes nothing in your shop. End it first to accept a quantity.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		$connectionId = (int) $conflict['connection_id'];
		$productId    = (int) $conflict['product_id'];
		$product      = $this->shop->product( $productId );
		if ( null === $product ) {
			$this->store->decide( (int) $conflict['id'], 'accept', Time::iso( Time::sql( $this->clock->now() ) ) ?? '' );
			throw new JobException( __( 'That product no longer exists.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		$rules   = $this->rules( $connectionId );
		$system  = (int) $conflict['system_quantity'];
		$after   = $this->target( $rules, $productId, $system, 0, null, 0 );
		$nowSql  = Time::sql( $this->clock->now() );
		$before  = (int) ( $product['quantity'] ?? 0 );
		if ( $this->shop->setQuantity( $productId, $after, $system, $nowSql ) && $before !== $after ) {
			$this->store->addChange(
				array(
					'connection_id'   => $connectionId,
					'sku'             => $product['sku'],
					'product_name'    => $product['name'],
					'system_quantity' => $system,
					'shop_before'     => $before,
					'shop_after'      => $after,
					'at'              => (string) Time::iso( $nowSql ),
				)
			);
		}
		$this->store->decide( (int) $conflict['id'], 'accept', (string) Time::iso( $nowSql ) );
	}

	/**
	 * Hold a conflict (STK-6): the shop quantity stays as it is until the system quantity changes.
	 *
	 * @param string $id Conflict id.
	 *
	 * @throws JobException With status 404 when there is no such open conflict.
	 */
	public function holdConflict( string $id ): void {
		$conflict = $this->openConflict( $id );
		$this->store->decide( (int) $conflict['id'], 'hold', (string) Time::iso( Time::sql( $this->clock->now() ) ) );
	}

	/**
	 * Run the pass now for one connection or for every connection that can.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed> Summary: connections, products, matched, unmatched, changed, conflicts, skipped, errors, shadow, complete, seconds.
	 */
	public function run( ?int $connectionId = null ): array {
		return $this->runTargets( $connectionId, false );
	}

	/**
	 * Run the pass for the connections whose interval has passed (what the recurring task calls).
	 *
	 * @return array<string,mixed> Same summary as run().
	 */
	public function runDue(): array {
		return $this->runTargets( null, true );
	}

	/**
	 * Run for the targets.
	 *
	 * @param int|null $connectionId One connection, or null.
	 * @param bool     $onlyDue      Only connections whose interval has passed.
	 * @return array<string,mixed>
	 */
	private function runTargets( ?int $connectionId, bool $onlyDue ): array {
		$started = $this->clock->now();
		$summary = array(
			'connections' => 0,
			'products'    => 0,
			'matched'     => 0,
			'unmatched'   => 0,
			'changed'     => 0,
			'conflicts'   => 0,
			'skipped'     => 0,
			'errors'      => 0,
			'shadow'      => $this->onboarding->isShadowActive( $started ),
			'complete'    => true,
			'seconds'     => 0.0,
		);
		foreach ( $this->gateway->targets( Capability::StockIn, $connectionId ) as $target ) {
			$connection = $target['connection'];
			$rules      = $this->rules( $connection->id );
			if ( ! $rules['enabled'] || ( $onlyDue && ! $this->isDue( $connection->id, $rules['interval_minutes'] ) ) ) {
				continue;
			}
			if ( $this->elapsed( $started ) >= $this->budget ) {
				$summary['complete'] = false;
				break;
			}
			++$summary['connections'];
			$this->pass( $connection, $target['connector'], $rules, $started, $summary );
		}
		$summary['seconds'] = round( $this->elapsed( $started ), 3 );
		return $summary;
	}

	/**
	 * Walk the products of one connection until the pass is complete or the time is up.
	 *
	 * @param Connection          $connection Connection.
	 * @param ConnectorInterface  $connector  Connector.
	 * @param array<string,mixed> $rules      Stock rules.
	 * @param \DateTimeImmutable  $started    Start of this call.
	 * @param array<string,mixed> $summary    Summary being built (updated).
	 */
	private function pass( Connection $connection, ConnectorInterface $connector, array $rules, \DateTimeImmutable $started, array &$summary ): void {
		$shadow  = (bool) $summary['shadow'];
		$state   = $this->store->last( $connection->id );
		$page    = max( 1, (int) ( $state['next_page'] ?? 1 ) );
		$partial = array_replace(
			array(
				'matched'   => 0,
				'unmatched' => 0,
				'changed'   => 0,
				'conflicts' => 0,
			),
			is_array( $state['partial'] ?? null ) ? $state['partial'] : array()
		);
		$before     = $partial;
		$unsynced   = $this->shop->unsyncedSoldUnits();
		$warehouses = $this->countingWarehouses( $rules['warehouses'] );
		$complete   = false;

		while ( $this->elapsed( $started ) < $this->budget ) {
			$batch    = $this->shop->products( $page, self::PAGE_SIZE );
			$eligible = array();
			foreach ( $batch['items'] as $item ) {
				++$summary['products'];
				if ( $item['excluded'] || ! $item['manages_stock'] ) {
					++$summary['skipped'];
					continue;
				}
				$item['system_sku'] = $this->systemSku( $connection->id, $item );
				$eligible[]         = $item;
			}
			if ( array() !== $eligible ) {
				$skus    = array_values( array_unique( array_column( $eligible, 'system_sku' ) ) );
				$answer  = $this->gateway->call( $connection, $connector, fn() => $connector->fetchStock( $connection, $skus, $warehouses ) );
				if ( $answer instanceof ConnectorError || ! is_array( $answer ) ) {
					++$summary['errors'];
					$summary['complete']   = false;
					$summary['matched']   += $partial['matched'] - $before['matched'];
					$summary['unmatched'] += $partial['unmatched'] - $before['unmatched'];
					$summary['changed']   += $partial['changed'] - $before['changed'];
					$summary['conflicts'] += $partial['conflicts'] - $before['conflicts'];
					$this->store->saveLast( $connection->id, array_replace( $state, array( 'next_page' => $page, 'partial' => $partial ) ) );
					return;
				}
				$this->apply( $connection, $rules, $eligible, array_change_key_case( $answer, CASE_LOWER ), $unsynced, $shadow, $partial );
			}
			if ( ! $batch['has_more'] ) {
				$complete = true;
				break;
			}
			++$page;
		}

		$summary['matched']   += $partial['matched'] - $before['matched'];
		$summary['unmatched'] += $partial['unmatched'] - $before['unmatched'];
		$summary['changed']   += $partial['changed'] - $before['changed'];
		$summary['conflicts'] += $partial['conflicts'] - $before['conflicts'];
		if ( ! $complete ) {
			$summary['complete'] = false;
			$this->store->saveLast( $connection->id, array_replace( $state, array( 'next_page' => $page, 'partial' => $partial ) ) );
			return;
		}
		$this->store->saveLast(
			$connection->id,
			array(
				'at'        => (string) Time::iso( Time::sql( $this->clock->now() ) ),
				'matched'   => $partial['matched'],
				'unmatched' => $partial['unmatched'],
				'changed'   => $partial['changed'],
				'conflicts' => $partial['conflicts'],
				'next_page' => 1,
				'partial'   => array(),
				'shadow'    => $shadow,
			)
		);
		if ( $partial['changed'] > 0 && ! $shadow ) {
			$this->logger->info(
				sprintf(
					/* translators: %d: number of products whose shop quantity was updated. */
					_n( 'Stock sync updated %d product.', 'Stock sync updated %d products.', $partial['changed'], 'estasoft-erp-crm-sync-engine' ),
					$partial['changed']
				),
				array( 'connection_id' => $connection->id )
			);
		}
	}

	/**
	 * Apply one page of system quantities.
	 *
	 * @param Connection                       $connection Connection.
	 * @param array<string,mixed>              $rules      Stock rules.
	 * @param array<int,array<string,mixed>>   $items      Eligible products of the page.
	 * @param array<string,array<string,int>>  $answer     System quantities by lower-case SKU.
	 * @param array<int,int>                   $unsynced   Units sold and not yet synced, by product.
	 * @param bool                             $shadow     Whether the shadow window is open (count only).
	 * @param array<string,int>                $partial    Counts (updated).
	 */
	private function apply( Connection $connection, array $rules, array $items, array $answer, array $unsynced, bool $shadow, array &$partial ): void {
		$nowSql = Time::sql( $this->clock->now() );
		foreach ( $items as $item ) {
			$id    = (int) $item['id'];
			$entry = $answer[ strtolower( (string) $item['system_sku'] ) ] ?? null;
			if ( ! is_array( $entry ) || ! isset( $entry['quantity'] ) ) {
				++$partial['unmatched'];
				continue;
			}
			++$partial['matched'];
			$system = max( 0, (int) $entry['quantity'] );
			$held   = max( 0, (int) ( $entry['held'] ?? 0 ) );
			$sold   = (int) ( $unsynced[ $id ] ?? 0 );

			if ( $sold > 0 && $system < $sold ) {
				$decision = $this->store->decision( $connection->id, $id );
				if ( null === $decision || (int) $decision['quantity'] !== $system ) {
					++$partial['conflicts'];
					if ( ! $shadow ) {
						$this->store->openConflict( $connection->id, $id, (string) $item['sku'], (string) $item['name'], $system, $sold, (string) Time::iso( $nowSql ) );
					}
					continue;
				}
				if ( 'hold' === $decision['decision'] ) {
					continue;
				}
				$sold = 0;
			} else {
				$this->store->closeConflictFor( $connection->id, $id );
			}

			$after  = $this->target( $rules, $id, $system, $held, $item['buffer'], $sold );
			$before = $item['quantity'];
			if ( $before === $after ) {
				continue;
			}
			++$partial['changed'];
			if ( $shadow ) {
				continue;
			}
			if ( $this->shop->setQuantity( $id, $after, $system, $nowSql ) ) {
				$this->store->addChange(
					array(
						'connection_id'   => $connection->id,
						'sku'             => (string) $item['sku'],
						'product_name'    => (string) $item['name'],
						'system_quantity' => $system,
						'shop_before'     => (int) ( $before ?? 0 ),
						'shop_after'      => $after,
						'at'              => (string) Time::iso( $nowSql ),
					)
				);
			}
		}
	}

	/**
	 * The shop quantity for a system quantity.
	 *
	 * @param array<string,mixed> $rules     Stock rules.
	 * @param int                 $productId Product or variation id.
	 * @param int                 $system    Quantity in the system.
	 * @param int                 $held      Units the system has reserved for other documents.
	 * @param int|null            $buffer    The product's own buffer, or null for the connection's.
	 * @param int                 $sold      Units sold and not yet known to the system.
	 */
	private function target( array $rules, int $productId, int $system, int $held, ?int $buffer, int $sold ): int {
		$value = $system - ( $buffer ?? (int) $rules['safety_buffer'] ) - $sold;
		if ( ! empty( $rules['subtract_held'] ) ) {
			$value -= $held + $this->shop->heldUnits( $productId );
		}
		return max( 0, $value );
	}

	/**
	 * The SKU to ask the system for: the person's own match when there is one, otherwise the shop SKU.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $item         Product row.
	 */
	private function systemSku( int $connectionId, array $item ): string {
		$link = $this->idMap->get( $connectionId, IdMapRepository::PRODUCT, (string) $item['id'] );
		if ( null !== $link && $link['is_override'] && '' !== trim( (string) $link['external_ref'] ) ) {
			return trim( (string) $link['external_ref'] );
		}
		return (string) $item['sku'];
	}

	/**
	 * Warehouse ids that count toward shop stock; an empty list means all of them.
	 *
	 * @param array<int,array<string,mixed>> $warehouses Warehouses of the rules.
	 * @return array<int,string>
	 */
	private function countingWarehouses( array $warehouses ): array {
		$counting = array();
		foreach ( $warehouses as $warehouse ) {
			if ( ! empty( $warehouse['counts_toward_stock'] ) ) {
				$counting[] = (string) $warehouse['id'];
			}
		}
		return count( $counting ) === count( $warehouses ) ? array() : $counting;
	}

	/**
	 * Whether the interval since the last finished pass has passed, or a pass is half done.
	 *
	 * @param int $connectionId Connection.
	 * @param int $minutes      Interval.
	 */
	private function isDue( int $connectionId, int $minutes ): bool {
		$state = $this->store->last( $connectionId );
		if ( (int) ( $state['next_page'] ?? 1 ) > 1 || empty( $state['at'] ) ) {
			return true;
		}
		$at = date_create_immutable( (string) $state['at'] );
		return false === $at || ( $this->clock->now()->getTimestamp() - $at->getTimestamp() ) >= ( $minutes * 60 ) - 30;
	}

	/**
	 * An open conflict by id.
	 *
	 * @param string $id Conflict id.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404.
	 */
	private function openConflict( string $id ): array {
		$conflict = ctype_digit( $id ) ? $this->store->conflict( (int) $id ) : null;
		if ( null === $conflict ) {
			throw new JobException( __( 'That stock conflict does not exist, or it was already decided.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		return $conflict;
	}

	/**
	 * Recent tracking, for the Stock screen.
	 *
	 * @param int|null $connectionId One connection, or null.
	 * @return array<int,array<string,mixed>>
	 */
	private function trackingRecent( ?int $connectionId ): array {
		return $this->trackingLog->recent( $connectionId );
	}

	/**
	 * The warehouses of a connection with the person's choices.
	 *
	 * @param array<string,mixed> $public Connection as the screens see it.
	 * @return array<int,array{id:string,name:string,counts_toward_stock:bool,countries:array<int,string>}>
	 */
	private function warehouses( array $public ): array {
		$list = array();
		foreach ( (array) ( $public['warehouses'] ?? array() ) as $warehouse ) {
			if ( ! is_array( $warehouse ) || ! isset( $warehouse['id'] ) ) {
				continue;
			}
			$list[] = array(
				'id'                  => (string) $warehouse['id'],
				'name'                => (string) ( $warehouse['name'] ?? $warehouse['id'] ),
				'counts_toward_stock' => ! isset( $warehouse['counts_toward_stock'] ) || (bool) $warehouse['counts_toward_stock'],
				'countries'           => array_values( array_map( 'strval', (array) ( $warehouse['countries'] ?? array() ) ) ),
			);
		}
		return $list;
	}

	/**
	 * Check the warehouse choices.
	 *
	 * @param mixed                          $input   Submitted list.
	 * @param array<int,array<string,mixed>> $current Warehouses now.
	 * @return array{list:?array<int,array<string,mixed>>,error:string}
	 */
	private function checkWarehouses( mixed $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return array(
				'list'  => null,
				'error' => __( 'Send the list of warehouses.', 'estasoft-erp-crm-sync-engine' ),
			);
		}
		$known = array();
		foreach ( $current as $warehouse ) {
			$known[ $warehouse['id'] ] = $warehouse;
		}
		foreach ( $input as $row ) {
			$id = is_array( $row ) && isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( ! isset( $known[ $id ] ) ) {
				return array(
					'list'  => null,
					'error' => __( 'One of the warehouses is not one of this connection\'s warehouses.', 'estasoft-erp-crm-sync-engine' ),
				);
			}
			if ( isset( $row['counts_toward_stock'] ) ) {
				$known[ $id ]['counts_toward_stock'] = filter_var( $row['counts_toward_stock'], FILTER_VALIDATE_BOOLEAN );
			}
			if ( isset( $row['countries'] ) ) {
				$countries = array();
				foreach ( (array) $row['countries'] as $country ) {
					$code = strtoupper( trim( (string) $country ) );
					if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
						return array(
							'list'  => null,
							'error' => __( 'Countries are two-letter codes such as AE or SA.', 'estasoft-erp-crm-sync-engine' ),
						);
					}
					$countries[ $code ] = $code;
				}
				$known[ $id ]['countries'] = array_values( $countries );
			}
		}
		$list = array_values( $known );
		if ( array() !== $list && array() === array_filter( array_column( $list, 'counts_toward_stock' ) ) ) {
			return array(
				'list'  => null,
				'error' => __( 'At least one warehouse must count toward shop stock.', 'estasoft-erp-crm-sync-engine' ),
			);
		}
		return array(
			'list'  => $list,
			'error' => '',
		);
	}

	/**
	 * Seconds since a moment.
	 *
	 * @param \DateTimeImmutable $started Start.
	 */
	private function elapsed( \DateTimeImmutable $started ): float {
		return max( 0.0, (float) $this->clock->now()->format( 'U.u' ) - (float) $started->format( 'U.u' ) );
	}
}
