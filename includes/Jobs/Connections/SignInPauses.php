<?php
/**
 * Which paused connections were paused because the system refused the sign-in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Connections;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;

/**
 * The public shape of a connection says `person` for every pause that is not the circuit breaker, so a pause after a refused
 * sign-in cannot be told from one a person asked for. Core announces the difference through
 * `estasoft_sync_connection_paused( $id, 'sign_in' | 'person' | 'circuit' )` and `estasoft_sync_connection_resumed`; this keeps a
 * short list of the connections announced as `sign_in`, in the option `estasoft_sync_signin_paused`, until they resume or are
 * paused for another reason. `ConnectionService::update()` reads it to resume such a connection after new credentials pass the test.
 */
final class SignInPauses {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_signin_paused';

	/**
	 * Most connections remembered.
	 */
	private const CAP = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options Options.
	 */
	public function __construct( private readonly OptionStore $options ) {
	}

	/**
	 * Hooked to `estasoft_sync_connection_paused`.
	 *
	 * @param mixed $connectionId Connection.
	 * @param mixed $reason       person, circuit or sign_in.
	 */
	public function onPaused( mixed $connectionId = 0, mixed $reason = '' ): void {
		if ( 'sign_in' === (string) $reason ) {
			$this->mark( (int) $connectionId );
		} else {
			$this->clear( (int) $connectionId );
		}
	}

	/**
	 * Hooked to `estasoft_sync_connection_resumed`.
	 *
	 * @param mixed $connectionId Connection.
	 */
	public function onResumed( mixed $connectionId = 0 ): void {
		$this->clear( (int) $connectionId );
	}

	/**
	 * Remember a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function mark( int $connectionId ): void {
		if ( $connectionId <= 0 ) {
			return;
		}
		$ids = array_values( array_unique( array_merge( $this->ids(), array( $connectionId ) ) ) );
		$this->options->set( self::OPTION, array_slice( $ids, -self::CAP ) );
	}

	/**
	 * Forget a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function clear( int $connectionId ): void {
		$ids = $this->ids();
		if ( in_array( $connectionId, $ids, true ) ) {
			$this->options->set( self::OPTION, array_values( array_diff( $ids, array( $connectionId ) ) ) );
		}
	}

	/**
	 * Whether a connection is paused because of a refused sign-in.
	 *
	 * @param int $connectionId Connection.
	 */
	public function has( int $connectionId ): bool {
		return in_array( $connectionId, $this->ids(), true );
	}

	/**
	 * The remembered ids.
	 *
	 * @return array<int,int>
	 */
	private function ids(): array {
		$stored = $this->options->get( self::OPTION, array() );
		return is_array( $stored ) ? array_values( array_map( 'intval', $stored ) ) : array();
	}
}
