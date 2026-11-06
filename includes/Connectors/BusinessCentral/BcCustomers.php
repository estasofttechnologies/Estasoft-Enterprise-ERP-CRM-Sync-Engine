<?php
/**
 * Customers of the company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Logging\Logger;

/**
 * Finds a customer by email, ignoring case, before any customer is created; creates one when the connection allows it. Names and addresses
 * are sent as typed (Arabic included); text longer than a field takes is cut with an ellipsis and a warning is logged.
 */
final class BcCustomers {

	/**
	 * Fields read of a customer.
	 */
	private const SELECT = 'id,number,displayName,email,phoneNumber,taxRegistrationNumber,blocked';

	/**
	 * Longest text of each customer field (from the AL tables; the API pages do not state them).
	 */
	private const LIMITS = array(
		'displayName'  => 100,
		'addressLine1' => 100,
		'addressLine2' => 50,
		'city'         => 30,
		'state'        => 30,
		'postalCode'   => 20,
		'phoneNumber'  => 30,
	);

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient    $bc     Client pinned to the company.
	 * @param string      $system System name for messages.
	 * @param Logger|null $logger Log, for the warning about cut text.
	 */
	public function __construct( private readonly BcClient $bc, private readonly string $system, private readonly ?Logger $logger = null ) {
	}

	/**
	 * The customers with an email, oldest number first. A blocked customer comes last.
	 *
	 * @param string $email Email address.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function findByEmail( string $email ): array {
		$email = trim( $email );
		if ( '' === $email ) {
			return array();
		}
		$path = $this->bc->path( 'customers' );
		try {
			// tolower() turns case sensitivity off in a Business Central filter (documented); plain eq is not documented as case-insensitive.
			$rows = $this->bc->rows(
				$path,
				array(
					'$filter'  => 'tolower(email) eq ' . OData::string( mb_strtolower( $email ) ),
					'$select'  => self::SELECT,
					'$orderby' => 'number',
				)
			);
		} catch ( ApiException $exception ) {
			if ( 400 !== $exception->status() ) {
				throw $exception;
			}
			$rows = $this->bc->rows(
				$path,
				array(
					'$filter'  => OData::eq( 'email', $email ),
					'$select'  => self::SELECT,
					'$orderby' => 'number',
				)
			);
		}
		$matches = array_values( array_filter( $rows, static fn( array $row ): bool => mb_strtolower( trim( (string) ( $row['email'] ?? '' ) ) ) === mb_strtolower( $email ) ) );
		usort( $matches, fn( array $a, array $b ): int => (int) $this->isBlocked( $a ) <=> (int) $this->isBlocked( $b ) );
		return $matches;
	}

	/**
	 * A customer by its number, or null.
	 *
	 * @param string $number Customer number.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function byNumber( string $number ): ?array {
		foreach ( $this->bc->rows(
			$this->bc->path( 'customers' ),
			array(
				'$filter' => OData::eq( 'number', $number ),
				'$select' => self::SELECT,
			)
		) as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['number'] ?? '' ), $number ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The customer an order goes to, following the connection's policy.
	 *
	 * @param OrderData $order   Order.
	 * @param BcOptions $options Options.
	 * @param string    $orderId WooCommerce order id, for the message.
	 * @return array{row:array<string,mixed>,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( OrderData $order, BcOptions $options, string $orderId ): array|ConnectorError {
		if ( 'fixed' === $options->customerPolicy() ) {
			$row = $this->byNumber( $options->fixedCustomer() );
			return null === $row
				? Failures::invalid( $this->system, 'fixed_customer', __( 'The fixed customer number does not exist in Business Central.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'fixed customer missing' )
				: array(
					'row'     => $row,
					'created' => false,
				);
		}
		$email = $order->partner['email'] ?? '';
		if ( '' === $email ) {
			return Failures::missingEmail( $orderId );
		}
		$matches = $this->findByEmail( $email );
		if ( array() !== $matches ) {
			if ( $this->isBlocked( $matches[0] ) ) {
				return Failures::invalid( $this->system, 'partner.email', __( 'The customer with this email is blocked in Business Central.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'customer blocked' );
			}
			return array(
				'row'     => $matches[0],
				'created' => false,
			);
		}
		if ( 'match_only' === $options->customerPolicy() ) {
			return Failures::invalid( $this->system, 'partner.email', __( 'No customer has this email in Business Central, and the connection only uses existing customers.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'customer not found' );
		}
		return array(
			'row'     => $this->create( $order->partner, $order->buyerName() ),
			'created' => true,
		);
	}

	/**
	 * Create a customer from a buyer block.
	 *
	 * @param array<string,string> $partner Buyer fields: email, company_name, street, street2, city, state_id, zip, country_id, phone, vat.
	 * @param string               $name    Name the customer is known by.
	 * @return array<string,mixed> The new customer.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( array $partner, string $name ): array {
		$body = array(
			'displayName'  => $name,
			'type'         => '' !== ( $partner['company_name'] ?? '' ) ? 'Company' : 'Person',
			'addressLine1' => $partner['street'] ?? '',
			'addressLine2' => $partner['street2'] ?? '',
			'city'         => $partner['city'] ?? '',
			'state'        => $partner['state_id'] ?? '',
			'country'      => strtoupper( $partner['country_id'] ?? '' ),
			'postalCode'   => $partner['zip'] ?? '',
			'phoneNumber'  => $partner['phone'] ?? '',
			'email'        => $partner['email'] ?? '',
		);
		if ( '' !== ( $partner['vat'] ?? '' ) ) {
			$body['taxRegistrationNumber'] = $partner['vat'];
		}
		$cut = array();
		foreach ( self::LIMITS as $field => $max ) {
			list( $body[ $field ], $was ) = Text::fit( (string) $body[ $field ], $max );
			if ( $was ) {
				$cut[] = $field;
			}
		}
		if ( array() !== $cut ) {
			$this->logger?->warning( 'Customer text was shortened to fit Business Central.', array( 'fields' => implode( ',', $cut ) ) );
		}
		return $this->bc->create( $this->bc->path( 'customers' ), array_filter( $body, static fn( $value ): bool => '' !== $value ) );
	}

	/**
	 * A customer row as a reference. The reference text is the name, as typed.
	 *
	 * @param array<string,mixed> $row Customer row.
	 */
	public function ref( array $row ): ExternalRef {
		return new ExternalRef(
			(string) ( $row['id'] ?? '' ),
			(string) ( $row['displayName'] ?? '' ),
			null,
			array(
				'number' => (string) ( $row['number'] ?? '' ),
				'email'  => (string) ( $row['email'] ?? '' ),
				'phone'  => (string) ( $row['phoneNumber'] ?? '' ),
				'vat'    => (string) ( $row['taxRegistrationNumber'] ?? '' ),
			)
		);
	}

	/**
	 * Whether transactions with the customer are blocked.
	 *
	 * @param array<string,mixed> $row Customer row.
	 */
	private function isBlocked( array $row ): bool {
		return in_array( trim( (string) ( $row['blocked'] ?? '' ) ), array( 'Ship', 'Invoice', 'All' ), true );
	}
}
