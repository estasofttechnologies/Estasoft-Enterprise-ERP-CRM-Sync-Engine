<?php
/**
 * The company file a connection uses: which one, on which host, in which currency.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * MYOB names a company file by a GUID (the "businessId" of the sign-in redirect). The list of files the signed-in person can open is read from
 * the host's /accountright address; it is read once per object.
 */
final class MyobCompany {

	/**
	 * The default host.
	 */
	public const HOST = 'api.myob.com';

	/**
	 * Hosts the person may choose.
	 *
	 * @var array<int,string>
	 */
	public const HOSTS = array( 'api.myob.com', 'arl2.api.myob.com' );

	/**
	 * Files of the signed-in person.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $files = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobApi $api    Client.
	 * @param string  $host   API host.
	 * @param string  $fileId Company file id.
	 * @param string  $currency Currency of the file.
	 */
	public function __construct( private readonly MyobApi $api, private readonly string $host, private readonly string $fileId, private readonly string $currency ) {
	}

	/**
	 * The company file id of a connection: the one stored after sign-in, else the one in the sign-in callback; empty when neither is a GUID.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fileOf( Connection $connection ): string {
		$id = $connection->config( 'company_file_id' );
		if ( ! is_scalar( $id ) || '' === trim( (string) $id ) ) {
			$callback = $connection->config( 'oauth_callback' );
			$id       = is_array( $callback ) ? ( $callback['businessId'] ?? '' ) : '';
		}
		$id = is_scalar( $id ) ? strtolower( trim( (string) $id ) ) : '';
		return self::isGuid( $id ) ? $id : '';
	}

	/**
	 * Whether a text is a GUID.
	 *
	 * @param string $text Text.
	 */
	public static function isGuid( string $text ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $text );
	}

	/**
	 * The API host of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function hostOf( Connection $connection ): string {
		$host = $connection->config( 'api_host' );
		return is_string( $host ) && in_array( $host, self::HOSTS, true ) ? $host : self::HOST;
	}

	/**
	 * The currency of the company file, as chosen on the connection (AUD by default).
	 *
	 * @param Connection $connection Connection.
	 */
	public static function currencyOf( Connection $connection ): string {
		$currency = strtoupper( MyobPayloadReader::text( $connection->config( 'currency' ) ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'AUD';
	}

	/**
	 * The currency of the company file.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * The company files the signed-in person can open.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function files(): array {
		if ( null === $this->files ) {
			$this->files = MyobApi::items( $this->api->getAbsolute( 'https://' . $this->host . '/accountright/' ) );
		}
		return $this->files;
	}

	/**
	 * The entry of this connection's company file in the list.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function file(): ?array {
		foreach ( $this->files() as $file ) {
			if ( 0 === strcasecmp( MyobPayloadReader::text( $file['Id'] ?? '' ), $this->fileId ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * The name of the company file ("" when MYOB does not list it).
	 *
	 * @throws ApiException On a failed call.
	 */
	public function name(): string {
		return MyobPayloadReader::text( $this->file()['Name'] ?? '' );
	}

	/**
	 * The country of the company file, when MYOB says.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function country(): string {
		return strtoupper( MyobPayloadReader::text( $this->file()['Country'] ?? '' ) );
	}
}
