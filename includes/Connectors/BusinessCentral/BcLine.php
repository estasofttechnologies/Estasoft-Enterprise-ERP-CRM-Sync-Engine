<?php
/**
 * One line to write on a sales document.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;

/**
 * Type Item or Account (a priced line) or Comment (a note without amounts). The price is in minor units and becomes a decimal only here.
 */
final class BcLine {

	/**
	 * Longest line text (the AL field is 100 characters).
	 */
	public const DESCRIPTION_MAX = 100;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string    $type        Item, Account or Comment.
	 * @param string    $number      Item number or G/L account number; empty for a comment.
	 * @param string    $description Line text.
	 * @param int|float $quantity    Quantity.
	 * @param int       $priceMinor  Unit price in minor units (negative for a discount).
	 * @param string    $taxCode     Tax group code, or empty.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $number,
		public readonly string $description,
		public readonly int|float $quantity = 1,
		public readonly int $priceMinor = 0,
		public readonly string $taxCode = '',
	) {
	}

	/**
	 * A comment line.
	 *
	 * @param string $text Text.
	 */
	public static function comment( string $text ): self {
		return new self( 'Comment', '', $text );
	}

	/**
	 * Whether this is a comment.
	 */
	public function isComment(): bool {
		return 'Comment' === $this->type;
	}

	/**
	 * The body of the POST that creates the line.
	 *
	 * @param string $currency Currency of the document.
	 * @return array<string,mixed>
	 */
	public function body( string $currency ): array {
		$description = Text::cut( $this->description, self::DESCRIPTION_MAX );
		if ( $this->isComment() ) {
			return array(
				'lineType'    => 'Comment',
				'description' => $description,
			);
		}
		$body = array(
			'lineType'         => $this->type,
			'lineObjectNumber' => $this->number,
			'description'      => $description,
			'quantity'         => $this->quantity,
			'unitPrice'        => Amounts::decimal( $this->priceMinor, $currency ),
		);
		if ( '' !== $this->taxCode ) {
			$body['taxCode'] = $this->taxCode;
		}
		return $body;
	}

	/**
	 * Whether a line already on the document is this line (same type, number, quantity and unit price; text and tax code may be adjusted by BC).
	 *
	 * @param array<string,mixed> $existing Line row from the API.
	 * @param string              $currency Currency of the document.
	 */
	public function matches( array $existing, string $currency ): bool {
		if ( 0 !== strcasecmp( (string) ( $existing['lineType'] ?? '' ), $this->type ) ) {
			return false;
		}
		if ( $this->isComment() ) {
			return trim( (string) ( $existing['description'] ?? '' ) ) === trim( Text::cut( $this->description, self::DESCRIPTION_MAX ) );
		}
		return 0 === strcasecmp( (string) ( $existing['lineObjectNumber'] ?? '' ), $this->number )
			&& abs( (float) ( $existing['quantity'] ?? 0 ) - (float) $this->quantity ) < 0.000001
			&& Amounts::minor( $existing['unitPrice'] ?? 0, $currency ) === $this->priceMinor;
	}
}
