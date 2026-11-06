<?php
/**
 * Opportunities (deals) and their products.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Find the deal of an order by its reference, read and complete its lines, and carry the completion marker. The reference is the start of the
 * topic (`WC-123 - Buyer`), or a custom text column when the connection names one. The marker is the last line of the description,
 * `Estasoft Sync WC-123 lines=3 email=buyer@example.test`, written in the same call that creates the deal. The marker only says that the
 * deal was started. Once the products are on it and its total was checked, the marker gets the word `verified` (see seal()); the deal is
 * finished when the verified marker is there and at least that many products are on it. A deal whose total failed the check therefore
 * stays unfinished, is never linked by findExisting() and is checked again by the next send().
 */
final class DvOpportunities {

	/**
	 * Columns read of an opportunity.
	 */
	public const SELECT = 'opportunityid,name,description,estimatedvalue,totalamount,discountamount,_transactioncurrencyid_value,statecode,createdon';

	/**
	 * Longest topic (documented: 300 characters).
	 */
	public const NAME_MAX = 300;

	/**
	 * Longest description (documented: 2,000 characters).
	 */
	public const DESCRIPTION_MAX = 2000;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient  $dv      Client.
	 * @param DvOptions $options Options.
	 * @param string    $system  System name for messages.
	 */
	public function __construct( private readonly DvClient $dv, private readonly DvOptions $options, private readonly string $system ) {
	}

	/**
	 * The oldest opportunity with the reference.
	 *
	 * @param string $ref WC-123.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $ref ): ?array {
		$column = $this->options->referenceColumn();
		$prefix = $ref . ' - ';
		$rows   = $this->dv->rows(
			'opportunities',
			array(
				'$filter'  => '' !== $column ? OData::eq( $column, $ref ) : OData::startsWith( 'name', $prefix ),
				'$select'  => self::SELECT . ( '' !== $column ? ',' . $column : '' ),
				'$orderby' => 'createdon asc',
			)
		);
		foreach ( $rows as $row ) {
			$same = '' !== $column ? (string) ( $row[ $column ] ?? '' ) === $ref : 0 === strncasecmp( (string) ( $row['name'] ?? '' ), $prefix, strlen( $prefix ) );
			if ( $same ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * An opportunity by id.
	 *
	 * @param string $id GUID.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function header( string $id ): ?array {
		$guid = OData::guid( $id );
		return null === $guid ? null : $this->dv->one( 'opportunities(' . $guid . ')', array( '$select' => self::SELECT ) );
	}

	/**
	 * Create an opportunity.
	 *
	 * @param array<string,mixed> $body Columns and bindings.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function create( array $body ): array {
		return $this->dv->create( 'opportunities', $body, 'opportunityid', self::SELECT );
	}

	/**
	 * The products of an opportunity, by line number.
	 *
	 * @param string $id Opportunity GUID.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function lines( string $id ): array {
		$guid = OData::guid( $id );
		if ( null === $guid ) {
			return array();
		}
		$rows = $this->dv->rows(
			'opportunityproducts',
			array(
				'$filter' => '_opportunityid_value eq ' . $guid,
				'$select' => 'opportunityproductid,lineitemnumber,quantity,priceperunit,productdescription,_productid_value,tax',
			)
		);
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['lineitemnumber'] ?? 0 ) <=> (int) ( $b['lineitemnumber'] ?? 0 ) );
		return $rows;
	}

	/**
	 * Create the lines that are not on the opportunity yet and check the ones that are.
	 *
	 * @param string            $id       Opportunity GUID.
	 * @param array<int,DvLine> $plan     Lines to write.
	 * @param string            $currency Currency.
	 * @return array{written:int,error:ConnectorError|null}
	 *
	 * @throws ApiException On failure.
	 */
	public function ensureLines( string $id, array $plan, string $currency ): array {
		$existing = array();
		foreach ( $this->lines( $id ) as $row ) {
			$existing[ (int) ( $row['lineitemnumber'] ?? 0 ) ] = $row;
		}
		$written = 0;
		foreach ( $plan as $index => $line ) {
			$number = $index + 1;
			if ( isset( $existing[ $number ] ) ) {
				if ( ! $line->matches( $existing[ $number ], $currency ) ) {
					return array(
						'written' => $written,
						'error'   => Failures::invalid(
							$this->system,
							'opportunity',
							__( 'The deal in Dynamics 365 was changed after it was created: its products are not the ones the shop sent. Delete the deal and try again.', 'estasoft-enterprise-erpcrm-sync-engine' ),
							'lines differ'
						),
					);
				}
				continue;
			}
			$this->dv->create( 'opportunityproducts', $line->body( $id, $number, $currency ), 'opportunityproductid' );
			++$written;
		}
		return array(
			'written' => $written,
			'error'   => null,
		);
	}

