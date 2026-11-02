<?php
/**
 * The connect form of each connector, as the catalog sends it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

require_once dirname( __DIR__, 2 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Jobs\Connections\CatalogForms;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceExportConnector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Jobs\Connections\CatalogForms
 * @covers \Estasoft\SyncEngine\Jobs\Connections\ConnectionPresenter
 */
final class CatalogFormsTest extends TestCase {

	private ApiTestKit $kit;

	/**
	 * How many connectors were built.
	 */
	private int $built = 0;

	protected function setUp(): void {
		$this->kit   = ApiTestKit::make();
		$this->built = 0;
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function registry(): ConnectorRegistry {
		$kit = $this->kit;
		return new ConnectorRegistry(
			array(
				'reference-books'  => function () use ( $kit ): GuardedConnector {
					++$this->built;
					return GuardedConnector::wrap( new ReferenceBooksConnector( $kit->deps ), $kit->tokens );
				},
				'reference-crm'    => function () use ( $kit ): GuardedConnector {
					++$this->built;
					return GuardedConnector::wrap( new ReferenceCrmConnector( $kit->deps ), $kit->tokens );
				},
				'reference-export' => function () use ( $kit ): GuardedConnector {
					++$this->built;
					return GuardedConnector::wrap( new ReferenceExportConnector( $kit->deps ), $kit->tokens );
				},
				'broken'           => static function (): never {
					throw new \RuntimeException( 'cannot be built' );
				},
				'odd-fields'       => function () use ( $kit ): GuardedConnector {
					++$this->built;
					return GuardedConnector::wrap(
						new class( $kit->deps ) extends ReferenceBooksConnector {
							protected function meta(): ConnectorMeta {
								return new ConnectorMeta( id: 'odd-fields', label: 'Odd fields', type: ConnectorType::Accounting, capabilities: array( Capability::Orders ), entities: array( EntityType::Order ), auth: AuthStyle::Key );
							}

							protected function credentialDefinition(): array {
								return array(
									new CredentialField( 'token', 'Token', true, true, 'Paste it.', 'secret-default' ),
									new CredentialField( 'pass', 'Password', false, true, '', 'password-default', 'password' ),
									new CredentialField( 'region', 'Region', false, false, '', 'eu', 'select', array( 'eu' => 'Europe', 'us' => 'United States' ), 'advanced' ),
								);
							}
						},
						$kit->tokens
					);
				},
			)
		);
	}

	public function test_a_key_connector_has_its_fields_no_sign_in_and_its_style(): void {
		$forms = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'reference-books' ) );
		$this->assertSame( array( 'reference-books' ), array_keys( $forms ) );
		$this->assertSame( 'key', $forms['reference-books']['auth_style'] );
		$this->assertFalse( $forms['reference-books']['can_sign_in'] );
		$field = $forms['reference-books']['credential_fields'][0];
		$this->assertSame( array( 'name', 'label', 'secret', 'required', 'hint', 'default', 'type', 'options', 'group' ), array_keys( $field ) );
		$this->assertTrue( $field['secret'] );
	}

	public function test_a_sign_in_connector_says_so_and_shows_the_redirect_address_as_a_display_field(): void {
		$forms = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'reference-crm' ) );
		$this->assertSame( 'oauth_authorization_code', $forms['reference-crm']['auth_style'] );
		$this->assertTrue( $forms['reference-crm']['can_sign_in'] );
		$types = array_column( $forms['reference-crm']['credential_fields'], 'type', 'name' );
		$this->assertContains( 'display', $types );
	}

	public function test_an_export_connector_has_the_export_style(): void {
		$forms = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'reference-export' ) );
		$this->assertSame( 'export_file', $forms['reference-export']['auth_style'] );
		$this->assertFalse( $forms['reference-export']['can_sign_in'] );
	}

	public function test_a_secret_field_and_a_password_field_never_carry_a_default(): void {
		$fields = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'odd-fields' ) )['odd-fields']['credential_fields'];
		$by     = array_column( $fields, null, 'name' );
		$this->assertSame( '', $by['token']['default'] );
		$this->assertSame( '', $by['pass']['default'] );
		$this->assertSame( 'eu', $by['region']['default'], 'An ordinary default stays.' );
		$this->assertSame( array( 'eu' => 'Europe', 'us' => 'United States' ), $by['region']['options'] );
		$this->assertSame( 'advanced', $by['region']['group'] );
		$this->assertStringNotContainsString( 'secret-default', (string) json_encode( $fields ) );
		$this->assertStringNotContainsString( 'password-default', (string) json_encode( $fields ) );
	}

	public function test_a_secret_that_says_text_is_sent_as_a_password_field(): void {
		$fields = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'odd-fields' ) )['odd-fields']['credential_fields'];
		$by     = array_column( $fields, 'type', 'name' );
		$this->assertSame( 'password', $by['token'], 'A secret declared as text would be shown in clear while it is typed.' );
		$this->assertSame( 'password', $by['pass'] );
		$this->assertSame( 'select', $by['region'], 'Other types stay as they are.' );
	}

	public function test_only_the_connectors_asked_for_are_built_and_a_broken_or_unknown_one_is_left_out(): void {
		$forms = ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array( 'reference-books', 'broken', 'nobody', '' ) );
		$this->assertSame( array( 'reference-books' ), array_keys( $forms ) );
		$this->assertSame( 1, $this->built, 'The other connectors were never constructed.' );
		$this->assertSame( array(), ( new CatalogForms( fn() => $this->registry() ) )->forConnectors( array() ) );
	}

	public function test_the_answer_is_kept_and_a_second_request_builds_nothing(): void {
		$kept  = null;
		$saves = 0;
		$make  = function () use ( &$kept, &$saves ): CatalogForms {
			return new CatalogForms(
				fn() => $this->registry(),
				static function () use ( &$kept ): ?array {
					return $kept;
				},
				static function ( array $map ) use ( &$kept, &$saves ): void {
					$kept = $map;
					++$saves;
				}
			);
		};
		$first = $make()->forConnectors( array( 'reference-books', 'reference-crm' ) );
		$this->assertSame( 2, $this->built );
		$this->assertSame( 1, $saves );

		$second = $make()->forConnectors( array( 'reference-books', 'reference-crm' ) );
		$this->assertSame( $first, $second );
		$this->assertSame( 2, $this->built, 'Nothing was built the second time.' );
		$this->assertSame( 1, $saves, 'And nothing was saved.' );

		$make()->forConnectors( array( 'reference-books', 'reference-export' ) );
		$this->assertSame( 3, $this->built, 'Only the new connector was built.' );
		$this->assertSame( 2, $saves );
		$this->assertSame( array( 'reference-books', 'reference-crm', 'reference-export' ), array_keys( $kept ) );
	}

	public function test_the_style_comes_from_the_connection_method_when_the_connector_names_none(): void {
		$this->assertSame( 'key', CatalogForms::styleFromMethod( 'key' ) );
		$this->assertSame( 'oauth_authorization_code', CatalogForms::styleFromMethod( 'own_app' ) );
		$this->assertSame( 'oauth_client_credentials', CatalogForms::styleFromMethod( 'cloud_app' ) );
		$this->assertSame( 'server_login', CatalogForms::styleFromMethod( 'server_login' ) );
		$this->assertSame( 'export_file', CatalogForms::styleFromMethod( 'export_file' ) );
		$this->assertSame( 'none', CatalogForms::styleFromMethod( 'unknown' ) );
		$this->assertSame( 'none', CatalogForms::styleFromMethod( '' ) );
	}
}
