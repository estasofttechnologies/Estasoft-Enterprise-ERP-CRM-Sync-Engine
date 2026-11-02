<?php
/**
 * Meta, sign-in styles, rate hints and the field catalog builder.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Connectors\Support\Xml;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use PHPUnit\Framework\TestCase;

final class MetaAndCatalogTest extends TestCase {

	public function test_flags_are_complete_booleans_and_only_the_listed_capabilities_are_true(): void {
		$meta  = new ConnectorMeta( id: 'x', label: 'X', type: ConnectorType::Accounting, capabilities: array( Capability::Orders, Capability::Refunds ), entities: array( EntityType::Order ), auth: AuthStyle::Key );
		$flags = $meta->flags();
		$this->assertCount( count( Capability::cases() ), $flags );
		$this->assertSame( array( 'orders', 'refunds' ), array_keys( array_filter( $flags ) ) );
		$this->assertTrue( $meta->has( Capability::Orders ) );
		$this->assertFalse( $meta->has( Capability::StockIn ) );
		$this->assertSame( ConnectorInterface::CONTRACT_VERSION, $meta->contractVersion );
		$this->assertSame( 1.0, $meta->limits()->requestsPerSecond, 'One call a second by default.' );
	}

	public function test_auth_styles_name_the_catalog_connection_methods(): void {
		$this->assertSame( 'key', AuthStyle::Key->connectionMethod() );
		$this->assertSame( 'key', AuthStyle::Basic->connectionMethod() );
		$this->assertSame( 'own_app', AuthStyle::OAuthAuthorizationCode->connectionMethod() );
		$this->assertSame( 'cloud_app', AuthStyle::OAuthClientCredentials->connectionMethod() );
		$this->assertSame( 'cloud_app', AuthStyle::OAuthJwtBearer->connectionMethod() );
		$this->assertSame( 'server_login', AuthStyle::ServerLogin->connectionMethod() );
		$this->assertSame( 'export_file', AuthStyle::ExportFile->connectionMethod() );
		$this->assertSame( 'none', AuthStyle::None->connectionMethod() );
		$this->assertTrue( AuthStyle::OAuthJwtBearer->isOAuth() );
		$this->assertFalse( AuthStyle::Key->isOAuth() );
		$this->assertTrue( AuthStyle::OAuthAuthorizationCode->needsSignIn() );
		$this->assertFalse( AuthStyle::OAuthClientCredentials->needsSignIn() );
	}

	public function test_rate_hints_keep_the_tightest_documented_limit(): void {
		$hints = new RateLimitHints( 10.0, 2, 60, 5000 );
		$this->assertSame( 1.0, $hints->intervalSeconds(), '60 a minute is slower than 10 a second.' );
		$this->assertSame( array( 'requests_per_second' => 10.0, 'concurrency' => 2 ), $hints->toArray() );
		$this->assertEqualsWithDelta( 0.1, ( new RateLimitHints( 10.0 ) )->intervalSeconds(), 0.0001 );
	}

	public function test_the_builder_applies_the_current_group_and_types(): void {
		$fields = FieldCatalogBuilder::make()
			->group( 'Customer' )
			->text( 'contact.name', 'Name', required: true )
			->field( 'contact.email', 'Email', 'email' )
			->group( 'Lines' )
			->sku( 'line[].sku', 'SKU', true )
			->number( 'line[].qty', 'Quantity', true )
			->money( 'line[].price', 'Unit price' )
			->taxCode( 'line[].tax', 'Tax' )
			->boolean( 'flags.paid', 'Paid' )
			->datetime( 'when', 'When' )
			->date( 'due', 'Due' )
			->integer( 'n', 'N' )
			->longText( 'note', 'Note' )
			->choice( 'mode', 'Mode' )
			->build();
		$this->assertCount( 12, $fields );
		$this->assertSame( array( 'path' => 'contact.name', 'label' => 'Name', 'type' => 'string', 'required' => true, 'group' => 'Customer' ), $fields[0]->toArray() );
		$this->assertSame( 'Lines', $fields[2]->group );
		$this->assertSame( array( 'sku', 'number', 'money', 'tax_code', 'boolean', 'datetime', 'date', 'integer', 'text', 'choice' ), array_map( static fn( $f ) => $f->type, array_slice( $fields, 2 ) ) );
		foreach ( $fields as $field ) {
			$this->assertContains( $field->type, \Estasoft\SyncEngine\Connectors\Support\FieldDef::TYPES );
		}
	}

	public function test_default_credential_fields_follow_the_sign_in_style(): void {
		$names = static fn( AuthStyle $style ): array => array_map( static fn( CredentialField $f ): string => $f->name, CredentialFields::forStyle( $style ) );
		$this->assertSame( array( 'api_key' ), $names( AuthStyle::Key ) );
		$this->assertSame( array( 'username', 'password' ), $names( AuthStyle::Basic ) );
		$this->assertSame( array( 'client_id', 'client_secret' ), $names( AuthStyle::OAuthAuthorizationCode ) );
		$this->assertSame( array( 'client_id', 'private_key' ), $names( AuthStyle::OAuthJwtBearer ) );
		$this->assertSame( array(), $names( AuthStyle::ExportFile ) );
		$secret = CredentialFields::clientSecret();
		$this->assertTrue( $secret->secret );
		$this->assertSame( 'password', $secret->type );
		$this->assertSame( 'app', $secret->group );
		$this->assertSame( 'textarea', CredentialFields::privateKey()->type );
		$choice = CredentialFields::choice( 'data_center', 'Data centre', array( 'eu' => 'Europe', 'com' => 'United States' ) );
		$this->assertSame( 'select', $choice->type );
		$this->assertSame( 'eu', $choice->default );
		$redirect = CredentialFields::redirectUri();
		$this->assertSame( 'display', $redirect->type );
		$this->assertSame( 'https://shop.example.test/wp-json/estasoft-sync/v1/oauth/callback', $redirect->default );
	}

	public function test_credential_field_keeps_old_positional_calls_working(): void {
		$field = new CredentialField( 'address', 'Address', false, true, 'hint', 'https://' );
		$this->assertSame( 'text', $field->type );
		$this->assertSame( array(), $field->options );
		$this->assertSame( 'connection', $field->group );
	}

	public function test_xml_escapes_and_names_elements_safely(): void {
		$this->assertSame( '<a x="1 &amp; 2">t&lt;</a>', Xml::element( 'a', array( '@attributes' => array( 'x' => '1 & 2' ), '#text' => 't<' ) ) );
		$this->assertSame( '<_1x/>', Xml::element( '1x', null ) );
		$this->assertSame( '<n>نور</n>', Xml::element( 'n', 'نور' ) );
		$this->assertSame( "<n>ab</n>", Xml::element( 'n', "a\x01b" ), 'Control characters are not valid in XML.' );
		$this->assertSame( '<b>true</b>', Xml::encode( array( 'b' => true ), 'r', false ), 'A single key is the root element.' );
		$this->assertSame( '<r><b>true</b><c>x</c></r>', Xml::encode( array( 'b' => true, 'c' => 'x' ), 'r', false ) );
	}
}
