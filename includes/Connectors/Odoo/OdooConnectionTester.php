<?php
/**
 * Test connection for Odoo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Runs the checks in the order of the connection spec: reach the address and check the certificate, find the version, sign in,
 * read the company and warehouses, then check the rights of the sync user model by model. The first problem with the address,
 * the sign-in or the plan stops the test. Rights that are missing are all listed. Nothing is saved or changed in Odoo.
 */
final class OdooConnectionTester {

	/**
	 * Rights the sync needs: key => [model, operation, blocks the connection when missing].
	 *
	 * @var array<string,array{0:string,1:string,2:bool}>
	 */
	private const RIGHTS = array(
		'read_products'       => array( 'product.product', 'read', true ),
		'read_customers'      => array( 'res.partner', 'read', true ),
		'create_customers'    => array( 'res.partner', 'create', true ),
		'create_sale_orders'  => array( 'sale.order', 'create', true ),
		'confirm_sale_orders' => array( 'sale.order', 'write', true ),
		'create_credit_notes' => array( 'account.move', 'create', true ),
		'read_stock'          => array( 'stock.quant', 'read', false ),
		'read_deliveries'     => array( 'stock.picking', 'read', false ),
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClientFactory $factory Client factory.
	 * @param OdooAmounts       $amounts Conversion helper.
	 */
	public function __construct( private readonly OdooClientFactory $factory, private readonly OdooAmounts $amounts ) {
	}

	/**
	 * Run the test.
	 *
	 * @param Connection $connection Connection with its decrypted key.
	 */
	public function test( Connection $connection ): TestResult {
		try {
			$client = $this->factory->forConnection( $connection, false, true );
			$client->userId();
			return $this->inspect( $client );
		} catch ( OdooException $exception ) {
			return new TestResult( false, '', '', array(), array(), $exception->error );
		}
	}

	/**
	 * Everything after the sign-in.
	 *
	 * @param OdooClient $client Signed-in client.
	 *
	 * @throws OdooException When a basic read fails.
	 */
	private function inspect( OdooClient $client ): TestResult {
		$version   = $client->version();
		$companyId = $client->companyId();
		$company   = $client->read( 'res.company', array( $companyId ), array( 'name', 'currency_id', 'country_id' ) );
		$record    = $company[0] ?? array();
		$country   = $this->countryCode( $client, OdooRecord::id( $record['country_id'] ?? 0 ) );
		$currency  = strtoupper( OdooRecord::name( $record['currency_id'] ?? false ) );

		$modules    = $this->modules( $client );
		$warehouses = $modules['stock'] ? $this->warehouses( $client ) : array();
		$rights     = $this->rights( $client );
		$missing    = array_keys( array_filter( $rights['allowed'], static fn( bool $allowed ): bool => ! $allowed ) );
		$blocking   = array_values( array_intersect( $missing, $rights['blocking'] ) );

		$error = null;
		if ( array() !== $blocking ) {
			$error = new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => OdooErrorMapper::SYSTEM,
					'action' => $this->describe( $blocking[0] ),
				),
				null,
				null,
				null,
				'missing: ' . implode( ', ', $blocking )
			);
		}

		$detected = $version->toArray() + array(
			'company_id'            => $companyId,
			'currency'              => $currency,
			'currency_decimals'     => $this->amounts->exponent( $currency ),
			'country'               => $country,
			'modules'               => $modules,
			'multi_company'         => $this->isMultiCompany( $client ),
			'product_count'         => $modules['sale'] ? $client->searchCount( 'product.product', array( array( 'default_code', '!=', false ) ) ) : 0,
			'hints'                 => $this->hints( $client, $country, $modules ),
			'credential_expires_at' => $this->keyExpiry( $client ),
		);

