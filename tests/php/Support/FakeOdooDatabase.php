<?php
/**
 * A tiny in-memory Odoo database for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Implements only what the Odoo connector calls, with the behaviour that matters: many2one values read back as [id, name],
 * empty text as false, sale order totals computed from lines and taxes, the field names that changed between versions
 * (tax_id and product_uom before 19, tax_ids and product_uom_id from 19), the warehouse context key (warehouse before 18,
 * warehouse_id from 18), has_access from 18, the invoice and payment wizards, credit notes, and the Saudi e-invoicing fields.
 * It is not a model of Odoo; it is just enough Odoo for the connector's behaviour to be tested end to end.
 */
final class FakeOdooDatabase {

	/**
	 * Records by model and id.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $records = array();

	/**
	 * Operations the sync user is not allowed to do, as "model:operation".
	 *
	 * @var array<string,bool>
	 */
	public array $denied = array();

	/**
	 * A fault raised by the next action_confirm (to test a failure after the order was created).
	 *
	 * @var FakeOdooFault|null
	 */
	public ?FakeOdooFault $confirmFault = null;

	/**
	 * Whether deliveries carry the sale order link (sale_id, from the sale_stock module).
	 *
	 * @var bool
	 */
	public bool $saleLink = true;

	/**
	 * How the Saudi e-invoicing service answers: accept or reject.
	 *
	 * @var string
	 */
	public string $zatcaOutcome = 'accept';

	/**
	 * Every call made, for assertions: model, method, ids, params, context.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $calls = array();

	/**
	 * Last id given per model.
	 *
	 * @var array<string,int>
	 */
	private array $last = array();

	/**
	 * Counters for document numbers.
	 *
	 * @var array<string,int>
	 */
	private array $counters = array(
		'order'   => 489,
		'invoice' => 0,
		'refund'  => 0,
		'picking' => 0,
	);

	/**
	 * Fields that point at other records (many2one): model => field => comodel.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const MANY2ONE = array(
		'sale.order'          => array(
			'partner_id'          => 'res.partner',
			'partner_shipping_id' => 'res.partner',
			'partner_invoice_id'  => 'res.partner',
			'currency_id'         => 'res.currency',
			'company_id'          => 'res.company',
			'payment_term_id'     => 'account.payment.term',
			'pricelist_id'        => 'product.pricelist',
			'warehouse_id'        => 'stock.warehouse',
		),
		'sale.order.line'     => array(
			'order_id'   => 'sale.order',
			'product_id' => 'product.product',
		),
		'res.partner'         => array(
			'parent_id'  => 'res.partner',
			'state_id'   => 'res.country.state',
			'country_id' => 'res.country',
		),
		'account.move'        => array(
			'partner_id'        => 'res.partner',
			'currency_id'       => 'res.currency',
			'reversed_entry_id' => 'account.move',
		),
		'account.move.line'   => array(
			'move_id'    => 'account.move',
			'product_id' => 'product.product',
		),
		'stock.picking'       => array(
			'carrier_id' => 'delivery.carrier',
			'sale_id'    => 'sale.order',
		),
		'res.company'         => array(
			'currency_id' => 'res.currency',
			'country_id'  => 'res.country',
		),
		'res.users'           => array( 'company_id' => 'res.company' ),
		'res.country.state'   => array( 'country_id' => 'res.country' ),
		'product.pricelist'   => array( 'currency_id' => 'res.currency' ),
		'stock.warehouse'     => array( 'company_id' => 'res.company' ),
		'account.tax'         => array( 'company_id' => 'res.company' ),
		'res.users.apikeys'   => array( 'user_id' => 'res.users' ),
	);

	/**
	 * Fields that hold lists of ids (one2many and many2many).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TO_MANY = array(
		'sale.order'      => array( 'order_line', 'invoice_ids' ),
		'sale.order.line' => array( 'tax_id', 'tax_ids' ),
		'account.move'    => array( 'invoice_line_ids' ),
		'account.move.line' => array( 'tax_ids' ),
		'res.users'       => array( 'company_ids' ),
	);

	/**
	 * Models whose records can be archived (the "active" field filters searches).
	 *
	 * @var array<int,string>
	 */
	private const ARCHIVABLE = array( 'res.partner', 'product.product', 'account.tax', 'stock.warehouse', 'product.pricelist', 'account.payment.term' );

