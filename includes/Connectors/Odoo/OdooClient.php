<?php
/**
 * A signed-in view of one Odoo database.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Wraps an OdooTransport with what every part of the connector needs: the company context, a memory of which models
 * and fields exist, and the links to records. One client is used for one connection; it keeps no secrets of its own.
 */
final class OdooClient {

	/**
	 * Fields known to exist, by model. A model that does not exist is stored as an empty list with the key "".
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $fieldCache = array();

	/**
	 * Id of the company the connector works in.
	 *
	 * @var int|null
	 */
	private ?int $companyId = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooTransport   $transport  The protocol in use.
	 * @param OdooVersion     $version    Detected version.
	 * @param OdooAddress     $address    Server address.
	 * @param OdooErrorMapper $errors     Error mapping.
	 * @param string          $companyRef Company name or id from the connection, empty for the user's own company.
	 */
	public function __construct(
		private readonly OdooTransport $transport,
		private readonly OdooVersion $version,
		private readonly OdooAddress $address,
		private readonly OdooErrorMapper $errors,
		private readonly string $companyRef = '',
	) {
	}

	/**
	 * The detected version.
	 */
	public function version(): OdooVersion {
		return $this->version;
	}

	/**
	 * The server address.
	 */
	public function address(): OdooAddress {
		return $this->address;
	}

	/**
	 * Id of the protocol in use: jsonrpc or json2.
	 */
	public function transportId(): string {
		return $this->transport->id();
	}

	/**
	 * Id of the user the key belongs to.
	 *
	 * @throws OdooException When the sign-in is refused.
	 */
	public function userId(): int {
		return $this->transport->userId();
	}

	/**
	 * Search and read.
	 *
	 * @param string                                      $model   Model.
	 * @param array<int,mixed>                            $domain  Domain.
	 * @param array<int,string>                           $fields  Fields.
	 * @param array{limit?:int,offset?:int,order?:string} $options Paging.
	 * @param array<string,mixed>                         $context Extra context, for example the warehouse stock is counted in.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException On failure.
	 */
	public function searchRead( string $model, array $domain, array $fields, array $options = array(), array $context = array() ): array {
		return $this->transport->searchRead( $model, $domain, $fields, $options, $this->context( $context ) );
	}

	/**
	 * Read records.
	 *
	 * @param string            $model  Model.
	 * @param array<int,int>    $ids    Ids.
	 * @param array<int,string> $fields Fields.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException On failure.
	 */
	public function read( string $model, array $ids, array $fields ): array {
		if ( array() === $ids ) {
			return array();
		}
		return $this->transport->read( $model, $ids, $fields, $this->context() );
	}

	/**
	 * Create a record.
	 *
	 * @param string              $model   Model.
	 * @param array<string,mixed> $values  Values.
	 * @param array<string,mixed> $context Extra context, for wizards that read active_ids.
	 * @return int Id.
	 *
	 * @throws OdooException On failure.
	 */
	public function create( string $model, array $values, array $context = array() ): int {
		return $this->transport->create( $model, $values, $this->context( $context ) );
	}

	/**
	 * Change records.
	 *
	 * @param string              $model  Model.
	 * @param array<int,int>      $ids    Ids.
	 * @param array<string,mixed> $values Values.
	 *
	 * @throws OdooException On failure.
	 */
	public function write( string $model, array $ids, array $values ): bool {
		return $this->transport->write( $model, $ids, $values, $this->context() );
	}

	/**
	 * Call a model-level method.
	 *
	 * @param string              $model  Model.
	 * @param string              $method Method.
	 * @param array<string,mixed> $params Named parameters.
	 * @return mixed
	 *
	 * @throws OdooException On failure.
	 */
	public function call( string $model, string $method, array $params = array() ): mixed {
		return $this->transport->callModel( $model, $method, $params, $this->context() );
	}

	/**
	 * Call a method on records.
	 *
	 * @param string              $model   Model.
	 * @param string              $method  Method.
	 * @param array<int,int>      $ids     Ids.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Extra context, for wizards that read active_ids.
	 * @return mixed
	 *
	 * @throws OdooException On failure.
	 */
	public function callRecords( string $model, string $method, array $ids, array $params = array(), array $context = array() ): mixed {
		return $this->transport->callRecords( $model, $method, $ids, $params, $this->context( $context ) );
	}

	/**
	 * Count records.
	 *
	 * @param string           $model  Model.
	 * @param array<int,mixed> $domain Domain.
	 *
	 * @throws OdooException On failure.
	 */
	public function searchCount( string $model, array $domain ): int {
		$count = $this->call( $model, 'search_count', array( 'domain' => $domain ) );
		return is_int( $count ) ? $count : 0;
	}

	/**
	 * The context sent with every call: the company the connector works in, plus extra values.
	 *
	 * @param array<string,mixed> $extra Extra context values.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException When the configured company cannot be found.
	 */
	public function context( array $extra = array() ): array {
		$context = $extra;
		if ( '' !== $this->companyRef ) {
			$context['allowed_company_ids'] = array( $this->companyId() );
		}
		return $context;
	}

