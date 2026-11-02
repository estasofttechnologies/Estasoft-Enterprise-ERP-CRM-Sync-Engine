<?php
/**
 * An in-memory sevDesk, as far as the connector uses it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls of the sevDesk connector with the shapes the sevDesk reference mirrors show: everything wrapped in `objects`, numbers as text,
 * a list for searches and a list with one element for a read, the factory calls that create an invoice or credit note with their positions, drafts
 * only (a status other than 100 on creation is refused, as sevDesk has done since 2024), `sendBy` raising the status to 200, and a rejected request
 * that arrives as HTTP 500 with a message. The filters are the ones the mirrors list: contact, startDate, endDate, limit, offset, customerNumber,
 * partNumber. The invoice and credit note reference sits in `customerInternalNote` and the heading, but there is deliberately no filter for it.
 */
final class FakeSevdeskServer {

	public const TOKEN = 'a3f9c2d1e4b7a6089c5d2e1f7a3b4c6d';

	/**
	 * The routing fake.
	 */
	public ApiFakeServer $server;

	/**
	 * Bookkeeping system version the fake reports.
	 */
	public string $version = '2.0';

	/**
	 * Contacts by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $contacts = array();

	/**
	 * Parts by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $parts = array();

	/**
	 * Invoices by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Credit notes by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $creditNotes = array();

	/**
	 * Stock by part id.
	 *
	 * @var array<int,int>
	 */
	public array $stock = array();

	/**
	 * Payments booked: [invoice id, body].
	 *
	 * @var array<int,array{0:int,1:array<string,mixed>}>
	 */
	public array $payments = array();

	/**
	 * When set, every document is stored with this date (ISO text), as a vendor that overwrites the date would.
	 */
	public ?string $overrideDate = null;

	/**
	 * Addresses and communication ways made.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $details = array();

	/**
	 * Counter for ids.
	 */
	private int $counter = 1000;

	/**
	 * @param FakeHttpClient $http The fake network of the test kit.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, 'my.sevdesk.de' );
		$this->routes();
	}

	/**
	 * Add a part.
	 *
	 * @param string $number  Part number (the WooCommerce SKU).
	 * @param string $name    Name.
	 * @param int    $stock   Stock; null-like -1 means stock tracking is off.
	 */
	public function addPart( string $number, string $name, int $stock = -1 ): int {
		$id                   = ++$this->counter;
		$this->parts[ $id ]   = array( 'id' => (string) $id, 'objectName' => 'Part', 'partNumber' => $number, 'name' => $name, 'stockEnabled' => $stock >= 0 ? '1' : '0', 'stock' => (string) max( 0, $stock ) );
		$this->stock[ $id ]   = max( 0, $stock );
		return $id;
	}

