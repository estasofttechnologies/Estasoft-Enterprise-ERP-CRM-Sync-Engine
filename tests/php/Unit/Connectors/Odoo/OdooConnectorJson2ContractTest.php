<?php
/**
 * The connector contract, checked against Odoo 19 over the JSON-2 API.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

final class OdooConnectorJson2ContractTest extends OdooConnectorContractTest {

	protected function major(): int {
		return 19;
	}
}
