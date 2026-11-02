<?php
/**
 * Customers of the legal entity.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

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
 * Customers (`CustomersV3`) found by their primary email before any customer is created. A customer needs a customer group and a currency
 * in F&O, so creating one is possible only when the connection names a group. Text longer than F&O keeps is cut with an ellipsis and a
 * warning is logged. The entity is documented as not for high volume; one search per order is all that is asked of it.
 */
final class FoCustomers {

	/**
	 * Longest text of each column (usual F&O lengths; the entity page states none).
	 */
	private const LIMITS = array(
		'OrganizationName' => 100,
		'PersonFirstName'  => 50,
		'PersonLastName'   => 50,
		'AddressStreet'    => 250,
		'AddressCity'      => 60,
		'AddressZipCode'   => 20,
	);

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param FoClient    $fo     Client.
	 * @param string      $system System name for messages.
	 * @param Logger|null $logger Log, for the warning about cut text.
	 */
	public function __construct( private readonly FoClient $fo, private readonly string $system, private readonly ?Logger $logger = null ) {
	}

	/**
	 * The customers with a primary email.
	 *
	 * @param string $email Email address.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function findByEmail( string $email ): array {
		$email = trim( $email );
		if ( '' === $email || ! FoClient::literalSafe( $email ) ) {
			return array();
		}
		$rows = $this->fo->rows( 'CustomersV3', array( OData::eq( 'PrimaryContactEmail', $email ) ) );
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => mb_strtolower( trim( (string) ( $row['PrimaryContactEmail'] ?? '' ) ) ) === mb_strtolower( $email ) ) );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['CustomerAccount'] ?? '' ), (string) ( $b['CustomerAccount'] ?? '' ) ) );
		return $rows;
	}

	/**
	 * A customer by account number, or null.
	 *
	 * @param string $account Customer account.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function byAccount( string $account ): ?array {
		if ( ! FoClient::literalSafe( $account ) ) {
			return null;
		}
		foreach ( $this->fo->rows( 'CustomersV3', array( OData::eq( 'CustomerAccount', $account ) ) ) as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['CustomerAccount'] ?? '' ), $account ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The customer account an order goes to, following the connection's policy.
	 *
	 * @param OrderData $order   Order.
	 * @param FoOptions $options Options.
	 * @param string    $orderId WooCommerce order id, for the message.
	 * @return array{row:array<string,mixed>,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( OrderData $order, FoOptions $options, string $orderId ): array|ConnectorError {
		if ( 'fixed' === $options->customerPolicy() ) {
			$row = $this->byAccount( $options->fixedCustomer() );
			return null === $row
				? Failures::invalid( $this->system, 'fixed_customer', __( 'The fixed customer account does not exist in this legal entity.', 'estasoft-erp-crm-sync-engine' ), 'fixed customer missing' )
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
			return array(
				'row'     => $matches[0],
				'created' => false,
			);
		}
		if ( 'match_only' === $options->customerPolicy() ) {
			return Failures::invalid( $this->system, 'partner.email', __( 'No customer has this email in Finance and Operations, and the connection only uses existing customers.', 'estasoft-erp-crm-sync-engine' ), 'customer not found' );
		}
		$created = $this->create( $order->partner, $order->personName(), $order->currency, $options );
		return $created instanceof ConnectorError ? $created : array(
			'row'     => $created,
			'created' => true,
		);
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,string> $partner  Buyer fields.
	 * @param string               $name     Full name when first and last name are not separate.
	 * @param string               $currency Sales currency.
	 * @param FoOptions            $options  Options.
	 * @return array<string,mixed>|ConnectorError The new customer, or the refusal when no customer group is configured.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( array $partner, string $name, string $currency, FoOptions $options ): array|ConnectorError {
		if ( '' === $options->customerGroup() ) {
			return Failures::invalid( $this->system, 'customer_group', __( 'Finance and Operations needs a customer group for a new customer. Set the option customer_group, or match existing customers only.', 'estasoft-erp-crm-sync-engine' ), 'customer group missing' );
		}
		$body = array(
			'CustomerGroupId'     => $options->customerGroup(),
			'SalesCurrencyCode'   => $currency,
			'PrimaryContactEmail' => $partner['email'] ?? '',
			'PrimaryContactPhone' => $partner['phone'] ?? '',
			'AddressStreet'       => $partner['street'] ?? '',
			'AddressCity'         => $partner['city'] ?? '',
			'AddressZipCode'      => $partner['zip'] ?? '',
		);
		if ( '' !== ( $partner['country_id'] ?? '' ) ) {
			$body['AddressCountryRegionId'] = strtoupper( $partner['country_id'] );
		}
		if ( '' !== ( $partner['state_id'] ?? '' ) ) {
			$body['AddressState'] = $partner['state_id'];
		}
		if ( '' !== ( $partner['company_name'] ?? '' ) ) {
			$body['PartyType']        = 'Organization';
			$body['OrganizationName'] = $partner['company_name'];
		} else {
			$last                    = '' !== ( $partner['last_name'] ?? '' ) ? $partner['last_name'] : $name;
			$body['PartyType']       = 'Person';
			$body['PersonFirstName'] = '' !== ( $partner['last_name'] ?? '' ) ? ( $partner['first_name'] ?? '' ) : '';
			$body['PersonLastName']  = '' !== $last ? $last : ( $partner['email'] ?? '' );
		}
		list( $body, $cut ) = Text::fitFields( $body, self::LIMITS );
		if ( array() !== $cut ) {
			$this->logger?->warning( 'Customer text was shortened to fit Finance and Operations.', array( 'fields' => implode( ',', $cut ) ) );
		}
		return $this->fo->create( 'CustomersV3', array_filter( $body, static fn( $value ): bool => '' !== $value ) );
	}

	/**
	 * A customer row as a reference. The reference text is the name as typed.
	 *
	 * @param array<string,mixed> $row Customer row.
	 */
	public function ref( array $row ): ExternalRef {
		$name = 'Person' === (string) ( $row['PartyType'] ?? '' )
			? trim( (string) ( $row['PersonFirstName'] ?? '' ) . ' ' . (string) ( $row['PersonLastName'] ?? '' ) )
			: (string) ( $row['OrganizationName'] ?? '' );
		return new ExternalRef(
			(string) ( $row['CustomerAccount'] ?? '' ),
			$name,
			null,
			array(
				'email'    => (string) ( $row['PrimaryContactEmail'] ?? '' ),
				'currency' => (string) ( $row['SalesCurrencyCode'] ?? '' ),
			)
		);
	}
}