	/**
	 * Make the next write request fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$response = match ( $kind ) {
			'rate_limit'    => $seconds > 0 ? FakeHttpClient::tooManyRequests( $seconds ) : FakeHttpClient::status( 429 ),
			'timeout'       => FakeHttpClient::timeout(),
			'server_error'  => FakeHttpClient::json( array( 'message' => 'Server Error' ), 500 ),
			'unknown_fault' => FakeHttpClient::status( 418, 'teapot' ),
			'auth'          => FakeHttpClient::json( array( 'message' => 'Authentication required' ), 401 ),
			default         => FakeHttpClient::certificateError(),
		};
		$this->server->failNext( 'POST', '', $response );
	}

	/**
	 * Define the routes.
	 */
	private function routes(): void {
		$s    = $this->server;
		$auth = fn( \Closure $handler ): \Closure => function ( ApiFakeRequest $request ) use ( $handler ) {
			if ( self::TOKEN !== (string) $request->header( 'Authorization' ) ) {
				return FakeHttpClient::json( array( 'message' => 'Authentication required' ), 401 );
			}
			return $handler( $request );
		};
		$s->route( 'GET', '/api/v1/Tools/bookkeepingSystemVersion', $auth( fn() => array( 'objects' => array( 'version' => $this->version ) ) ) );
		$s->route( 'GET', '/api/v1/SevClient', $auth( fn() => array( 'objects' => array( array( 'id' => '1', 'name' => 'Beispiel GmbH' ) ) ) ) );
		$s->route( 'GET', '/api/v1/SevUser', $auth( fn() => array( 'objects' => array( array( 'id' => '77', 'objectName' => 'SevUser' ) ) ) ) );
		$s->route( 'GET', '/api/v1/Unity', $auth( fn() => array( 'objects' => array( array( 'id' => '1', 'name' => 'Stück' ), array( 'id' => '9', 'name' => 'Stunde' ) ) ) ) );
		$s->route( 'GET', '/api/v1/CheckAccount', $auth( fn() => array( 'objects' => array( array( 'id' => '55', 'name' => 'Girokonto' ) ) ) ) );
		$s->route( 'GET', '/api/v1/StaticCountry', $auth( fn() => array( 'objects' => array( array( 'id' => '1', 'code' => 'de' ), array( 'id' => '2', 'code' => 'at' ), array( 'id' => '3', 'code' => 'ae' ) ) ) ) );
		$s->route( 'GET', '/api/v1/Contact', $auth( fn( ApiFakeRequest $r ) => $this->listContacts( $r ) ) );
		$s->route( 'GET', '/api/v1/Contact/{id}', $auth( fn( ApiFakeRequest $r ) => isset( $this->contacts[ (int) $r->params['id'] ] ) ? array( 'objects' => array( $this->contacts[ (int) $r->params['id'] ] ) ) : null ) );
		$s->route( 'POST', '/api/v1/Contact', $auth( fn( ApiFakeRequest $r ) => $this->createContact( $r ) ) );
		$s->route( 'POST', '/api/v1/ContactAddress', $auth( fn( ApiFakeRequest $r ) => $this->recordDetail( $r, 'ContactAddress' ) ) );
		$s->route( 'POST', '/api/v1/CommunicationWay', $auth( fn( ApiFakeRequest $r ) => $this->recordDetail( $r, 'CommunicationWay' ) ) );
		$s->route( 'GET', '/api/v1/Part', $auth( fn( ApiFakeRequest $r ) => $this->listParts( $r ) ) );
		$s->route( 'GET', '/api/v1/Part/{id}/getStock', $auth( fn( ApiFakeRequest $r ) => array( 'objects' => $this->stock[ (int) $r->params['id'] ] ?? 0 ) ) );
		$s->route( 'POST', '/api/v1/Invoice/Factory/saveInvoice', $auth( fn( ApiFakeRequest $r ) => $this->save( $r, 'Invoice' ) ) );
		$s->route( 'GET', '/api/v1/Invoice', $auth( fn( ApiFakeRequest $r ) => $this->listDocuments( $r, $this->invoices ) ) );
		$s->route( 'GET', '/api/v1/Invoice/{id}', $auth( fn( ApiFakeRequest $r ) => isset( $this->invoices[ (int) $r->params['id'] ] ) ? array( 'objects' => array( $this->invoices[ (int) $r->params['id'] ] ) ) : null ) );
		$s->route( 'GET', '/api/v1/Invoice/{id}/getPositions', $auth( fn( ApiFakeRequest $r ) => array( 'objects' => $this->invoices[ (int) $r->params['id'] ]['_positions'] ?? array() ) ) );
		$s->route( 'DELETE', '/api/v1/Invoice/{id}', $auth( fn( ApiFakeRequest $r ) => $this->delete( $r, $this->invoices ) ) );
		$s->route( 'PUT', '/api/v1/Invoice/{id}/sendBy', $auth( fn( ApiFakeRequest $r ) => $this->sendBy( $r, $this->invoices ) ) );
		$s->route( 'PUT', '/api/v1/Invoice/{id}/bookAmount', $auth( fn( ApiFakeRequest $r ) => $this->book( $r ) ) );
		$s->route( 'POST', '/api/v1/CreditNote/Factory/saveCreditNote', $auth( fn( ApiFakeRequest $r ) => $this->save( $r, 'CreditNote' ) ) );
		$s->route( 'GET', '/api/v1/CreditNote', $auth( fn( ApiFakeRequest $r ) => $this->listDocuments( $r, $this->creditNotes ) ) );
		$s->route( 'GET', '/api/v1/CreditNote/{id}', $auth( fn( ApiFakeRequest $r ) => isset( $this->creditNotes[ (int) $r->params['id'] ] ) ? array( 'objects' => array( $this->creditNotes[ (int) $r->params['id'] ] ) ) : null ) );
		$s->route( 'DELETE', '/api/v1/CreditNote/{id}', $auth( fn( ApiFakeRequest $r ) => $this->delete( $r, $this->creditNotes ) ) );
		$s->route( 'PUT', '/api/v1/CreditNote/{id}/sendBy', $auth( fn( ApiFakeRequest $r ) => $this->sendBy( $r, $this->creditNotes ) ) );
	}

