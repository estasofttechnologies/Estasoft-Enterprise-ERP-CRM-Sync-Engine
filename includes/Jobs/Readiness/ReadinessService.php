<?php
/**
 * The store check: ten checks before and after setup.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Readiness;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Jobs\Ports\ShopCatalog;
use Estasoft\SyncEngine\Jobs\Ports\ShopCustomers;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Urls;

/**
 * ONB-3. Ten checks, each Passed, Worth fixing or Needs fixing, each with a link to the cause:
 * `hpos`, `cron_running`, `cron_visits`, `dup_sku`, `no_sku`, `state`, `currency`, `charset`, `key`, `php`.
 *
 * Worth fixing never blocks setup; only Needs fixing does (`blocked`). Other plugins can add checks with the filter
 * `estasoft_sync_readiness_checks( array $checks )`: each check is an array with `id`, `title`, `status`, `detail`, and
 * optionally `link` and `link_label`; an entry that does not have that shape is ignored. The last result is kept in the
 * option `estasoft_sync_readiness`.
 */
final class ReadinessService {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_readiness';

	/**
	 * Minutes after which the queue runner counts as stopped.
	 */
	private const STALE_MINUTES = 30;

	/**
	 * Products and customers named in a detail line.
	 */
	private const LIST = 8;

	/**
	 * Returns the time of the last queue run as a MySQL datetime (UTC), or null.
	 *
	 * @var \Closure
	 */
	private \Closure $lastQueueRun;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ShopEnvironment      $environment  Shop and server facts.
	 * @param ShopCatalog          $catalog      Products.
	 * @param ShopCustomers        $customers    Customers.
	 * @param SecretBox            $box          Encryption key.
	 * @param ConnectionRepository $connections  Connections.
	 * @param Urls                 $urls         Links.
	 * @param OptionStore          $options      Options.
	 * @param Clock                $clock        Time source.
	 * @param \Closure             $lastQueueRun Returns the time of the last queue run (MySQL datetime) or null.
	 */
	public function __construct(
		private readonly ShopEnvironment $environment,
		private readonly ShopCatalog $catalog,
		private readonly ShopCustomers $customers,
		private readonly SecretBox $box,
		private readonly ConnectionRepository $connections,
		private readonly Urls $urls,
		private readonly OptionStore $options,
		private readonly Clock $clock,
		\Closure $lastQueueRun
	) {
		$this->lastQueueRun = $lastQueueRun;
	}

