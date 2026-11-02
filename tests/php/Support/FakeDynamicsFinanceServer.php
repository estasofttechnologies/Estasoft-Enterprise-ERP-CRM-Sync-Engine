<?php
/**
 * A small in-memory Finance and Operations OData service for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the `/data/` calls the connector makes, over FakeHttpClient: legal entities, customers, released products, sales order headers
 * and lines, with OData filters, composite keys (`SalesOrderHeadersV2(dataAreaId='usmf',SalesOrderNumber='…')`), the wildcard `*` in a
 * string `eq`, and `{"error":{"code","message"}}`.
 *
 * It models on purpose: reads that see only the service user's default company unless `cross-company=true` is given, a `dataAreaId` in
 * every row, mandatory fields (requested shipping date on header and lines, the ordering customer), lines that cannot exist without their
 * header, header totals that are filled in only when `computesTotals` is true (the documentation does not say whether F&O fills them for
 * orders created over OData), and a write whose answer is lost after it succeeded.
 */
final class FakeDynamicsFinanceServer {

	public const TENANT      = '11111111-2222-3333-4444-555555555555';
	public const CLIENT_ID   = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	public const SECRET      = 'fake-client-secret-value-9f3a';
	public const ENVIRONMENT = 'https://contoso.operations.dynamics.com';

	/**
	 * The token endpoint.
	 */
	public FakeMicrosoftEntra $entra;

	/**
	 * Filters.
	 */
	public FakeMicrosoftOData $odata;

	/**
	 * Rows by entity.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $data = array(
		'LegalEntities'       => array(),
		'CustomersV3'         => array(),
		'ReleasedProductsV2'  => array(),
		'SalesOrderHeadersV2' => array(),
		'SalesOrderLines'     => array(),
	);

	/**
	 * Company the service user sees without cross-company=true.
	 */
	public string $defaultCompany = 'ussi';

	/**
	 * Currencies set up.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'AED', 'KWD', 'USD' );

	/**
	 * Whether header totals are filled in.
	 */
	public bool $computesTotals = true;

	/**
	 * Tax percent by item sales tax group.
	 *
	 * @var array<string,float>
	 */
	public array $taxRates = array(
		'FULL' => 5.0,
		'ZERO' => 0.0,
	);

	/**
	 * Entities the service user may not read (403).
	 *
	 * @var array<int,string>
	 */
	public array $denied = array();

	/**
	 * When set, F&O stores every new line with this price instead of the one sent (its own price agreements).
	 */
	public ?float $storedPrice = null;

	/**
	 * Whether the sales order number sequence is manual: a header made without SalesOrderNumber is refused.
	 */
	public bool $manualNumbering = false;

	/**
	 * Failure for the next write: timeout, server_error, unknown_fault, certificate, rate_limit.
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
	 * Counter for numbers.
	 */
	private int $sequence = 0;

