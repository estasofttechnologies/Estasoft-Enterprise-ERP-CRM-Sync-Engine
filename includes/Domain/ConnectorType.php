<?php
/**
 * The kind of system, which decides what can sync.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum ConnectorType: string {
	case Erp        = 'erp';
	case Accounting = 'accounting';
	case Crm        = 'crm';
}
