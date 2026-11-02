<?php
/**
 * Routes: tools.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\DataEraser;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Porter\SettingsPorter;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * Export and import of settings, clear logs, delete all data. Every one needs `estasoft_sync_configure`. Importing never touches
 * credentials or order data and creates draft mapping versions only; deleting data needs the word `DELETE` typed.
 */
final class ToolsController extends BaseController {

	/**
	 * The word that must be typed to delete everything.
	 */
	public const DELETE_WORD = 'DELETE';

	/**
	 * Largest settings file accepted, in bytes.
	 */
	public const MAX_IMPORT_BYTES = 1048576;

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/tools/export', 'POST', self::CONFIGURE, $this->export( ... ), array(), false );
		$this->add(
			'/tools/import',
			'POST',
			self::CONFIGURE,
			$this->import( ... ),
			array(
				'json'    => array(
					'type'              => 'string',
					'required'          => true,
					'maxLength'         => self::MAX_IMPORT_BYTES,
					'sanitize_callback' => array( Sanitize::class, 'document' ),
				),
				'confirm' => self::boolArg(),
			)
		);
		$this->add( '/tools/clear-logs', 'POST', self::CONFIGURE, $this->clearLogs( ... ) );
		$this->add(
			'/tools/delete-data',
			'POST',
			self::CONFIGURE,
			$this->deleteData( ... ),
			array(
				'word' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
	}

	/**
	 * `POST /tools/export`: a settings file without credentials, order data or logs.
	 *
	 * @return array{filename:string,json:string}
	 */
	public function export(): array {
		$porter = $this->serviceAs( JobServices::PORTER, SettingsPorter::class );
		return $porter->export();
	}

	/**
	 * `POST /tools/import`: a preview of the changes, or with `confirm` the changes themselves.
	 *
	 * The answer has exactly the shape of the type: `{ preview: string[] }`, or `{ applied: true, drafts: number, skipped: string[] }`
	 * where `drafts` counts the mapping drafts that were created and `skipped` lists what was left out because it is not connected here.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{preview:array<int,string>}|array{applied:true,drafts:int,skipped:array<int,string>}
	 */
	public function import( \WP_REST_Request $request ): array {
		$porter = $this->serviceAs( JobServices::PORTER, SettingsPorter::class );
		$json   = Sanitize::document( $request->get_param( 'json' ) );
		if ( Sanitize::flag( $request->get_param( 'confirm' ) ) ) {
			$done = $porter->apply( $json, $this->actorId() );
			return array(
				'applied' => true,
				'drafts'  => (int) ( $done['drafts'] ?? 0 ),
				'skipped' => array_values( array_map( 'strval', (array) ( $done['skipped'] ?? array() ) ) ),
			);
		}
		$preview = $porter->preview( $json );
		$lines   = (array) ( $preview['preview'] ?? array() );
		return array( 'preview' => array_values( array_map( 'strval', $lines ) ) );
	}

	/**
	 * `POST /tools/clear-logs`.
	 *
	 * @return array{cleared:int}
	 */
	public function clearLogs(): array {
		$logs = $this->serviceAs( Services::LOG_REPOSITORY, LogRepository::class );
		return array( 'cleared' => $logs->clear() );
	}

	/**
	 * `POST /tools/delete-data`: remove the plugin's tables, options and meta. WooCommerce orders and the connected system are untouched.
	 * The capabilities stay, so the person can set up again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{deleted:true}
	 *
	 * @throws ApiException 422 when the typed word is not DELETE.
	 */
	public function deleteData( \WP_REST_Request $request ): array {
		if ( self::DELETE_WORD !== (string) $request->get_param( 'word' ) ) {
			throw ApiException::invalid(
				esc_html__( 'Type the word DELETE to confirm.', 'estasoft-erp-crm-sync-engine' ),
				array( 'word' => esc_html__( 'Type the word DELETE exactly as shown.', 'estasoft-erp-crm-sync-engine' ) )
			);
		}
		$eraser = $this->serviceAs( Services::DATA_ERASER, DataEraser::class );
		$eraser->erase( false );
		return array( 'deleted' => true );
	}
}
