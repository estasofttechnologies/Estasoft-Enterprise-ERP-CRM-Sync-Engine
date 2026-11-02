<?php
/**
 * Looks up the Odoo records an order refers to: products, taxes, countries and so on.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every lookup asks Odoo once per run and remembers the answer, so an order with ten lines does not make ten calls.
 * Create one instance per connector call; it is not meant to live longer than that.
 */
final class OdooCatalog {

	/**
	 * Default internal references of the service products used for shipping, fees, discounts and refunds without lines.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const SERVICE_PRODUCTS = array(
		'shipping' => array( 'SHIPPING', 'Shipping' ),
		'fee'      => array( 'FEE', 'Fee' ),
		'discount' => array( 'DISCOUNT', 'Discount' ),
		'refund'   => array( 'REFUND', 'Refund' ),
	);

	/**
	 * Remembered answers by kind and key.
	 *
	 * @var array<string,array<int|string,int|string>>
	 */
	private array $memo = array();

	/**
	 * Tax details by tax id, remembered for the run.
	 *
	 * @var array<int,array{name:string,rate:int,percent:bool,included:bool}>
	 */
	private array $details = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooOptions $options Connection options.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooOptions $options ) {
	}

	/**
	 * Product ids by internal reference (default_code). The oldest product wins when a reference is used twice.
	 *
	 * @param array<int,string> $codes References, as typed in WooCommerce.
	 * @return array<string,int> Reference => product id; references Odoo does not know are missing.
	 *
	 * @throws OdooException On failure.
	 */
	public function productIdsByReference( array $codes ): array {
		$codes   = array_values( array_unique( array_filter( array_map( 'trim', $codes ), static fn( string $code ): bool => '' !== $code ) ) );
		$missing = array_values( array_filter( $codes, fn( string $code ): bool => ! isset( $this->memo['product'][ $code ] ) ) );
		if ( array() !== $missing ) {
			$records = $this->client->searchRead( 'product.product', array_merge( array( array( 'default_code', 'in', $missing ) ), $this->client->companyDomain() ), array( 'default_code' ), array( 'order' => 'id asc' ) );
			foreach ( $records as $record ) {
				$code = OdooRecord::text( $record['default_code'] ?? '' );
				$id   = OdooRecord::id( $record['id'] ?? 0 );
				if ( '' !== $code && $id > 0 && ! isset( $this->memo['product'][ $code ] ) ) {
					$this->memo['product'][ $code ] = $id;
				}
			}
		}
		$found = array();
		foreach ( $codes as $code ) {
			if ( isset( $this->memo['product'][ $code ] ) ) {
				$found[ $code ] = (int) $this->memo['product'][ $code ];
			}
		}
		return $found;
	}

	/**
	 * Which of some product ids exist.
	 *
	 * @param array<int,int> $ids Product ids.
	 * @return array<int,bool> Id => true for the ids that exist.
	 *
	 * @throws OdooException On failure.
	 */
	public function existingProductIds( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$exists = array();
		foreach ( $this->client->searchRead( 'product.product', array_merge( array( array( 'id', 'in', $ids ) ), $this->client->companyDomain() ), array( 'id' ) ) as $record ) {
			$exists[ OdooRecord::id( $record['id'] ?? 0 ) ] = true;
		}
		return $exists;
	}

	/**
	 * The service product for shipping, a fee, a discount or a refund. Found by internal reference; created when missing
	 * and when the option create_service_products allows it.
	 *
	 * @param string $kind shipping, fee, discount or refund.
	 * @return int Product id.
	 *
	 * @throws OdooException When the product is missing and cannot be created.
	 */
	public function serviceProductId( string $kind ): int {
		$defaults = self::SERVICE_PRODUCTS[ $kind ] ?? self::SERVICE_PRODUCTS['fee'];
		$code     = $this->options->text( $kind . '_product', $defaults[0] );
		$found    = $this->productIdsByReference( array( $code ) );
		if ( isset( $found[ $code ] ) ) {
			return $found[ $code ];
		}
		if ( ! $this->options->flag( 'create_service_products', true ) ) {
			$exception = new OdooException(
				new ConnectorError(
					ErrorCode::Map001,
					array(
						'sku'    => $code,
						'system' => OdooErrorMapper::SYSTEM,
					),
					null,
					null,
					null,
					'service product missing'
				)
			);
			throw $exception;
		}
		$id                             = $this->client->create(
			'product.product',
			array(
				'name'         => $defaults[1],
				'default_code' => $code,
				'type'         => 'service',
				'sale_ok'      => true,
				'purchase_ok'  => false,
				'list_price'   => 0.0,
			)
		);
		$this->memo['product'][ $code ] = $id;
		return $id;
	}

	/**
	 * Create a product that Odoo does not have (option missing_product = create).
	 *
	 * @param string $code  Internal reference (the SKU).
	 * @param string $name  Name.
	 * @param float  $price Sales price.
	 * @return int Product id.
	 *
	 * @throws OdooException On failure.
	 */
	public function createProduct( string $code, string $name, float $price ): int {
		$id                             = $this->client->create(
			'product.product',
			array(
				'name'         => '' !== $name ? $name : $code,
				'default_code' => $code,
				'type'         => 'consu',
				'sale_ok'      => true,
				'list_price'   => $price,
			)
		);
		$this->memo['product'][ $code ] = $id;
		return $id;
	}

	/**
	 * Sale tax ids for the codes on a payload. A code made of digits is a tax id; any other code is a tax name.
	 *
	 * @param array<int,string> $codes Tax codes from the tax mapping.
	 * @return array<string,int> Code => tax id; codes Odoo does not know are missing.
	 *
	 * @throws OdooException On failure.
	 */
	public function taxIds( array $codes ): array {
		$codes   = array_values( array_unique( array_filter( array_map( 'trim', $codes ), static fn( string $code ): bool => '' !== $code ) ) );
		$missing = array_values( array_filter( $codes, fn( string $code ): bool => ! isset( $this->memo['tax'][ $code ] ) ) );
		if ( array() !== $missing ) {
			$ids   = array();
			$names = array();
			foreach ( $missing as $code ) {
				if ( ctype_digit( $code ) ) {
					$ids[] = (int) $code;
				} else {
					$names[] = $code;
				}
			}
			$choice = array();
			if ( array() !== $ids ) {
				$choice[] = array( 'id', 'in', $ids );
			}
			if ( array() !== $names ) {
				$choice[] = array( 'name', 'in', $names );
			}
			$domain = array_merge(
				array( array( 'type_tax_use', 'in', array( 'sale', 'none' ) ) ),
				$this->client->companyDomain(),
				count( $choice ) > 1 ? array_merge( array( '|' ), $choice ) : $choice
			);
			foreach ( $this->client->searchRead( 'account.tax', $domain, array( 'name' ), array( 'order' => 'id asc' ) ) as $record ) {
				$id   = OdooRecord::id( $record['id'] ?? 0 );
				$name = OdooRecord::text( $record['name'] ?? '' );
				if ( isset( $record['id'] ) && in_array( (string) $id, $missing, true ) ) {
					$this->memo['tax'][ (string) $id ] = $id;
				}
				if ( in_array( $name, $missing, true ) && ! isset( $this->memo['tax'][ $name ] ) ) {
					$this->memo['tax'][ $name ] = $id;
				}
			}
		}
		$found = array();
		foreach ( $codes as $code ) {
			if ( isset( $this->memo['tax'][ $code ] ) ) {
				$found[ $code ] = (int) $this->memo['tax'][ $code ];
			}
		}
		return $found;
	}

	/**
	 * Country id for an ISO 3166-1 alpha-2 code; 0 when Odoo does not know it.
	 *
	 * @param string $code Country code such as AE.
	 *
	 * @throws OdooException On failure.
	 */
	public function countryId( string $code ): int {
		$code = strtoupper( trim( $code ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return 0;
		}
		if ( ! isset( $this->memo['country'][ $code ] ) ) {
			$found                          = $this->client->searchRead( 'res.country', array( array( 'code', '=', $code ) ), array( 'code' ), array( 'limit' => 1 ) );
			$this->memo['country'][ $code ] = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
		}
		return (int) $this->memo['country'][ $code ];
	}

	/**
	 * State id for an ISO 3166-2 code such as AE-DU; 0 when Odoo does not know the state.
	 *
	 * @param string $iso Subdivision code with the country prefix.
	 *
	 * @throws OdooException On failure.
	 */
	public function stateId( string $iso ): int {
		$iso = strtoupper( trim( $iso ) );
		if ( 1 !== preg_match( '/^([A-Z]{2})-([A-Z0-9]{1,3})$/', $iso, $parts ) ) {
			return 0;
		}
		if ( ! isset( $this->memo['state'][ $iso ] ) ) {
			$country = $this->countryId( $parts[1] );
			$id      = 0;
			if ( $country > 0 ) {
				$found = $this->client->searchRead(
					'res.country.state',
					array( array( 'country_id', '=', $country ), array( 'code', '=', $parts[2] ) ),
					array( 'code' ),
					array( 'limit' => 1 )
				);
				$id    = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
			}
			$this->memo['state'][ $iso ] = $id;
		}
		return (int) $this->memo['state'][ $iso ];
	}

	/**
	 * Payment term id by name; 0 when there is none.
	 *
	 * @param string $name Payment term name.
	 *
	 * @throws OdooException On failure.
	 */
	public function paymentTermId( string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		if ( ! isset( $this->memo['term'][ $name ] ) ) {
			$found                       = $this->client->searchRead(
				'account.payment.term',
				array_merge( array( array( 'name', '=ilike', $name ) ), $this->client->companyDomain() ),
				array( 'name' ),
				array(
					'limit' => 1,
					'order' => 'id asc',
				)
			);
			$this->memo['term'][ $name ] = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
		}
		return (int) $this->memo['term'][ $name ];
	}

	/**
	 * The active price list to use for orders in a currency; 0 when there is none. Price lists shared by all companies
	 * (no company) count, and so do those of the company the connector works in. With the option pricelist (a name or an
	 * id) only that price list is considered, and only when it is in the currency. Otherwise the first one by sequence wins.
	 *
	 * @param string $currency ISO 4217 code.
	 *
	 * @throws OdooException On failure.
	 */
	public function pricelistId( string $currency ): int {
		$currency = strtoupper( trim( $currency ) );
		if ( '' === $currency ) {
			return 0;
		}
		if ( ! isset( $this->memo['pricelist'][ $currency ] ) ) {
			$domain = array( array( 'currency_id.name', '=', $currency ) );
			$wanted = $this->options->text( 'pricelist' );
			if ( '' !== $wanted ) {
				$domain[] = ctype_digit( $wanted ) ? array( 'id', '=', (int) $wanted ) : array( 'name', '=ilike', $wanted );
			}
			$found                                = $this->client->searchRead(
				'product.pricelist',
				array_merge( $domain, $this->client->companyDomain() ),
				array( 'name' ),
				array(
					'limit' => 1,
					'order' => 'sequence asc, id asc',
				)
			);
			$this->memo['pricelist'][ $currency ] = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
		}
		return (int) $this->memo['pricelist'][ $currency ];
	}

	/**
	 * Make a price list for a currency (option create_pricelist). It belongs to the company the connector works in.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int Price list id, or 0 when Odoo has no active currency with that code.
	 *
	 * @throws OdooException On failure.
	 */
	public function createPricelist( string $currency ): int {
		$currency = strtoupper( trim( $currency ) );
		$found    = $this->client->searchRead( 'res.currency', array( array( 'name', '=', $currency ) ), array( 'name' ), array( 'limit' => 1 ) );
		if ( ! isset( $found[0] ) ) {
			return 0;
		}
		$id                                   = $this->client->create(
			'product.pricelist',
			array(
				'name'        => 'WooCommerce ' . $currency,
				'currency_id' => OdooRecord::id( $found[0]['id'] ?? 0 ),
				'company_id'  => $this->client->companyId(),
			)
		);
		$this->memo['pricelist'][ $currency ] = $id;
		return $id;
	}

	/**
	 * What the pricing of a line needs to know about taxes: name, percentage (in ten-thousandths of a percent, so 5% is 50000),
	 * whether the tax is a plain percentage, and whether Odoo counts it as included in the price. The flag is stored on the tax
	 * in Odoo 17; from 18 it follows the tax's own setting or the company's default, and reads the same way.
	 *
	 * @param array<int,int> $ids Tax ids.
	 * @return array<int,array{name:string,rate:int,percent:bool,included:bool}>
	 *
	 * @throws OdooException On failure.
	 */
	public function taxDetails( array $ids ): array {
		$ids     = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
		$missing = array_values( array_filter( $ids, fn( int $id ): bool => ! isset( $this->details[ $id ] ) ) );
		if ( array() !== $missing ) {
			$fields = array( 'name', 'amount', 'amount_type' );
			if ( $this->client->hasField( 'account.tax', 'price_include' ) ) {
				$fields[] = 'price_include';
			}
			foreach ( $this->client->searchRead( 'account.tax', array( array( 'id', 'in', $missing ) ), $fields ) as $record ) {
				$this->details[ OdooRecord::id( $record['id'] ?? 0 ) ] = array(
					'name'     => OdooRecord::text( $record['name'] ?? '' ),
					'rate'     => (int) round( OdooRecord::number( $record['amount'] ?? 0 ) * 10000 ),
					'percent'  => 'percent' === OdooRecord::text( $record['amount_type'] ?? 'percent' ),
					'included' => true === ( $record['price_include'] ?? false ),
				);
			}
		}
		$result = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->details[ $id ] ) ) {
				$result[ $id ] = $this->details[ $id ];
			}
		}
		return $result;
	}

	/**
	 * ISO code of the company currency.
	 *
	 * @throws OdooException On failure.
	 */
	public function companyCurrency(): string {
		if ( ! isset( $this->memo['currency']['company'] ) ) {
			$company                           = $this->client->read( 'res.company', array( $this->client->companyId() ), array( 'currency_id' ) );
			$this->memo['currency']['company'] = isset( $company[0] ) ? strtoupper( OdooRecord::name( $company[0]['currency_id'] ?? false ) ) : '';
		}
		return (string) $this->memo['currency']['company'];
	}

	/**
	 * Warehouse id by code, name or id; 0 when there is none.
	 *
	 * @param string $reference Warehouse code, name or id.
	 *
	 * @throws OdooException On failure.
	 */
	public function warehouseId( string $reference ): int {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return 0;
		}
		if ( ! isset( $this->memo['warehouse'][ $reference ] ) ) {
			$domain                                = ctype_digit( $reference )
				? array( array( 'id', '=', (int) $reference ) )
				: array( '|', array( 'code', '=ilike', $reference ), array( 'name', '=ilike', $reference ) );
			$found                                 = $this->client->searchRead(
				'stock.warehouse',
				array_merge( $domain, $this->client->companyDomain() ),
				array( 'code' ),
				array(
					'limit' => 1,
					'order' => 'id asc',
				)
			);
			$this->memo['warehouse'][ $reference ] = isset( $found[0] ) ? OdooRecord::id( $found[0]['id'] ?? 0 ) : 0;
		}
		return (int) $this->memo['warehouse'][ $reference ];
	}
}
