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
			'no plus uae'       => array( '971501234567' ),
			'no plus saudi'     => array( '966551234567' ),
			'us brackets'       => array( '(212) 555-0199' ),
			'us dashes'         => array( '212-555-0199' ),
			'us dots'           => array( '212.555.0199' ),
			'spaced groups'     => array( '971 50 123 4567' ),
			'ten digits'        => array( '2125550199' ),
			'nine digits'       => array( '501234567' ),
		);
	}

	/**
	 * @dataProvider notPhones
	 */
	public function test_money_ids_dates_and_codes_are_not_taken_for_phone_numbers( string $text ): void {
		$this->assertSame( $text, $this->redactor->message( $text ), $text );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function notPhones(): array {
		return array(
			'plain amount'          => array( 'Total 1234567.89 charged' ),
			'big amount'            => array( 'Total 1234567890.12 charged' ),
			'grouped amount'        => array( 'Total 1,234,567,890.00 charged' ),
			'european amount'       => array( 'Total 1.234.567.890,50 charged' ),
			'spaced amount'         => array( 'Amount: 12 345 678.90' ),
			'currency before'       => array( 'AED 1234567890 due' ),
			'currency symbol'       => array( 'Price €1234567890 incl. tax' ),
			'currency after'        => array( '1234567890 AED due' ),
			'order id'              => array( 'Order 100234567 shipped' ),
			'order hash'            => array( 'Order #100234567 shipped' ),
			'order id field'        => array( '{"order_id":"100234567"}' ),
			'invoice number'        => array( 'Invoice number: 202600123456' ),
			'sku'                   => array( 'SKU 123456789012 not found' ),
			'tracking'              => array( 'Tracking no. 1234567890123' ),
			'json number'           => array( '{"created":1730563200}' ),
			'id list'               => array( '{"ids":["123456789012"]}' ),
			'iso date and time'     => array( 'Started 2026-11-02 14:30:00 UTC' ),
			'iso date long'         => array( 'On 2026-11-02 1234567' ),
			'slash date'            => array( 'On 12/11/2026 at noon' ),
			'ip address'            => array( 'From 192.168.100.200 blocked' ),
			'short order id'        => array( 'Order 10490 and order 1234567 were sent' ),
			'italian style code'    => array( 'Reference IT00123456 matched' ),
			'tax amount in json'    => array( '{"tax":123456,"total":99900}' ),
			'six digit value'       => array( 'Waited 123456 ms' ),
		);
	}

	public function test_a_number_inside_a_longer_word_is_not_touched(): void {
		$this->assertSame( 'Ref AB971501234567 ok', $this->redactor->message( 'Ref AB971501234567 ok' ) );
		$this->assertSame( 'Ref 971501234567X ok', $this->redactor->message( 'Ref 971501234567X ok' ) );
	}

	public function test_a_phone_in_a_phone_field_is_removed_whatever_its_shape(): void {
		$out = $this->redactor->structure( array( 'phone' => 1234, 'billing' => array( 'phone_number' => '12' ), 'mobile_number' => '99' ) );
		$this->assertSame( Redactor::PERSONAL, $out['phone'] );
		$this->assertSame( Redactor::PERSONAL, $out['billing'] );
		$this->assertSame( Redactor::PERSONAL, $out['mobile_number'] );
	}

	public function test_numeric_looking_ids_under_id_like_keys_survive_in_a_structure(): void {
		$out = $this->redactor->structure( array( 'order_id' => '100234567', 'sku' => '123456789012', 'ids' => array( '123456789012' ), 'notes' => 'call 971501234567' ) );
		$this->assertSame( '100234567', $out['order_id'] );
		$this->assertSame( '123456789012', $out['sku'] );
		$this->assertSame( array( '123456789012' ), $out['ids'] );
		$this->assertSame( 'call [phone]', $out['notes'], 'Free text is still searched for phone numbers.' );
	}

	public function test_the_new_phone_rule_follows_the_personal_data_setting(): void {
		$this->assertSame( 'Call 971501234567 now', $this->redactor->message( 'Call 971501234567 now', false ) );
		$this->assertSame( 'Call [phone] now', $this->redactor->message( 'Call 971501234567 now', true ) );
	}

	/**
	 * @dataProvider personalKeys
	 */
	public function test_more_personal_field_names_are_removed( string $key ): void {
		$out = $this->redactor->structure( array( $key => 'Some Value' ) );
		$this->assertSame( Redactor::PERSONAL, $out[ $key ], $key );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function personalKeys(): array {
		$keys = array( 'name', 'nickname', 'username', 'recipient', 'receiver', 'sender', 'ship_to', 'bill_to', 'date_of_birth', 'dob', 'ip_address', 'iban', 'account_number', 'national_id', 'passport', 'whatsapp', 'cardholder', 'phone_number' );
		return array_combine( $keys, array_map( static fn( string $key ): array => array( $key ), $keys ) );
	}

	public function test_card_data_names_are_secrets_and_stay_removed_with_personal_redaction_off(): void {
		$out = $this->redactor->structure( array( 'card_number' => '4111111111111111', 'cvv' => '123' ), false );
		$this->assertSame( Redactor::SECRET, $out['card_number'] );
		$this->assertSame( Redactor::SECRET, $out['cvv'] );
	}

	public function test_a_sensitive_path_hides_a_field_that_has_an_ordinary_name(): void {
		$payload = array(
			'acct'  => array( 'tier' => 'gold' ),
			'other' => array( 'tier' => 'visible' ),
			'deep'  => array( 'wrap' => array( 'acct' => array( 'tier' => 'gold-too' ) ) ),
		);
		$out = $this->redactor->structure( $payload, true, array( 'acct.tier' ) );
		$this->assertSame( Redactor::PERSONAL, $out['acct']['tier'] );
		$this->assertSame( 'visible', $out['other']['tier'], 'The same last name under another parent is not touched.' );
		$this->assertSame( Redactor::PERSONAL, $out['deep']['wrap']['acct']['tier'], 'The path may sit anywhere below the top.' );

		$out = $this->redactor->structure( $payload, true, array( 'tier' ) );
		$this->assertSame( Redactor::PERSONAL, $out['other']['tier'], 'A path of one part is a field name.' );

		$out = $this->redactor->structure( $payload, false, array( 'acct.tier' ) );
		$this->assertSame( 'gold', $out['acct']['tier'], 'With personal redaction off, paths do nothing.' );
	}

	public function test_an_excerpt_uses_the_sensitive_paths(): void {
		$text = $this->redactor->excerpt( array( 'acct' => array( 'tier' => 'gold' ), 'sku' => 'A1' ), 500, true, array( 'acct.tier' ) );
		$this->assertStringNotContainsString( 'gold', (string) $text );
		$this->assertStringContainsString( 'A1', (string) $text );
	}

	/**
	 * @dataProvider urlCredentials
	 */
	public function test_user_and_password_in_an_address_are_removed_even_with_personal_redaction_off( string $text, string $secret ): void {
		foreach ( array( true, false ) as $personal ) {
			$out = $this->redactor->message( $text, $personal );
			$this->assertStringNotContainsString( $secret, $out );
			$this->assertStringContainsString( '[secret]@', $out );
			$this->assertStringContainsString( 'example.com', $out );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function urlCredentials(): array {
		return array(
			'https'            => array( 'GET https://bob:hunter2@example.com/api failed', 'hunter2' ),
			'with port'        => array( 'Could not reach https://admin:p%40ss@example.com:8069/jsonrpc', 'p%40ss' ),
			'ftp'              => array( 'ftp://user:secret-word@example.com/file.csv', 'secret-word' ),
			'user is an email' => array( 'https://jane.doe:pw123@example.com/x', 'pw123' ),
		);
	}

	public function test_an_address_without_credentials_is_left_alone(): void {
		$text = 'See https://example.com:8069/path?a=1 and mail me at someone@example.com';
		$this->assertSame( 'See https://example.com:8069/path?a=1 and mail me at [email]', $this->redactor->message( $text ) );
		$this->assertSame( 'See https://example.com:8069/path?a=1 and mail me at someone@example.com', $this->redactor->message( $text, false ) );
	}

	public function test_eight_character_codes_that_look_like_vat_are_not_masked_but_real_numbers_are(): void {
		$this->assertSame( 'IT00123456', $this->redactor->message( 'IT00123456' ) );
		$this->assertStringContainsString( '[tax number]', $this->redactor->message( 'VAT IT12345678901 on file' ) );
		$this->assertStringContainsString( '[tax number]', $this->redactor->message( 'Customer DK12345678 unknown' ) );
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
