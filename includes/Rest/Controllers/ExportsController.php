<?php
/**
 * Routes: export files for the systems that import a file instead of taking calls.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ConnectorsModule;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFile;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportService;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /exports`, `POST /exports`, `GET /exports/{id}/download` and `DELETE /exports/{id}`. A person who can manage lists and downloads; a person who can
 * configure creates and deletes. Files are never reachable by address: the download route checks the capability and streams the file with
 * `Content-Disposition: attachment`.
 */
final class ExportsController extends BaseController {

	/**
	 * Pattern of a file id in a route.
	 */
	private const FILE_ID = '(?P<file>[a-f0-9]{1,32})';

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$file = array(
			'file' => array(
				'type'     => 'string',
				'pattern'  => '^[a-f0-9]{1,32}$',
				'required' => true,
			),
		);
		$this->add( '/exports', 'GET', self::MANAGE, $this->index( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add( '/exports', 'POST', self::CONFIGURE, $this->create( ... ), array( 'connection_id' => array_merge( self::connectionArg(), array( 'required' => true ) ) ) );
		$this->add(
			'/exports/' . self::FILE_ID . '/download',
			'GET',
			self::MANAGE,
			$this->download( ... ),
			$file + array(
				'part' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 20,
					'default' => 0,
				),
			),
			false
		);
		$this->add( '/exports/' . self::FILE_ID, 'DELETE', self::CONFIGURE, $this->delete( ... ), $file );
	}

	/**
	 * `GET /exports?connection_id=`: the files, newest first, and the orders waiting for the next one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{files:array<int,array<string,mixed>>,open_batch:array<string,mixed>|null}
	 */
	public function index( \WP_REST_Request $request ): array {
		$requested = $request->get_param( 'connection_id' );
		$overview  = $this->exports()->overview( is_numeric( $requested ) ? (int) $requested : null );
		return array(
			'files'      => array_map( static fn( ExportFile $file ): array => $file->info(), $overview['files'] ),
			'open_batch' => $overview['open_batch'],
		);
	}

	/**
	 * `POST /exports {connection_id}`: make the file from the orders that wait.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response The new file's `ExportFileInfo`.
	 *
	 * @throws ApiException SYNC-EXP-001, SYNC-EXP-002 or a refusal when nothing waits.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$file = $this->exports()->create( (int) $request->get_param( 'connection_id' ), $this->actorName() );
		} catch ( ExportException $error ) {
			$refusal = $this->translate( $error );
			throw $refusal;
		}
		return new \WP_REST_Response( $file->info(), 201 );
	}

	/**
	 * `GET /exports/{id}/download?part=`: the file, as an attachment. `part` 0 is the file; 1 and up are the extra files that go with it (for
	 * example a list of customers and items to import first), listed in `attachments` of the file's details.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 *
	 * @throws ApiException 404 when the file does not exist (any more).
	 */
	public function download( \WP_REST_Request $request ): \WP_REST_Response {
		$found = $this->exports()->download( (string) $request['file'], (int) $request->get_param( 'part' ) );
		if ( null === $found ) {
			throw ApiException::notFound( esc_html__( 'That export file does not exist any more. Files are kept for 30 days.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $found['mime'] );
		$response->header( 'Content-Disposition', 'attachment; filename="' . str_replace( array( '"', '\\', "\r", "\n" ), '', $found['filename'] ) . '"' );
		$response->header( 'Content-Length', (string) $found['size'] );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		// WordPress would encode the body as JSON. This one-shot filter writes the file instead and tells WordPress the answer was served.
		add_filter(
			'rest_pre_serve_request',
			static function ( $served ) use ( $found ) {
				unset( $served );
				$handle = fopen( $found['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams the plugin's own export file.
				if ( false !== $handle ) {
					while ( ! feof( $handle ) ) {
						echo (string) fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.Security.EscapeOutput.OutputNotEscaped -- the bytes of a file download.
					}
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				return true;
			},
			10,
			1
		);
		return $response;
	}

	/**
	 * `DELETE /exports/{id}`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{deleted:true}
	 *
	 * @throws ApiException 404 when the file does not exist.
	 */
	public function delete( \WP_REST_Request $request ): array {
		if ( ! $this->exports()->delete( (string) $request['file'] ) ) {
			throw ApiException::notFound( esc_html__( 'That export file does not exist any more.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return array( 'deleted' => true );
	}

	/**
	 * The export service.
	 *
	 * @throws \LogicException When the service is not registered.
	 */
	private function exports(): ExportService {
		$service = $this->service( ConnectorsModule::EXPORT_SERVICE );
		if ( ! $service instanceof ExportService ) {
			throw new \LogicException( 'The export service is not registered.' );
		}
		return $service;
	}

	/**
	 * The answer for an export failure: the catalog code with its status.
	 *
	 * @param ExportException $error Failure.
	 */
	private function translate( ExportException $error ): ApiException {
		$failure = $error->error();
		$status  = match ( $failure->code ) {
			ErrorCode::Exp001 => 500,
			ErrorCode::Exp002 => 422,
			default           => 409,
		};
		$context = array();
		foreach ( $failure->context as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$context[ (string) $name ] = (string) $value;
			}
		}
		// A refusal about the export itself (nothing waits, the connection makes no files) is not "a system rejected a field": the catalog
		// sentence would start with an empty system name. Say the reason as it is.
		if ( ErrorCode::Val002 === $failure->code && 'export' === ( $context['field'] ?? '' ) && '' !== trim( $context['reason'] ?? '' ) ) {
			return new ApiException( $failure->code->value, esc_html( $context['reason'] ), $status, ErrorClass::NeedsPerson->value, $context );
		}
		return ApiException::fromCatalog( $failure->code, $context, $status );
	}
}
