<?php
/**
 * Tenant ids and environment addresses typed by the merchant.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\EntraTenant;
use Estasoft\SyncEngine\Connectors\Microsoft\EnvironmentUrl;
use PHPUnit\Framework\TestCase;

final class AddressesTest extends TestCase {

	public function test_a_tenant_is_a_guid_or_a_domain_and_nothing_else(): void {
		$this->assertSame( '11111111-2222-3333-4444-555555555555', EntraTenant::normalise( ' 11111111-2222-3333-4444-555555555555 ' ) );
		$this->assertSame( 'contoso.onmicrosoft.com', EntraTenant::normalise( 'Contoso.OnMicrosoft.com' ) );
		foreach ( array( '', 'common', 'contoso', '../evil', 'a/b.com', 'evil.com/path', 'a b.com', "x.com\n/y", 'user@contoso.com', 'contoso.com:443', 'http://contoso.com' ) as $bad ) {
			$this->assertNull( EntraTenant::normalise( $bad ), $bad );
		}
	}

	public function test_the_token_address_is_built_from_a_checked_tenant(): void {
		$this->assertSame( 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token', EntraTenant::tokenUrl( 'contoso.onmicrosoft.com' ) );
	}

	public function test_an_environment_is_an_https_dynamics_address_reduced_to_its_host(): void {
		$expected = array(
			'https://contoso.crm4.dynamics.com'                              => 'https://contoso.crm4.dynamics.com',
			'contoso.crm4.dynamics.com'                                      => 'https://contoso.crm4.dynamics.com',
			' https://Contoso.CRM4.Dynamics.com/main.aspx?appid=1 '          => 'https://contoso.crm4.dynamics.com',
			'https://contoso.operations.dynamics.com/'                       => 'https://contoso.operations.dynamics.com',
			'https://contoso-uat.sandbox.operations.dynamics.com/namespaces' => 'https://contoso-uat.sandbox.operations.dynamics.com',
		);
		foreach ( $expected as $typed => $clean ) {
			$this->assertSame( $clean, EnvironmentUrl::normalise( $typed ), $typed );
		}
	}

	public function test_anything_else_is_refused(): void {
		$refused = array(
			'',
			'http://contoso.crm4.dynamics.com',
			'https://evil.example.com',
			'https://dynamics.com.evil.test',
			'https://contoso.crm4.dynamics.com.evil.test',
			'https://user:pw@contoso.crm4.dynamics.com',
			'https://contoso.crm4.dynamics.com:8443',
			'https://evildynamics.com',
			'https://-bad.dynamics.com',
			'ftp://contoso.crm4.dynamics.com',
			'https://127.0.0.1',
		);
		foreach ( $refused as $bad ) {
			$this->assertNull( EnvironmentUrl::normalise( $bad ), $bad );
		}
	}
}
