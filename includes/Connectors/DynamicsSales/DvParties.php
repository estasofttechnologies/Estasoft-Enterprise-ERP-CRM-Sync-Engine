<?php
/**
 * Contacts and accounts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

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
 * The buyer of an order: a contact found by email (string filters are case-insensitive in Dataverse, the rows are still compared here),
 * and, when the buyer gave a company name, an account found by name. Nothing is created before a search found nothing, so a retry never
 * makes a second contact. Text longer than a column takes is cut with an ellipsis and a warning is logged.
 */
final class DvParties {

	/**
	 * Longest text of each column (the Contact and Account references state the address columns; the others are the usual lengths).
	 */
	private const LIMITS = array(
		'firstname'                => 50,
		'lastname'                 => 50,
		'telephone1'               => 50,
		'address1_line1'           => 250,
		'address1_line2'           => 250,
		'address1_city'            => 80,
		'address1_stateorprovince' => 50,
		'address1_postalcode'      => 20,
		'address1_country'         => 80,
		'name'                     => 160,
	);

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient    $dv     Client.
	 * @param string      $system System name for messages.
	 * @param Logger|null $logger Log, for the warning about cut text.
	 */
	public function __construct( private readonly DvClient $dv, private readonly string $system, private readonly ?Logger $logger = null ) {
	}

	/**
	 * The oldest active contact with an email.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findContact( string $email ): ?array {
		$email = trim( $email );
		if ( '' === $email ) {
			return null;
		}
		$rows = $this->dv->rows(
			'contacts',
			array(
				'$filter'  => OData::all( array( OData::eq( 'emailaddress1', $email ), 'statecode eq 0' ) ),
				'$select'  => 'contactid,fullname,firstname,lastname,emailaddress1,telephone1,createdon',
				'$orderby' => 'createdon asc',
			)
		);
		foreach ( $rows as $row ) {
			if ( 0 === strcmp( mb_strtolower( trim( (string) ( $row['emailaddress1'] ?? '' ) ) ), mb_strtolower( $email ) ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The oldest active account with a name.
	 *
	 * @param string $name Company name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findAccount( string $name ): ?array {
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}
		$rows = $this->dv->rows(
			'accounts',
			array(
				'$filter'  => OData::all( array( OData::eq( 'name', $name ), 'statecode eq 0' ) ),
				'$select'  => 'accountid,name,createdon',
				'$orderby' => 'createdon asc',
			)
		);
		foreach ( $rows as $row ) {
			if ( 0 === strcmp( mb_strtolower( trim( (string) ( $row['name'] ?? '' ) ) ), mb_strtolower( $name ) ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The contact (and the account) an order belongs to.
	 *
	 * @param OrderData $order   Order.
	 * @param DvOptions $options Options.
	 * @param string    $orderId WooCommerce order id, for the message.
	 * @return array{contact:array<string,mixed>,account:array<string,mixed>|null}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( OrderData $order, DvOptions $options, string $orderId ): array|ConnectorError {
		$email = $order->partner['email'] ?? '';
		if ( '' === $email ) {
			return Failures::missingEmail( $orderId );
		}
		$company = $order->partner['company_name'] ?? '';
		$account = null;
		if ( '' !== $company && $options->createAccounts() ) {
			$account = $this->findAccount( $company );
		}
		$contact = $this->findContact( $email );
		if ( null === $contact && 'match_only' === $options->customerPolicy() ) {
			return Failures::invalid( $this->system, 'partner.email', __( 'No contact has this email in Dynamics 365, and the connection only uses existing contacts.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'contact not found' );
		}
		if ( null === $account && '' !== $company && $options->createAccounts() && 'match_only' !== $options->customerPolicy() ) {
			$account = $this->createAccount( $order->partner, $company );
		}
		if ( null === $contact ) {
			$contact = $this->createContact( $order->partner, $order->personName(), null === $account ? null : (string) ( $account['accountid'] ?? '' ) );
		}
		return array(
			'contact' => $contact,
			'account' => $account,
		);
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string,string> $partner    Buyer fields.
	 * @param string               $name       Full name when first and last name are not separate.
	 * @param string|null          $accountId  Account GUID the contact belongs to.
	 * @return array<string,mixed> The new contact.
	 *
	 * @throws ApiException On failure.
	 */
	public function createContact( array $partner, string $name, ?string $accountId ): array {
		$first = $partner['first_name'] ?? '';
		$last  = $partner['last_name'] ?? '';
		if ( '' === $last ) {
			$last  = '' !== $name ? $name : ( $partner['company_name'] ?? ( $partner['email'] ?? '' ) );
			$first = '';
		}
		$body = $this->address(
			array(
				'firstname'     => $first,
				'lastname'      => $last,
				'emailaddress1' => $partner['email'] ?? '',
				'telephone1'    => $partner['phone'] ?? '',
			),
			$partner
		);
		if ( null !== $accountId && null !== OData::guid( $accountId ) ) {
			$body['parentcustomerid_account@odata.bind'] = '/accounts(' . OData::guid( $accountId ) . ')';
		}
		return $this->dv->create( 'contacts', $this->fit( $body ), 'contactid', 'contactid,fullname,firstname,lastname,emailaddress1,telephone1' );
	}

	/**
	 * Create an account for a company buyer.
	 *
	 * @param array<string,string> $partner Buyer fields.
	 * @param string               $name    Company name.
	 * @return array<string,mixed> The new account.
	 *
	 * @throws ApiException On failure.
	 */
	public function createAccount( array $partner, string $name ): array {
		$body = $this->address(
			array(
				'name'       => $name,
				'telephone1' => $partner['phone'] ?? '',
			),
			$partner
		);
		return $this->dv->create( 'accounts', $this->fit( $body ), 'accountid', 'accountid,name' );
	}

	/**
	 * A contact row as a reference. The reference text is the name as typed.
	 *
	 * @param array<string,mixed> $row Contact row.
	 */
	public function contactRef( array $row ): ExternalRef {
		$name = trim( (string) ( $row['fullname'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $row['firstname'] ?? '' ) . ' ' . (string) ( $row['lastname'] ?? '' ) );
		}
		return new ExternalRef(
			(string) ( $row['contactid'] ?? '' ),
			$name,
			null,
			array(
				'email' => (string) ( $row['emailaddress1'] ?? '' ),
				'phone' => (string) ( $row['telephone1'] ?? '' ),
			)
		);
	}

	/**
	 * Add the address columns.
	 *
	 * @param array<string,string> $body    Columns so far.
	 * @param array<string,string> $partner Buyer fields.
	 * @return array<string,string>
	 */
	private function address( array $body, array $partner ): array {
		return $body + array(
			'address1_line1'           => $partner['street'] ?? '',
			'address1_line2'           => $partner['street2'] ?? '',
			'address1_city'            => $partner['city'] ?? '',
			'address1_stateorprovince' => $partner['state_id'] ?? '',
			'address1_postalcode'      => $partner['zip'] ?? '',
			'address1_country'         => strtoupper( $partner['country_id'] ?? '' ),
		);
	}

	/**
	 * Cut the text columns to their length, drop empty ones.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 */
	private function fit( array $body ): array {
		list( $body, $cut ) = Text::fitFields( $body, self::LIMITS );
		if ( array() !== $cut ) {
			$this->logger?->warning( 'Text was shortened to fit Dynamics 365.', array( 'fields' => implode( ',', $cut ) ) );
		}
		return array_filter( $body, static fn( $value ): bool => '' !== $value );
	}
}
