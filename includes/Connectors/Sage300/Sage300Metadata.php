<?php
/**
 * What the `$metadata` of a Sage 300 module says about the properties of its entities.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The property names of Order Entry beyond the official samples differ between releases and setups, so the connector reads them from
 * the server instead of guessing: which property holds the order reference, whether an order line has a unit price, whether the order
 * has a tax group, which customer property holds the email. Each module is read once per connector call.
 */
final class Sage300Metadata {

	/**
	 * Entity sets by module and name: the properties of their entity and of the collections inside it.
	 *
	 * @var array<string,array<string,array{props:array<int,string>,children:array<string,array<int,string>>}>>
	 */
	private array $modules = array();

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage300Client $client Client.
	 */
	public function __construct( private readonly Sage300Client $client ) {
	}

	/**
	 * The property names of an entity set, and those of each collection inside it.
	 *
	 * @param string $module Module such as OE.
	 * @param string $set    Entity set such as OEOrders.
	 * @return array{props:array<int,string>,children:array<string,array<int,string>>}|null Null when the server did not describe it.
	 *
	 * @throws ApiException On failure.
	 */
	public function entity( string $module, string $set ): ?array {
		if ( ! isset( $this->modules[ $module ] ) ) {
			$this->modules[ $module ] = $this->read( $module );
		}
		return $this->modules[ $module ][ $set ] ?? null;
	}

	/**
	 * Whether an entity has a property.
	 *
	 * @param string $module   Module.
	 * @param string $set      Entity set.
	 * @param string $property Property name.
	 * @param string $child    Collection inside the entity, or empty for the entity itself.
	 *
	 * @throws ApiException On failure.
	 */
	public function has( string $module, string $set, string $property, string $child = '' ): bool {
		$entity = $this->entity( $module, $set );
		if ( null === $entity ) {
			return false;
		}
		$names = '' === $child ? $entity['props'] : ( $entity['children'][ $child ] ?? array() );
		return in_array( strtolower( $property ), array_map( 'strtolower', $names ), true );
	}

	/**
	 * The first of several property names that an entity has, as the server spells it.
	 *
	 * @param string            $module     Module.
	 * @param string            $set        Entity set.
	 * @param array<int,string> $candidates Names in order of preference.
	 *
	 * @throws ApiException On failure.
	 */
	public function firstOf( string $module, string $set, array $candidates ): string {
		$entity = $this->entity( $module, $set );
		if ( null === $entity ) {
			return '';
		}
		foreach ( $candidates as $candidate ) {
			foreach ( $entity['props'] as $name ) {
				if ( strtolower( $name ) === strtolower( $candidate ) ) {
					return $name;
				}
			}
		}
		return '';
	}

	/**
	 * The first property of an entity whose name contains a word, as the server spells it.
	 *
	 * @param string $module Module.
	 * @param string $set    Entity set.
	 * @param string $word   Word such as Email.
	 *
	 * @throws ApiException On failure.
	 */
	public function containing( string $module, string $set, string $word ): string {
		$entity = $this->entity( $module, $set );
		foreach ( null === $entity ? array() : $entity['props'] as $name ) {
			if ( false !== stripos( $name, $word ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Read and index a module's `$metadata`.
	 *
	 * @param string $module Module.
	 * @return array<string,array{props:array<int,string>,children:array<string,array<int,string>>}>
	 *
	 * @throws ApiException On failure.
	 */
	private function read( string $module ): array {
		$xml = $this->client->metadata( $module );
		if ( null === $xml ) {
			return array();
		}
		$types = array();
		foreach ( $this->nodes( $xml, '//*[local-name()="EntityType"]' ) as $type ) {
			$props = array();
			foreach ( $this->nodes( $type, './*[local-name()="Property"]' ) as $property ) {
				$props[] = (string) $property['Name'];
			}
			$nav = array();
			foreach ( $this->nodes( $type, './*[local-name()="NavigationProperty"]' ) as $navigation ) {
				$nav[ (string) $navigation['Name'] ] = $this->short( (string) $navigation['Type'] );
			}
			$types[ (string) $type['Name'] ] = array(
				'props' => $props,
				'nav'   => $nav,
			);
		}
		$sets = array();
		foreach ( $this->nodes( $xml, '//*[local-name()="EntitySet"]' ) as $set ) {
			$type = $types[ $this->short( (string) $set['EntityType'] ) ] ?? null;
			if ( null === $type ) {
				continue;
			}
			$children = array();
			foreach ( $type['nav'] as $name => $target ) {
				$children[ $name ] = $types[ $target ]['props'] ?? array();
			}
			$sets[ (string) $set['Name'] ] = array(
				'props'    => $type['props'],
				'children' => $children,
			);
		}
		return $sets;
	}

	/**
	 * The nodes an XPath finds.
	 *
	 * @param \SimpleXMLElement $node  Where to search from.
	 * @param string            $query XPath.
	 * @return array<int,\SimpleXMLElement>
	 */
	private function nodes( \SimpleXMLElement $node, string $query ): array {
		$found = $node->xpath( $query );
		return is_array( $found ) ? $found : array();
	}

	/**
	 * A type name without its namespace and without Collection( ).
	 *
	 * @param string $name Type name as written in the document.
	 */
	private function short( string $name ): string {
		$name = (string) preg_replace( '/^Collection\((.*)\)$/', '$1', $name );
		$dot  = strrpos( $name, '.' );
		return false === $dot ? $name : substr( $name, $dot + 1 );
	}
}
