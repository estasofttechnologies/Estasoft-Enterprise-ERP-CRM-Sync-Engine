<?php
/**
 * What a transform may look at while it runs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Diagnostics;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;

defined( 'ABSPATH' ) || exit;

/**
 * The order, the lookups, the rule currently running and, inside a list rule, the row. The engine moves the cursor with enter();
 * transforms only read it.
 */
final class TransformContext {

	/**
	 * Rule id.
	 *
	 * @var string
	 */
	private string $ruleId = '';

	/**
	 * Target path of the rule.
	 *
	 * @var string
	 */
	private string $target = '';

	/**
	 * First source path of the rule.
	 *
	 * @var string
	 */
	private string $source = '';

	/**
	 * Error code configured on the rule.
	 *
	 * @var ErrorCode|null
	 */
	private ?ErrorCode $errorCode = null;

	/**
	 * Row of a list rule, or null.
	 *
	 * @var int|null
	 */
	private ?int $row = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param CanonicalOrder     $order       The order being mapped.
	 * @param Diagnostics        $diagnostics Where warnings go.
	 * @param MappingEnvironment $environment Connection, system name and lookups.
	 */
	public function __construct(
		public readonly CanonicalOrder $order,
		public readonly Diagnostics $diagnostics,
		public readonly MappingEnvironment $environment = new MappingEnvironment(),
	) {
	}

	/**
	 * Point the context at a rule (and a row of it).
	 *
	 * @param string         $ruleId    Rule id.
	 * @param string         $target    Target path.
	 * @param string         $source    First source path, empty for a constant.
	 * @param ErrorCode|null $errorCode Code the rule asked for when a required value is missing.
	 * @param int|null       $row       Row of a list rule.
	 */
	public function enter( string $ruleId, string $target, string $source, ?ErrorCode $errorCode, ?int $row ): void {
		$this->ruleId    = $ruleId;
		$this->target    = $target;
		$this->source    = $source;
		$this->errorCode = $errorCode;
		$this->row       = $row;
	}

	/**
	 * Rule id.
	 */
	public function ruleId(): string {
		return $this->ruleId;
	}

	/**
	 * Target path of the running rule.
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * First source path of the running rule.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Error code configured on the running rule.
	 */
	public function errorCode(): ?ErrorCode {
		return $this->errorCode;
	}

	/**
	 * Row of the running list rule, or null.
	 */
	public function row(): ?int {
		return $this->row;
	}

	/**
	 * Read another canonical value. Inside a list rule a path such as "lines[].tax_class" returns the entry of the current row.
	 *
	 * @param string $path Canonical path.
	 * @return mixed
	 */
	public function read( string $path ): mixed {
		if ( null !== $this->row && CanonicalOrder::fansOut( $path ) ) {
			return $this->order->getRow( $path, $this->row );
		}
		return $this->order->get( $path );
	}

	/**
	 * Add a warning for the running rule.
	 *
	 * @param string $message Plain-language message.
	 */
	public function warn( string $message ): void {
		$this->diagnostics->warn( $this->ruleId, $this->target, $message );
	}

	/**
	 * Values every failure message can use: the system's name and the order number.
	 *
	 * @return array<string,string>
	 */
	public function messageContext(): array {
		$number = $this->order->get( 'order.number' );
		return array(
			'system' => '' === $this->environment->system ? __( 'The system', 'estasoft-erp-crm-sync-engine' ) : $this->environment->system,
			'order'  => is_scalar( $number ) ? (string) $number : '',
		);
	}
}