	/**
	 * Plain fields per model (many2one and to-many fields are added from the tables above).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PLAIN = array(
		'sale.order'             => array( 'id', 'name', 'state', 'client_order_ref', 'date_order', 'origin', 'note', 'amount_total', 'locked' ),
		'sale.order.line'        => array( 'id', 'name', 'product_uom_qty', 'price_unit', 'discount', 'sequence', 'display_type' ),
		'res.partner'            => array( 'id', 'name', 'email', 'phone', 'street', 'street2', 'city', 'zip', 'vat', 'company_name', 'type', 'is_company', 'active', 'display_name' ),
		'product.product'        => array( 'id', 'name', 'default_code', 'barcode', 'type', 'list_price', 'sale_ok', 'purchase_ok', 'active', 'qty_available', 'free_qty' ),
		'account.tax'            => array( 'id', 'name', 'amount', 'amount_type', 'type_tax_use', 'active' ),
		'account.payment.term'   => array( 'id', 'name', 'active' ),
		'product.pricelist'      => array( 'id', 'name', 'active' ),
		'stock.warehouse'        => array( 'id', 'name', 'code', 'sequence', 'active' ),
		'account.move'           => array( 'id', 'name', 'state', 'payment_state', 'move_type', 'ref', 'invoice_origin', 'invoice_date', 'amount_total', 'amount_residual' ),
		'account.move.line'      => array( 'id', 'name', 'quantity', 'price_unit' ),
		'stock.picking'          => array( 'id', 'name', 'state', 'date_done', 'origin', 'picking_type_code' ),
		'res.company'            => array( 'id', 'name' ),
		'res.users'              => array( 'id', 'login', 'name' ),
		'res.country'            => array( 'id', 'name', 'code' ),
		'res.country.state'      => array( 'id', 'name', 'code' ),
		'res.currency'           => array( 'id', 'name', 'decimal_places' ),
		'res.users.apikeys'      => array( 'id', 'name', 'scope', 'expiration_date' ),
		'delivery.carrier'       => array( 'id', 'name' ),
		'stock.quant'            => array( 'id' ),
		'sale.advance.payment.inv' => array( 'id', 'advance_payment_method' ),
		'account.payment.register' => array( 'id', 'communication', 'journal_id', 'amount', 'payment_date' ),
	);

	/**
	 * @param int    $major           Major version: 17, 18 or 19.
	 * @param bool   $stock           Whether the Inventory app is installed.
	 * @param bool   $delivery        Whether the Delivery module (carrier and tracking fields) is installed.
	 * @param bool   $zatca           Whether the Saudi e-invoicing module is installed.
	 * @param string $currency        ISO code of the company currency.
	 * @param string $companyCountry  ISO code of the company country.
	 */
	public function __construct( public readonly int $major = 18, public readonly bool $stock = true, public readonly bool $delivery = true, public readonly bool $zatca = false, string $currency = 'AED', string $companyCountry = 'AE' ) {
		foreach ( array( 'AED', 'USD', 'KWD', 'SAR' ) as $code ) {
			$this->insert( 'res.currency', array( 'name' => $code, 'decimal_places' => 'KWD' === $code ? 3 : 2 ) );
		}
		foreach ( array( 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'US' => 'United States', 'KW' => 'Kuwait' ) as $code => $name ) {
			$this->insert( 'res.country', array( 'name' => $name, 'code' => $code ) );
		}
		$this->insert( 'res.country.state', array( 'name' => 'Dubai', 'code' => 'DU', 'country_id' => $this->idOf( 'res.country', 'code', 'AE' ) ) );
		$this->insert( 'res.country.state', array( 'name' => 'Riyadh', 'code' => 'RY', 'country_id' => $this->idOf( 'res.country', 'code', 'SA' ) ) );
		$this->insert(
			'res.company',
			array(
				'name'        => 'Cedar & Stone Trading LLC',
				'currency_id' => $this->idOf( 'res.currency', 'name', $currency ),
				'country_id'  => $this->idOf( 'res.country', 'code', $companyCountry ),
				'l10n_sa_edi_is_production' => false,
				'l10n_sa_api_mode' => 'sandbox',
			)
		);
		$this->insert( 'res.users', array( 'login' => '__system__', 'name' => 'OdooBot', 'company_id' => 1, 'company_ids' => array( 1 ) ) );
		$this->insert( 'res.users', array( 'login' => 'sync@example.com', 'name' => 'Sync Bot', 'company_id' => 1, 'company_ids' => array( 1 ) ) );
		if ( $stock ) {
			$this->insert( 'stock.warehouse', array( 'name' => 'Dubai Main', 'code' => 'DXB', 'sequence' => 1, 'active' => true, 'company_id' => 1 ) );
			$this->insert( 'stock.warehouse', array( 'name' => 'Riyadh 3PL', 'code' => 'RUH', 'sequence' => 2, 'active' => true, 'company_id' => 1 ) );
		}
		$this->insert( 'account.payment.term', array( 'name' => 'Immediate Payment', 'active' => true ) );
		$this->insert( 'product.pricelist', array( 'name' => 'Default', 'currency_id' => $this->idOf( 'res.currency', 'name', $currency ), 'active' => true ) );
	}

	/* ------------------------------------------------------------ seeding */

	/**
	 * Insert a record without any business logic.
	 *
	 * @param string              $model  Model.
	 * @param array<string,mixed> $values Values.
	 * @return int Id.
	 */
	public function insert( string $model, array $values ): int {
		$id                           = ( $this->last[ $model ] ?? 0 ) + 1;
		$this->last[ $model ]         = $id;
		$values['id']                 = $id;
		$this->records[ $model ][ $id ] = $values;
		return $id;
	}

	/**
	 * Add a product.
	 *
	 * @param string            $code     Internal reference.
	 * @param string            $name     Name.
	 * @param float             $price    Sales price.
	 * @param array<int,float>  $onHand   Quantity on hand per warehouse id.
	 * @param array<int,float>  $reserved Reserved quantity per warehouse id.
	 * @param bool              $stocked  Whether the product keeps a stock quantity.
	 * @param bool              $active   Whether the product is active.
	 * @return int Product id.
	 */
	public function addProduct( string $code, string $name, float $price = 10.0, array $onHand = array(), array $reserved = array(), bool $stocked = true, bool $active = true ): int {
		return $this->insert(
			'product.product',
			array(
				'name'         => $name,
				'default_code' => $code,
				'type'         => $stocked ? ( $this->major >= 18 ? 'consu' : 'product' ) : 'service',
				'is_storable'  => $stocked,
				'list_price'   => $price,
				'sale_ok'      => true,
				'active'       => $active,
				'_on_hand'     => $onHand,
				'_reserved'    => $reserved,
			)
		);
	}