	/**
	 * Contact list: the customerNumber filter and the limit.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function listContacts( ApiFakeRequest $request ): array|HttpResponse {
		if ( isset( $request->query['limit'] ) && ( (int) $request->query['limit'] < 1 || (int) $request->query['limit'] > 1000 ) ) {
			return FakeHttpClient::json( array( 'message' => "Invalid 'limit' parameter. Please provide an integer value between 1 and 1000." ), 400 );
		}
		$number = (string) ( $request->query['customerNumber'] ?? '' );
		$rows   = array();
		foreach ( $this->contacts as $contact ) {
			if ( '' === $number || $contact['customerNumber'] === $number ) {
				$rows[] = $contact;
			}
		}
		return array( 'objects' => array_slice( $rows, 0, (int) ( $request->query['limit'] ?? 1000 ) ) );
	}

	/**
	 * Create a contact: the category is required, a customer number must be unique (the answer is a 500 with a message, as the mirrors report).
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function createContact( ApiFakeRequest $request ): array|HttpResponse {
		$body = $request->json();
		if ( empty( $body['category']['id'] ) ) {
			return FakeHttpClient::json( array( 'message' => 'Bad request' ), 400 );
		}
		foreach ( $this->contacts as $contact ) {
			if ( isset( $body['customerNumber'] ) && $contact['customerNumber'] === $body['customerNumber'] ) {
				return FakeHttpClient::json( array( 'error' => array( 'message' => 'debitor/creditor number already exists' ) ), 500 );
			}
		}
		$id                    = ++$this->counter;
		$body['id']            = (string) $id;
		$body['objectName']    = 'Contact';
		$this->contacts[ $id ] = $body;
		return array( 'objects' => $body );
	}

	/**
	 * Record an address or a communication way.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Object name.
	 * @return array<string,mixed>
	 */
	private function recordDetail( ApiFakeRequest $request, string $kind ): array {
		$body                = $request->json() + array( 'objectName' => $kind, 'id' => (string) ++$this->counter );
		$this->details[]     = $body;
		return array( 'objects' => $body );
	}

	/**
	 * Part list with the partNumber and name filters.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>
	 */
	private function listParts( ApiFakeRequest $request ): array {
		$number = (string) ( $request->query['partNumber'] ?? '' );
		$name   = strtolower( (string) ( $request->query['name'] ?? '' ) );
		$rows   = array();
		foreach ( $this->parts as $part ) {
			if ( ( '' === $number || $part['partNumber'] === $number ) && ( '' === $name || str_contains( strtolower( (string) $part['name'] ), $name ) ) ) {
				$rows[] = $part;
			}
		}
		return array( 'objects' => $rows );
	}

