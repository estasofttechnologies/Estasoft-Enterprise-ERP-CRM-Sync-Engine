<?php
/**
 * Removing secrets from messages.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\Redaction;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase {

	public function test_known_values_and_their_url_encoded_form_are_removed(): void {
		$redaction = new Redaction( array( 'sk-live-4f9a/secret+value', 'tenant-123456' ) );
		$text      = $redaction->scrub( 'Bad key sk-live-4f9a/secret+value (sk-live-4f9a%2Fsecret%2Bvalue) for tenant-123456' );
		$this->assertStringNotContainsString( 'sk-live', $text );
		$this->assertStringNotContainsString( 'tenant-123456', $text );
		$this->assertStringContainsString( Redaction::MASK, $text );
	}

	public function test_token_shapes_are_removed_even_when_the_value_is_unknown(): void {
		$redaction = new Redaction();
		$text      = $redaction->scrub( 'Authorization: Bearer abcdef.ghijkl-mnop_qrst  {"access_token":"AAA-BBB-CCC","client_secret": "zzzz9999"} password=hunter2hunter2&x=1' );
		foreach ( array( 'abcdef.ghijkl', 'AAA-BBB-CCC', 'zzzz9999', 'hunter2' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
		$this->assertStringContainsString( 'x=1', $text, 'Other text is left alone.' );
	}

	public function test_a_multi_line_private_key_is_removed_line_by_line(): void {
		$key       = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\nKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj\n-----END PRIVATE KEY-----";
		$redaction = new Redaction( array( $key ) );
		$text      = $redaction->scrub( 'bad key: MIIEvQIBADANBgkqhkiG9w0BAQEFAASC and KcwggSjAgEAAoIBAQC7VJTUt9Us8cKj' );
		$this->assertStringNotContainsString( 'MIIEvQ', $text );
		$this->assertStringNotContainsString( 'KcwggSj', $text );
	}

	public function test_short_values_are_ignored_so_ordinary_text_is_not_damaged(): void {
		$text = ( new Redaction( array( 'ab', 'x' ) ) )->scrub( 'a table about xylophones' );
		$this->assertSame( 'a table about xylophones', $text );
	}

	public function test_reason_is_one_clean_short_line(): void {
		$redaction = new Redaction( array( 'SECRETVALUE' ) );
		$reason    = $redaction->reason( "<b>Field</b> \"name\" is\n\ttoo   long. SECRETVALUE " . str_repeat( 'x', 400 ), 60 );
		$this->assertLessThanOrEqual( 60, mb_strlen( $reason ) );
		$this->assertStringEndsWith( '…', $reason );
		$this->assertStringNotContainsString( "\n", $reason );
		$this->assertStringNotContainsString( '<b>', $reason );
		$this->assertStringNotContainsString( 'SECRETVALUE', $reason );
	}

	public function test_arabic_text_survives(): void {
		$this->assertSame( 'الحقل غير صالح', ( new Redaction() )->reason( 'الحقل غير صالح' ) );
	}

	public function test_for_connection_takes_the_secret_fields_and_the_reserved_names(): void {
		$connection = new Connection( 3, 'x', ConnectorType::Crm, 'X', ConnectionStatus::Active, array(), array(), array( 'api_key' => 'KEY-1234567', 'oauth_access_token' => 'TOKEN-7654321', 'custom_secret' => 'CUSTOM-0000' ) );
		$redaction  = Redaction::forConnection( $connection, array( 'custom_secret' ), array( 'shop.example.test' ) )->addHost( 'https://acme.example.test/api' );
		$text       = $redaction->scrub( 'KEY-1234567 TOKEN-7654321 CUSTOM-0000 shop.example.test acme.example.test' );
		$this->assertSame( 5, substr_count( $text, Redaction::MASK ) );
	}
}
