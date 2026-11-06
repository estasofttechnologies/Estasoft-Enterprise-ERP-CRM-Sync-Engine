<?php
/**
 * Connections: list, add, change, test, disconnect, and the catalog of systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Connections;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\MappingRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\PlainText;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Connectors\Support\GuardedSignInConnector;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;

/**
 * CON-1 to CON-4, CON-9, CON-10, MAP-1, ONB-5.
 *
 * Adding a connection (CON-1): refused without an encryption key (SYNC-CFG-002, with the line to add to wp-config.php); the
 * connector is asked to test the connection first and a failed test saves nothing (the exception carries the test result); on
 * success the row is saved with the secrets encrypted, the facts the test found (version, company, warehouses, capabilities, the
 * contract version) are kept in its config, the reliability settings start from the connector's hints, and version 1 of every
 * mapping type the connector has defaults for is created live (MAP-1).
 *
 * One connection per connector in Phase 1 (CON-10): the data model allows more, so the check lives here and only here
 * (`assertFree()`); a disconnected connection does not count.
 *
 * What a person may send as config is limited to the connector's own non-secret credential fields (and a small `options` map);
 * what they may send as secrets to its secret fields. Anything else is dropped, so a request cannot write into the connection's
 * other settings. Secrets are write-only: an empty value keeps the saved one and nothing is ever returned.
 */
final class ConnectionService {

	/**
	 * Mapping types that get a version 1 when a connection is added.
	 */
	public const MAPPING_TYPES = array( 'orders', 'customers', 'products', 'refunds', 'stock', 'tracking' );

	/**
	 * Longest value of a config field or an option, in characters.
	 */
	private const MAX_VALUE = 200;

	/**
	 * Returns the static catalog rows.
	 *
	 * @var \Closure
	 */
	private \Closure $catalogRows;

