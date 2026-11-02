<?php
/**
 * Route: signed hints from the connected system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `POST /inbound/{connection}`. This is the only route without a login. The handler checks the HMAC signature of the raw body, the
 * timestamp (five minutes), replays and the rate limit, and the body is only a hint: the plugin reads the data again from the system.
 * A refusal is answered with SYNC-INB-001 and the status the handler chose (401, 404, 409, 413 or 429); the handler logs it.
 */
final class InboundController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/inbound/(?P<connection>\d+)', 'POST', self::SIGNED, $this->receive( ... ), array( 'connection' => array_merge( self::idArg(), array( 'required' => true ) ) ), false );
	}

	/**
	 * Hand the raw body and the two signature headers to the handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{accepted:true}
	 *
	 * @throws \Estasoft\SyncEngine\Jobs\Inbound\InboundRefused When the message is refused; the error mapper answers with its status and SYNC-INB-001.
	 */
	public function receive( \WP_REST_Request $request ): array {
		/** @var InboundHandler $inbound */
		$inbound = $this->service( JobServices::INBOUND );
		$result  = $inbound->handle(
			(int) $request['connection'],
			$request->get_body(),
			array(
				'X-Estasoft-Signature' => (string) $request->get_header( 'X-Estasoft-Signature' ),
				'X-Estasoft-Timestamp' => (string) $request->get_header( 'X-Estasoft-Timestamp' ),
			)
		);
		return array( 'accepted' => ! empty( $result['accepted'] ) );
	}
}
