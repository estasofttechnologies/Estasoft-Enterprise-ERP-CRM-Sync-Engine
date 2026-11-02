<?php
/**
 * An in-memory Lexware Office, as far as the connector uses it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls of the Lexware Office connector with the shapes the Lexware documentation shows (contacts, articles, invoices, credit
 * notes, voucher list, profile; paging with content/last/totalPages; 406 with an IssueList for a rejected document; 429 without Retry-After).
 * Totals are worked out with whole-number arithmetic the way Lexware describes it: each line rounded to cents, tax per rate group. Behaviours
 * that once hid bugs elsewhere are modelled on purpose: the draft invoice cannot be referenced by a credit note (406), only one credit note
 * can be linked to an invoice, and a finalised document gets `voucherStatus` open.
 */
final class FakeLexwareOfficeServer {

	public const KEY = 'lx-test-key-0f8a2c91-5b7d-4e1a-9c33-aa51d02e7b64';

	/**
	 * The routing fake.
	 */
	public ApiFakeServer $server;

	/**
	 * Contacts by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $contacts = array();

	/**
	 * Articles by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $articles = array();

	/**
	 * Invoices by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Credit notes by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $creditNotes = array();

	/**
	 * Rates the fake accepts, in percent.
	 *
	 * @var array<int,int>
	 */
	public array $rates = array( 0, 7, 19 );

	/**
	 * How many of the next reads of an invoice by id time out (the answer to a read-back that never comes).
	 */
	public int $failInvoiceReads = 0;

	/**
	 * When set, every document is stored with this voucher date (ISO text), as a vendor that overwrites the date would.
	 */
	public ?string $overrideDate = null;

	/**
	 * Counter for ids and numbers.
	 */
	private int $counter = 0;

