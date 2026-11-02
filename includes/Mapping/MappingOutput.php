<?php
/**
 * What running a rule set produced.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * The payload, what each rule did, warnings, the targets that differ from the plain source, and the failures that stop the order.
 */
final class MappingOutput {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,mixed>                                        $payload  Payload keyed by target path.
	 * @param array<int,array{rule:string,target:string,message:string}> $notes    What rules did.
	 * @param array<int,array{rule:string,target:string,message:string}> $warnings Data that was dropped or guessed.
	 * @param array<int,string>                                          $changed  Targets whose value differs from the plain source.
	 * @param array<int,MappingException>                                $errors   Failures that stop the order.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly array $notes = array(),
		public readonly array $warnings = array(),
		public readonly array $changed = array(),
		public readonly array $errors = array(),
	) {
	}

	/**
	 * Whether the payload can be sent.
	 */
	public function isValid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Raise the first failure.
	 *
	 * @throws MappingException When any rule failed.
	 */
	public function throwIfFailed(): void {
		if ( array() !== $this->errors ) {
			throw $this->errors[0];
		}
	}

	/**
	 * Warning texts only.
	 *
	 * @return array<int,string>
	 */
	public function warningMessages(): array {
		return array_map( static fn( array $warning ): string => $warning['message'], $this->warnings );
	}
}