	/**
	 * The last line of the description of a deal: the marker. Written without `verified` when the deal is created and with it once the
	 * total was checked.
	 *
	 * @param string $ref      WC-123.
	 * @param int    $count    Number of product lines.
	 * @param string $email    Buyer's email.
	 * @param bool   $verified Whether the total of the deal was checked.
	 */
	public static function marker( string $ref, int $count, string $email, bool $verified = false ): string {
		return sprintf( 'Estasoft Sync %s lines=%d email=%s', $ref, $count, $email ) . ( $verified ? ' verified' : '' );
	}

	/**
	 * What the marker of a reference says, or null when the description holds none.
	 *
	 * @param string $description Description text.
	 * @param string $ref         WC-123.
	 * @return array{lines:int,email:string,verified:bool}|null
	 */
	public static function markerInfo( string $description, string $ref ): ?array {
		if ( 1 !== preg_match( '/Estasoft Sync ' . preg_quote( $ref, '/' ) . ' lines=(\d+) email=(\S*?)( verified)?\s*$/', $description, $found ) ) {
			return null;
		}
		return array(
			'lines'    => (int) $found[1],
			'email'    => $found[2],
			'verified' => isset( $found[3] ) && '' !== $found[3],
		);
	}

	/**
	 * Whether the deal is finished: its verified marker is there and so are its products.
	 *
	 * @param array<string,mixed> $row Opportunity row.
	 * @param string              $ref WC-123.
	 *
	 * @throws ApiException On failure.
	 */
	public function isComplete( array $row, string $ref ): bool {
		$info = self::markerInfo( (string) ( $row['description'] ?? '' ), $ref );
		$id   = OData::guid( $row['opportunityid'] ?? null );
		return null !== $info && $info['verified'] && null !== $id && count( $this->lines( $id ) ) >= $info['lines'];
	}

	/**
	 * Mark the deal as checked: the last line of the description becomes the verified marker. A marker that is missing (a person edited the
	 * description) is written again; a marker that is already verified is left alone.
	 *
	 * @param array<string,mixed> $row   Opportunity row with its description.
	 * @param string              $ref   WC-123.
	 * @param int                 $count Number of product lines.
	 * @param string              $email Buyer's email.
	 * @return bool True when the description was changed now.
	 *
	 * @throws ApiException On failure.
	 */
	public function seal( array $row, string $ref, int $count, string $email ): bool {
		$id = OData::guid( $row['opportunityid'] ?? null );
		if ( null === $id ) {
			return false;
		}
		$description = (string) ( $row['description'] ?? '' );
		$marker      = self::marker( $ref, $count, $email, true );
		$keep        = array();
		$prefix      = 'Estasoft Sync ' . $ref . ' lines=';
		$parts       = preg_split( '/\R/', $description );
		foreach ( false === $parts ? array() : $parts as $line ) {
			if ( '' !== trim( $line ) && 0 !== strncmp( trim( $line ), $prefix, strlen( $prefix ) ) ) {
				$keep[] = $line;
			}
		}
		$sealed = ( array() === $keep ? '' : implode( "\n", $keep ) . "\n" ) . $marker;
		if ( rtrim( $description ) === $sealed ) {
			return false;
		}
		$this->dv->update( 'opportunities', $id, array( 'description' => $sealed ) );
		return true;
	}

	/**
	 * An opportunity as a reference.
	 *
	 * @param array<string,mixed> $row      Opportunity row.
	 * @param string              $currency ISO code of the opportunity.
	 * @param string              $env      Environment address, for the link.
	 * @param string              $warning  Accepted difference.
	 */
	public function ref( array $row, string $currency, string $env, string $warning = '' ): ExternalRef {
		$id    = (string) ( $row['opportunityid'] ?? '' );
		$state = array(
			0 => 'open',
			1 => 'won',
			2 => 'lost',
		)[ (int) ( $row['statecode'] ?? 0 ) ] ?? 'open';
		$extra = array(
			'total_minor' => Amounts::minor( $row['estimatedvalue'] ?? 0, $currency ),
			'currency'    => $currency,
			'status'      => $state,
			'kind'        => 'opportunity',
		);
		if ( '' !== $warning ) {
			$extra['warnings'] = array( $warning );
		}
		return new ExternalRef( $id, (string) ( $row['name'] ?? '' ), '' === $env ? null : $env . '/main.aspx?etn=opportunity&pagetype=entityrecord&id=' . $id, $extra );
	}
}
