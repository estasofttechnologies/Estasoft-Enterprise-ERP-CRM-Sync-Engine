<?php
/**
 * A small in-memory Dataverse (Dynamics 365 Sales) for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the Web API v9.2 calls the connector makes, over FakeHttpClient: accounts, contacts, products, price lists, currencies, deals
 * (opportunities) with their products, notes, WhoAmI and RetrieveVersion, with OData filters (string filters ignore case, as the
 * documentation says), `Prefer: return=representation`, `@odata.bind`, 204 with `OData-EntityId`, and `{"error":{"code","message"}}`.
 *
 * It models on purpose: money stored at the currency row's precision, an opportunity total that Dataverse works out from its products, a
 * lookup that must point at an existing row, a missing application user (401), a missing privilege (403 naming it), a Sales app that is
 * not installed (404 on the table), and a write whose answer is lost after it succeeded.
 */
final class FakeDynamicsSalesServer {

	public const TENANT     = '11111111-2222-3333-4444-555555555555';
	public const CLIENT_ID  = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	public const SECRET     = 'fake-client-secret-value-9f3a';
	public const ENVIRONMENT = 'https://contoso.crm4.dynamics.com';
	public const UOM_ID     = 'c0c0c0c0-0000-4000-8000-000000000001';

	/**
	 * The token endpoint.
	 */
	public FakeMicrosoftEntra $entra;

	/**
	 * Filters.
	 */
	public FakeMicrosoftOData $odata;

	/**
	 * Rows by entity set.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $data = array();

	/**
	 * Whether the Sales app (opportunities) is installed.
	 */
	public bool $salesInstalled = true;

	/**
	 * Privileges the application user lacks, by entity set (create or read).
	 *
	 * @var array<string,string>
	 */
	public array $denied = array();

	/**
	 * Failure for the next write: timeout, server_error, unknown_fault, certificate, rate_limit, auth.
	 */
	public string $failNextWrite = '';

	/**
	 * Seconds for rate_limit.
	 */
	public int $retryAfter = 0;

	/**
	 * Substrings of URLs whose write is applied but whose answer is lost.
	 *
	 * @var array<int,string>
	 */
	public array $loseAnswers = array();

	/**
	 * Cut the Nth write whose URL contains the key (0 = the next one).
	 *
	 * @var array<string,int>
	 */
	public array $failAfterWrites = array();

	/**
	 * Writes handled.
	 */
	public int $writes = 0;

	/**
	 * Counter for timestamps.
	 */
	private int $tick = 0;

