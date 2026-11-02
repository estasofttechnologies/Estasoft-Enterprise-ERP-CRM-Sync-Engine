<?php
/**
 * The facts the admin notices depend on.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Reads the state of the site once and hands it to the pure rules in NoticeRules.
 */
final class NoticeFacts {

	/**
	 * User meta that lists the notices a person dismissed for good.
	 */
	public const DISMISSED_META = 'estasoft_sync_dismissed_notices';

	/**
	 * @param Snapshot        $snapshot   Queue and connection picture.
	 * @param SecretBox       $box        Encryption key.
	 * @param Settings        $settings   Settings (queue warning limit).
	 * @param OnboardingState $onboarding Setup state.
	 * @param Clock           $clock      Time source.
	 */
	public function __construct(
		private readonly Snapshot $snapshot,
		private readonly SecretBox $box,
		private readonly Settings $settings,
		private readonly OnboardingState $onboarding,
		private readonly Clock $clock
	) {
	}

	/**
	 * The facts for one person.
	 *
	 * @param int $userId User id.
	 * @return array<string,mixed>
	 */
	public function gather( int $userId ): array {
		$picture    = $this->snapshot->current();
		$onboarding = $this->onboarding->get();
		$missing    = ! $this->box->keyAvailable();

		return array(
			'can_manage'        => Capabilities::userCanManage(),
			'can_configure'     => Capabilities::userCanConfigure(),
			'key_missing'       => $missing,
			'key_line'          => $missing ? $this->box->generateKeyLine() : '',
			'connected'         => 'not_connected' !== $picture['state'],
			'setup_done'        => (bool) $onboarding['completed'],
			'waiting'           => $picture['waiting'],
			'failed'            => $picture['failed'],
			'reconnect'         => $picture['reconnect'],
			'backlog_threshold' => $this->settings->backlogThreshold(),
			'shadow_ends_soon'  => $this->shadowEndsSoon( $picture['shadow'], $picture['shadow_ends_at'] ),
			'dismissed'         => $this->dismissed( $userId ),
		);
	}

	/**
	 * Notice ids a person dismissed for good.
	 *
	 * @param int $userId User id.
	 * @return array<int,string>
	 */
	public function dismissed( int $userId ): array {
		$stored = get_user_meta( $userId, self::DISMISSED_META, true );
		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/**
	 * Remember that a person dismissed a notice for good.
	 *
	 * @param int    $userId User id.
	 * @param string $id     Notice id.
	 */
	public function dismiss( int $userId, string $id ): void {
		$list = $this->dismissed( $userId );
		if ( ! in_array( $id, $list, true ) ) {
			$list[] = $id;
			update_user_meta( $userId, self::DISMISSED_META, $list );
		}
	}

	/**
	 * Whether the shadow window is open and ends within a day.
	 *
	 * @param bool        $active Whether the window is open.
	 * @param string|null $endsAt End time as stored.
	 */
	private function shadowEndsSoon( bool $active, ?string $endsAt ): bool {
		if ( ! $active || null === $endsAt ) {
			return false;
		}
		$end = date_create_immutable( $endsAt, new \DateTimeZone( 'UTC' ) );
		if ( false === $end ) {
			return false;
		}
		$left = (int) $end->format( 'U' ) - (int) $this->clock->now()->format( 'U' );
		return $left > 0 && $left <= DAY_IN_SECONDS;
	}
}