		return new TestResult(
			null === $error,
			$version->label(),
			OdooRecord::text( $record['name'] ?? '' ),
			$warehouses,
			$rights['allowed'],
			$error,
			$detected
		);
	}

	/**
	 * Which apps are installed, found by the models and fields they add (the sync user cannot read the module list).
	 *
	 * @param OdooClient $client Client.
	 * @return array<string,bool>
	 *
	 * @throws OdooException On failure.
	 */
	private function modules( OdooClient $client ): array {
		$picking = $client->hasModel( 'stock.picking' ) ? $client->fields( 'stock.picking', array( 'carrier_tracking_ref' ) ) : array( 'carrier_tracking_ref' => false );
		return array(
			'sale'        => $client->hasModel( 'sale.order' ),
			'stock'       => $client->hasModel( 'stock.warehouse' ),
			'account'     => $client->hasModel( 'account.move' ),
			'delivery'    => $picking['carrier_tracking_ref'],
			'l10n_sa_edi' => $client->hasModel( 'account.move' ) && $client->hasField( 'account.move', 'l10n_sa_uuid' ),
		);
	}

	/**
	 * Warehouses of the company.
	 *
	 * @param OdooClient $client Client.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws OdooException On failure.
	 */
	private function warehouses( OdooClient $client ): array {
		$list = array();
		foreach ( $client->searchRead( 'stock.warehouse', $client->companyDomain(), array( 'name', 'code' ), array( 'order' => 'sequence asc, id asc' ) ) as $warehouse ) {
			$list[] = array(
				'id'   => (string) OdooRecord::id( $warehouse['id'] ?? 0 ),
				'name' => OdooRecord::text( $warehouse['name'] ?? '' ),
			);
		}
		return $list;
	}

	/**
	 * Check the sync user's rights model by model.
	 *
	 * @param OdooClient $client Client.
	 * @return array{allowed:array<string,bool>,blocking:array<int,string>}
	 *
	 * @throws OdooException On failures other than a missing model.
	 */
	private function rights( OdooClient $client ): array {
		$allowed  = array();
		$blocking = array();
		foreach ( self::RIGHTS as $key => $right ) {
			list( $model, $operation, $blocks ) = $right;
			if ( $blocks ) {
				$blocking[] = $key;
			}
			if ( ! $client->hasModel( $model ) ) {
				$allowed[ $key ] = false;
				continue;
			}
			$allowed[ $key ] = $this->hasAccess( $client, $model, $operation );
		}
		return array(
			'allowed'  => $allowed,
			'blocking' => $blocking,
		);
	}

	/**
	 * Whether the user may do an operation on a model (Odoo 18 and later: has_access; 17: check_access_rights).
	 *
	 * @param OdooClient $client    Client.
	 * @param string     $model     Model.
	 * @param string     $operation read, write, create or unlink.
	 *
	 * @throws OdooException On failure.
	 */
	private function hasAccess( OdooClient $client, string $model, string $operation ): bool {
		if ( $client->version()->atLeast( 18 ) ) {
			return true === $client->callRecords( $model, 'has_access', array(), array( 'operation' => $operation ) );
		}
		return true === $client->call(
			$model,
			'check_access_rights',
			array(
				'operation'       => $operation,
				'raise_exception' => false,
			)
		);
	}

	/**
	 * Words for a missing right.
	 *
	 * @param string $key Right key.
	 */
	private function describe( string $key ): string {
		return match ( $key ) {
			'read_products'       => __( 'read products', 'estasoft-erp-crm-sync-engine' ),
			'read_customers'      => __( 'read customers', 'estasoft-erp-crm-sync-engine' ),
			'create_customers'    => __( 'create customers', 'estasoft-erp-crm-sync-engine' ),
			'create_sale_orders'  => __( 'create sale orders', 'estasoft-erp-crm-sync-engine' ),
			'confirm_sale_orders' => __( 'confirm sale orders', 'estasoft-erp-crm-sync-engine' ),
			'create_credit_notes' => __( 'create credit notes', 'estasoft-erp-crm-sync-engine' ),
			default               => $key,
		};
	}

	/**
	 * ISO code of a country.
	 *
	 * @param OdooClient $client Client.
	 * @param int        $id     Country id.
	 *
	 * @throws OdooException On failure.
	 */
	private function countryCode( OdooClient $client, int $id ): string {
		if ( $id < 1 ) {
			return '';
		}
		$country = $client->read( 'res.country', array( $id ), array( 'code' ) );
		return strtoupper( OdooRecord::text( $country[0]['code'] ?? '' ) );
	}

	/**
	 * Whether the sync user can work in more than one company.
	 *
	 * @param OdooClient $client Client.
	 *
	 * @throws OdooException On failure.
	 */
	private function isMultiCompany( OdooClient $client ): bool {
		$user = $client->read( 'res.users', array( $client->userId() ), array( 'company_ids' ) );
		return isset( $user[0] ) && count( OdooRecord::ids( $user[0]['company_ids'] ?? array() ) ) > 1;
	}

	/**
	 * Short codes the screens turn into notes: Saudi e-invoicing state and the UAE.
	 *
	 * @param OdooClient         $client  Client.
	 * @param string             $country Company country code.
	 * @param array<string,bool> $modules Installed apps.
	 * @return array<int,string>
	 *
	 * @throws OdooException On failure.
	 */
	private function hints( OdooClient $client, string $country, array $modules ): array {
		$hints = array();
		if ( 'SA' === $country ) {
			if ( ! $modules['l10n_sa_edi'] ) {
				$hints[] = 'zatca_not_installed';
			} else {
				$production = $this->zatcaInProduction( $client );
				if ( null !== $production ) {
					$hints[] = $production ? 'zatca_production' : 'zatca_not_production';
				}
			}
		}
		if ( 'AE' === $country ) {
			$hints[] = 'uae_no_einvoicing_module';
		}
		return $hints;
	}

	/**
	 * Whether the Saudi e-invoicing module of the company talks to the production tax service. Odoo 19 has the flag
	 * l10n_sa_edi_is_production on the company; Odoo 17 and 18 only have the API mode (sandbox, preprod or prod).
	 *
	 * @param OdooClient $client Client.
	 * @return bool|null Null when the version has neither field.
	 *
	 * @throws OdooException On failure.
	 */
	private function zatcaInProduction( OdooClient $client ): ?bool {
		foreach ( array( 'l10n_sa_edi_is_production', 'l10n_sa_api_mode' ) as $field ) {
			if ( ! $client->hasField( 'res.company', $field ) ) {
				continue;
			}
			$company = $client->read( 'res.company', array( $client->companyId() ), array( $field ) );
			$value   = $company[0][ $field ] ?? false;
			return 'l10n_sa_api_mode' === $field ? 'prod' === $value : true === $value;
		}
		return null;
	}

	/**
	 * End date of the API key, when the user has exactly one key that can be used for outside access. Odoo does not say which
	 * key made the call, so more than one candidate gives no answer rather than a wrong one.
	 *
	 * @param OdooClient $client Client.
	 * @return string|null ISO 8601 UTC, or null.
	 */
	private function keyExpiry( OdooClient $client ): ?string {
		try {
			$keys = $client->searchRead( 'res.users.apikeys', array( array( 'user_id', '=', $client->userId() ) ), array( 'scope', 'expiration_date' ) );
		} catch ( OdooException $exception ) {
			return null;
		}
		$candidates = array_values(
			array_filter(
				$keys,
				static fn( array $key ): bool => false === ( $key['scope'] ?? false ) || null === ( $key['scope'] ?? null ) || 'rpc' === ( $key['scope'] ?? '' )
			)
		);
		if ( 1 !== count( $candidates ) ) {
			return null;
		}
		$date = $this->amounts->fromOdooDatetime( $candidates[0]['expiration_date'] ?? false );
		return '' === $date ? null : $date;
	}
}
