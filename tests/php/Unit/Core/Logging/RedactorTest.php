<?php
/**
 * Redaction of secrets and personal data.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Logging;

use Estasoft\SyncEngine\Core\Logging\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	private Redactor $redactor;

	protected function setUp(): void {
		$this->redactor = new Redactor();
	}

	public function test_emails_are_removed(): void {
		$text = $this->redactor->message( 'Order from noor.almansouri+shop@example.co.uk was placed' );
		$this->assertStringNotContainsString( 'noor', $text );
		$this->assertStringNotContainsString( '@', $text );
		$this->assertStringContainsString( 'Order from', $text );
	}

	/**
	 * @dataProvider phones
	 */
	public function test_phone_numbers_are_removed( string $phone ): void {
		$text = $this->redactor->message( 'Call ' . $phone . ' today' );
		$this->assertStringNotContainsString( $phone, $text );
		$this->assertStringContainsString( '[phone]', $text );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function phones(): array {
		return array(
			'uae international' => array( '+971 50 123 4567' ),
			'uae local'         => array( '0501234567' ),
			'with brackets'     => array( '+44 (0) 20 7946 0958' ),
			'double zero'       => array( '00966 55 123 4567' ),
			'uk local'          => array( '020 7946 0958' ),
		);
	}

	/**
	 * @dataProvider taxNumbers
	 */
	public function test_tax_numbers_are_removed( string $text, string $number ): void {
		$redacted = $this->redactor->message( $text );
		$this->assertStringNotContainsString( $number, $redacted );
		$this->assertStringContainsString( '[tax number]', $redacted );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function taxNumbers(): array {
		return array(
			'saudi 15 digits'      => array( 'Buyer 310122393500003 rejected', '310122393500003' ),
			'uae trn'              => array( 'TRN 100123456700003', '100123456700003' ),
			'labelled vat'         => array( 'VAT: GB 123 4567 89 invalid', '123 4567 89' ),
			'german vat'           => array( 'Customer DE123456789 unknown', 'DE123456789' ),
			'labelled tax number'  => array( 'tax number = 12-3456789 invalid', '12-3456789' ),
		);
	}

	public function test_address_like_text_is_removed(): void {
		$text = $this->redactor->message( 'Ship to 221B Baker Street London and Sheikh Zayed Road, PO Box 4412' );
		$this->assertStringNotContainsString( 'Baker', $text );
		$this->assertStringNotContainsString( 'Sheikh Zayed', $text );
		$this->assertStringNotContainsString( '4412', $text );
	}

	/**
	 * @dataProvider secrets
	 */
	public function test_secrets_and_tokens_are_removed( string $text, string $secret ): void {
		$redacted = $this->redactor->message( $text );
		$this->assertStringNotContainsString( $secret, $redacted );
		$this->assertStringContainsString( '[secret]', $redacted );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function secrets(): array {
		return array(
			'authorization header' => array( "POST /x\nAuthorization: Bearer abc123def456ghi789jkl012\nHost: a", 'abc123def456ghi789jkl012' ),
			'basic credentials'    => array( 'Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA' ),
			'bearer in text'       => array( 'sent with Bearer tok_0123456789abcdef', 'tok_0123456789abcdef' ),
			'jwt'                  => array( 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r', 'eyJhbGciOiJIUzI1NiJ9' ),
			'api key pair'         => array( 'GET /?api_key=9f2c4e8a1b7d&limit=5', '9f2c4e8a1b7d' ),
			'json password'        => array( '{"login":"admin","password":"hunter2!"}', 'hunter2!' ),
			'access token json'    => array( '{"access_token":"ya29.a0AfH6SMB"}', 'ya29.a0AfH6SMB' ),
			'client secret'        => array( 'client_secret=abcDEF123ghiJKL', 'abcDEF123ghiJKL' ),
			'stripe style key'     => array( 'key sk_live_ABCDEFGH12345678', 'sk_live_ABCDEFGH12345678' ),
			'long hex token'       => array( 'key 9f2c4e8a1b7d4c3e9a6f5b2d8c1e7f40a3b5c9d2 used', '9f2c4e8a1b7d4c3e9a6f5b2d8c1e7f40a3b5c9d2' ),
		);
	}

	public function test_ordinary_text_survives(): void {
		$text = 'Product CS-BOWL-04 was not found in Odoo. Order #10490 at 2026-11-02 11:22:19, qty 2, HTTP 200 in 840 ms, mapping version 7.';
		$this->assertSame( $text, $this->redactor->message( $text ) );
	}

	public function test_secrets_are_removed_even_when_personal_redaction_is_off(): void {
		$text = $this->redactor->message( 'noor@example.com sent Authorization: Bearer abc123def456ghi789jkl012 and password=hunter2', false );
		$this->assertStringContainsString( 'noor@example.com', $text );
		$this->assertStringNotContainsString( 'abc123def456ghi789jkl012', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
	}

	public function test_structures_are_redacted_by_field_name(): void {
		$redacted = $this->redactor->structure(
			array(
				'client_order_ref' => 'WC-10485',
				'partner'          => array(
					'name'  => 'Noor Al-Mansouri',
					'email' => 'noor@example.com',
				),
				'lines'            => array( array( 'default_code' => 'CS-BOWL-04', 'qty' => 2 ) ),
				'Authorization'    => 'Bearer abc',
				'note'             => 'leave with the guard',
				'billing'          => array( 'phone' => '+971501234567' ),
			)
		);
		$this->assertSame( 'WC-10485', $redacted['client_order_ref'] );
		$this->assertSame( Redactor::PERSONAL, $redacted['partner'] );
		$this->assertSame( Redactor::PERSONAL, $redacted['note'] );
		$this->assertSame( Redactor::PERSONAL, $redacted['billing'] );
		$this->assertSame( Redactor::SECRET, $redacted['Authorization'] );
		$this->assertSame( 'CS-BOWL-04', $redacted['lines'][0]['default_code'] );
		$this->assertSame( 2, $redacted['lines'][0]['qty'] );
	}

	public function test_field_names_come_from_mapping_source_paths(): void {
		$names = Redactor::namesFromSourcePaths( array( 'billing.email', 'shipping.address_1', 'customer.phone', 'lines[].sku', 'order.buyer_tax_number', 'order.total', 'order.note' ) );
		$this->assertEqualsCanonicalizing( array( 'email', 'address_1', 'phone', 'buyer_tax_number', 'note' ), $names );

		$redacted = $this->redactor->structure( array( 'x_contact_mail' => 'noor@example.com', 'sku' => 'CS-1', 'totalz' => 4 ), true, array( 'x_contact_mail' ) );
		$this->assertSame( Redactor::PERSONAL, $redacted['x_contact_mail'] );
		$this->assertSame( 'CS-1', $redacted['sku'] );
	}

	public function test_personal_fields_stay_when_redaction_is_off_but_secrets_do_not(): void {
		$redacted = $this->redactor->structure( array( 'email' => 'noor@example.com', 'password' => 'x' ), false );
		$this->assertSame( 'noor@example.com', $redacted['email'] );
		$this->assertSame( Redactor::SECRET, $redacted['password'] );
	}

	public function test_excerpt_is_cut_to_the_byte_size_without_splitting_a_character(): void {
		$long    = array( 'text' => str_repeat( 'é', 3000 ) );
		$excerpt = $this->redactor->excerpt( $long, 2048 );
		$this->assertNotNull( $excerpt );
		$this->assertLessThanOrEqual( 2048, strlen( $excerpt ) );
		$this->assertTrue( mb_check_encoding( $excerpt, 'UTF-8' ) );
		$this->assertStringEndsWith( "\u{2026}", $excerpt );
	}

	public function test_excerpt_that_fits_is_not_cut(): void {
		$this->assertSame( '{"a":"b"}', $this->redactor->excerpt( array( 'a' => 'b' ), 2048 ) );
	}

	public function test_excerpt_of_size_zero_keeps_nothing(): void {
		$this->assertNull( $this->redactor->excerpt( array( 'a' => 'b' ), 0 ) );
		$this->assertNull( $this->redactor->excerpt( null, 2048 ) );
	}

	public function test_a_payload_over_the_size_cap_is_replaced_by_a_note_without_being_processed(): void {
		$big     = array( 'lines' => array_fill( 0, 400, array( 'sku' => 'CS-BOWL-04', 'name' => 'Stoneware bowl, large' ) ) );
		$excerpt = (string) $this->redactor->excerpt( $big, 2048, true, array(), 1024 );
		$this->assertSame( '[The payload is larger than 1 KB and was not kept.]', $excerpt );
		$this->assertNotNull( $this->redactor->excerpt( array( 'a' => 'b' ), 2048, true, array(), 1024 ) );
		$this->assertSame( '[The payload is larger than 1 KB and was not kept.]', $this->redactor->excerpt( str_repeat( 'x', 5000 ), 2048, true, array(), 1024 ) );
		$this->assertNotSame( '[The payload is larger than 1 KB and was not kept.]', $this->redactor->excerpt( $big, 2048, true, array(), 0 ), 'a cap of zero means no cap' );
	}

	public function test_excerpt_of_a_json_string_is_redacted_by_field(): void {
		$excerpt = (string) $this->redactor->excerpt( '{"partner":{"email":"noor@example.com"},"ref":"WC-1"}', 2048 );
		$this->assertStringNotContainsString( 'noor', $excerpt );
		$this->assertStringContainsString( 'WC-1', $excerpt );
	}
}
