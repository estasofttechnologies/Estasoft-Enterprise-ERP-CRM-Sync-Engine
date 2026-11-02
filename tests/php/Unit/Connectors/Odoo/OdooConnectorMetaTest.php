<?php
/**
 * What the connector declares: identity, capabilities, fields and the version 1 rules.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class OdooConnectorMetaTest extends OdooTestCase {

	private function odoo(): ConnectorInterface {
		return $this->connector( new FakeHttpClient() );
	}

	public function test_identity(): void {
		$odoo = $this->odoo();
		$this->assertSame( 'odoo', $odoo->id() );
		$this->assertSame( 'Odoo', $odoo->label() );
		$this->assertSame( ConnectorType::Erp, $odoo->type() );
		$this->assertSame( ConnectorInterface::CONTRACT_VERSION, $odoo->contractVersion() );
		$this->assertTrue( ConnectorRegistry::sameMajor( $odoo->contractVersion(), ConnectorInterface::CONTRACT_VERSION ) );
	}

	public function test_the_registry_accepts_it(): void {
		$registry = new ConnectorRegistry( array( $this->odoo() ) );
		$this->assertNotNull( $registry->get( 'odoo' ) );
		$this->assertSame( array(), $registry->rejected() );
	}

	public function test_capabilities_name_every_flag_and_only_claim_what_is_built(): void {
		$flags = $this->odoo()->capabilities();
		$this->assertEqualsCanonicalizing( array_map( static fn( Capability $c ): string => $c->value, Capability::cases() ), array_keys( $flags ) );
		foreach ( array( 'orders', 'customers', 'refunds', 'stock_in', 'tracking_in', 'invoice_readback', 'reconciliation', 'shadow_lookup' ) as $flag ) {
			$this->assertTrue( $flags[ $flag ], $flag );
		}
		$this->assertFalse( $flags['prices_in'], 'Prices from Odoo are not built.' );
		$this->assertFalse( $flags['webhooks'], 'Odoo has no generic outbound webhook; polling is used.' );
	}

	public function test_supported_entities_match_the_send_capabilities(): void {
		$this->assertSame( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->odoo()->supportedEntities() );
	}

	public function test_rate_hints_follow_the_acceptable_use_policy(): void {
		$this->assertSame( array( 'requests_per_second' => 1.0, 'concurrency' => 1 ), $this->odoo()->rateLimitHints() );
	}

	public function test_credential_fields(): void {
		$fields = $this->odoo()->credentialFields();
		$byName = array();
		foreach ( $fields as $field ) {
			$this->assertInstanceOf( CredentialField::class, $field );
			$byName[ $field->name ] = $field;
		}
		$this->assertSame( array( 'address', 'database', 'user', 'api_key', 'company' ), array_keys( $byName ) );
		$this->assertTrue( $byName['api_key']->secret );
		$this->assertSame( 'password', $byName['api_key']->type, 'The key is drawn as a password box, so the browser does not show it while it is typed.' );
		$this->assertTrue( $byName['api_key']->required );
		foreach ( array( 'address', 'database', 'user', 'company' ) as $plain ) {
			$this->assertFalse( $byName[ $plain ]->secret, $plain );
		}
		$this->assertTrue( $byName['address']->required );
		$this->assertTrue( $byName['database']->required );
		$this->assertFalse( $byName['user']->required, 'Odoo 19 and later use the key alone.' );
		$this->assertFalse( $byName['company']->required );
		$this->assertStringContainsString( 'Odoo 19', $byName['user']->hint );
		$this->assertStringContainsString( 'never shown again', $byName['api_key']->hint );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_types(): array {
		$types = array();
		foreach ( array( 'orders', 'customers', 'products', 'refunds', 'stock', 'tracking' ) as $type ) {
			$types[ $type ] = array( $type );
		}
		return $types;
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_every_mapping_type_has_fields_in_the_shape_the_screens_read( string $type ): void {
		$fields = $this->odoo()->describeFields( $type );
		$this->assertNotEmpty( $fields );
		$paths = array();
		foreach ( $fields as $field ) {
			$this->assertSame( array( 'path', 'label', 'type', 'required', 'group' ), array_keys( $field ) );
			$this->assertNotSame( '', $field['path'] );
			$this->assertNotSame( '', $field['label'] );
			$this->assertIsBool( $field['required'] );
			$this->assertNotContains( $field['path'], $paths, 'Paths are unique.' );
			$paths[] = $field['path'];
		}
	}

	public function test_an_unknown_mapping_type_has_no_fields_and_no_rules(): void {
		$this->assertSame( array(), $this->odoo()->describeFields( 'nothing' ) );
		$this->assertSame( array(), $this->odoo()->defaultMapping( 'nothing' ) );
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_every_type_has_version_one_rules_in_the_rule_model( string $type ): void {
		$rules = $this->odoo()->defaultMapping( $type );
		$this->assertNotEmpty( $rules );
		$ids = array();
		foreach ( $rules as $rule ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]{1,64}$/', $rule['id'] );
			$this->assertNotContains( $rule['id'], $ids, 'Rule ids are unique.' );
			$ids[] = $rule['id'];
			$this->assertIsString( $rule['target'] );
			$this->assertTrue( is_string( $rule['source'] ) || is_array( $rule['source'] ) );
			$this->assertIsArray( $rule['transforms'] );
			$this->assertIsBool( $rule['required'] );
			foreach ( $rule['transforms'] as $transform ) {
				$this->assertArrayHasKey( 'type', $transform );
			}
		}
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_the_version_one_rules_pass_the_mapping_engine_s_own_validator( string $type ): void {
		$validator = new RuleValidator( TransformRegistry::standard() );
		$inbound   = in_array( $type, array( 'stock', 'tracking' ), true );
		$errors    = $validator->validate( $this->odoo()->defaultMapping( $type ), $inbound ? array() : $this->odoo()->describeFields( $type ), ! $inbound );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_the_order_rules_fill_every_required_field_and_name_the_codes_for_problems(): void {
		$rules = $this->odoo()->defaultMapping( 'orders' );
		$byTarget = array();
		foreach ( $rules as $rule ) {
			$byTarget[ $rule['target'] ] = $rule;
		}
		foreach ( $this->odoo()->describeFields( 'orders' ) as $field ) {
			if ( $field['required'] ) {
				$this->assertArrayHasKey( $field['path'], $byTarget, $field['path'] );
			}
		}
		$this->assertSame( 'order.client_ref', $byTarget['client_order_ref']['source'] );
		$this->assertSame( 'SYNC-MAP-003', $byTarget['partner.email']['error_code'] );
		$this->assertSame( 'SYNC-MAP-001', $byTarget['order_line[].product']['error_code'] );
		$this->assertSame( array( array( 'type' => 'sku_match', 'args' => array( 'output' => 'ref' ) ) ), $byTarget['order_line[].product']['transforms'] );
		$this->assertSame( 'lines[].unit_price', $byTarget['order_line[].price_unit']['source'] );
		$this->assertSame( 'tax_lookup', $byTarget['order_line[].tax']['transforms'][0]['type'] );
		$this->assertTrue( $byTarget['partner.email']['required'] );
	}

	public function test_the_default_rules_do_not_decide_the_document_flow(): void {
		$targets = array_column( $this->odoo()->defaultMapping( 'orders' ), 'target' );
		foreach ( array( 'flow.confirm', 'flow.invoice', 'flow.register_payment', 'payment_term' ) as $target ) {
			$this->assertNotContains( $target, $targets, $target . ' is a choice for the merchant.' );
		}
		$fields = array_column( $this->odoo()->describeFields( 'orders' ), 'path' );
		foreach ( array( 'flow.confirm', 'flow.invoice', 'flow.register_payment', 'payment_term' ) as $target ) {
			$this->assertContains( $target, $fields, $target . ' can be added.' );
		}
	}

	public function test_the_order_fields_include_the_board_s_odoo_paths(): void {
		$paths = array_column( $this->odoo()->describeFields( 'orders' ), 'path' );
		foreach ( array( 'client_order_ref', 'partner.email', 'partner.name', 'partner.phone', 'partner.state_id', 'partner.vat', 'order_line[].product', 'order_line[].price_unit', 'order_line[].tax', 'payment_term', 'note' ) as $path ) {
			$this->assertContains( $path, $paths );
		}
	}

	public function test_classify_error_makes_conflicts_retry_and_leaves_the_rest_to_the_catalog(): void {
		$odoo = $this->odoo();
		$this->assertSame( ErrorClass::Retry, $odoo->classifyError( new ConnectorError( ErrorCode::Val002, array(), 422, null, null, 'odoo.exceptions.LockError: could not serialize access' ) ) );
		$this->assertSame( ErrorClass::Retry, $odoo->classifyError( new ConnectorError( ErrorCode::Val002, array(), 409 ) ) );
		$this->assertSame( ErrorClass::Retry, $odoo->classifyError( new ConnectorError( ErrorCode::Val002, array(), null, null, ErrorClass::Retry ) ) );
		$this->assertNull( $odoo->classifyError( new ConnectorError( ErrorCode::Auth004, array(), 403 ) ) );
		$this->assertNull( $odoo->classifyError( new ConnectorError( ErrorCode::Map001, array( 'sku' => 'X' ) ) ) );
		$this->assertNull( $odoo->classifyError( new ConnectorError( ErrorCode::Net002, array(), 500 ) ), 'Server errors already retry by catalog.' );
	}

	public function test_a_customer_is_created_then_updated_by_email(): void {
		$server = $this->server( 18 );
		$odoo   = $this->connector( $server );
		$conn   = $this->connection( $server );
		$item   = $this->item( EntityType::Customer, '77', 'created' );
		$first  = $odoo->send( $conn, $item, array( 'partner' => array( 'name' => 'نور المنصوري', 'email' => 'noor@example.com', 'phone' => '+971500000001', 'country_id' => 'AE' ) ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertSame( 'نور المنصوري', $first->ref->ref );

		$second = $odoo->send( $conn, $item, array( 'partner' => array( 'name' => 'نور المنصوري', 'email' => 'NOOR@example.com', 'phone' => '+971500000002', 'country_id' => 'AE' ) ) );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $server->db->all( 'res.partner' ) );
		$this->assertSame( '+971500000002', $server->db->all( 'res.partner' )[0]['phone'] );
	}

	public function test_a_customer_without_a_name_or_email_is_refused(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->send( $this->connection( $server ), $this->item( EntityType::Customer, '77' ), array( 'partner' => array( 'name' => 'No Email' ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_an_external_ref_for_a_customer_lookup_can_be_used_as_a_preview_line(): void {
		$server = $this->server( 18 );
		$server->db->addPartner( 'A', 'a@example.com' );
		$ref = $this->connector( $server )->lookupCustomer( $this->connection( $server ), 'a@example.com' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
	}
}
