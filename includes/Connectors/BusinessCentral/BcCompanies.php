<?php
/**
 * Picks the company the connection works in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A Business Central environment holds one or more companies. The connection names one by id or by name; with an empty setting the only
 * company is used. Several companies and no choice is a refusal that lists the names, never a guess.
 */
final class BcCompanies {

	/**
	 * Most company names listed in a message.
	 */
	private const LISTED = 6;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string $system System name for messages.
	 */
	public function __construct( private readonly string $system ) {
	}

	/**
	 * The company a setting names.
	 *
	 * @param BcClient $bc      Client without a company.
	 * @param string   $setting What the merchant typed: a name, an id, or empty.
	 * @return array{id:string,name:string,version:string}
	 *
	 * @throws ApiException On failure or when no company can be chosen.
	 */
	public function resolve( BcClient $bc, string $setting ): array {
		$rows   = $bc->rows( 'companies' );
		$wanted = trim( $setting );
		$found  = array();
		foreach ( $rows as $row ) {
			$id = OData::guid( $row['id'] ?? null );
			if ( null === $id ) {
				continue;
			}
			$names = array_filter( array( (string) ( $row['name'] ?? '' ), (string) ( $row['displayName'] ?? '' ) ) );
			$match = '' === $wanted || OData::guid( $wanted ) === $id;
			foreach ( $names as $name ) {
				$match = $match || 0 === strcmp( mb_strtolower( $name ), mb_strtolower( $wanted ) );
			}
			if ( $match ) {
				$found[] = array(
					'id'      => $id,
					'name'    => '' !== (string) ( $row['displayName'] ?? '' ) ? (string) $row['displayName'] : (string) ( $row['name'] ?? '' ),
					'version' => (string) ( $row['systemVersion'] ?? '' ),
				);
			}
		}
		if ( 1 === count( $found ) ) {
			return $found[0];
		}
		if ( array() === $found ) {
			throw new ApiException( Failures::invalid( $this->system, 'company', __( 'There is no company with this name or ID in the environment.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'company not found' ) );
		}
		$names = array_map( static fn( array $company ): string => $company['name'], array_slice( $found, 0, self::LISTED ) );
		throw new ApiException(
			Failures::invalid(
				$this->system,
				'company',
				sprintf(
					/* translators: %s: company names separated by commas. */
					__( 'The environment has several companies. Type the name or ID of one of: %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					implode( ', ', $names )
				),
				'company ambiguous'
			)
		);
	}
}
