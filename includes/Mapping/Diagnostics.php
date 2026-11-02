<?php
/**
 * Warnings and notes collected while rules run.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * A warning means data was dropped or guessed (an unreadable phone number became empty). A note explains what a rule
 * did, for the preview. Neither stops the order.
 */
final class Diagnostics {

	/**
	 * Warnings in the order they happened.
	 *
	 * @var array<int,array{rule:string,target:string,message:string}>
	 */
	private array $warnings = array();

	/**
	 * Notes in the order they happened.
	 *
	 * @var array<int,array{rule:string,target:string,message:string}>
	 */
	private array $notes = array();

	/**
	 * Record a warning.
	 *
	 * @param string $ruleId  Rule id.
	 * @param string $target  Target path.
	 * @param string $message Plain-language message.
	 */
	public function warn( string $ruleId, string $target, string $message ): void {
		$this->warnings[] = array(
			'rule'    => $ruleId,
			'target'  => $target,
			'message' => $message,
		);
	}

	/**
	 * Record a note.
	 *
	 * @param string $ruleId  Rule id.
	 * @param string $target  Target path.
	 * @param string $message Plain-language message.
	 */
	public function note( string $ruleId, string $target, string $message ): void {
		$this->notes[] = array(
			'rule'    => $ruleId,
			'target'  => $target,
			'message' => $message,
		);
	}

	/**
	 * All warnings.
	 *
	 * @return array<int,array{rule:string,target:string,message:string}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * All notes.
	 *
	 * @return array<int,array{rule:string,target:string,message:string}>
	 */
	public function notes(): array {
		return $this->notes;
	}
}