	/**
	 * Add a sale tax.
	 *
	 * @param string $name    Name.
	 * @param float  $percent Rate in percent.
	 * @param string $use     Use: sale, purchase or none.
	 * @return int Tax id.
	 */
	public function addTax( string $name, float $percent, string $use = 'sale' ): int {
		return $this->insert( 'account.tax', array( 'name' => $name, 'amount' => $percent, 'amount_type' => 'percent', 'type_tax_use' => $use, 'active' => true, 'company_id' => 1 ) );
	}

	/**
	 * Add a customer.
	 *
	 * @param string              $name   Name.
	 * @param string              $email  Email.
	 * @param array<string,mixed> $extra  Other fields.
	 * @return int Partner id.
	 */
	public function addPartner( string $name, string $email, array $extra = array() ): int {
		return $this->insert( 'res.partner', $extra + array( 'name' => $name, 'email' => $email, 'type' => 'contact', 'is_company' => false, 'active' => true ) );
	}

	/**
	 * Add a finished outgoing delivery for a sale order.
	 *
	 * @param int    $orderId  Sale order id.
	 * @param string $carrier  Carrier name.
	 * @param string $tracking Tracking number.
	 * @param string $doneAt   "Y-m-d H:i:s" UTC.
	 * @return int Picking id.
	 */
	public function addDelivery( int $orderId, string $carrier, string $tracking, string $doneAt ): int {
		$number  = ++$this->counters['picking'];
		$values  = array(
			'name'              => sprintf( 'WH/OUT/%05d', $number ),
			'state'             => 'done',
			'date_done'         => $doneAt,
			'origin'            => (string) $this->records['sale.order'][ $orderId ]['name'],
			'picking_type_code' => 'outgoing',
		);
		if ( $this->saleLink ) {
			$values['sale_id'] = $orderId;
		}
		if ( $this->delivery ) {
			$values['carrier_id']           = '' === $carrier ? false : $this->insert( 'delivery.carrier', array( 'name' => $carrier ) );
			$values['carrier_tracking_ref'] = $tracking;
		}
		return $this->insert( 'stock.picking', $values );
	}

	/**
	 * Forbid an operation for the sync user.
	 *
	 * @param string $model     Model.
	 * @param string $operation read, create, write or unlink.
	 */
	public function deny( string $model, string $operation ): void {
		$this->denied[ $model . ':' . $operation ] = true;
	}

	/**
	 * Id of the first record whose field has a value.
	 *
	 * @param string $model Model.
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public function idOf( string $model, string $field, mixed $value ): int {
		foreach ( $this->records[ $model ] ?? array() as $id => $record ) {
			if ( ( $record[ $field ] ?? null ) === $value ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * All records of a model, for assertions.
	 *
	 * @param string $model Model.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $model ): array {
		return array_values( $this->records[ $model ] ?? array() );
	}

	/* ------------------------------------------------------------ the call surface */

	/**
	 * Whether a model exists on this server.
	 *
	 * @param string $model Model.
	 */
	public function hasModel( string $model ): bool {
		if ( str_starts_with( $model, 'stock.' ) ) {
			return $this->stock;
		}
		if ( 'delivery.carrier' === $model ) {
			return $this->delivery;
		}
		return isset( self::PLAIN[ $model ] );
	}

	/**
	 * Call a method. Raises FakeOdooFault for anything Odoo would refuse.
	 *
	 * @param string              $model   Model.
	 * @param string              $method  Method.
	 * @param array<int,int>|null $ids     Record ids, or null for a model-level call.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context.
	 * @return mixed
	 *
	 * @throws FakeOdooFault For refusals.
	 */
	public function call( string $model, string $method, ?array $ids, array $params, array $context ): mixed {
		$this->calls[] = array(
			'model'   => $model,
			'method'  => $method,
			'ids'     => $ids,
			'params'  => $params,
			'context' => $context,
		);
		if ( ! $this->hasModel( $model ) ) {
			throw new FakeOdooFault( 'odoo.exceptions.UserError', "Object {$model} doesn't exist", 404 );
		}
		switch ( $method ) {
			case 'search_read':
				$this->guard( $model, 'read' );
				return $this->searchRead( $model, (array) ( $params['domain'] ?? array() ), (array) ( $params['fields'] ?? array() ), $params, $context );
			case 'search_count':
				$this->guard( $model, 'read' );
				return count( $this->search( $model, (array) ( $params['domain'] ?? array() ) ) );
			case 'read':
				$this->guard( $model, 'read' );
				return $this->read( $model, (array) $ids, (array) ( $params['fields'] ?? array() ), $context );
			case 'create':
				$this->guard( $model, 'create' );
				return $this->createMany( $model, (array) ( $params['vals_list'] ?? array() ), $context );
			case 'write':
				$this->guard( $model, 'write' );
				return $this->write( $model, (array) $ids, (array) ( $params['vals'] ?? array() ) );
			case 'fields_get':
				return $this->fieldsGet( $model, (array) ( $params['allfields'] ?? array() ) );
			case 'has_access':
				if ( $this->major < 18 ) {
					throw new FakeOdooFault( 'werkzeug.exceptions.NotFound', "The method 'has_access' does not exist on the model '{$model}'", 404 );
				}
				return ! isset( $this->denied[ $model . ':' . (string) ( $params['operation'] ?? '' ) ] );
			case 'check_access_rights':
				return ! isset( $this->denied[ $model . ':' . (string) ( $params['operation'] ?? '' ) ] );
			case 'context_get':
				return array( 'lang' => 'en_US', 'tz' => 'Asia/Dubai', 'uid' => 2 );
			case 'action_confirm':
				$this->guard( $model, 'write' );
				return $this->confirm( (array) $ids );
			case 'action_cancel':
				$this->guard( $model, 'write' );
				return $this->cancel( (array) $ids );
			case 'create_invoices':
				$this->guard( 'account.move', 'create' );
				return $this->createInvoices( (array) $ids, $context );
			case 'action_post':
				$this->guard( $model, 'write' );
				return $this->post( (array) $ids );
			case 'action_process_edi_web_services':
				return $this->processEdi( (array) $ids );
			case 'action_create_payments':
				return $this->createPayments( (array) $ids, $context );
		}
		throw new FakeOdooFault( 'werkzeug.exceptions.NotFound', "The method '{$method}' does not exist on the model '{$model}'", 404 );
	}

