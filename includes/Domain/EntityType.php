<?php
/**
 * What a queue item is about.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum EntityType: string {
	case Order    = 'order';
	case Refund   = 'refund';
	case Customer = 'customer';
	case Stock    = 'stock';
	case Tracking = 'tracking';
	case Invoice  = 'invoice';
}