	/**
	 * A domain that limits records to the configured company; empty when no company is configured.
	 *
	 * @return array<int,mixed>
	 *
	 * @throws OdooException When the configured company does not exist.
	 */
	public function companyDomain(): array {
		return '' === $this->companyRef ? array() : array( array( 'company_id', '=', $this->companyId() ) );
	}

	/**
	 * Id of the company the connector works in: the configured one, or the sync user's own company.
	 *
	 * @throws OdooException When the configured company does not exist.
	 */
	public function companyId(): int {
		if ( null !== $this->companyId ) {
			return $this->companyId;
		}
		if ( '' === $this->companyRef ) {
			$users = $this->transport->read( 'res.users', array( $this->transport->userId() ), array( 'company_id' ), array() );
			$id    = isset( $users[0] ) ? OdooRecord::id( $users[0]['company_id'] ?? 0 ) : 0;
			if ( $id < 1 ) {
				$exception = $this->companyNotFound( '' );
				throw $exception;
			}
			$this->companyId = $id;
			return $id;
		}
		$domain = ctype_digit( $this->companyRef )
			? array( array( 'id', '=', (int) $this->companyRef ) )
			: array( array( 'name', '=ilike', $this->companyRef ) );
		$found  = $this->transport->searchRead( 'res.company', $domain, array( 'name' ), array( 'limit' => 1 ), array() );
		$id     = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
		if ( $id < 1 ) {
			$exception = $this->companyNotFound( $this->companyRef );
			throw $exception;
		}
		$this->companyId = $id;
		return $id;
	}

	/**
	 * Whether a model exists on the server (its Odoo app is installed).
	 *
	 * @param string $model Model name.
	 *
	 * @throws OdooException On failures other than a missing model.
	 */
	public function hasModel( string $model ): bool {
		return $this->fields( $model, array( 'id' ) )['id'];
	}

	/**
	 * Whether a model has a field.
	 *
	 * @param string $model Model name.
	 * @param string $field Field name.
	 *
	 * @throws OdooException On failures other than a missing model.
	 */
	public function hasField( string $model, string $field ): bool {
		$fields = $this->fields( $model, array( $field ) );
		return $fields[ $field ] ?? false;
	}

	/**
	 * Which of some fields a model has. Asks Odoo once for the fields not asked about before.
	 *
	 * @param string            $model Model name.
	 * @param array<int,string> $names Field names.
	 * @return array<string,bool> Field name => exists.
	 *
	 * @throws OdooException On failures other than a missing model.
	 */
	public function fields( string $model, array $names ): array {
		if ( ! isset( $this->fieldCache[ $model ][''] ) ) {
			$unknown = array_values( array_filter( $names, fn( string $name ): bool => ! isset( $this->fieldCache[ $model ][ $name ] ) ) );
			if ( array() !== $unknown ) {
				$this->loadFields( $model, $unknown );
			}
		}
		$result = array();
		foreach ( $names as $name ) {
			$result[ $name ] = $this->fieldCache[ $model ][ $name ] ?? false;
		}
		return $result;
	}

	/**
	 * A link to a record in the Odoo web client.
	 *
	 * @param string $model Model name.
	 * @param int    $id    Record id.
	 */
	public function recordUrl( string $model, int $id ): string {
		if ( $this->version->atLeast( 18 ) ) {
			return $this->address->url( '/odoo/' . $model . '/' . $id );
		}
		return $this->address->url( '/web#id=' . $id . '&model=' . $model . '&view_type=form' );
	}

	/**
	 * Ask Odoo which of some fields exist and remember the answer.
	 *
	 * @param string            $model Model name.
	 * @param array<int,string> $names Field names.
	 *
	 * @throws OdooException On failures other than a missing model.
	 */
	private function loadFields( string $model, array $names ): void {
		try {
			$found = $this->transport->callModel(
				$model,
				'fields_get',
				array(
					'allfields'  => $names,
					'attributes' => array( 'type' ),
				)
			);
		} catch ( OdooException $exception ) {
			if ( $exception->modelMissing ) {
				$this->fieldCache[ $model ] = array( '' => false );
				return;
			}
			throw $exception;
		}
		$found = is_array( $found ) ? $found : array();
		foreach ( $names as $name ) {
			$this->fieldCache[ $model ][ $name ] = isset( $found[ $name ] );
		}
		unset( $this->fieldCache[ $model ][''] );
	}

	/**
	 * The error for a company that cannot be found.
	 *
	 * @param string $name Name from the connection.
	 */
	private function companyNotFound( string $name ): OdooException {
		return new OdooException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => OdooErrorMapper::SYSTEM,
					'field'  => __( 'Company', 'estasoft-erp-crm-sync-engine' ),
					'reason' => '' === $name
						? __( 'The sync user has no company.', 'estasoft-erp-crm-sync-engine' )
						: sprintf(
							/* translators: %s: company name typed on the connection. */
							__( 'Odoo has no company called %s, or the sync user cannot see it.', 'estasoft-erp-crm-sync-engine' ),
							$this->errors->scrub( $name )
						),
				),
				null,
				null,
				null,
				'company not found'
			),
			'',
			'res.company',
			'search_read'
		);
	}
}