	/**
	 * The factory call that creates an invoice or a credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Invoice or CreditNote.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function save( ApiFakeRequest $request, string $kind ): array|HttpResponse {
		$body   = $request->json();
		$key    = 'Invoice' === $kind ? 'invoice' : 'creditNote';
		$posKey = 'Invoice' === $kind ? 'invoicePosSave' : 'creditNotePosSave';
		$doc    = (array) ( $body[ $key ] ?? array() );
		$bad    = fn( string $text ) => FakeHttpClient::json( array( 'message' => $text ), 400 );
		foreach ( array( 'objectName', 'mapAll', 'contact', 'contactPerson', 'addressCountry', 'taxText', 'currency' ) as $required ) {
			if ( ! isset( $doc[ $required ] ) ) {
				return $bad( 'Missing ' . $required );
			}
		}
		if ( '2.0' === $this->version && ! isset( $doc['taxRule'] ) || '1.0' === $this->version && ! isset( $doc['taxType'] ) ) {
			return $bad( 'Missing tax rule' );
		}
		if ( 100 !== (int) ( $doc['status'] ?? -1 ) ) {
			return $bad( 'Only drafts can be created' );
		}
		if ( empty( $body[ $posKey ] ) || array_keys( $body ) !== array( $key, $posKey, 'Invoice' === $kind ? 'invoicePosDelete' : 'creditNotePosDelete', 'discountSave', 'discountDelete', 'takeDefaultAddress' ) + ( 'CreditNote' === $kind ? array( 6 => 'forCashRegister' ) : array() ) ) {
			return $bad( 'The trailing parameters must be present and in order' );
		}
		$sumNet = 0;
		$groups = array();
		$pos    = array();
		foreach ( $body[ $posKey ] as $row ) {
			$cents                 = (int) round( (float) $row['price'] * (float) $row['quantity'] * 100 );
			$rate                  = (int) round( (float) $row['taxRate'] * 100 );
			$groups[ $rate ]       = ( $groups[ $rate ] ?? 0 ) + $cents;
			$pos[]                 = $row + array( 'sumNet' => number_format( $cents / 100, 2, '.', '' ) );
		}
		$tax = 0;
		foreach ( $groups as $rate => $net ) {
			$sumNet += $net;
			$tax    += intdiv( $net * $rate + 5000, 10000 );
		}
		$id                = ++$this->counter;
		$prefix            = 'Invoice' === $kind ? 'RE-' : 'GS-';
		$doc['id']         = (string) $id;
		$doc['objectName'] = $kind;
		$doc[ 'Invoice' === $kind ? 'invoiceNumber' : 'creditNoteNumber' ] = $prefix . $id;
		$doc['sumNet']     = number_format( $sumNet / 100, 2, '.', '' );
		$doc['sumTax']     = number_format( $tax / 100, 2, '.', '' );
		$doc['sumGross']   = number_format( ( $sumNet + $tax ) / 100, 2, '.', '' );
		$doc['paidAmount'] = '0';
		$doc['status']     = '100';
		$doc['_positions'] = $pos;
		$doc['_date']      = $this->timestamp( (string) ( $doc['invoiceDate'] ?? $doc['creditNoteDate'] ?? '' ) );
		$dateKey           = 'Invoice' === $kind ? 'invoiceDate' : 'creditNoteDate';
		if ( null !== $this->overrideDate ) {
			$doc[ $dateKey ] = $this->overrideDate;
		} elseif ( $doc['_date'] > 0 ) {
			$doc[ $dateKey ] = ( new \DateTimeImmutable( '@' . ( $doc['_date'] - 3600 ) ) )->setTimezone( new \DateTimeZone( 'Europe/Berlin' ) )->format( 'Y-m-d\TH:i:sP' );
		}
		if ( 'Invoice' === $kind ) {
			$this->invoices[ $id ] = $doc;
		} else {
			$this->creditNotes[ $id ] = $doc;
		}
		return array( 'objects' => array( $key => $doc, ( 'Invoice' === $kind ? 'invoicePos' : 'creditNotePos' ) => $pos ) );
	}

	/**
	 * List invoices or credit notes with the documented filters.
	 *
	 * @param ApiFakeRequest                    $request Request.
	 * @param array<int,array<string,mixed>>    $store   Documents.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function listDocuments( ApiFakeRequest $request, array $store ): array|HttpResponse {
		$limit = isset( $request->query['limit'] ) ? (int) $request->query['limit'] : 1000;
		if ( $limit < 1 || $limit > 1000 ) {
			return FakeHttpClient::json( array( 'message' => "Invalid 'limit' parameter. Please provide an integer value between 1 and 1000." ), 400 );
		}
		$contact = (string) ( $request->query['contact']['id'] ?? '' );
		$from    = isset( $request->query['startDate'] ) ? (int) $request->query['startDate'] : null;
		$to      = isset( $request->query['endDate'] ) ? (int) $request->query['endDate'] : null;
		$rows    = array();
		foreach ( $store as $doc ) {
			if ( '' !== $contact && (string) $doc['contact']['id'] !== $contact ) {
				continue;
			}
			if ( ( null !== $from && $doc['_date'] < $from ) || ( null !== $to && $doc['_date'] > $to ) ) {
				continue;
			}
			$rows[] = array_diff_key( $doc, array( '_positions' => 1, '_date' => 1 ) );
		}
		return array( 'objects' => array_slice( $rows, (int) ( $request->query['offset'] ?? 0 ), $limit ) );
	}

	/**
	 * Delete a draft.
	 *
	 * @param ApiFakeRequest                    $request Request.
	 * @param array<int,array<string,mixed>>    $store   Documents (by reference through the property).
	 * @return array<string,mixed>|null
	 */
	private function delete( ApiFakeRequest $request, array &$store ): ?array {
		$id = (int) $request->params['id'];
		if ( ! isset( $store[ $id ] ) ) {
			return null;
		}
		unset( $store[ $id ] );
		return array( 'objects' => array() );
	}

