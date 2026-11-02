<?php
/**
 * The nine tables (spec section 5).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * CREATE TABLE statements in the exact layout dbDelta needs: one column per line, two spaces after
 * PRIMARY KEY, no backticks, no comments. Bump VERSION whenever a statement changes; the migrator
 * runs dbDelta again for every site whose stored version is older. dbDelta cannot drop or change an
 * index and cannot add a unique index over duplicate rows, so Upgrades runs first (see Upgrades).
 *
 * Version 1.1.0: queue `priority`, `reaps`, the claim index with priority, a unique (connection_id,
 * idempotency_key); logs `ts` in milliseconds and `message` capped at 500; connections `disconnected_at`.
 */
final class Schema {

	/**
	 * Schema version stored in the option estasoft_sync_db_version.
	 */
	public const VERSION = '1.1.0';

	/**
	 * Set up the schema.
	 *
	 * @param Tables $tables         Table names.
	 * @param string $charsetCollate Result of $wpdb->get_charset_collate() (utf8mb4).
	 */
	public function __construct(
		private readonly Tables $tables,
		private readonly string $charsetCollate
	) {
	}

	/**
	 * Every CREATE TABLE statement, keyed by logical table name.
	 *
	 * @return array<string,string>
	 */
	public function statements(): array {
		$c = $this->charsetCollate;
		$t = $this->tables;

		$statements = array();

		$statements['connections'] = "CREATE TABLE {$t->connections()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connector varchar(64) NOT NULL,
  type varchar(16) NOT NULL DEFAULT 'erp',
  label varchar(191) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'active',
  config_json longtext NULL,
  settings_json longtext NULL,
  secrets_enc longtext NULL,
  secret_hints_json text NULL,
  key_fingerprint varchar(32) NOT NULL DEFAULT '',
  paused_until datetime NULL DEFAULT NULL,
  paused_reason varchar(16) NULL DEFAULT NULL,
  breaker_failures int(10) unsigned NOT NULL DEFAULT 0,
  last_success_at datetime NULL DEFAULT NULL,
  last_failure_at datetime NULL DEFAULT NULL,
  typical_response_ms int(10) unsigned NULL DEFAULT NULL,
  credential_expires_at datetime NULL DEFAULT NULL,
  credential_warn_days smallint(5) unsigned NOT NULL DEFAULT 14,
  inbound_enabled tinyint(1) NOT NULL DEFAULT 0,
  disconnected_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY connector (connector),
  KEY status (status)
) $c;";