	/**
	 * The connect form of each connector.
	 *
	 * @var CatalogForms
	 */
	private CatalogForms $forms;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param ConnectionSettings   $limits      Reliability settings rules and defaults.
	 * @param MappingRepository    $mappings    Mapping versions.
	 * @param \Closure             $registry    Returns the connector registry.
	 * @param SecretBox            $box         Encryption.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param PauseController      $pause       Resumes a connection whose new credentials passed the test.
	 * @param SignInPauses         $signIns     Which connections were paused by a refused sign-in.
	 * @param \Closure|null        $catalogRows Returns the catalog rows; the static file by default.
	 * @param CatalogForms|null    $forms       Builds (and keeps) the connect form of each connector for the catalog; nothing is kept by default.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly ConnectionSettings $limits,
		private readonly MappingRepository $mappings,
		private readonly \Closure $registry,
		private readonly SecretBox $box,
		private readonly Logger $logger,
		private readonly Clock $clock,
		private readonly PauseController $pause,
		private readonly SignInPauses $signIns,
		?\Closure $catalogRows = null,
		?CatalogForms $forms = null
	) {
		$this->forms       = $forms ?? new CatalogForms( $registry );
		$this->catalogRows = $catalogRows ?? static function (): array {
			$rows = require dirname( __DIR__, 2 ) . '/Catalog/catalog.php';
			return is_array( $rows ) ? $rows : array();
		};
	}

	/**
	 * Every connection as a `ConnectionSummary`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function summaries(): array {
		$registry = ( $this->registry )();
		$rows     = array();
		foreach ( $this->connections->all() as $public ) {
			$rows[] = ConnectionPresenter::summary( $public, $registry->get( (string) $public['connector'] ) );
		}
		return $rows;
	}

	/**
	 * One connection as a `Connection` (no secrets).
	 *
	 * @param int $id Connection id.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404 when it does not exist.
	 */
	public function get( int $id ): array {
		$public = $this->connections->findPublic( $id );
		if ( null === $public ) {
			throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		return ConnectionPresenter::full( $public, ( $this->registry )()->get( (string) $public['connector'] ) );
	}

	/**
	 * Add a connection after a successful test.
	 *
	 * @param array<string,mixed> $input A `ConnectionInput`: connector, label, config, secrets, settings.
	 * @return array<string,mixed> The new `Connection`.
	 *
	 * @throws ValidationException When a required field is missing or a setting is out of range.
	 * @throws JobException        409 (SYNC-CFG-002) without an encryption key, 409 when the connector already has a connection, 422 when the test fails (its result is in the context under `test`).
	 */
	public function create( array $input ): array {
		$this->assertKey();
		$connector = $this->connector( (string) ( $input['connector'] ?? '' ) );
		$this->assertFree( $connector );
		$config   = $this->cleanConfig( $connector, (array) ( $input['config'] ?? array() ) );
		$secrets  = $this->cleanSecrets( $connector, (array) ( $input['secrets'] ?? array() ) );
		$settings = array_merge( $this->limits->defaults( $connector->rateLimitHints() ), (array) ( $input['settings'] ?? array() ) );
		$this->requireFields( $connector, $config, $secrets );
		$label = trim( (string) ( $input['label'] ?? '' ) );
		$label = '' === $label ? $connector->label() : mb_substr( $label, 0, 120 );

		// A system with a sign-in page cannot be tested before the person signs in: save the app's details and wait for the sign-in.
		$awaitingSignIn = $connector instanceof SignInConnector && ( ! $connector instanceof GuardedSignInConnector || $connector->requiresSignIn( new Connection( 0, $connector->id(), $connector->type(), $label, ConnectionStatus::Active, $config, array(), $secrets ) ) );
		$tested         = $awaitingSignIn ? new TestResult( true ) : $this->runTest( $connector, 0, $label, $config, $secrets );
		if ( ! $tested->ok ) {
			$failure = $this->failure( $tested );
			throw $failure;
		}
		$id = $this->connections->create(
			array(
				'connector' => $connector->id(),
				'type'      => $connector->type()->value,
				'label'     => $label,
			) + $config,
			$secrets,
			$settings
		);
		if ( $awaitingSignIn ) {
			// Nothing was tested and nothing is sent until the sign-in finishes and the organisation is chosen (selectOrganisation()).
			$this->connections->setStatus( $id, ConnectionStatus::NeedsReconnect, null, 'sign_in' );
			$this->connections->update(
				$id,
				array(
					'config' => array(
						'capabilities'     => array_map( 'boolval', $connector->capabilities() ),
						'contract_version' => $connector->contractVersion(),
					),
				)
			);
		} else {
			$this->connections->update(
				$id,
				array(
					'config'                => $this->detectedFacts( $connector, $tested ),
					'credential_expires_at' => $this->expiry( $tested ),
				)
			);
		}
		$this->seedMappings( $id, $connector );
		$this->logger->info(
			sprintf(
				/* translators: %s: name of the connected system. */
				$awaitingSignIn ? __( 'Saved the app details for %s. Sign in to finish connecting.', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'Connected to %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$connector->label()
			),
			array( 'connection_id' => $id )
		);
		/**
		 * Fires after a connection was added.
		 *
		 * @param int    $id        Connection id.
		 * @param string $connector Connector id.
		 */
		do_action( 'estasoft_sync_connection_created', $id, $connector->id() );
		return $this->get( $id );
	}

	/**
	 * Change a connection. When credentials change, the connection is tested first and a failed test saves nothing: the old
	 * credentials stay and a connection that is paused stays paused. When the test passes and the connection is held because of its
	 * sign-in (a refused sign-in paused it, or it needs reconnecting), it is resumed and the log says so.
	 *
	 * @param int                 $id    Connection id.
	 * @param array<string,mixed> $input Fields to change: label, config, secrets (an empty value keeps the saved one unless an address or another identity field changed), settings.
	 * @return array<string,mixed> The `Connection` after the change.
	 *
	 * @throws ValidationException When a field is missing or out of range.
	 * @throws JobException        404 when it does not exist, 409 without an encryption key, 422 when the test of new credentials fails.
	 */
	public function update( int $id, array $input ): array {
		$public = $this->connections->findPublic( $id );
		if ( null === $public ) {
			throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		$connector = $this->connector( (string) $public['connector'] );
		$changes   = array();
		if ( isset( $input['label'] ) && '' !== trim( (string) $input['label'] ) ) {
			$changes['label'] = mb_substr( trim( (string) $input['label'] ), 0, 120 );
		}
		$config  = $this->cleanConfig( $connector, (array) ( $input['config'] ?? array() ), true );
		$secrets = $this->cleanSecrets( $connector, (array) ( $input['secrets'] ?? array() ), true );
		if ( array() !== array_filter( $secrets, static fn( ?string $value ): bool => '' !== (string) $value ) ) {
			$this->assertKey();
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$errors = $this->limits->validate( $input['settings'] );
			if ( array() !== $errors ) {
				throw new ValidationException( array_map( 'esc_html', $errors ) );
			}
			$changes['settings'] = $input['settings'];
		}
		$credentialsChanged = array() !== $config || array() !== array_filter( $secrets, static fn( ?string $value ): bool => '' !== (string) $value );
		if ( $credentialsChanged ) {
			$tested = $this->test(
				array(
					'connector' => $connector->id(),
					'config'    => $config,
					'secrets'   => $secrets,
				),
				$id
			);
			if ( ! $tested['ok'] ) {
				$failure = $this->failureFromArray( $tested );
				throw $failure;
			}
			$changes['config']                = $config + $this->detectedFactsFromArray( $connector, $tested, (array) ( $public['config']['warehouses'] ?? array() ) );
			$changes['credential_expires_at'] = $this->expiryFromDetected( (array) ( $tested['detected'] ?? array() ) );
		}
		if ( array() !== array_filter( $secrets, static fn( ?string $value ): bool => '' !== (string) $value ) ) {
			$changes['secrets'] = $secrets;
			if ( empty( $public['secrets_readable'] ) ) {
				// The saved secrets belong to another key and cannot be opened. Every secret was entered again (the test needed them);
				// the inbound secret cannot be entered, so it is dropped and inbound is switched off until a person enables it again.
				$changes['secrets']['inbound_secret'] = null;
				$changes['inbound_enabled']           = false;
			}
		}
		if ( array() !== $changes ) {
			try {
				$this->connections->update( $id, $changes );
			} catch ( CryptoException $e ) {
				// The message was escaped where it was raised; it is escaped once here, whatever it was.
				$refusal = new JobException( esc_html( PlainText::fromEscaped( $e->getMessage() ) ), 409, $e->errorCode() );
				throw $refusal;
			}
		}
		if ( $credentialsChanged && $this->heldBySignIn( $id, (string) $public['status'] ) && $this->pause->resume( $id ) ) {
			$this->logger->info(
				sprintf(
					/* translators: %s: name of the connected system. */
					__( 'New credentials for %s passed the test. Syncing resumed.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$connector->label()
				),
				array( 'connection_id' => $id )
			);
		}
		return $this->get( $id );
	}

	/**
	 * Test a connection, saved or not. Saves nothing.
	 *
	 * @param array<string,mixed> $input      A `ConnectionInput`. With an existing connection, config and secrets are laid over the saved ones (an empty secret keeps the saved one) as long as no non-secret credential field (address, database, user, ...) differs from the saved value; when one does, every secret that is required or was saved has to be sent again.
	 * @param int|null            $existingId The saved connection to test, or null for a new one.
	 * @return array<string,mixed> A `ConnectionTestResult`.
	 *
	 * @throws JobException 404 when the saved connection does not exist, 409 when a saved secret is needed and cannot be read with the current encryption key; ValidationException when a required field is missing, or a secret has to be entered again because the address (or another identity field) changed.
	 */
	public function test( array $input, ?int $existingId = null ): array {
		$base   = null;
		$public = null;
		if ( null !== $existingId ) {
			// Without secrets: the saved ones are opened only when they are needed, so a changed encryption key does not block a
			// test whose secrets were all entered again.
			$base   = $this->connections->findWithoutSecrets( $existingId );
			$public = $this->connections->findPublic( $existingId );
			if ( null === $base || null === $public ) {
				throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
			}
		}
		$connector = $this->connector( null === $base ? (string) ( $input['connector'] ?? '' ) : $base->connector );
		$config    = $this->cleanConfig( $connector, (array) ( $input['config'] ?? array() ), null !== $base );
		$secrets   = $this->cleanSecrets( $connector, (array) ( $input['secrets'] ?? array() ), null !== $base );
		$label     = null === $base ? $connector->label() : $base->label;
		if ( null !== $base && null !== $public ) {
			$moved   = $this->changedIdentity( $connector, $config, $base );
			$config  = array_replace( $base->config, $config );
			$secrets = $this->withSavedSecrets( $connector, $secrets, (int) $existingId, (array) ( $public['secret_hints'] ?? array() ), $moved );
		}
		$secrets = array_filter( $secrets, static fn( ?string $value ): bool => null !== $value && '' !== $value );
		$this->requireFields( $connector, $config, $secrets );
		return $this->resultToArray( $this->runTest( $connector, $existingId ?? 0, $label, $config, $secrets, $base ), $connector );
	}

	/**
	 * The label of the first non-secret credential field (address, database, login, company, ...) whose submitted value differs from
	 * the saved one. Where the sign-in goes has changed, so the saved secrets must not go with it.
	 *
	 * @param ConnectorInterface  $connector Connector.
	 * @param array<string,mixed> $submitted Cleaned submitted config.
	 * @param Connection          $base      The saved connection.
	 * @return string|null Label of the field that moved, or null when none did.
	 */
	private function changedIdentity( ConnectorInterface $connector, array $submitted, Connection $base ): ?string {
		foreach ( $connector->credentialFields() as $field ) {
			if ( $field->secret || ! array_key_exists( $field->name, $submitted ) ) {
				continue;
			}
			$saved = isset( $base->config[ $field->name ] ) && is_scalar( $base->config[ $field->name ] ) ? trim( (string) $base->config[ $field->name ] ) : '';
			if ( (string) $submitted[ $field->name ] !== $saved ) {
				return '' !== $field->label ? $field->label : $field->name;
			}
		}
		return null;
	}

	/**
	 * Fill the secrets a person left empty from the saved ones, unless the identity of the connection changed: then nothing saved
	 * is reused and every secret that is required or was saved has to be entered again (a saved key is never sent to an address the
	 * request supplied). Saved secrets are opened only when one is really reused.
	 *
	 * @param ConnectorInterface   $connector Connector.
	 * @param array<string,string> $secrets   Cleaned submitted secrets.
	 * @param int                  $id        Connection id.
	 * @param array<string,mixed>  $hints     Which secrets are saved (name to true).
	 * @param string|null          $moved     Label of the identity field that changed, or null.
	 * @return array<string,string>
	 *
	 * @throws ValidationException When a secret has to be entered again.
	 * @throws JobException        409 when a saved secret is needed and cannot be read with the current key.
	 */
	private function withSavedSecrets( ConnectorInterface $connector, array $secrets, int $id, array $hints, ?string $moved ): array {
		$saved  = null;
		$errors = array();
		foreach ( $connector->credentialFields() as $field ) {
			if ( ! $field->secret || '' !== ( $secrets[ $field->name ] ?? '' ) ) {
				continue;
			}
			$had = ! empty( $hints[ $field->name ] );
			if ( null !== $moved ) {
				if ( $had || $field->required ) {
					$errors[ $field->name ] = sprintf(
						/* translators: 1: name of the secret field (for example API key), 2: name of the field that changed (for example Address). */
						__( 'Enter the %1$s again because the %2$s changed.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$this->plain( $field->label ),
						$this->plain( $moved )
					);
				}
				continue;
			}
			if ( ! $had ) {
				continue;
			}
			if ( null === $saved ) {
				try {
					$saved = $this->connections->find( $id );
				} catch ( CryptoException $e ) {
					$refusal = new JobException( esc_html( PlainText::fromEscaped( $e->getMessage() ) ), 409, $e->errorCode() );
					throw $refusal;
				}
				if ( null === $saved ) {
					throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
				}
			}
			if ( '' !== $saved->secret( $field->name ) ) {
				$secrets[ $field->name ] = $saved->secret( $field->name );
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
		return $secrets;
	}

	/**
	 * A field label inside a sentence: "Address" becomes "address", "API key" stays.
	 *
	 * @param string $label Label.
	 */
	private function plain( string $label ): string {
		return ( mb_strlen( $label ) > 1 && mb_strtolower( mb_substr( $label, 1, 1 ) ) === mb_substr( $label, 1, 1 ) ) ? mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 ) : $label;
	}

	/**
	 * Finish connecting a system with a sign-in page: remember the organisation the person chose, test the connection and, when the test passes,
	 * make it active. A failed test changes nothing: the connection keeps waiting.
	 *
	 * @param int    $id             Connection id.
	 * @param string $organisationId An id from the sign-in connector's organisations().
	 * @return array<string,mixed> The `Connection`.
	 *
	 * @throws JobException 404 when the connection does not exist, 400 when it does not use sign-in, 422 when the organisation is not one the person may use or the test fails, 502 when the system cannot be asked.
	 */
	public function selectOrganisation( int $id, string $organisationId ): array {
		$connection = $this->connections->find( $id );
		if ( null === $connection ) {
			throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		$connector = ( $this->registry )()->get( $connection->connector );
		if ( ! $connector instanceof GuardedSignInConnector ) {
			$refusal = new JobException( esc_html( ErrorCode::Cfg004->template() ), 400, ErrorCode::Cfg004 );
			throw $refusal;
		}
		$organisations = $connector->organisations( $connection );
		if ( $organisations instanceof ConnectorError ) {
			$refusal = new JobException( esc_html( $organisations->message() ), $this->statusFor( $organisations ), $organisations->code );
			throw $refusal;
		}
		$chosen = null;
		foreach ( $organisations as $organisation ) {
			if ( (string) $organisation['id'] === $organisationId ) {
				$chosen = $organisation;
			}
		}
		if ( null === $chosen ) {
			throw new JobException( esc_html__( 'That organisation is not available to the account you signed in with.', 'estasoft-enterprise-erpcrm-sync-engine' ), 422 );
		}
		$keys = $connector->selectOrganisation( $connection, $organisationId );
		if ( $keys instanceof ConnectorError ) {
			$refusal = new JobException( esc_html( $keys->message() ), $this->statusFor( $keys ), $keys->code );
			throw $refusal;
		}
		$this->connections->update(
			$id,
			array(
				'config' => $keys + array(
					'oauth_organisation'      => $organisationId,
					'oauth_organisation_name' => (string) $chosen['name'],
				),
			)
		);
		$this->activateAfterSignIn( $id, $connector );
		return $this->get( $id );
	}

	/**
	 * After a sign-in finished: when an organisation was chosen earlier (the person reconnects an expired sign-in), test and resume.
	 * A first sign-in waits for the choice of the organisation.
	 *
	 * @param int $id Connection id.
	 */
	public function afterSignIn( int $id ): void {
		$connection = $this->connections->findWithoutSecrets( $id );
		$connector  = null === $connection ? null : ( $this->registry )()->get( $connection->connector );
		if ( null === $connection || ! $connector instanceof GuardedSignInConnector || '' === (string) $connection->config( 'oauth_organisation', '' ) ) {
			return;
		}
		try {
			$this->activateAfterSignIn( $id, $connector );
		} catch ( JobException $error ) {
			unset( $error ); // The connection keeps waiting; the log has the reason, and Test connection shows it.
		}
	}

	/**
	 * Test a connection that has tokens and an organisation, and resume it when the test passes.
	 *
	 * @param int                    $id        Connection id.
	 * @param GuardedSignInConnector $connector Connector.
	 *
	 * @throws JobException 422 when the test fails.
	 */
	private function activateAfterSignIn( int $id, GuardedSignInConnector $connector ): void {
		$connection = $this->connections->find( $id );
		if ( null === $connection ) {
			return;
		}
		$tested = $connector->test( $connection ); // Guarded: a failure comes back as a result, never as an exception.
		if ( ! $tested->ok ) {
			$failure = $this->failure( $tested );
			throw $failure;
		}
		$facts = $this->detectedFacts( $connector, $tested );
		$this->connections->update( $id, array( 'config' => $facts ) );
		$this->pause->resume( $id );
		$this->logger->info(
			sprintf(
				/* translators: %s: name of the connected system. */
				__( 'Connected to %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$connector->label()
			),
			array( 'connection_id' => $id )
		);
	}

	/**
	 * Disconnect: the secrets are wiped, the queue, the mapping versions and the history stay (CON-9).
	 *
	 * @param int $id Connection id.
	 *
	 * @throws JobException With status 404 when it does not exist.
	 */
	public function disconnect( int $id ): void {
		$public = $this->connections->findPublic( $id );
		if ( null === $public ) {
			throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		$this->connections->delete( $id );
		$this->logger->info(
			sprintf(
				/* translators: %s: name of the connected system. */
				__( 'Disconnected from %s. The saved sign-in details were erased. Nothing was deleted in the system or in WooCommerce.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				(string) $public['label']
			),
			array( 'connection_id' => $id )
		);
		/**
		 * Fires after a connection was disconnected.
		 *
		 * @param int    $id        Connection id.
		 * @param string $connector Connector id.
		 */
		do_action( 'estasoft_sync_connection_disconnected', $id, (string) $public['connector'] );
	}

	/**
	 * The systems the person can choose from (`CatalogEntry[]`): static data, with `available` true only for a connector that is installed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function catalog(): array {
		$registry = ( $this->registry )();
		/**
		 * Filter the catalog of systems.
		 *
		 * @param array<int,array<string,mixed>> $rows Catalog rows.
		 */
		$rows     = apply_filters( 'estasoft_sync_catalog', ( $this->catalogRows )() );
		$prepared = array();
		$wanted   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
				continue;
			}
			// A connector document can say the system cannot be chosen yet (`available: false` in its front matter, written to the row as `selectable`).
			$selectable = false !== ( $row['selectable'] ?? true );
			$connector  = isset( $row['connector'] ) && is_string( $row['connector'] ) && '' !== $row['connector'] ? $row['connector'] : null;
			$variants   = array();
			foreach ( is_array( $row['variants'] ?? null ) ? $row['variants'] : array() as $variant ) {
				if ( is_array( $variant ) && isset( $variant['id'] ) && is_string( $variant['id'] ) ) {
					$variants[] = array(
						'id'        => $variant['id'],
						'label'     => (string) ( $variant['label'] ?? $variant['id'] ),
						'available' => $registry->has( $variant['id'] ),
					) + ( isset( $variant['unavailable_reason'] ) && '' !== (string) $variant['unavailable_reason'] ? array( 'unavailable_reason' => (string) $variant['unavailable_reason'] ) : array() ) + self::variantDetails( $variant );
				}
			}
			$available = null !== $connector && $registry->has( $connector );
			foreach ( $variants as $variant ) {
				$available = $available || $variant['available'];
			}
			$available = $available && $selectable;
			if ( $selectable ) {
				$wanted[] = null !== $connector && $registry->has( $connector ) ? $connector : '';
				foreach ( $variants as $variant ) {
					$wanted[] = $variant['available'] ? $variant['id'] : '';
				}
			}
			$prepared[] = array( $row, $connector, $variants, $available, $selectable );
		}
		$forms = $this->forms->forConnectors( $wanted );
		$out   = array();
		foreach ( $prepared as list( $row, $connector, $variants, $available, $selectable ) ) {
			$method = (string) ( $row['connection_method'] ?? 'unknown' );
			foreach ( $variants as $index => $variant ) {
				$variants[ $index ] += self::formOf( $selectable && $variant['available'] ? $forms[ $variant['id'] ] ?? null : null, (string) ( $variant['connection_method'] ?? $method ) );
			}
			$out[] = array(
				'name'               => (string) $row['name'],
				'type'               => (string) ( $row['type'] ?? 'erp' ),
				'regions'            => (string) ( $row['regions'] ?? '' ),
				'connector'          => $connector,
				'connection_method'  => $method,
				'needs'              => (string) ( $row['needs'] ?? '' ),
				'available'          => $available,
				'unavailable_reason' => (string) ( $row['unavailable_reason'] ?? '' ),
				'verified'           => ! empty( $row['verified'] ),
				'variants'           => $variants,
				'hosts'              => array_values( array_map( 'strval', is_array( $row['hosts'] ?? null ) ? $row['hosts'] : array() ) ),
				'terms_url'          => (string) ( $row['terms_url'] ?? '' ),
				'privacy_url'        => (string) ( $row['privacy_url'] ?? '' ),
				'developer_url'      => (string) ( $row['developer_url'] ?? '' ),
				'steps'              => array_values( array_map( 'strval', is_array( $row['steps'] ?? null ) ? $row['steps'] : array() ) ),
				'wave'               => (int) ( $row['wave'] ?? 0 ),
			) + self::formOf( $selectable && null !== $connector ? $forms[ $connector ] ?? null : null, $method );
		}
		return $out;
	}

	/**
	 * What a product of a family row says about itself: how it connects, the addresses it talks to, its legal and developer links and
	 * its steps. A family row (Sage) holds the first product's text, so without these the screen of another product would show the
	 * wrong steps and the wrong addresses. Only what the product's document gives is sent.
	 *
	 * @param array<string,mixed> $variant The variant as the catalog row holds it.
	 * @return array<string,mixed>
	 */
	private static function variantDetails( array $variant ): array {
		$out = array();
		foreach ( array( 'connection_method', 'terms_url', 'privacy_url', 'developer_url' ) as $key ) {
			if ( isset( $variant[ $key ] ) && is_string( $variant[ $key ] ) ) {
				$out[ $key ] = $variant[ $key ];
			}
		}
		foreach ( array( 'hosts', 'steps' ) as $key ) {
			if ( isset( $variant[ $key ] ) && is_array( $variant[ $key ] ) ) {
				$out[ $key ] = array_values( array_map( 'strval', array_filter( $variant[ $key ], 'is_scalar' ) ) );
			}
		}
		return $out;
	}

	/**
	 * The form part of a catalog row or variant: fields, whether the person signs in on the system's page, and the sign-in style.
	 * A system that cannot be chosen (not installed, or not selectable yet) has no fields and cannot sign in.
	 *
	 * @param array<string,mixed>|null $form   From CatalogForms, or null.
	 * @param string                   $method The row's connection method, for the style when the connector does not name one.
	 * @return array{credential_fields:array<int,array<string,mixed>>,can_sign_in:bool,auth_style:string}
	 */
	private static function formOf( ?array $form, string $method ): array {
		$style = null === $form ? '' : (string) ( $form['auth_style'] ?? '' );
		return array(
			'credential_fields' => null === $form ? array() : array_values( (array) ( $form['credential_fields'] ?? array() ) ),
			'can_sign_in'       => null !== $form && ! empty( $form['can_sign_in'] ),
			'auth_style'        => '' !== $style ? $style : CatalogForms::styleFromMethod( $method ),
		);
	}

	/**
	 * Whether a connection is held because of its sign-in: it needs reconnecting, or a refused sign-in paused it (a pause a person
	 * asked for, or the circuit breaker's, is left alone).
	 *
	 * @param int    $id     Connection id.
	 * @param string $status Its status.
	 */
	private function heldBySignIn( int $id, string $status ): bool {
		return ConnectionStatus::NeedsReconnect->value === $status || ( ConnectionStatus::Paused->value === $status && $this->signIns->has( $id ) );
	}

	/**
	 * Refuse when no encryption key is defined.
	 *
	 * @throws JobException 409 with SYNC-CFG-002 and the line to add to wp-config.php.
	 */
	private function assertKey(): void {
		if ( ! $this->box->keyAvailable() ) {
			$refusal = new JobException( esc_html( ErrorCode::Cfg002->template() ), 409, ErrorCode::Cfg002, array( 'key_line' => $this->box->generateKeyLine() ) );
			throw $refusal;
		}
	}

	/**
	 * The connector with an id.
	 *
	 * @param string $id Connector id.
	 *
	 * @throws ValidationException When no such connector is installed.
	 */
	private function connector( string $id ): ConnectorInterface {
		$connector = ( $this->registry )()->get( $id );
		if ( null === $connector ) {
			throw new ValidationException( array( 'connector' => esc_html__( 'Choose a system that is installed.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		return $connector;
	}

	/**
	 * One connection per connector in Phase 1.
	 *
	 * @param ConnectorInterface $connector Connector.
	 *
	 * @throws JobException With status 409 when it already has a connection.
	 */
	private function assertFree( ConnectorInterface $connector ): void {
		foreach ( $this->connections->allWithoutSecrets() as $existing ) {
			if ( $existing->connector === $connector->id() && ConnectionStatus::Disconnected !== $existing->status ) {
				throw new JobException(
					esc_html(
						sprintf(
							/* translators: %s: name of the connected system. */
							__( 'You are already connected to %s. Change that connection, or disconnect it first.', 'estasoft-enterprise-erpcrm-sync-engine' ),
							$connector->label()
						)
					),
					409
				);
			}
		}
	}

	/**
	 * Keep the connector's own non-secret fields, and a small map of options.
	 *
	 * @param ConnectorInterface $connector Connector.
	 * @param array<mixed>       $config    Submitted config.
	 * @param bool               $partial   Whether fields may be left out.
	 * @return array<string,mixed>
	 */
	private function cleanConfig( ConnectorInterface $connector, array $config, bool $partial = false ): array {
		unset( $partial );
		$clean = array();
		foreach ( $connector->credentialFields() as $field ) {
			if ( ! $field->secret && 'display' !== $field->type && array_key_exists( $field->name, $config ) && is_scalar( $config[ $field->name ] ) ) {
				$clean[ $field->name ] = mb_substr( trim( (string) $config[ $field->name ] ), 0, self::MAX_VALUE );
			}
		}
		if ( isset( $config['options'] ) && is_array( $config['options'] ) ) {
			$options = array();
			foreach ( $config['options'] as $key => $value ) {
				if ( is_string( $key ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) && is_scalar( $value ) ) {
					$options[ $key ] = mb_substr( trim( (string) $value ), 0, self::MAX_VALUE );
				}
			}
			$clean['options'] = $options;
		}
		return $clean;
	}

	/**
	 * Keep the connector's own secret fields.
	 *
	 * @param ConnectorInterface $connector Connector.
	 * @param array<mixed>       $secrets   Submitted secrets.
	 * @param bool               $partial   Unused: an empty value always means "keep".
	 * @return array<string,string>
	 */
	private function cleanSecrets( ConnectorInterface $connector, array $secrets, bool $partial = false ): array {
		unset( $partial );
		$clean = array();
		foreach ( $connector->credentialFields() as $field ) {
			if ( $field->secret && array_key_exists( $field->name, $secrets ) && is_scalar( $secrets[ $field->name ] ) ) {
				$clean[ $field->name ] = trim( (string) $secrets[ $field->name ] );
			}
		}
		return $clean;
	}

	/**
	 * Every required field must have a value (a saved secret counts as a value when testing a saved connection).
	 *
	 * @param ConnectorInterface   $connector Connector.
	 * @param array<string,mixed>  $config    Non-secret values.
	 * @param array<string,string> $secrets   Secret values.
	 *
	 * @throws ValidationException With one message per missing field.
	 */
	private function requireFields( ConnectorInterface $connector, array $config, array $secrets ): void {
		$errors = array();
		foreach ( $connector->credentialFields() as $field ) {
			if ( ! $field->required ) {
				continue;
			}
			$value = $field->secret ? ( $secrets[ $field->name ] ?? '' ) : (string) ( $config[ $field->name ] ?? '' );
			if ( '' === trim( (string) $value ) ) {
				$errors[ $field->name ] = __( 'This field is required.', 'estasoft-enterprise-erpcrm-sync-engine' );
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
	}

	/**
	 * Run the connector's test. Whatever it throws becomes a failed result.
	 *
	 * @param ConnectorInterface   $connector Connector.
	 * @param int                  $id        Connection id, 0 for a new one.
	 * @param string               $label     Label.
	 * @param array<string,mixed>  $config    Non-secret values.
	 * @param array<string,string> $secrets   Secret values.
	 * @param Connection|null      $base      The saved connection, if testing one.
	 */
	private function runTest( ConnectorInterface $connector, int $id, string $label, array $config, array $secrets, ?Connection $base = null ): TestResult {
		$connection = new Connection(
			$id,
			$connector->id(),
			$connector->type(),
			$label,
			ConnectionStatus::Active,
			$config,
			null === $base ? $this->limits->defaults( $connector->rateLimitHints() ) : $base->settings,
			$secrets
		);
		try {
			return $connector->test( $connection );
		} catch ( \Throwable $e ) {
			return new TestResult(
				false,
				'',
				'',
				array(),
				array(),
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $connector->label(),
						'field'  => 'connection',
						'reason' => sprintf(
							/* translators: %s: PHP error class name. */
							__( 'The test stopped unexpectedly (%s).', 'estasoft-enterprise-erpcrm-sync-engine' ),
							( new \ReflectionClass( $e ) )->getShortName()
						),
					)
				)
			);
		}
	}

	/**
	 * A `ConnectionTestResult`.
	 *
	 * @param TestResult         $result    Test result.
	 * @param ConnectorInterface $connector Connector.
	 * @return array<string,mixed>
	 */
	private function resultToArray( TestResult $result, ConnectorInterface $connector ): array {
		unset( $connector );
		$error = $result->error;
		return array(
			'ok'                  => $result->ok,
			'version'             => $result->version,
			'company'             => $result->company,
			'warehouses'          => array_values(
				array_map(
					static fn( array $warehouse ): array => array(
						'id'   => (string) $warehouse['id'],
						'name' => (string) $warehouse['name'],
					),
					$result->warehouses
				)
			),
			'product_count'       => isset( $result->detected['product_count'] ) ? (int) $result->detected['product_count'] : null,
			'permissions'         => array_map( 'boolval', $result->permissions ),
			'missing_permissions' => $result->missingPermissions(),
			'error'               => null === $error ? null : array(
				'code'    => $error->code->value,
				'message' => $error->message(),
				'data'    => array(
					'status'  => $this->statusFor( $error ),
					'class'   => $error->errorClass()->value,
					'context' => $this->strings( $error->context ),
				),
			),
			'detected'            => $result->detected,
		);
	}

	/**
	 * The HTTP status that goes with a failed test.
	 *
	 * @param ConnectorError $error Failure.
	 */
	private function statusFor( ConnectorError $error ): int {
		if ( null !== $error->httpStatus && $error->httpStatus >= 400 && $error->httpStatus < 600 ) {
			return $error->httpStatus;
		}
		return match ( $error->code ) {
			ErrorCode::Auth001, ErrorCode::Auth002, ErrorCode::Auth005 => 401,
			ErrorCode::Auth004, ErrorCode::Auth006, ErrorCode::Auth007 => 403,
			ErrorCode::Cfg003 => 404,
			ErrorCode::Net001, ErrorCode::Net002, ErrorCode::Net003 => 502,
			default => 422,
		};
	}

	/**
	 * Only the text values of a context.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,string>
	 */
	private function strings( array $context ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The exception for a failed test.
	 *
	 * @param TestResult $result Failed result.
	 */
	private function failure( TestResult $result ): JobException {
		$error  = $result->error ?? new ConnectorError(
			ErrorCode::Val002,
			array(
				'field'  => 'connection',
				'reason' => __( 'The test failed.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			)
		);
		$mapped = array(
			'ok'                  => false,
			'version'             => $result->version,
			'company'             => $result->company,
			'warehouses'          => array(),
			'product_count'       => null,
			'permissions'         => array_map( 'boolval', $result->permissions ),
			'missing_permissions' => $result->missingPermissions(),
			'error'               => array(
				'code'    => $error->code->value,
				'message' => $error->message(),
				'data'    => array(
					'status'  => $this->statusFor( $error ),
					'class'   => $error->errorClass()->value,
					'context' => $this->strings( $error->context ),
				),
			),
		);
		return new JobException( esc_html( $error->message() ), $this->statusFor( $error ), $error->code, array( 'test' => $mapped ) );
	}

	/**
	 * The exception for a failed test given as an array.
	 *
	 * @param array<string,mixed> $result A `ConnectionTestResult`.
	 */
	private function failureFromArray( array $result ): JobException {
		$error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
		$code  = ErrorCode::tryFrom( (string) ( $error['code'] ?? '' ) );
		unset( $result['detected'] );
		return new JobException( esc_html( (string) ( $error['message'] ?? __( 'The test failed.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ), (int) ( $error['data']['status'] ?? 422 ), $code, array( 'test' => $result ) );
	}

	/**
	 * What the test found, to keep in the connection's config.
	 *
	 * @param ConnectorInterface $connector Connector.
	 * @param TestResult         $tested    Result.
	 * @return array<string,mixed>
	 */
	private function detectedFacts( ConnectorInterface $connector, TestResult $tested ): array {
		$warehouses = array();
		foreach ( $tested->warehouses as $warehouse ) {
			$warehouses[] = array(
				'id'                  => (string) $warehouse['id'],
				'name'                => (string) $warehouse['name'],
				'counts_toward_stock' => true,
				'countries'           => array(),
			);
		}
		$detected = $tested->detected;
		unset( $detected['credential_expires_at'] );
		return array(
			'version'          => $tested->version,
			'detected_company' => $tested->company,
			'warehouses'       => $warehouses,
			'capabilities'     => array_map( 'boolval', $connector->capabilities() ),
			'contract_version' => $connector->contractVersion(),
			'detected'         => $detected,
			'tested_at'        => (string) Time::iso( Time::sql( $this->clock->now() ) ),
		);
	}

	/**
	 * The same facts from a test result array (used when saved credentials change). The person's warehouse choices are kept.
	 *
	 * @param ConnectorInterface  $connector Connector.
	 * @param array<string,mixed> $tested    A `ConnectionTestResult` with `detected`.
	 * @param array<int,mixed>    $before    Warehouses saved before, with the person's choices.
	 * @return array<string,mixed>
	 */
	private function detectedFactsFromArray( ConnectorInterface $connector, array $tested, array $before = array() ): array {
		$chosen = array();
		foreach ( $before as $warehouse ) {
			if ( is_array( $warehouse ) && isset( $warehouse['id'] ) ) {
				$chosen[ (string) $warehouse['id'] ] = $warehouse;
			}
		}
		$warehouses = array();
		foreach ( (array) ( $tested['warehouses'] ?? array() ) as $warehouse ) {
			$id           = (string) $warehouse['id'];
			$warehouses[] = array(
				'id'                  => $id,
				'name'                => (string) $warehouse['name'],
				'counts_toward_stock' => ! isset( $chosen[ $id ]['counts_toward_stock'] ) || (bool) $chosen[ $id ]['counts_toward_stock'],
				'countries'           => array_values( array_map( 'strval', (array) ( $chosen[ $id ]['countries'] ?? array() ) ) ),
			);
		}
		$detected = (array) ( $tested['detected'] ?? array() );
		unset( $detected['credential_expires_at'] );
		return array(
			'version'          => (string) ( $tested['version'] ?? '' ),
			'detected_company' => (string) ( $tested['company'] ?? '' ),
			'warehouses'       => $warehouses,
			'capabilities'     => array_map( 'boolval', $connector->capabilities() ),
			'contract_version' => $connector->contractVersion(),
			'detected'         => $detected,
			'tested_at'        => (string) Time::iso( Time::sql( $this->clock->now() ) ),
		);
	}

	/**
	 * The credential end date the test found, as a MySQL datetime.
	 *
	 * @param TestResult $tested Result.
	 */
	private function expiry( TestResult $tested ): ?string {
		return $this->expiryFromDetected( $tested->detected );
	}

	/**
	 * The credential end date in a detected-facts array, as a MySQL datetime.
	 *
	 * @param array<string,mixed> $detected Detected facts.
	 */
	private function expiryFromDetected( array $detected ): ?string {
		$value = $detected['credential_expires_at'] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$parsed = date_create_immutable( $value, new \DateTimeZone( 'UTC' ) );
		return false === $parsed ? null : Time::sql( $parsed );
	}

	/**
	 * Create version 1 of every mapping type the connector has default rules for. A failure here never undoes the connection.
	 *
	 * @param int                $id        Connection id.
	 * @param ConnectorInterface $connector Connector.
	 */
	private function seedMappings( int $id, ConnectorInterface $connector ): void {
		foreach ( self::MAPPING_TYPES as $type ) {
			try {
				$rules = $connector->defaultMapping( $type );
				if ( array() !== $rules ) {
					$this->mappings->seedLive( $id, $type, $rules, 'system' );
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					sprintf(
						/* translators: %s: mapping type. */
						__( 'The default %s mapping could not be created. Open Mapping to set it up.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$type
					),
					array( 'connection_id' => $id )
				);
			}
		}
	}
}