	/**
	 * Mark as sent: a draft becomes open.
	 *
	 * @param ApiFakeRequest                    $request Request.
	 * @param array<int,array<string,mixed>>    $store   Documents.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function sendBy( ApiFakeRequest $request, array &$store ): array|HttpResponse|null {
		$id = (int) $request->params['id'];
		if ( ! isset( $store[ $id ] ) ) {
			return null;
		}
		if ( ! isset( $request->json()['sendType'] ) ) {
			return FakeHttpClient::json( array( 'message' => 'sendType is required' ), 400 );
		}
		$store[ $id ]['status'] = '200';
		return array( 'objects' => $store[ $id ] );
	}

	/**
	 * Book a payment on an invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function book( ApiFakeRequest $request ): array|HttpResponse|null {
		$id   = (int) $request->params['id'];
		$body = $request->json();
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return null;
		}
		if ( empty( $body['checkAccount']['id'] ) || ! isset( $body['amount'], $body['date'], $body['type'] ) ) {
			return FakeHttpClient::json( array( 'message' => 'Bad request' ), 400 );
		}
		if ( ! is_int( $body['date'] ) ) {
			return FakeHttpClient::json( array( 'message' => "Invalid 'date' parameter. Please provide an integer value." ), 400 );
		}
		$this->payments[]                   = array( $id, $body );
		$this->invoices[ $id ]['paidAmount'] = number_format( (float) $body['amount'], 2, '.', '' );
		$this->invoices[ $id ]['status']     = (float) $body['amount'] >= (float) $this->invoices[ $id ]['sumGross'] ? '1000' : '750';
		return array( 'objects' => $this->invoices[ $id ] );
	}

	/**
	 * A dd.mm.yyyy date as the timestamp of that day in Berlin.
	 *
	 * @param string $date Date text.
	 */
	private function timestamp( string $date ): int {
		$moment = \DateTimeImmutable::createFromFormat( '!d.m.Y', $date, new \DateTimeZone( 'Europe/Berlin' ) );
		return false === $moment ? 0 : $moment->getTimestamp() + 3600;
	}
}