		$statements['queue'] = "CREATE TABLE {$t->queue()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  entity_type varchar(16) NOT NULL,
  entity_id varchar(64) NOT NULL,
  entity_label varchar(191) NOT NULL DEFAULT '',
  event varchar(32) NOT NULL,
  partition_key varchar(100) NOT NULL,
  idempotency_key char(64) NOT NULL,
  priority tinyint(3) unsigned NOT NULL DEFAULT 5,
  state varchar(16) NOT NULL DEFAULT 'queued',
  mode varchar(8) NOT NULL DEFAULT 'live',
  attempt smallint(5) unsigned NOT NULL DEFAULT 0,
  reaps tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
  next_attempt_at datetime NULL DEFAULT NULL,
  locked_until datetime NULL DEFAULT NULL,
  locked_by varchar(64) NULL DEFAULT NULL,
  error_code varchar(20) NULL DEFAULT NULL,
  error_message varchar(500) NULL DEFAULT NULL,
  error_cause varchar(191) NULL DEFAULT NULL,
  external_id varchar(191) NULL DEFAULT NULL,
  external_ref varchar(191) NULL DEFAULT NULL,
  external_url varchar(500) NULL DEFAULT NULL,
  skip_reason varchar(191) NULL DEFAULT NULL,
  customer_name varchar(191) NULL DEFAULT NULL,
  total_minor bigint(20) NULL DEFAULT NULL,
  currency char(3) NULL DEFAULT NULL,
  search_text varchar(500) NULL DEFAULT NULL,
  mapping_version int(10) unsigned NULL DEFAULT NULL,
  failed_before tinyint(1) NOT NULL DEFAULT 0,
  last_log_id bigint(20) unsigned NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY claim (state, next_attempt_at, priority),
  KEY locked (state, locked_until),
  KEY partition_order (connection_id, partition_key, id),
  KEY connection_state (connection_id, state),
  KEY entity (entity_type, entity_id),
  UNIQUE KEY connection_key (connection_id, idempotency_key),
  KEY finished (finished_at),
  KEY created (created_at)
) $c;";

		$statements['logs'] = "CREATE TABLE {$t->logs()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime(3) NOT NULL,
  level varchar(8) NOT NULL DEFAULT 'info',
  connection_id bigint(20) unsigned NULL DEFAULT NULL,
  queue_id bigint(20) unsigned NULL DEFAULT NULL,
  entity_ref varchar(100) NULL DEFAULT NULL,
  code varchar(20) NULL DEFAULT NULL,
  message varchar(500) NOT NULL,
  http_status smallint(5) unsigned NULL DEFAULT NULL,
  duration_ms int(10) unsigned NULL DEFAULT NULL,
  attempt smallint(5) unsigned NULL DEFAULT NULL,
  mapping_version int(10) unsigned NULL DEFAULT NULL,
  request_excerpt text NULL,
  response_excerpt text NULL,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY level_ts (level, ts),
  KEY connection_ts (connection_id, ts),
  KEY queue_id (queue_id),
  KEY code (code)
) $c;";

		$statements['mappings'] = "CREATE TABLE {$t->mappings()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  type varchar(16) NOT NULL,
  version int(10) unsigned NOT NULL,
  status varchar(8) NOT NULL DEFAULT 'draft',
  rules_json longtext NOT NULL,
  note varchar(255) NULL DEFAULT NULL,
  created_by varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  published_at datetime NULL DEFAULT NULL,
  based_on int(10) unsigned NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version_key (connection_id, type, version),
  KEY status_key (connection_id, type, status)
) $c;";

		$statements['id_map'] = "CREATE TABLE {$t->idMap()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  entity_type varchar(16) NOT NULL,
  wc_id varchar(64) NOT NULL,
  external_id varchar(191) NOT NULL,
  external_ref varchar(191) NULL DEFAULT NULL,
  external_url varchar(500) NULL DEFAULT NULL,
  sku varchar(100) NULL DEFAULT NULL,
  is_override tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wc_key (connection_id, entity_type, wc_id),
  KEY external_key (connection_id, entity_type, external_id),
  KEY sku_key (connection_id, sku)
) $c;";

		$statements['state'] = "CREATE TABLE {$t->state()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  entity_type varchar(16) NOT NULL,
  wc_id varchar(64) NOT NULL,
  data_hash char(64) NOT NULL DEFAULT '',
  mapping_version int(10) unsigned NULL DEFAULT NULL,
  synced_at datetime NULL DEFAULT NULL,
  extra_json longtext NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wc_key (connection_id, entity_type, wc_id)
) $c;";

		$statements['idempotency'] = "CREATE TABLE {$t->idempotency()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  idempotency_key char(64) NOT NULL,
  connection_id bigint(20) unsigned NOT NULL,
  entity_type varchar(16) NOT NULL,
  entity_id varchar(64) NOT NULL,
  event varchar(32) NOT NULL,
  ordinal int(10) unsigned NOT NULL DEFAULT 0,
  queue_id bigint(20) unsigned NULL DEFAULT NULL,
  result varchar(16) NOT NULL DEFAULT 'pending',
  external_id varchar(191) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  completed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY entity_event (connection_id, entity_type, entity_id, event),
  KEY created (created_at)
) $c;";

		$statements['recon_runs'] = "CREATE TABLE {$t->reconRuns()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  window_days smallint(5) unsigned NOT NULL DEFAULT 7,
  compared int(10) unsigned NOT NULL DEFAULT 0,
  matched int(10) unsigned NOT NULL DEFAULT 0,
  differences int(10) unsigned NOT NULL DEFAULT 0,
  repaired int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(8) NOT NULL DEFAULT 'running',
  error_code varchar(20) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY connection_started (connection_id, started_at),
  KEY started (started_at)
) $c;";

		$statements['recon_diffs'] = "CREATE TABLE {$t->reconDiffs()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  connection_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  order_label varchar(64) NOT NULL DEFAULT '',
  diff_type varchar(24) NOT NULL,
  wc_value varchar(255) NOT NULL DEFAULT '',
  system_value varchar(255) NOT NULL DEFAULT '',
  status varchar(8) NOT NULL DEFAULT 'open',
  note varchar(255) NULL DEFAULT NULL,
  acted_by varchar(100) NULL DEFAULT NULL,
  acted_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY status_key (status, connection_id),
  KEY order_key (order_id),
  KEY created (created_at)
) $c;";

		return $statements;
	}
}
