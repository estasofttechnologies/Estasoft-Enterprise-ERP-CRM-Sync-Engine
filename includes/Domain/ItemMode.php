<?php
/**
 * Live items are sent; shadow items are built and stored only.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum ItemMode: string {
	case Live   = 'live';
	case Shadow = 'shadow';
}
