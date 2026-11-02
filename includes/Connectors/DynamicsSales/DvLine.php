<?php
/**
 * One product line of an opportunity.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;

/**
 * Either an existing product (bound to its row and its unit of measure) or a write-in line (free text, `isproductoverridden`). The price is
 * always the order's own: `ispriceoverridden` is true, so no price list decides it.
 */
final class DvLine {

	/**
	 * Longest line text (`productdescription` and `opportunityproductname` are 500 characters).
	 */
	public const DESCRIPTION_MAX = 500;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string      $description Line text.
	 * @param int|float   $quantity    Quantity.
	 * @param int         $priceMinor  Unit price without tax in minor units.
	 * @param int         $taxMinor    Tax of the line in minor units.
	 * @param string|null $productId   Product GUID, or null for a write-in line.
	 * @param string|null $uomId       Unit of measure GUID of an existing product.
	 */
	public function __construct(
		public readonly string $description,
		public readonly int|float $quantity,
		public readonly int $priceMinor,
		public readonly int $taxMinor = 0,
		public readonly ?string $productId = null,
		public readonly ?string $uomId = null,
	) {
	}

	/**
	 * The body of the POST that creates line number `$number` of an opportunity.
	 *
	 * @param string $opportunityId Opportunity GUID.
	 * @param int    $number        Line number from 1.
	 * @param string $currency      Currency of the opportunity.
	 * @return array<string,mixed>
	 */
	public function body( string $opportunityId, int $number, string $currency ): array {
		$text = Text::cut( $this->description, self::DESCRIPTION_MAX );
		$body = array(
			'opportunityid@odata.bind' => '/opportunities(' . $opportunityId . ')',
			'opportunityproductname'   => $text,
			'quantity'                 => $this->quantity,
			'priceperunit'             => Amounts::decimal( $this->priceMinor, $currency ),
			'ispriceoverridden'        => true,
			'lineitemnumber'           => $number,
		);
		if ( null !== $this->productId ) {
			$body['productid@odata.bind'] = '/products(' . $this->productId . ')';
			if ( null !== $this->uomId ) {
				$body['uomid@odata.bind'] = '/uoms(' . $this->uomId . ')';
			}
		} else {
			$body['isproductoverridden'] = true;
			$body['productdescription']  = $text;
		}
		if ( $this->taxMinor > 0 ) {
			$body['tax'] = Amounts::decimal( $this->taxMinor, $currency );
		}
		return $body;
	}

	/**
	 * Whether a row already on the opportunity is this line.
	 *
	 * @param array<string,mixed> $row      Row of opportunityproducts.
	 * @param string              $currency Currency of the opportunity.
	 */
	public function matches( array $row, string $currency ): bool {
		if ( abs( (float) ( $row['quantity'] ?? 0 ) - (float) $this->quantity ) > 0.000001 || Amounts::minor( $row['priceperunit'] ?? 0, $currency ) !== $this->priceMinor ) {
			return false;
		}
		if ( null !== $this->productId ) {
			return 0 === strcasecmp( (string) ( $row['_productid_value'] ?? '' ), $this->productId );
		}
		return trim( (string) ( $row['productdescription'] ?? '' ) ) === trim( Text::cut( $this->description, self::DESCRIPTION_MAX ) );
	}
}
