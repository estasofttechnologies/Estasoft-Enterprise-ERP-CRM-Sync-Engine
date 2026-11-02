<?php
/**
 * A transform was given arguments it cannot use.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

defined( 'ABSPATH' ) || exit;

/**
 * The rule is set up wrongly. Publishing refuses such rules; at run time the engine turns this into a clear mapping failure.
 */
final class TransformArgumentException extends \InvalidArgumentException {
}
