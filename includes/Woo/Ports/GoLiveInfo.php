<?php
/**
 * When syncing went live.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented over Core\OnboardingState: the moment the setup was finished (answers.went_live_at, written by Jobs\Shadow\GoLiveService).
 * An order placed before that moment is not sent unless a person sends it; an order placed after it is.
 */
interface GoLiveInfo {

	/**
	 * The moment syncing went live, or null while setup is not finished.
	 */
	public function wentLiveAt(): ?\DateTimeImmutable;
}