	/**
	 * Raise an access error when the operation is denied.
	 *
	 * @param string $model     Model.
	 * @param string $operation Operation.
	 *
	 * @throws FakeOdooFault When denied.
	 */
	private function guard( string $model, string $operation ): void {
		if ( isset( $this->denied[ $model . ':' . $operation ] ) ) {
			throw FakeOdooFault::access( "You are not allowed to {$operation} documents of type '{$model}'." );
		}
	}

	/* ------------------------------------------------------------ reading */

	/**
	 * Search and read.
	 *
	 * @param string              $model   Model.
	 * @param array<int,mixed>    $domain  Domain.
	 * @param array<int,string>   $fields  Fields.
	 * @param array<string,mixed> $params  offset, limit, order.
	 * @param array<string,mixed> $context Context.
	 * @return array<int,array<string,mixed>>
	 */
	private function searchRead( string $model, array $domain, array $fields, array $params, array $context ): array {
		$ids = $this->search( $model, $domain );
		$ids = $this->order( $model, $ids, (string) ( $params['order'] ?? '' ) );
		$ids = array_slice( $ids, (int) ( $params['offset'] ?? 0 ), isset( $params['limit'] ) ? (int) $params['limit'] : null );
		return $this->read( $model, $ids, $fields, $context );
	}