	/**
	 * @param FakeHttpClient $http The client to answer.
	 */
	public function __construct( private readonly FakeHttpClient $http ) {
		$this->entra = new FakeMicrosoftEntra( self::TENANT, self::CLIENT_ID, self::SECRET, self::ENVIRONMENT );
		$this->odata = new FakeMicrosoftOData();
		$this->odata->caseInsensitiveEq = true;
		foreach ( array( 'contacts', 'accounts', 'products', 'pricelevels', 'transactioncurrencies', 'opportunities', 'opportunityproducts', 'annotations', 'organizations', 'uoms' ) as $set ) {
			$this->data[ $set ] = array();
		}
		$this->data['organizations'][] = array(
			'organizationid'          => $this->guid(),
			'name'                    => 'Contoso Trading',
			'pricingdecimalprecision' => 2,
		);
		foreach ( array( 'AED' => 2, 'KWD' => 3, 'USD' => 2 ) as $code => $precision ) {
			$this->addCurrency( $code, $precision );
		}
		$this->data['uoms'][] = array( 'uomid' => self::UOM_ID, 'name' => 'Unit' );
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Set the Pricing Decimal Precision of the organization (2 on a default organization). The price of a product (`priceperunit`) is stored
	 * with these decimals whatever the currency keeps.
	 *
	 * @param int $places Decimal places (0 to 4).
	 */
	public function setPricingPrecision( int $places ): void {
		$this->data['organizations'][0]['pricingdecimalprecision'] = $places;
	}

	/**
	 * Add a currency row.
	 *
	 * @param string $code      ISO code.
	 * @param int    $precision Decimal places the row keeps.
	 */
	public function addCurrency( string $code, int $precision ): void {
		$this->data['transactioncurrencies'][] = array(
			'transactioncurrencyid' => $this->guid(),
			'isocurrencycode'       => $code,
			'currencyprecision'     => $precision,
			'statecode'             => 0,
		);
	}

	/**
	 * Add a product.
	 *
	 * @param string              $number Product ID (the SKU).
	 * @param array<string,mixed> $more   More columns.
	 * @return array<string,mixed>
	 */
	public function addProduct( string $number, array $more = array() ): array {
		$row                     = $more + array(
			'productid'           => $this->guid(),
			'productnumber'       => $number,
			'name'                => 'Product ' . $number,
			'statecode'           => 0,
			'price'               => 10.0,
			'_defaultuomid_value' => self::UOM_ID,
		);
		$this->data['products'][] = $row;
		return $row;
	}

	/**
	 * Add a contact.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return array<string,mixed>
	 */
	public function addContact( string $email, string $first, string $last ): array {
		$row                     = array(
			'contactid'     => $this->guid(),
			'firstname'     => $first,
			'lastname'      => $last,
			'fullname'      => trim( $first . ' ' . $last ),
			'emailaddress1' => $email,
			'statecode'     => 0,
			'createdon'     => $this->stamp(),
		);
		$this->data['contacts'][] = $row;
		return $row;
	}

	/**
	 * The rows of a set.
	 *
	 * @param string $set Entity set.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $set ): array {
		return $this->data[ $set ];
	}

	/**
	 * Route a request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function handle( array $request ): HttpResponse {
		$url = $request['url'];
		if ( $this->entra->handles( $url ) ) {
			return $this->entra->answer( $request );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( self::ENVIRONMENT !== 'https://' . wp_parse_url( $url, PHP_URL_HOST ) || 1 !== preg_match( '#^/api/data/v9\.2/(.+)$#', $path, $found ) ) {
			return FakeHttpClient::status( 404, '' );
		}
		$auth = $request['headers']['Authorization'] ?? '';
		if ( ! str_starts_with( $auth, 'Bearer ' ) || ! $this->entra->accepts( substr( $auth, 7 ) ) ) {
			return FakeHttpClient::json( $this->error( '0x80072560', 'The user is not a member of the organization.' ), 401 );
		}
		$write = 'GET' !== $request['method'];
		if ( $write ) {
			$failure = $this->writeFailure( $url );
			if ( null !== $failure ) {
				return $failure;
			}
		}
		$response = $this->route( $request, $found[1], $url );
		if ( $write ) {
			foreach ( $this->loseAnswers as $key => $needle ) {
				if ( str_contains( $url, $needle ) ) {
					unset( $this->loseAnswers[ $key ] );
					return FakeHttpClient::timeout();
				}
			}
		}
		return $response;
	}

	/**
	 * Decide whether this write fails.
	 *
	 * @param string $url URL.
	 */
	private function writeFailure( string $url ): ?HttpResponse {
		++$this->writes;
		$kind = $this->failNextWrite;
		foreach ( $this->failAfterWrites as $key => $left ) {
			if ( str_contains( $url, $key ) ) {
				if ( $left <= 0 ) {
					unset( $this->failAfterWrites[ $key ] );
					$kind = 'timeout';
					break;
				}
				--$this->failAfterWrites[ $key ];
			}
		}
		if ( '' === $kind ) {
			return null;
		}
		$this->failNextWrite = '';
		return match ( $kind ) {
			'timeout'       => FakeHttpClient::timeout(),
			'certificate'   => FakeHttpClient::certificateError(),
			'server_error'  => FakeHttpClient::json( $this->error( '0x80040216', 'An unexpected error occurred.' ), 500 ),
			'unknown_fault' => FakeHttpClient::json( $this->error( '', 'Something unexpected happened.' ), 500 ),
			'auth'          => FakeHttpClient::json( $this->error( '0x80072560', 'The user is not a member of the organization.' ), 401 ),
			'rate_limit'    => FakeHttpClient::json( $this->error( '0x80072322', 'Number of requests exceeded the limit of 6000 over time window of 300 seconds.' ), 429, array( 'retry-after' => (string) $this->retryAfter ) ),
			default         => null,
		};
	}

	/**
	 * Route by path.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $path    Path after api/data/v9.2/.
	 * @param string              $url     Full URL.
	 */
	private function route( array $request, string $path, string $url ): HttpResponse {
		$method = (string) $request['method'];
		if ( 'WhoAmI' === $path ) {
			return FakeHttpClient::json( array(
				'BusinessUnitId' => $this->guid(),
				'UserId'         => $this->guid(),
				'OrganizationId' => (string) $this->data['organizations'][0]['organizationid'],
			) );
		}
		if ( 'RetrieveVersion' === $path ) {
			return FakeHttpClient::json( array( 'Version' => '9.2.24101.00123' ) );
		}
		if ( 1 !== preg_match( '/^(\w+)(?:\(([0-9a-f-]{36})\))?$/i', $path, $part ) || ! isset( $this->data[ $part[1] ] ) ) {
			return FakeHttpClient::json( $this->error( '0x80060888', "Resource not found for the segment '" . $path . "'." ), 404 );
		}
		$set = $part[1];
		if ( ! $this->salesInstalled && in_array( $set, array( 'opportunities', 'opportunityproducts' ), true ) ) {
			return FakeHttpClient::json( $this->error( '0x80060888', "Resource not found for the segment '" . $set . "'." ), 404 );
		}
		$verb = 'GET' === $method ? 'read' : 'create';
		if ( isset( $this->denied[ $set ] ) && ( $this->denied[ $set ] === $verb || 'all' === $this->denied[ $set ] ) ) {
			return FakeHttpClient::json( $this->error( '0x80040220', 'Principal user (Id: ' . $this->guid() . ', type: 8, roleCount: 1, privilegeCount: 3), is missing prv' . ucfirst( $verb ) . $this->entityName( $set ) . ' privilege (Id: ' . $this->guid() . ') on OTC=3' ), 403 );
		}
		if ( ! isset( $part[2] ) ) {
			return 'GET' === $method ? $this->list( $set, $url ) : $this->create( $set, $request, $url );
		}
		$index = $this->indexOf( $set, strtolower( $part[2] ) );
		if ( null === $index ) {
			return FakeHttpClient::json( $this->error( '0x80040217', $set . ' With Id = ' . $part[2] . ' Does Not Exist' ), 404 );
		}
		if ( 'GET' === $method ) {
			return $this->single( $set, $index, $url );
		}
		if ( 'PATCH' === $method ) {
			if ( ( $request['headers']['If-Match'] ?? '' ) !== '*' ) {
				return FakeHttpClient::json( $this->error( '0x80060882', 'An upsert is not allowed here.' ), 400 );
			}
			$this->data[ $set ][ $index ] = $this->columns( $this->body( $request ) ) + $this->data[ $set ][ $index ];
			return FakeHttpClient::status( 204 );
		}
		return FakeHttpClient::status( 405 );
	}

	/**
	 * A list answer.
	 *
	 * @param string $set Entity set.
	 * @param string $url URL.
	 */
	private function list( string $set, string $url ): HttpResponse {
		$rows = array_map( fn( array $row ): array => $this->computed( $set, $row ), $this->data[ $set ] );
		$base = self::ENVIRONMENT . '/api/data/v9.2/' . $set;
		$answer = $this->odata->query( $rows, $url, $base );
		return FakeHttpClient::json( $answer['body'], $answer['status'] );
	}

	/**
	 * A single row answer.
	 *
	 * @param string $set   Entity set.
	 * @param int    $index Index.
	 * @param string $url   URL.
	 */
	private function single( string $set, int $index, string $url ): HttpResponse {
		$answer = $this->odata->query( array( $this->computed( $set, $this->data[ $set ][ $index ] ) ), $url, '' );
		return FakeHttpClient::json( $answer['body']['value'][0] ?? array() );
	}

	/**
	 * A row with the columns Dataverse works out when it is read.
	 *
	 * @param string              $set Entity set.
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function computed( string $set, array $row ): array {
		if ( 'opportunities' !== $set ) {
			return $row;
		}
		$precision = $this->precisionOf( (string) ( $row['_transactioncurrencyid_value'] ?? '' ) );
		$sum       = 0.0;
		foreach ( $this->data['opportunityproducts'] as $line ) {
			if ( ( $line['_opportunityid_value'] ?? '' ) === $row['opportunityid'] ) {
				$sum += round( (float) $line['quantity'] * (float) $line['priceperunit'], $precision ) + (float) ( $line['tax'] ?? 0 );
			}
		}
		$row['totalamount'] = round( $sum - (float) ( $row['discountamount'] ?? 0 ), $precision );
		return $row;
	}

	/**
	 * Create a row.
	 *
	 * @param string              $set     Entity set.
	 * @param array<string,mixed> $request Request.
	 * @param string              $url     URL.
	 */
	private function create( string $set, array $request, string $url ): HttpResponse {
		$body = $this->body( $request );
		$bad  = $this->validate( $set, $body );
		if ( null !== $bad ) {
			return FakeHttpClient::json( $this->error( '0x80040265', $bad ), 400 );
		}
		$idColumn = array(
			'contacts'            => 'contactid',
			'accounts'            => 'accountid',
			'opportunities'       => 'opportunityid',
			'opportunityproducts' => 'opportunityproductid',
			'annotations'         => 'annotationid',
		)[ $set ] ?? null;
		if ( null === $idColumn ) {
			return FakeHttpClient::json( $this->error( '0x80060888', 'Read only.' ), 405 );
		}
		$row = $this->columns( $body ) + array(
			$idColumn   => $this->guid(),
			'statecode' => 0,
			'createdon' => $this->stamp(),
		);
		if ( 'contacts' === $set ) {
			$row['fullname'] = trim( (string) ( $row['firstname'] ?? '' ) . ' ' . (string) ( $row['lastname'] ?? '' ) );
		}
		foreach ( array( 'estimatedvalue', 'discountamount', 'priceperunit', 'tax' ) as $money ) {
			if ( isset( $row[ $money ] ) ) {
				$currency = (string) ( $row['_transactioncurrencyid_value'] ?? $this->opportunityCurrency( (string) ( $row['_opportunityid_value'] ?? '' ) ) );
				$places        = 'priceperunit' === $money ? (int) $this->data['organizations'][0]['pricingdecimalprecision'] : $this->precisionOf( $currency );
				$row[ $money ] = round( (float) $row[ $money ], $places );
			}
		}
		if ( 'opportunityproducts' === $set ) {
			$row['_transactioncurrencyid_value'] = $this->opportunityCurrency( (string) ( $row['_opportunityid_value'] ?? '' ) );
		}
		$this->data[ $set ][] = $row;
		if ( str_contains( (string) ( $request['headers']['Prefer'] ?? '' ), 'return=representation' ) ) {
			$answer = $this->odata->query( array( $this->computed( $set, $row ) ), $url, '' );
			return FakeHttpClient::json( $answer['body']['value'][0] ?? $row, 201, array( 'preference-applied' => 'return=representation' ) );
		}
		return FakeHttpClient::status( 204, '', array( 'odata-entityid' => self::ENVIRONMENT . '/api/data/v9.2/' . $set . '(' . $row[ $idColumn ] . ')' ) );
	}

	/**
	 * Check a body the way Dataverse does.
	 *
	 * @param string              $set  Entity set.
	 * @param array<string,mixed> $body Body.
	 */
	private function validate( string $set, array $body ): ?string {
		foreach ( $body as $key => $value ) {
			if ( str_ends_with( (string) $key, '@odata.bind' ) && is_string( $value ) && 1 === preg_match( '#^/(\w+)\(([0-9a-f-]{36})\)$#i', $value, $target ) && ( ! isset( $this->data[ $target[1] ] ) || null === $this->indexOf( $target[1], strtolower( $target[2] ) ) ) ) {
				return 'Could not find an entity of type ' . $target[1] . ' with id ' . $target[2] . ' for the lookup ' . $key . '.';
			}
		}
		if ( 'contacts' === $set && '' === trim( (string) ( $body['lastname'] ?? '' ) ) ) {
			return 'Required field "lastname" is missing.';
		}
		if ( 'opportunities' === $set ) {
			if ( '' === trim( (string) ( $body['name'] ?? '' ) ) ) {
				return 'Required field "name" is missing.';
			}
			if ( ! isset( $body['customerid_account@odata.bind'] ) && ! isset( $body['customerid_contact@odata.bind'] ) ) {
				return 'Required field "customerid" is missing.';
			}
			if ( isset( $body['estimatedvalue'] ) && ! isset( $body['transactioncurrencyid@odata.bind'] ) ) {
				return 'The currency is required for a money value.';
			}
		}
		if ( 'opportunityproducts' === $set && ! isset( $body['opportunityid@odata.bind'] ) ) {
			return 'Required field "opportunityid" is missing.';
		}
		if ( 'annotations' === $set && '' === trim( (string) ( $body['subject'] ?? '' ) ) ) {
			return 'Required field "subject" is missing.';
		}
		return null;
	}

	/**
	 * Turn `@odata.bind` entries into lookup values and keep the other columns.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 */
	private function columns( array $body ): array {
		$row = array();
		foreach ( $body as $key => $value ) {
			if ( str_ends_with( (string) $key, '@odata.bind' ) && is_string( $value ) && 1 === preg_match( '#\(([0-9a-f-]{36})\)#i', $value, $guid ) ) {
				$name = (string) preg_replace( '/_(account|contact|opportunity)$/', '', substr( (string) $key, 0, -11 ) );
				$row[ '_' . $name . '_value' ] = strtolower( $guid[1] );
				continue;
			}
			$row[ $key ] = $value;
		}
		return $row;
	}

	/**
	 * Currency row id of an opportunity.
	 *
	 * @param string $opportunityId Id.
	 */
	private function opportunityCurrency( string $opportunityId ): string {
		foreach ( $this->data['opportunities'] as $row ) {
			if ( $row['opportunityid'] === $opportunityId ) {
				return (string) ( $row['_transactioncurrencyid_value'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Decimal places a currency row keeps.
	 *
	 * @param string $currencyId Row id.
	 */
	private function precisionOf( string $currencyId ): int {
		foreach ( $this->data['transactioncurrencies'] as $row ) {
			if ( $row['transactioncurrencyid'] === $currencyId ) {
				return (int) $row['currencyprecision'];
			}
		}
		return Money::exponent( 'AED' );
	}

	/**
	 * Name of an entity in a privilege name (prvCreateOpportunity).
	 *
	 * @param string $set Entity set.
	 */
	private function entityName( string $set ): string {
		return array(
			'opportunities'       => 'Opportunity',
			'opportunityproducts' => 'OpportunityProduct',
			'contacts'            => 'Contact',
			'accounts'            => 'Account',
			'annotations'         => 'Annotation',
			'products'            => 'Product',
		)[ $set ] ?? ucfirst( $set );
	}

	/**
	 * Index of a row by id.
	 *
	 * @param string $set Entity set.
	 * @param string $id  Id.
	 */
	private function indexOf( string $set, string $id ): ?int {
		$key = array(
			'contacts'              => 'contactid',
			'accounts'              => 'accountid',
			'products'              => 'productid',
			'pricelevels'           => 'pricelevelid',
			'transactioncurrencies' => 'transactioncurrencyid',
			'opportunities'         => 'opportunityid',
			'opportunityproducts'   => 'opportunityproductid',
			'annotations'           => 'annotationid',
			'organizations'         => 'organizationid',
			'uoms'                  => 'uomid',
		)[ $set ] ?? 'id';
		foreach ( $this->data[ $set ] as $index => $row ) {
			if ( strtolower( (string) ( $row[ $key ] ?? '' ) ) === $id ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * The decoded body.
	 *
	 * @param array<string,mixed> $request Request.
	 * @return array<string,mixed>
	 */
	private function body( array $request ): array {
		$decoded = json_decode( (string) ( $request['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * An error body.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * An increasing timestamp.
	 */
	private function stamp(): string {
		return gmdate( 'Y-m-d\TH:i:s' ) . sprintf( '.%06dZ', ++$this->tick );
	}

	/**
	 * A random GUID.
	 */
	private function guid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