	/**
	 * The result of the last run (the `Readiness` of src/api/types.ts).
	 *
	 * @return array{ran_at:?string,checks:array<int,array<string,mixed>>,blocked:bool}
	 */
	public function last(): array {
		$stored = $this->options->get( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['checks'] ) || ! is_array( $stored['checks'] ) ) {
			return array(
				'ran_at'  => null,
				'checks'  => array(),
				'blocked' => false,
			);
		}
		return $this->shape( isset( $stored['ran_at'] ) ? (string) $stored['ran_at'] : null, $stored['checks'] );
	}

	/**
	 * Run the checks and keep the result.
	 *
	 * @return array{ran_at:?string,checks:array<int,array<string,mixed>>,blocked:bool}
	 */
	public function run(): array {
		$checks = array(
			$this->hpos(),
			$this->cronRunning(),
			$this->cronVisits(),
			$this->duplicateSkus(),
			$this->missingSkus(),
			$this->freeTextStates(),
			$this->currency(),
			$this->charset(),
			$this->key(),
			$this->php(),
		);
		/**
		 * Filter the readiness checks. Add arrays with id, title, status (passed, worth_fixing, needs_fixing), detail, link, link_label.
		 *
		 * @param array<int,array<string,mixed>> $checks The ten built-in checks.
		 */
		$filtered = apply_filters( 'estasoft_sync_readiness_checks', $checks );
		$checks   = array_merge( $checks, array_slice( is_array( $filtered ) ? array_values( array_filter( $filtered, static fn( $check ): bool => is_array( $check ) && ! in_array( $check['id'] ?? '', array( 'hpos', 'cron_running', 'cron_visits', 'dup_sku', 'no_sku', 'state', 'currency', 'charset', 'key', 'php' ), true ) ) ) : array(), 0, 20 ) );
		$result   = $this->shape( Time::iso( Time::sql( $this->clock->now() ) ), $checks );
		$this->options->set( self::OPTION, $result );
		return $result;
	}

	/**
	 * Clean the checks and add the blocked flag.
	 *
	 * @param string|null                    $ranAt  ISO time.
	 * @param array<int,array<string,mixed>> $checks Checks.
	 * @return array{ran_at:?string,checks:array<int,array<string,mixed>>,blocked:bool}
	 */
	private function shape( ?string $ranAt, array $checks ): array {
		$clean   = array();
		$blocked = false;
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['id'], $check['title'], $check['status'] ) || ! in_array( $check['status'], array( 'passed', 'worth_fixing', 'needs_fixing' ), true ) ) {
				continue;
			}
			$link    = isset( $check['link'] ) && is_string( $check['link'] ) && '' !== $check['link'] ? $check['link'] : null;
			$clean[] = array(
				'id'         => mb_substr( (string) $check['id'], 0, 60 ),
				'title'      => (string) $check['title'],
				'status'     => (string) $check['status'],
				'detail'     => isset( $check['detail'] ) ? (string) $check['detail'] : '',
				'link'       => $link,
				'link_label' => null === $link ? null : ( isset( $check['link_label'] ) && '' !== (string) $check['link_label'] ? (string) $check['link_label'] : null ),
			);
			$blocked = $blocked || 'needs_fixing' === $check['status'];
		}
		return array(
			'ran_at'  => $ranAt,
			'checks'  => $clean,
			'blocked' => $blocked,
		);
	}

	/**
	 * Build a check.
	 *
	 * @param string      $id        Id.
	 * @param string      $title     Title.
	 * @param string      $status    passed, worth_fixing or needs_fixing.
	 * @param string      $detail    One or two sentences.
	 * @param string|null $link      Route or admin URL.
	 * @param string|null $linkLabel Text of the link.
	 * @return array<string,mixed>
	 */
	private function check( string $id, string $title, string $status, string $detail, ?string $link = null, ?string $linkLabel = null ): array {
		return array(
			'id'         => $id,
			'title'      => $title,
			'status'     => $status,
			'detail'     => $detail,
			'link'       => $link,
			'link_label' => $linkLabel,
		);
	}

	/**
	 * Order storage.
	 *
	 * @return array<string,mixed>
	 */
	private function hpos(): array {
		if ( $this->environment->hposEnabled() ) {
			return $this->check(
				'hpos',
				__( 'Orders use High-Performance Order Storage', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				__( 'Compatible. The plugin reads and writes orders through WooCommerce, never through raw tables.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'hpos',
			__( 'Orders use the older posts storage', 'estasoft-erp-crm-sync-engine' ),
			'passed',
			__( 'Compatible. The plugin works with the older storage and with High-Performance Order Storage alike.', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * Whether the queue runner has run lately.
	 *
	 * @return array<string,mixed>
	 */
	private function cronRunning(): array {
		$title = __( 'Background tasks are running', 'estasoft-erp-crm-sync-engine' );
		$last  = ( $this->lastQueueRun )();
		$at    = null === $last ? null : Time::fromSql( $last );
		if ( null === $at ) {
			return $this->check(
				'cron_running',
				__( 'Background tasks have not run yet', 'estasoft-erp-crm-sync-engine' ),
				'worth_fixing',
				__( 'The sync runs as a background task each minute. It has not run yet; this is normal right after installing. Visit a few pages, then check again.', 'estasoft-erp-crm-sync-engine' ),
				$this->urls->route( '/settings/performance' ),
				__( 'How to set it up', 'estasoft-erp-crm-sync-engine' )
			);
		}
		$minutes = (int) floor( ( $this->clock->now()->getTimestamp() - $at->getTimestamp() ) / 60 );
		if ( $minutes > self::STALE_MINUTES ) {
			return $this->check(
				'cron_running',
				__( 'Background tasks have stopped', 'estasoft-erp-crm-sync-engine' ),
				'needs_fixing',
				sprintf(
					/* translators: %d: minutes since the last run. */
					_n( 'The last run was %d minute ago. Nothing is sent while background tasks are stopped.', 'The last run was %d minutes ago. Nothing is sent while background tasks are stopped.', $minutes, 'estasoft-erp-crm-sync-engine' ),
					$minutes
				),
				$this->urls->route( '/settings/performance' ),
				__( 'How to set it up', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'cron_running',
			$title,
			'passed',
			sprintf(
				/* translators: %d: minutes since the last run. */
				_n( 'The last run was %d minute ago. This is healthy.', 'The last run was %d minutes ago. This is healthy.', max( 1, $minutes ), 'estasoft-erp-crm-sync-engine' ),
				max( 1, $minutes )
			)
		);
	}

	/**
	 * Whether scheduled tasks wait for site visits.
	 *
	 * @return array<string,mixed>
	 */
	private function cronVisits(): array {
		if ( $this->environment->cronRunsOnVisits() ) {
			return $this->check(
				'cron_visits',
				__( 'Scheduled tasks depend on site visits', 'estasoft-erp-crm-sync-engine' ),
				'worth_fixing',
				__( 'Scheduled tasks only run when someone visits the site. A real cron job keeps timing steady.', 'estasoft-erp-crm-sync-engine' ),
				$this->urls->route( '/settings/performance' ),
				__( 'How to set it up', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'cron_visits',
			__( 'Scheduled tasks run on a real cron job', 'estasoft-erp-crm-sync-engine' ),
			'passed',
			__( 'WordPress cron is switched off for page visits, so a server cron job drives the schedule.', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * SKUs used twice.
	 *
	 * @return array<string,mixed>
	 */
	private function duplicateSkus(): array {
		$found = $this->catalog->duplicateSkus( self::LIST );
		if ( 0 === $found['total'] ) {
			return $this->check(
				'dup_sku',
				__( 'No duplicate SKUs', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				sprintf(
					/* translators: %s: number of products checked. */
					_n( '%s product checked.', '%s products checked.', $this->catalog->productCount(), 'estasoft-erp-crm-sync-engine' ),
					number_format_i18n( $this->catalog->productCount() )
				)
			);
		}
		return $this->check(
			'dup_sku',
			sprintf(
				/* translators: %d: number of SKUs used by more than one product. */
				_n( '%d SKU is used by more than one product', '%d SKUs are used by more than one product', $found['total'], 'estasoft-erp-crm-sync-engine' ),
				$found['total']
			),
			'worth_fixing',
			sprintf(
				/* translators: %s: list of SKUs. */
				__( 'Your system matches products by SKU, so a shared SKU can send an order to the wrong product. Examples: %s.', 'estasoft-erp-crm-sync-engine' ),
				implode( ', ', array_column( $found['items'], 'sku' ) )
			),
			$this->urls->products(),
			__( 'Open the products', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * Products without a SKU.
	 *
	 * @return array<string,mixed>
	 */
	private function missingSkus(): array {
		$found = $this->catalog->withoutSku( self::LIST );
		if ( 0 === $found['total'] ) {
			return $this->check(
				'no_sku',
				__( 'Every product has a SKU', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				__( 'Your system matches products by SKU, and none is missing one.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		$names = array();
		foreach ( $found['items'] as $product ) {
			$names[] = '' !== $product['name'] ? $product['name'] : '#' . $product['id'];
		}
		$more = $found['total'] - count( $names );
		return $this->check(
			'no_sku',
			sprintf(
				/* translators: %d: number of products without a SKU. */
				_n( '%d product has no SKU', '%d products have no SKU', $found['total'], 'estasoft-erp-crm-sync-engine' ),
				$found['total']
			),
			'worth_fixing',
			sprintf(
				/* translators: 1: number of products, 2: list of product names. */
				_n( 'Your system matches products by SKU, so this product cannot sync until it has one: %2$s.', 'Your system matches products by SKU, so these %1$d cannot sync until they have one: %2$s.', $found['total'], 'estasoft-erp-crm-sync-engine' ),
				$found['total'],
				implode( ', ', $names ) . ( $more > 0 ? sprintf( /* translators: %d: number of products not listed. */ __( ' and %d more', 'estasoft-erp-crm-sync-engine' ), $more ) : '' )
			),
			$this->urls->route( '/mapping/products' ),
			sprintf(
				/* translators: %d: number of products. */
				_n( 'View the %d product', 'View the %d products', $found['total'], 'estasoft-erp-crm-sync-engine' ),
				$found['total']
			)
		);
	}

	/**
	 * Customers whose state is free text.
	 *
	 * @return array<string,mixed>
	 */
	private function freeTextStates(): array {
		$found = $this->customers->withFreeTextState( self::LIST );
		if ( 0 === $found['total'] ) {
			return $this->check(
				'state',
				__( 'Customer states can be matched', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				__( 'Every saved billing state is one WooCommerce knows.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'state',
			sprintf(
				/* translators: %d: number of customers. */
				_n( '%d customer has a free-text state', '%d customers have a free-text state', $found['total'], 'estasoft-erp-crm-sync-engine' ),
				$found['total']
			),
			'worth_fixing',
			__( 'Your system needs a state it can match, such as Dubai (AE-DU).', 'estasoft-erp-crm-sync-engine' ),
			$this->urls->customers(),
			__( 'Open the customers', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * Decimals in WooCommerce against decimals in the system.
	 *
	 * @return array<string,mixed>
	 */
	private function currency(): array {
		$title    = __( 'Currency decimals match', 'estasoft-erp-crm-sync-engine' );
		$store    = strtoupper( $this->environment->currency() );
		$decimals = $this->environment->currencyDecimals();
		foreach ( $this->connections->all() as $connection ) {
			if ( ConnectionStatus::Disconnected->value === $connection['status'] ) {
				continue;
			}
			$detected = is_array( $connection['config']['detected'] ?? null ) ? $connection['config']['detected'] : array();
			if ( ! isset( $detected['currency_decimals'] ) ) {
				continue;
			}
			$system     = strtoupper( (string) ( $detected['currency'] ?? '' ) );
			$systemDecs = (int) $detected['currency_decimals'];
			if ( '' !== $system && $system !== $store ) {
				return $this->check(
					'currency',
					__( 'The system uses another main currency', 'estasoft-erp-crm-sync-engine' ),
					'worth_fixing',
					sprintf(
						/* translators: 1: store currency, 2: system currency. */
						__( 'Your shop sells in %1$s and the system keeps its books in %2$s. Orders are sent in %1$s; the system converts them.', 'estasoft-erp-crm-sync-engine' ),
						$store,
						$system
					)
				);
			}
			if ( $systemDecs !== $decimals ) {
				return $this->check(
					'currency',
					$title,
					'needs_fixing',
					sprintf(
						/* translators: 1: currency, 2: decimals in WooCommerce, 3: decimals in the system. */
						__( '%1$s has %2$d decimals in WooCommerce and %3$d in the system. Amounts would be off. Change the number of decimals in WooCommerce under Settings, General.', 'estasoft-erp-crm-sync-engine' ),
						$store,
						$decimals,
						$systemDecs
					),
					$this->urls->wooSettings(),
					__( 'Open WooCommerce settings', 'estasoft-erp-crm-sync-engine' )
				);
			}
			return $this->check(
				'currency',
				$title,
				'passed',
				sprintf(
					/* translators: 1: currency, 2: number of decimals. */
					__( '%1$s uses %2$d decimals in WooCommerce and in the system.', 'estasoft-erp-crm-sync-engine' ),
					$store,
					$decimals
				)
			);
		}
		return $this->check(
			'currency',
			$title,
			'passed',
			sprintf(
				/* translators: 1: currency, 2: number of decimals. */
				__( '%1$s uses %2$d decimals in WooCommerce. This is checked against your system once it is connected.', 'estasoft-erp-crm-sync-engine' ),
				'' === $store ? __( 'Your currency', 'estasoft-erp-crm-sync-engine' ) : $store,
				$decimals
			)
		);
	}

	/**
	 * Database character set.
	 *
	 * @return array<string,mixed>
	 */
	private function charset(): array {
		$charset = strtolower( $this->environment->databaseCharset() );
		if ( str_starts_with( $charset, 'utf8mb4' ) ) {
			return $this->check(
				'charset',
				__( 'The database stores Arabic and other scripts', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				__( 'The database setting that keeps names and addresses intact is in place.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'charset',
			__( 'The database cannot store every script', 'estasoft-erp-crm-sync-engine' ),
			'needs_fixing',
			sprintf(
				/* translators: %s: character set name. */
				__( 'The plugin tables use %s. Names and addresses in Arabic, emoji and some other scripts would be damaged. Ask your host to convert the tables to utf8mb4.', 'estasoft-erp-crm-sync-engine' ),
				'' === $charset ? __( 'an unknown character set', 'estasoft-erp-crm-sync-engine' ) : $charset
			)
		);
	}

	/**
	 * Encryption key.
	 *
	 * @return array<string,mixed>
	 */
	private function key(): array {
		if ( $this->box->keyAvailable() ) {
			return $this->check(
				'key',
				__( 'The encryption key is in wp-config.php', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				__( 'Saved credentials cannot be read from a copy of the database.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		return $this->check(
			'key',
			__( 'The encryption key is missing from wp-config.php', 'estasoft-erp-crm-sync-engine' ),
			'needs_fixing',
			__( 'Credentials cannot be saved without it. Add the line shown under Connections to wp-config.php, then reload.', 'estasoft-erp-crm-sync-engine' ),
			$this->urls->route( '/connections' ),
			__( 'Show the line to add', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * PHP version.
	 *
	 * @return array<string,mixed>
	 */
	private function php(): array {
		$version = $this->environment->phpVersion();
		if ( version_compare( $version, '8.1', '>=' ) ) {
			return $this->check(
				'php',
				__( 'PHP 8.1 or newer', 'estasoft-erp-crm-sync-engine' ),
				'passed',
				sprintf(
					/* translators: %s: PHP version. */
					__( 'This site runs PHP %s.', 'estasoft-erp-crm-sync-engine' ),
					$version
				)
			);
		}
		return $this->check(
			'php',
			__( 'PHP is older than 8.1', 'estasoft-erp-crm-sync-engine' ),
			'needs_fixing',
			sprintf(
				/* translators: %s: PHP version. */
				__( 'This site runs PHP %s. Ask your host to switch to PHP 8.1 or newer.', 'estasoft-erp-crm-sync-engine' ),
				$version
			)
		);
	}
}