	/**
	 * Ids matching a domain (archived records are left out, as Odoo does).
	 *
	 * @param string           $model  Model.
	 * @param array<int,mixed> $domain Domain.
	 * @return array<int,int>
	 */
	private function search( string $model, array $domain ): array {
		$matcher = new FakeOdooDomain( fn( array $record, string $path ): mixed => $this->resolve( $model, $record, $path ) );
		$ids     = array();
		foreach ( $this->records[ $model ] ?? array() as $id => $record ) {
			if ( in_array( $model, self::ARCHIVABLE, true ) && false === ( $record['active'] ?? true ) ) {
				continue;
			}
			if ( $matcher->matches( $record, $domain ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Sort ids by an Odoo order string such as "date_done asc, id asc".
	 *
	 * @param string           $model Model.
	 * @param array<int,int>   $ids   Ids.
	 * @param string           $order Order string.
	 * @return array<int,int>
	 */
	private function order( string $model, array $ids, string $order ): array {
		if ( '' === trim( $order ) ) {
			return $ids;
		}
		$keys = array();
		foreach ( explode( ',', $order ) as $part ) {
			$bits   = preg_split( '/\s+/', trim( $part ) );
			$keys[] = array( (string) $bits[0], isset( $bits[1] ) && 'desc' === strtolower( $bits[1] ) ? -1 : 1 );
		}
		usort(
			$ids,
			function ( int $a, int $b ) use ( $model, $keys ): int {
				foreach ( $keys as $key ) {
					$left  = $this->resolve( $model, $this->records[ $model ][ $a ], $key[0] );
					$right = $this->resolve( $model, $this->records[ $model ][ $b ], $key[0] );
					$cmp   = is_numeric( $left ) && is_numeric( $right ) ? (float) $left <=> (float) $right : strcmp( (string) $left, (string) $right );
					if ( 0 !== $cmp ) {
						return $cmp * $key[1];
					}
				}
				return 0;
			}
		);
		return $ids;
	}

	/**
	 * The value at a path for a record, crossing many2one fields. Many2one values are ids, empty values are false.
	 *
	 * @param string              $model  Model.
	 * @param array<string,mixed> $record Record.
	 * @param string              $path   Field or field.subfield.
	 */
	private function resolve( string $model, array $record, string $path ): mixed {
		$parts = explode( '.', $path );
		$field = array_shift( $parts );
		$value = $this->value( $model, $record, (string) $field, array() );
		if ( array() === $parts ) {
			return $value;
		}
		$comodel = self::MANY2ONE[ $model ][ $field ] ?? '';
		if ( '' === $comodel || ! is_int( $value ) || ! isset( $this->records[ $comodel ][ $value ] ) ) {
			return false;
		}
		return $this->resolve( $comodel, $this->records[ $comodel ][ $value ], implode( '.', $parts ) );
	}

	/**
	 * Read records.
	 *
	 * @param string              $model   Model.
	 * @param array<int,int>      $ids     Ids.
	 * @param array<int,string>   $fields  Fields; empty means the common ones.
	 * @param array<string,mixed> $context Context.
	 * @return array<int,array<string,mixed>>
	 */
	private function read( string $model, array $ids, array $fields, array $context ): array {
		$known  = $this->fieldNames( $model );
		$fields = array() === $fields ? $known : $fields;
		foreach ( $fields as $field ) {
			if ( ! in_array( $field, $known, true ) ) {
				throw FakeOdooFault::invalidField( "Invalid field '{$field}' on model '{$model}'" );
			}
		}
		$rows = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $this->records[ $model ][ $id ] ) ) {
				throw new FakeOdooFault( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.', 404 );
			}
			$record = $this->records[ $model ][ $id ];
			$row    = array( 'id' => $id );
			foreach ( $fields as $field ) {
				$row[ $field ] = $this->present( $model, $field, $this->value( $model, $record, $field, $context ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Format a stored value the way Odoo's read does.
	 *
	 * @param string $model Model.
	 * @param string $field Field.
	 * @param mixed  $value Raw value.
	 */
	private function present( string $model, string $field, mixed $value ): mixed {
		$comodel = self::MANY2ONE[ $model ][ $field ] ?? '';
		if ( '' !== $comodel ) {
			return is_int( $value ) && isset( $this->records[ $comodel ][ $value ] ) ? array( $value, $this->display( $comodel, $value ) ) : false;
		}
		if ( in_array( $field, self::TO_MANY[ $model ] ?? array(), true ) ) {
			return array_values( (array) $value );
		}
		return '' === $value || null === $value ? false : $value;
	}

	/**
	 * The raw or computed value of a field.
	 *
	 * @param string              $model   Model.
	 * @param array<string,mixed> $record  Record.
	 * @param string              $field   Field.
	 * @param array<string,mixed> $context Context.
	 */
	private function value( string $model, array $record, string $field, array $context ): mixed {
		if ( 'sale.order' === $model && 'invoice_ids' === $field ) {
			return $this->invoiceIdsOf( (int) $record['id'] );
		}
		if ( 'product.product' === $model && in_array( $field, array( 'qty_available', 'free_qty' ), true ) ) {
			return $this->quantity( $record, $field, $context );
		}
		if ( 'account.move' === $model && 'edi_state' === $field ) {
			return $record['_edi_state'] ?? false;
		}
		if ( 'account.move' === $model && 'edi_error_message' === $field ) {
			return $record['_edi_error'] ?? false;
		}
		if ( 'account.move' === $model && 'l10n_sa_qr_code_str' === $field ) {
			return 'posted' === ( $record['state'] ?? '' ) && isset( $record['_edi_state'] ) ? 'QR-' . $record['name'] : '';
		}
		if ( 'account.move' === $model && 'l10n_sa_confirmation_datetime' === $field ) {
			return $record['_confirmed_at'] ?? false;
		}
		if ( 'account.move' === $model && 'l10n_sa_uuid' === $field ) {
			return $record['_uuid'] ?? false;
		}
		if ( 'res.company' === $model && 'l10n_sa_edi_is_production' === $field ) {
			return $record['l10n_sa_edi_is_production'] ?? false;
		}
		return $record[ $field ] ?? false;
	}

	/**
	 * Quantity on hand or free quantity, limited to the warehouses in the context.
	 *
	 * @param array<string,mixed> $record  Product record.
	 * @param string              $field   qty_available or free_qty.
	 * @param array<string,mixed> $context Context.
	 */
	private function quantity( array $record, string $field, array $context ): float {
		$key        = $this->major >= 18 ? 'warehouse_id' : 'warehouse';
		$warehouses = isset( $context[ $key ] ) ? (array) $context[ $key ] : array_keys( (array) ( $record['_on_hand'] ?? array() ) );
		$total      = 0.0;
		foreach ( $warehouses as $warehouse ) {
			$onHand   = (float) ( $record['_on_hand'][ $warehouse ] ?? 0.0 );
			$reserved = (float) ( $record['_reserved'][ $warehouse ] ?? 0.0 );
			$total   += 'free_qty' === $field ? $onHand - $reserved : $onHand;
		}
		return $total;
	}

	/**
	 * Display name of a record.
	 *
	 * @param string $model Model.
	 * @param int    $id    Id.
	 */
	private function display( string $model, int $id ): string {
		$record = $this->records[ $model ][ $id ] ?? array();
		return (string) ( $record['name'] ?? $id );
	}

	/**
	 * Field names a model has in this version and with these modules installed.
	 *
	 * @param string $model Model.
	 * @return array<int,string>
	 */
	public function fieldNames( string $model ): array {
		$names = array_merge( self::PLAIN[ $model ] ?? array(), array_keys( self::MANY2ONE[ $model ] ?? array() ), self::TO_MANY[ $model ] ?? array() );
		if ( 'sale.order.line' === $model ) {
			$names = array_diff( $names, array( $this->major >= 19 ? 'tax_id' : 'tax_ids' ) );
		}
		if ( 'product.product' === $model && $this->major < 18 ) {
			$names[] = 'type';
		}
		if ( 'product.product' === $model && $this->major >= 18 ) {
			$names[] = 'is_storable';
		}
		if ( 'stock.picking' === $model && ! $this->saleLink ) {
			$names = array_diff( $names, array( 'sale_id' ) );
		}
		if ( 'stock.picking' === $model && ! $this->delivery ) {
			$names = array_diff( $names, array( 'carrier_id' ) );
		}
		if ( 'stock.picking' === $model && $this->delivery ) {
			$names[] = 'carrier_tracking_ref';
		}
		if ( 'account.move' === $model ) {
			$names = array_merge( $names, array( 'edi_state', 'edi_error_message', 'edi_document_ids' ) );
			if ( $this->zatca ) {
				$names = array_merge( $names, array( 'l10n_sa_uuid', 'l10n_sa_qr_code_str', 'l10n_sa_confirmation_datetime' ) );
				if ( $this->major >= 19 ) {
					// The reason of a credit note is a field of account.move only from Odoo 19.
					$names[] = 'l10n_sa_reason';
				}
			} else {
				$names = array_diff( $names, array( 'edi_state', 'edi_error_message', 'edi_document_ids' ) );
			}
		}
		if ( 'res.company' === $model && $this->zatca ) {
			$names[] = 'l10n_sa_api_mode';
			if ( $this->major >= 19 ) {
				// The production flag is a field of res.company only from Odoo 19; before it the API mode says it.
				$names[] = 'l10n_sa_edi_is_production';
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Field definitions for the names asked about.
	 *
	 * @param string            $model Model.
	 * @param array<int,string> $names Names; empty means all.
	 * @return array<string,array<string,string>>
	 */
	private function fieldsGet( string $model, array $names ): array {
		$result = array();
		foreach ( $this->fieldNames( $model ) as $name ) {
			if ( array() === $names || in_array( $name, $names, true ) ) {
				$result[ $name ] = array( 'type' => isset( self::MANY2ONE[ $model ][ $name ] ) ? 'many2one' : 'char' );
			}
		}
		return $result;
	}

	/* ------------------------------------------------------------ writing */

	/**
	 * Create records; returns their ids.
	 *
	 * @param string                           $model     Model.
	 * @param array<int,array<string,mixed>>   $valuesList Values for each record.
	 * @param array<string,mixed>              $context   Context.
	 * @return array<int,int>
	 */
	private function createMany( string $model, array $valuesList, array $context ): array {
		$ids = array();
		foreach ( $valuesList as $values ) {
			$ids[] = $this->createOne( $model, (array) $values, $context );
		}
		return $ids;
	}

	/**
	 * Create one record with the checks Odoo makes.
	 *
	 * @param string              $model   Model.
	 * @param array<string,mixed> $values  Values.
	 * @param array<string,mixed> $context Context.
	 */
	private function createOne( string $model, array $values, array $context ): int {
		$known = $this->fieldNames( $model );
		foreach ( array_keys( $values ) as $field ) {
			if ( ! in_array( $field, $known, true ) && 'order_line' !== $field ) {
				throw FakeOdooFault::invalidField( "Invalid field '{$field}' in create() on model '{$model}'" );
			}
		}
		switch ( $model ) {
			case 'res.partner':
				return $this->createPartner( $values );
			case 'sale.order':
				return $this->createOrder( $values );
			case 'sale.order.line':
				return $this->createOrderLine( $values );
			case 'account.move':
				return $this->createMove( $values );
			case 'product.product':
				$values += array( 'active' => true, 'sale_ok' => true, '_on_hand' => array(), '_reserved' => array() );
				return $this->insert( $model, $values );
			case 'sale.advance.payment.inv':
			case 'account.payment.register':
				return $this->insert( $model, $values + array( '_context' => $context ) );
		}
		return $this->insert( $model, $values );
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	private function createPartner( array $values ): int {
		if ( '' === trim( (string) ( $values['name'] ?? '' ) ) ) {
			throw FakeOdooFault::validation( "Missing required value for the field 'name' (name)." );
		}
		if ( 'INVALID-VAT' === ( $values['vat'] ?? '' ) ) {
			throw FakeOdooFault::validation( 'The VAT number [INVALID-VAT] for the customer does not seem to be valid.' );
		}
		return $this->insert( 'res.partner', $values + array( 'active' => true, 'type' => 'contact', 'is_company' => false ) );
	}

	/**
	 * Create a sale order with its lines.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	private function createOrder( array $values ): int {
		if ( empty( $values['partner_id'] ) ) {
			throw FakeOdooFault::validation( "Missing required value for the field 'partner_id' (partner_id)." );
		}
		if ( isset( $values['partner_id'] ) && ! isset( $this->records['res.partner'][ (int) $values['partner_id'] ] ) ) {
			throw new FakeOdooFault( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.', 404 );
		}
		$lines = (array) ( $values['order_line'] ?? array() );
		unset( $values['order_line'] );
		$currency = isset( $values['pricelist_id'] )
			? (int) $this->records['product.pricelist'][ (int) $values['pricelist_id'] ]['currency_id']
			: (int) $this->records['res.company'][1]['currency_id'];
		$id       = $this->insert(
			'sale.order',
			$values + array(
				'name'        => sprintf( 'S%05d', ++$this->counters['order'] ),
				'state'       => 'draft',
				'date_order'  => gmdate( 'Y-m-d H:i:s' ),
				'currency_id' => $currency,
				'company_id'  => 1,
				'locked'      => false,
			)
		);
		foreach ( $lines as $command ) {
			if ( is_array( $command ) && 0 === ( $command[0] ?? null ) ) {
				$this->createOrderLine( (array) $command[2] + array( 'order_id' => $id ) );
			}
		}
		$this->recomputeOrder( $id );
		return $id;
	}

	/**
	 * Create a sale order line, refusing a line without a product.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	private function createOrderLine( array $values ): int {
		if ( empty( $values['product_id'] ) ) {
			throw new FakeOdooFault( 'psycopg2.errors.CheckViolation', 'new row for relation "sale_order_line" violates check constraint "sale_order_line_accountable_required_fields"', 500 );
		}
		if ( ! isset( $this->records['product.product'][ (int) $values['product_id'] ] ) ) {
			throw new FakeOdooFault( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.', 404 );
		}
		$taxField = $this->major >= 19 ? 'tax_ids' : 'tax_id';
		if ( isset( $values[ $taxField ] ) ) {
			$values[ $taxField ] = $this->commandIds( (array) $values[ $taxField ] );
		}
		return $this->insert( 'sale.order.line', $values );
	}

	/**
	 * Create an invoice or credit note with its lines.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	private function createMove( array $values ): int {
		if ( 'out_refund' === ( $values['move_type'] ?? '' ) && empty( $values['partner_id'] ) ) {
			throw FakeOdooFault::validation( "Missing required value for the field 'partner_id'." );
		}
		$lines = (array) ( $values['invoice_line_ids'] ?? array() );
		unset( $values['invoice_line_ids'] );
		$id = $this->insert(
			'account.move',
			$values + array(
				'name'          => '/',
				'state'         => 'draft',
				'payment_state' => 'not_paid',
				'currency_id'   => (int) $this->records['res.company'][1]['currency_id'],
			)
		);
		foreach ( $lines as $command ) {
			if ( is_array( $command ) && 0 === ( $command[0] ?? null ) ) {
				$line = (array) $command[2];
				if ( isset( $line['tax_ids'] ) ) {
					$line['tax_ids'] = $this->commandIds( (array) $line['tax_ids'] );
				}
				$this->insert( 'account.move.line', $line + array( 'move_id' => $id ) );
			}
		}
		$this->recomputeMove( $id );
		return $id;
	}

	/**
	 * The ids in a many2many command list such as [[6, 0, [1, 2]]].
	 *
	 * @param array<int,mixed> $commands Commands.
	 * @return array<int,int>
	 */
	private function commandIds( array $commands ): array {
		$ids = array();
		foreach ( $commands as $command ) {
			if ( is_array( $command ) && 6 === ( $command[0] ?? null ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $command[2] ) );
			}
		}
		return $ids;
	}

	/**
	 * Change records.
	 *
	 * @param string              $model  Model.
	 * @param array<int,int>      $ids    Ids.
	 * @param array<string,mixed> $values Values.
	 */
	private function write( string $model, array $ids, array $values ): bool {
		foreach ( $ids as $id ) {
			if ( ! isset( $this->records[ $model ][ $id ] ) ) {
				throw new FakeOdooFault( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.', 404 );
			}
			$this->records[ $model ][ $id ] = $values + $this->records[ $model ][ $id ];
		}
		return true;
	}

	/* ------------------------------------------------------------ documents */

	/**
	 * Totals of a set of lines in a currency.
	 *
	 * @param array<int,array<string,mixed>> $lines  Lines with price, quantity, discount and tax ids.
	 * @param int                            $places Decimal places.
	 */
	private function total( array $lines, int $places ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$quantity = (float) ( $line['product_uom_qty'] ?? $line['quantity'] ?? 0 );
			$base     = (float) ( $line['price_unit'] ?? 0 ) * $quantity * ( 1 - (float) ( $line['discount'] ?? 0 ) / 100 );
			$tax      = 0.0;
			foreach ( (array) ( $line['tax_ids'] ?? $line['tax_id'] ?? array() ) as $taxId ) {
				$tax += $base * (float) ( $this->records['account.tax'][ (int) $taxId ]['amount'] ?? 0 ) / 100;
			}
			$total += round( $base, $places ) + round( $tax, $places );
		}
		return round( $total, $places );
	}

	/**
	 * Decimal places of a currency record.
	 *
	 * @param int $currencyId Currency id.
	 */
	private function places( int $currencyId ): int {
		return (int) ( $this->records['res.currency'][ $currencyId ]['decimal_places'] ?? 2 );
	}

	/**
	 * Compute and store the total of a sale order.
	 *
	 * @param int $orderId Sale order id.
	 */
	private function recomputeOrder( int $orderId ): void {
		$lines = array_filter( $this->records['sale.order.line'] ?? array(), static fn( array $line ): bool => (int) $line['order_id'] === $orderId );
		$this->records['sale.order'][ $orderId ]['amount_total'] = $this->total( $lines, $this->places( (int) $this->records['sale.order'][ $orderId ]['currency_id'] ) );
		$this->records['sale.order'][ $orderId ]['order_line']   = array_keys( $lines );
	}

	/**
	 * Compute and store the total of an invoice or credit note.
	 *
	 * @param int $moveId Move id.
	 */
	private function recomputeMove( int $moveId ): void {
		$lines = array_filter( $this->records['account.move.line'] ?? array(), static fn( array $line ): bool => (int) $line['move_id'] === $moveId );
		$total = $this->total( $lines, $this->places( (int) $this->records['account.move'][ $moveId ]['currency_id'] ) );
		$this->records['account.move'][ $moveId ]['amount_total']    = $total;
		$this->records['account.move'][ $moveId ]['amount_residual'] = $total;
		$this->records['account.move'][ $moveId ]['invoice_line_ids'] = array_keys( $lines );
	}

	/**
	 * Invoices and credit notes linked to a sale order (credit notes only when made from its lines, as in Odoo).
	 *
	 * @param int $orderId Sale order id.
	 * @return array<int,int>
	 */
	private function invoiceIdsOf( int $orderId ): array {
		$ids = array();
		foreach ( $this->records['account.move'] ?? array() as $id => $move ) {
			if ( (int) ( $move['_sale_order_id'] ?? 0 ) === $orderId ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Confirm quotations.
	 *
	 * @param array<int,int> $ids Sale order ids.
	 */
	private function confirm( array $ids ): bool {
		if ( null !== $this->confirmFault ) {
			$fault              = $this->confirmFault;
			$this->confirmFault = null;
			throw $fault;
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $this->records['sale.order'][ $id ]['state'] ?? '', array( 'draft', 'sent' ), true ) ) {
				throw FakeOdooFault::user( 'Only quotations can be confirmed.' );
			}
			$this->records['sale.order'][ $id ]['state'] = 'sale';
		}
		return true;
	}

	/**
	 * Cancel orders.
	 *
	 * @param array<int,int> $ids Sale order ids.
	 */
	private function cancel( array $ids ): bool {
		foreach ( $ids as $id ) {
			$this->records['sale.order'][ $id ]['state'] = 'cancel';
		}
		return true;
	}

	/**
	 * The invoice wizard: one invoice per sale order, from the order lines.
	 *
	 * @param array<int,int>      $wizardIds Wizard ids.
	 * @param array<string,mixed> $context   Context with active_ids.
	 */
	private function createInvoices( array $wizardIds, array $context ): bool {
		$orderIds = (array) ( $context['active_ids'] ?? $this->records['sale.advance.payment.inv'][ $wizardIds[0] ?? 0 ]['_context']['active_ids'] ?? array() );
		foreach ( $orderIds as $orderId ) {
			$order = $this->records['sale.order'][ (int) $orderId ] ?? null;
			if ( null === $order || 'sale' !== $order['state'] ) {
				throw FakeOdooFault::user( 'There is nothing to invoice! Confirm the order first.' );
			}
			$move = $this->createMove(
				array(
					'move_type'      => 'out_invoice',
					'partner_id'     => (int) $order['partner_id'],
					'currency_id'    => (int) $order['currency_id'],
					'invoice_origin' => (string) $order['name'],
					'ref'            => (string) $order['client_order_ref'],
					'invoice_date'   => gmdate( 'Y-m-d' ),
					'invoice_line_ids' => array_map(
						static fn( array $line ): array => array(
							0,
							0,
							array(
								'name'       => (string) ( $line['name'] ?? '' ),
								'quantity'   => (float) $line['product_uom_qty'],
								'price_unit' => (float) $line['price_unit'],
								'tax_ids'    => array( array( 6, 0, (array) ( $line['tax_ids'] ?? $line['tax_id'] ?? array() ) ) ),
							),
						),
						array_values( array_filter( $this->records['sale.order.line'] ?? array(), static fn( array $line ): bool => (int) $line['order_id'] === (int) $orderId ) )
					),
				)
			);
			$this->records['account.move'][ $move ]['_sale_order_id'] = (int) $orderId;
		}
		return true;
	}

	/**
	 * Post drafts: give them a number and, with the Saudi module, queue them for the tax authority.
	 *
	 * @param array<int,int> $ids Move ids.
	 */
	private function post( array $ids ): bool {
		foreach ( $ids as $id ) {
			$move = $this->records['account.move'][ $id ] ?? null;
			if ( null === $move ) {
				throw new FakeOdooFault( 'odoo.exceptions.MissingError', 'Record does not exist or has been deleted.', 404 );
			}
			if ( 'draft' !== $move['state'] ) {
				continue;
			}
			$refund = 'out_refund' === $move['move_type'];
			$this->records['account.move'][ $id ]['state'] = 'posted';
			$this->records['account.move'][ $id ]['name']  = sprintf( $refund ? 'RINV/2026/%05d' : 'INV/2026/%05d', ++$this->counters[ $refund ? 'refund' : 'invoice' ] );
			if ( $this->zatca ) {
				$this->records['account.move'][ $id ]['_edi_state']    = 'to_send';
				$this->records['account.move'][ $id ]['_uuid']         = 'uuid-' . $id;
				$this->records['account.move'][ $id ]['_confirmed_at'] = gmdate( 'Y-m-d H:i:s' );
			}
		}
		return true;
	}

	/**
	 * The e-invoicing web service step: accept or reject what is waiting.
	 *
	 * @param array<int,int> $ids Move ids.
	 */
	private function processEdi( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( 'to_send' !== ( $this->records['account.move'][ $id ]['_edi_state'] ?? '' ) ) {
				continue;
			}
			if ( 'reject' === $this->zatcaOutcome ) {
				$this->records['account.move'][ $id ]['_edi_error'] = '<p>The buyer&#039;s address is missing.</p>';
			} else {
				$this->records['account.move'][ $id ]['_edi_state'] = 'sent';
				unset( $this->records['account.move'][ $id ]['_edi_error'] );
			}
		}
		return true;
	}

	/**
	 * The payment wizard: marks the invoices in the context as paid.
	 *
	 * @param array<int,int>      $wizardIds Wizard ids.
	 * @param array<string,mixed> $context   Context with active_ids.
	 */
	private function createPayments( array $wizardIds, array $context ): bool {
		$moveIds = (array) ( $context['active_ids'] ?? $this->records['account.payment.register'][ $wizardIds[0] ?? 0 ]['_context']['active_ids'] ?? array() );
		foreach ( $moveIds as $moveId ) {
			if ( 'posted' !== ( $this->records['account.move'][ (int) $moveId ]['state'] ?? '' ) ) {
				throw FakeOdooFault::user( 'You can only register payments for posted journal entries.' );
			}
			$this->records['account.move'][ (int) $moveId ]['payment_state']   = 'paid';
			$this->records['account.move'][ (int) $moveId ]['amount_residual'] = 0.0;
			$this->records['account.move'][ (int) $moveId ]['_payment_memo']   = (string) ( $this->records['account.payment.register'][ $wizardIds[0] ?? 0 ]['communication'] ?? '' );
		}
		return true;
	}
}
