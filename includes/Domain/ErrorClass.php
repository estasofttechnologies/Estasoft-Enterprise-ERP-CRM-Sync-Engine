<?php
/**
 * What the plugin does with an error.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum ErrorClass: string {
	case Retry       = 'retry';
	case NeedsPerson = 'needs_person';
	case Handled     = 'handled';
	case Info        = 'info';
}