	/**
	 * @param FakeHttpClient $http The fake network of the test kit.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, 'api.lexware.io' );
		$this->routes();
	}

	/**
	 * Add an article.
	 *
	 * @param string $number Article number (the WooCommerce SKU).
	 * @param string $title  Title.
	 * @param string $type   PRODUCT or SERVICE.
	 */
	public function addArticle( string $number, string $title, string $type = 'PRODUCT' ): string {
		$id                    = $this->uuid();
		$this->articles[ $id ] = array(
			'id'            => $id,
			'title'         => $title,
			'type'          => $type,
			'articleNumber' => $number,
			'unitName'      => 'Stück',
			'price'         => array(
				'netPrice'     => 10.0,
				'leadingPrice' => 'NET',
				'taxRate'      => 19,
			),
		);
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
			'server_error'  => FakeHttpClient::json( array( 'message' => 'Internal error' ), 500 ),
			'unknown_fault' => FakeHttpClient::status( 418, 'teapot' ),
			'auth'          => FakeHttpClient::json( array( 'message' => 'Unauthorized' ), 401 ),
			default         => FakeHttpClient::certificateError(),
		};
		$this->server->failNext( 'POST', '', $response );
	}

	/**
	 * The invoice a connector made for a client reference (first line of the introduction).
	 *
	 * @param string $marker Client reference.
	 * @return array<string,mixed>|null
	 */
	public function invoiceWith( string $marker ): ?array {
		foreach ( $this->invoices as $invoice ) {
			if ( str_contains( (string) $invoice['introduction'], $marker ) ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * Define the routes.
	 */
	private function routes(): void {
		$auth = fn( \Closure $handler ): \Closure => function ( ApiFakeRequest $request ) use ( $handler ) {
			if ( 'Bearer ' . self::KEY !== (string) $request->header( 'Authorization' ) ) {
				return FakeHttpClient::json( array( 'message' => 'Unauthorized' ), 401 );
			}
			return $handler( $request );
		};
		$s    = $this->server;
		$s->route( 'GET', '/v1/profile', $auth( fn() => array( 'organizationId' => 'org-1', 'companyName' => 'Beispiel GmbH', 'subscriptionStatus' => 'active', 'taxType' => 'net', 'smallBusiness' => false ) ) );
		$s->route( 'GET', '/v1/contacts', $auth( fn( ApiFakeRequest $r ) => $this->listContacts( $r ) ) );
		$s->route( 'POST', '/v1/contacts', $auth( fn( ApiFakeRequest $r ) => $this->createContact( $r ) ) );
		$s->route( 'GET', '/v1/articles', $auth( fn( ApiFakeRequest $r ) => $this->listArticles( $r ) ) );
		$s->route( 'GET', '/v1/voucherlist', $auth( fn( ApiFakeRequest $r ) => $this->voucherList( $r ) ) );
		$s->route( 'POST', '/v1/invoices', $auth( fn( ApiFakeRequest $r ) => $this->createDocument( $r, 'invoice' ) ) );
		$s->route( 'GET', '/v1/invoices/{id}', $auth( fn( ApiFakeRequest $r ) => $this->readInvoice( $r ) ) );
		$s->route( 'POST', '/v1/credit-notes', $auth( fn( ApiFakeRequest $r ) => $this->createDocument( $r, 'creditnote' ) ) );
		$s->route( 'GET', '/v1/credit-notes/{id}', $auth( fn( ApiFakeRequest $r ) => $this->creditNotes[ $r->params['id'] ] ?? null ) );
	}

	/**
	 * Contact list with the email filter [documented]: substring, case-insensitive, three characters at least.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function listContacts( ApiFakeRequest $request ): array|HttpResponse {
		$email = strtolower( str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), (string) ( $request->query['email'] ?? '' ) ) );
		if ( '' !== $email && strlen( $email ) < 3 ) {
			return $this->invalid( 'email_too_short' );
		}
		$rows = array();
		foreach ( $this->contacts as $contact ) {
			$text = strtolower( wp_json_encode( array( $contact['emailAddresses'] ?? array(), $contact['company']['contactPersons'] ?? array() ) ) );
			if ( '' === $email || str_contains( $text, $email ) ) {
				$rows[] = $contact;
			}
		}
		return $this->paged( $rows, $request );
	}

	/**
	 * Create a contact.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function createContact( ApiFakeRequest $request ): array|HttpResponse {
		$body = $request->json();
		if ( ! isset( $body['roles'] ) || ( ! isset( $body['company'] ) && ! isset( $body['person'] ) ) || 0 !== ( $body['version'] ?? null ) ) {
			return $this->invalid( 'contact_incomplete' );
		}
		if ( isset( $body['person'] ) && '' === (string) ( $body['person']['lastName'] ?? '' ) ) {
			return $this->invalid( 'last_name_required' );
		}
		$id                    = $this->uuid();
		$body['id']            = $id;
		$body['roles']         = array( 'customer' => array( 'number' => 10000 + count( $this->contacts ) + 1 ) );
		$body['version']       = 1;
		$this->contacts[ $id ] = $body;
		return $this->created( $id, 'contacts' );
	}

	/**
	 * Article list with the article number filter.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>
	 */
	private function listArticles( ApiFakeRequest $request ): array {
		$number = (string) ( $request->query['articleNumber'] ?? '' );
		$rows   = array();
		foreach ( $this->articles as $article ) {
			if ( '' === $number || $article['articleNumber'] === $number ) {
				$rows[] = $article;
			}
		}
		return $this->paged( $rows, $request );
	}

	/**
	 * The voucher list: id, number, contact, date, totals and status, but not the introduction.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>
	 */
	private function voucherList( ApiFakeRequest $request ): array {
		$type     = (string) ( $request->query['voucherType'] ?? '' );
		$contact  = (string) ( $request->query['contactId'] ?? '' );
		$from     = (string) ( $request->query['voucherDateFrom'] ?? '' );
		$to       = (string) ( $request->query['voucherDateTo'] ?? '' );
		$statuses = explode( ',', (string) ( $request->query['voucherStatus'] ?? '' ) );
		$rows     = array();
		foreach ( array_merge( $this->invoices, $this->creditNotes ) as $document ) {
			$day = substr( (string) $document['voucherDate'], 0, 10 );
			if ( ( '' !== $type && $document['voucherType'] !== $type ) || ( '' !== $contact && $document['address']['contactId'] !== $contact ) ) {
				continue;
			}
			if ( ( '' !== $from && $day < $from ) || ( '' !== $to && $day > $to ) || ! in_array( $document['voucherStatus'], $statuses, true ) ) {
				continue;
			}
			$rows[] = array(
				'id'            => $document['id'],
				'voucherType'   => $document['voucherType'],
				'voucherStatus' => $document['voucherStatus'],
				'voucherNumber' => $document['voucherNumber'],
				'voucherDate'   => $document['voucherDate'],
				'contactId'     => $document['address']['contactId'],
				'totalAmount'   => $document['totalPrice']['totalGrossAmount'],
				'currency'      => 'EUR',
			);
		}
		return $this->paged( $rows, $request );
	}

	/**
	 * Create an invoice or credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $type    invoice or creditnote.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function createDocument( ApiFakeRequest $request, string $type ): array|HttpResponse {
		$body = $request->json();
		if ( empty( $body['address']['contactId'] ) || ! isset( $this->contacts[ $body['address']['contactId'] ] ) ) {
			return $this->invalid( 'contact_not_found' );
		}
		if ( empty( $body['lineItems'] ) || empty( $body['voucherDate'] ) || 'EUR' !== ( $body['totalPrice']['currency'] ?? '' ) || empty( $body['taxConditions']['taxType'] ) ) {
			return $this->invalid( 'document_incomplete' );
		}
		$gross = 'gross' === $body['taxConditions']['taxType'];
		foreach ( $body['lineItems'] as $line ) {
			$rate = (float) ( $line['unitPrice']['taxRatePercentage'] ?? -1 );
			if ( ! in_array( (int) $rate, $this->rates, true ) || (float) (int) $rate !== $rate ) {
				return $this->invalid( 'tax_rate_not_supported', 'lineItems[0].unitPrice.taxRatePercentage' );
			}
			if ( $gross !== isset( $line['unitPrice']['grossAmount'] ) && 'text' !== $line['type'] ) {
				return $this->invalid( 'amount_type_mismatch', 'taxConditions.taxType' );
			}
		}
		$status = 'draft';
		$linked = (string) ( $request->query['precedingSalesVoucherId'] ?? '' );
		if ( 'creditnote' === $type && '' !== $linked ) {
			$invoice = $this->invoices[ $linked ] ?? null;
			if ( null === $invoice || 'draft' === $invoice['voucherStatus'] || array() !== $invoice['relatedVouchers'] ) {
				return $this->invalid( 'preceding_voucher_not_allowed' );
			}
		}
		if ( 'true' === (string) ( $request->query['finalize'] ?? '' ) ) {
			$status = 'creditnote' === $type && '' !== $linked ? 'paidoff' : 'open';
		}
		$id      = $this->uuid();
		$prefix  = 'invoice' === $type ? 'RE' : 'GS';
		$totals  = $this->totals( $body['lineItems'], (string) $body['taxConditions']['taxType'] );
		$doc     = $body + array(
			'id'              => $id,
			'voucherType'     => $type,
			'voucherStatus'   => $status,
			'voucherNumber'   => sprintf( '%s%04d', $prefix, ++$this->counter ),
			'introduction'    => '',
			'relatedVouchers' => array(),
			'version'         => 1,
		);
		$doc['totalPrice'] = array( 'currency' => 'EUR' ) + $totals;
		if ( null !== $this->overrideDate ) {
			$doc['voucherDate'] = $this->overrideDate;
		}
		if ( 'creditnote' === $type && '' !== $linked ) {
			$this->invoices[ $linked ]['relatedVouchers'][] = array( 'id' => $id, 'voucherNumber' => $doc['voucherNumber'], 'voucherType' => 'creditnote' );
		}
		if ( 'invoice' === $type ) {
			$this->invoices[ $id ] = $doc;
		} else {
			$this->creditNotes[ $id ] = $doc;
		}
		return $this->created( $id, 'invoice' === $type ? 'invoices' : 'credit-notes' );
	}

	/**
	 * Read an invoice, unless a read-back is set to time out.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function readInvoice( ApiFakeRequest $request ): array|HttpResponse|null {
		if ( $this->failInvoiceReads > 0 ) {
			--$this->failInvoiceReads;
			return FakeHttpClient::timeout();
		}
		return $this->invoices[ $request->params['id'] ] ?? null;
	}

	/**
	 * Totals the way the documentation describes: lines in cents, tax per rate group.
	 *
	 * @param array<int,array<string,mixed>> $lines   Line items.
	 * @param string                         $taxType Tax type.
	 * @return array<string,mixed>
	 */
	private function totals( array $lines, string $taxType ): array {
		$groups = array();
		foreach ( $lines as $line ) {
			if ( 'text' === $line['type'] ) {
				continue;
			}
			$price = $line['unitPrice'];
			$unit4 = (int) round( ( $price['grossAmount'] ?? $price['netAmount'] ) * 10000 );
			$qty4  = (int) round( (float) $line['quantity'] * 10000 );
			$cents = intdiv( $unit4 * $qty4 + 500000, 1000000 );
			$rate  = (int) round( (float) $price['taxRatePercentage'] * 100 );
			$groups[ $rate ] = ( $groups[ $rate ] ?? 0 ) + $cents;
		}
		$net = 0;
		$tax = 0;
		$out = array();
		foreach ( $groups as $rate => $sum ) {
			if ( 'gross' === $taxType ) {
				$groupNet = intdiv( $sum * 10000 + intdiv( 10000 + $rate, 2 ), 10000 + $rate );
				$groupTax = $sum - $groupNet;
			} else {
				$groupNet = $sum;
				$groupTax = intdiv( $sum * $rate + 5000, 10000 );
			}
			$net  += $groupNet;
			$tax  += $groupTax;
			$out[] = array( 'taxRatePercentage' => $rate / 100, 'taxAmount' => $groupTax / 100, 'netAmount' => $groupNet / 100 );
		}
		return array(
			'totalNetAmount'   => $net / 100,
			'totalGrossAmount' => ( $net + $tax ) / 100,
			'totalTaxAmount'   => $tax / 100,
		) + array( 'taxAmounts' => $out );
	}

	/**
	 * A page of rows with the paging fields.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param ApiFakeRequest                 $request Request.
	 * @return array<string,mixed>
	 */
	private function paged( array $rows, ApiFakeRequest $request ): array {
		$size  = max( 1, min( 250, (int) ( $request->query['size'] ?? 25 ) ) );
		$page  = max( 0, (int) ( $request->query['page'] ?? 0 ) );
		$pages = max( 1, (int) ceil( count( $rows ) / $size ) );
		return array(
			'content'       => array_values( array_slice( $rows, $page * $size, $size ) ),
			'totalPages'    => $pages,
			'totalElements' => count( $rows ),
			'size'          => $size,
			'number'        => $page,
			'first'         => 0 === $page,
			'last'          => $page >= $pages - 1,
		);
	}

	/**
	 * The create answer.
	 *
	 * @param string $id   New id.
	 * @param string $path Resource path.
	 * @return array<string,mixed>
	 */
	private function created( string $id, string $path ): array {
		return array(
			'id'          => $id,
			'resourceUri' => 'https://api.lexware.io/v1/' . $path . '/' . $id,
			'createdDate' => '2026-11-02T10:00:00.000+01:00',
			'updatedDate' => '2026-11-02T10:00:00.000+01:00',
			'version'     => 1,
		);
	}

	/**
	 * A 406 with an IssueList.
	 *
	 * @param string $key    Issue key.
	 * @param string $source Where the problem is, as a field path.
	 */
	private function invalid( string $key, string $source = 'request' ): HttpResponse {
		return FakeHttpClient::json( array( 'IssueList' => array( array( 'i18nKey' => $key, 'source' => $source, 'type' => 'validation_failure' ) ) ), 406 );
	}

	/**
	 * A UUID-shaped id.
	 */
	private function uuid(): string {
		$hex = bin2hex( random_bytes( 16 ) );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}
