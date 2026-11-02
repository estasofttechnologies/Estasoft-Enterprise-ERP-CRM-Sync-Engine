<?php
/**
 * The WooCommerce object to map no longer exists.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Raised by a CanonicalSource when an order, refund or customer was deleted between queueing and sending.
 */
final class SourceNotFoundException extends \RuntimeException {
}