	/**
	 * @param FakeHttpClient $http The client to answer.
	 */
	public function __construct( private readonly FakeHttpClient $http ) {
		$this->entra = new FakeMicrosoftEntra( self::TENANT, self::CLIENT_ID, self::SECRET, self::ENVIRONMENT );
		$this->odata = new FakeMicrosoftOData();
		$this->odata->wildcardEq        = true;
		$this->odata->caseInsensitiveEq = true;
		$this->data['LegalEntities'][] = array( 'LegalEntityId' => 'USMF', 'Name' => 'Contoso Entertainment System USA' );
		$this->data['LegalEntities'][] = array( 'LegalEntityId' => 'USSI', 'Name' => 'Contoso Retail San Diego' );
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Add a released product.
	 *
	 * @param string              $number Item number (the SKU).
	 * @param string              $entity Legal entity.
	 * @param array<string,mixed> $more   More fields.
	 * @return array<string,mixed>
	 */
	public function addProduct( string $number, string $entity = 'usmf', array $more = array() ): array {
		$row                                = $more + array(
			'dataAreaId'  => $entity,
			'ItemNumber'  => $number,
			'SearchName'  => 'Item ' . $number,
			'ProductName' => 'Item ' . $number,
		);
		$this->data['ReleasedProductsV2'][] = $row;
		return $row;
	}

	/**
	 * Add a customer.
	 *
	 * @param string $email  Email.
	 * @param string $name   Name.
	 * @param string $entity Legal entity.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $email, string $name, string $entity = 'usmf' ): array {
		$row                         = array(
			'dataAreaId'          => $entity,
			'CustomerAccount'     => 'US-' . str_pad( (string) ( 1 + count( $this->data['CustomersV3'] ) ), 3, '0', STR_PAD_LEFT ),
			'PartyType'           => 'Organization',
			'OrganizationName'    => $name,
			'PrimaryContactEmail' => $email,
			'CustomerGroupId'     => '10',
			'SalesCurrencyCode'   => 'USD',
		);
		$this->data['CustomersV3'][] = $row;
		return $row;
	}

	/**
	 * The rows of an entity.
	 *
	 * @param string $entity Entity.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $entity ): array {
		return $this->data[ $entity ];
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
		if ( self::ENVIRONMENT !== 'https://' . wp_parse_url( $url, PHP_URL_HOST ) || 1 !== preg_match( '#^/data/(.+)$#', rawurldecode( $path ), $found ) ) {
			return FakeHttpClient::status( 404, '' );
		}
		$auth = $request['headers']['Authorization'] ?? '';
		if ( ! str_starts_with( $auth, 'Bearer ' ) || ! $this->entra->accepts( substr( $auth, 7 ) ) ) {
			return FakeHttpClient::json( $this->error( 'Unauthorized', 'The request is not authorized.' ), 401 );
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
			'server_error'  => FakeHttpClient::json( $this->error( '', 'The service hit an error.' ), 500 ),
			'unknown_fault' => FakeHttpClient::json( $this->error( '', 'Something unexpected happened.' ), 500 ),
			'rate_limit'    => FakeHttpClient::json( $this->error( '', 'Number of requests exceeded the limit of 6000 over the time window of 300 seconds.' ), 429, array( 'retry-after' => (string) $this->retryAfter ) ),
			default         => null,
		};
	}

	/**
	 * Route by path.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $path    Path after /data/.
	 * @param string              $url     Full URL.
	 */
	private function route( array $request, string $path, string $url ): HttpResponse {
		$method = (string) $request['method'];
		if ( 1 !== preg_match( '/^(\w+)(?:\((.*)\))?$/', $path, $part ) || ! isset( $this->data[ $part[1] ] ) ) {
			return FakeHttpClient::json( $this->error( '', "Resource not found for the segment '" . $path . "'." ), 404 );
		}
		$entity = $part[1];
		if ( in_array( $entity, $this->denied, true ) ) {
			return FakeHttpClient::json( $this->error( '', 'Access is denied for the entity ' . $entity . '.' ), 403 );
		}
		if ( ! isset( $part[2] ) ) {
			return 'GET' === $method ? $this->list( $entity, $url ) : $this->create( $entity, $this->body( $request ) );
		}
		$key = $this->parseKey( $part[2] );
		if ( ! isset( $key['dataAreaId'] ) ) {
			return FakeHttpClient::json( $this->error( '', 'All fields of the key must be given.' ), 400 );
		}
		$index = null;
		foreach ( $this->data[ $entity ] as $i => $row ) {
			$match = true;
			foreach ( $key as $name => $value ) {
				$match = $match && strtolower( (string) ( $row[ $name ] ?? '' ) ) === strtolower( $value );
			}
			if ( $match ) {
				$index = $i;
			}
		}
		if ( null === $index ) {
			return FakeHttpClient::json( $this->error( '', 'The record does not exist.' ), 404 );
		}
		if ( 'PATCH' === $method ) {
			if ( ( $request['headers']['If-Match'] ?? '' ) === '' ) {
				return FakeHttpClient::json( $this->error( '', 'If-Match is required.' ), 400 );
			}
			$this->data[ $entity ][ $index ] = $this->body( $request ) + $this->data[ $entity ][ $index ];
			return FakeHttpClient::status( 204 );
		}
		return 'GET' === $method ? FakeHttpClient::json( $this->data[ $entity ][ $index ] ) : FakeHttpClient::status( 405 );
	}

	/**
	 * Parse a composite key.
	 *
	 * @param string $text Text inside the parentheses.
	 * @return array<string,string>
	 */
	private function parseKey( string $text ): array {
		$key = array();
		if ( preg_match_all( "/(\w+)='((?:[^']|'')*)'/", $text, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $pair ) {
				$key[ $pair[1] ] = str_replace( "''", "'", $pair[2] );
			}
		}
		return $key;
	}

	/**
	 * A list answer: only the default company without cross-company=true; wildcard strings; totals when computed.
	 *
	 * @param string $entity Entity.
	 * @param string $url    URL.
	 */
	private function list( string $entity, string $url ): HttpResponse {
		$options = $this->odata->options( $url );
		$rows    = $this->data[ $entity ];
		if ( 'LegalEntities' !== $entity && 'true' !== ( $options['cross-company'] ?? '' ) ) {
			$rows = array_values( array_filter( $rows, fn( array $row ): bool => strtolower( (string) ( $row['dataAreaId'] ?? '' ) ) === $this->defaultCompany ) );
		}
		if ( 'SalesOrderHeadersV2' === $entity ) {
			$rows = array_map( fn( array $row ): array => $this->withTotals( $row ), $rows );
		}
		$answer = $this->odata->query( $rows, $url, self::ENVIRONMENT . '/data/' . $entity );
		return FakeHttpClient::json( $answer['body'], $answer['status'] );
	}

	/**
	 * A header with the totals F&O works out from its lines.
	 *
	 * @param array<string,mixed> $header Header row.
	 * @return array<string,mixed>
	 */
	private function withTotals( array $header ): array {
		if ( ! $this->computesTotals ) {
			return $header;
		}
		$exponent = Money::exponent( (string) $header['CurrencyCode'] );
		$net      = 0.0;
		$tax      = 0.0;
		foreach ( $this->data['SalesOrderLines'] as $line ) {
			if ( $line['SalesOrderNumber'] === $header['SalesOrderNumber'] && $line['dataAreaId'] === $header['dataAreaId'] ) {
				$amount = round( (float) $line['OrderedSalesQuantity'] * (float) $line['SalesPrice'], $exponent );
				$net   += $amount;
				$tax   += $amount * ( $this->taxRates[ (string) ( $line['SalesTaxItemGroupCode'] ?? 'FULL' ) ] ?? 0.0 ) / 100;
			}
		}
		$header['OrderTotalAmount'] = round( $net - (float) ( $header['TotalDiscountAmount'] ?? 0 ) + $tax, $exponent );
		return $header;
	}

	/**
	 * Create a row.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $body   Body.
	 */
	private function create( string $entity, array $body ): HttpResponse {
		$body['dataAreaId'] = strtolower( (string) ( $body['dataAreaId'] ?? $this->defaultCompany ) );
		$bad                = $this->validate( $entity, $body );
		if ( null !== $bad ) {
			return FakeHttpClient::json( $this->error( '', $bad ), 400 );
		}
		if ( 'SalesOrderHeadersV2' === $entity ) {
			if ( '' === (string) ( $body['SalesOrderNumber'] ?? '' ) ) {
				if ( $this->manualNumbering ) {
					return FakeHttpClient::json( $this->error( '', 'The field SalesOrderNumber must be filled in: the number sequence is manual.' ), 400 );
				}
				$body['SalesOrderNumber'] = '00' . str_pad( (string) ( 1000 + ++$this->sequence ), 6, '0', STR_PAD_LEFT );
			}
			foreach ( $this->data['SalesOrderHeadersV2'] as $row ) {
				if ( $row['SalesOrderNumber'] === $body['SalesOrderNumber'] && $row['dataAreaId'] === $body['dataAreaId'] ) {
					return FakeHttpClient::json( $this->error( '', 'The sales order ' . $body['SalesOrderNumber'] . ' already exists.' ), 400 );
				}
			}
		}
		if ( 'SalesOrderLines' === $entity ) {
			if ( null !== $this->storedPrice ) {
				$body['SalesPrice'] = $this->storedPrice;
			}
			$body += array(
				'LineCreationSequenceNumber' => 1 + count( array_filter( $this->data['SalesOrderLines'], static fn( array $l ): bool => $l['SalesOrderNumber'] === ( $body['SalesOrderNumber'] ?? '' ) ) ),
				'InventoryLotId'             => 'LOT-' . ++$this->sequence,
			);
		}
		if ( 'CustomersV3' === $entity ) {
			$body += array( 'CustomerAccount' => 'US-' . str_pad( (string) ( 1 + count( $this->data['CustomersV3'] ) ), 3, '0', STR_PAD_LEFT ) );
		}
		$this->data[ $entity ][] = $body;
		return FakeHttpClient::json( 'SalesOrderHeadersV2' === $entity ? $this->withTotals( $body ) : $body, 201 );
	}

	/**
	 * Check a body the way F&O does.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $body   Body.
	 */
	private function validate( string $entity, array $body ): ?string {
		if ( 'SalesOrderHeadersV2' === $entity ) {
			if ( ! $this->customerExists( (string) ( $body['OrderingCustomerAccountNumber'] ?? '' ), (string) $body['dataAreaId'] ) ) {
				return 'Customer account ' . ( $body['OrderingCustomerAccountNumber'] ?? '' ) . ' does not exist.';
			}
			if ( '' === (string) ( $body['RequestedShippingDate'] ?? '' ) ) {
				return 'Field \'Requested ship date\' must be filled in.';
			}
			if ( null !== ( $bad = $this->badDate( $body ) ) ) {
				return $bad;
			}
			if ( ! in_array( strtoupper( (string) ( $body['CurrencyCode'] ?? '' ) ), $this->currencies, true ) ) {
				return 'Currency code ' . ( $body['CurrencyCode'] ?? '' ) . ' does not exist.';
			}
		}
		if ( 'SalesOrderLines' === $entity ) {
			$header = null;
			foreach ( $this->data['SalesOrderHeadersV2'] as $row ) {
				if ( $row['SalesOrderNumber'] === ( $body['SalesOrderNumber'] ?? '' ) && $row['dataAreaId'] === $body['dataAreaId'] ) {
					$header = $row;
				}
			}
			if ( null === $header ) {
				return 'The sales order ' . ( $body['SalesOrderNumber'] ?? '' ) . ' does not exist.';
			}
			if ( '' === (string) ( $body['RequestedShippingDate'] ?? '' ) ) {
				return 'Field \'Requested ship date\' must be filled in.';
			}
			if ( null !== ( $bad = $this->badDate( $body ) ) ) {
				return $bad;
			}
			$found = false;
			foreach ( $this->data['ReleasedProductsV2'] as $item ) {
				$found = $found || ( strcasecmp( (string) $item['ItemNumber'], (string) ( $body['ItemNumber'] ?? '' ) ) === 0 && $item['dataAreaId'] === $body['dataAreaId'] );
			}
			if ( ! $found ) {
				return 'Item ' . ( $body['ItemNumber'] ?? '' ) . ' does not exist.';
			}
		}
		if ( 'CustomersV3' === $entity && '' === (string) ( $body['CustomerGroupId'] ?? '' ) ) {
			return 'Field \'Customer group\' must be filled in.';
		}
		return null;
	}

	/**
	 * The refusal for a date field that is not an Edm.DateTimeOffset literal, as the service parses the date fields.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function badDate( array $body ): ?string {
		foreach ( array( 'RequestedShippingDate', 'RequestedReceiptDate' ) as $field ) {
			if ( isset( $body[ $field ] ) && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', (string) $body[ $field ] ) ) {
				return "Cannot convert the literal '" . $body[ $field ] . "' to the expected type 'Edm.DateTimeOffset'.";
			}
		}
		return null;
	}

	/**
	 * Whether a customer account exists in a legal entity.
	 *
	 * @param string $account Account.
	 * @param string $entity  Legal entity.
	 */
	private function customerExists( string $account, string $entity ): bool {
		foreach ( $this->data['CustomersV3'] as $row ) {
			if ( strcasecmp( (string) $row['CustomerAccount'], $account ) === 0 && $row['dataAreaId'] === $entity ) {
				return true;
			}
		}
		return false;
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
}
