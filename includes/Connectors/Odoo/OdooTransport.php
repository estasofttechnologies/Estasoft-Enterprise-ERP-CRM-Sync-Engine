<?php
/**
 * The way the connector talks to one Odoo server.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Two implementations: JsonRpcTransport (Odoo 17 and 18, POST /jsonrpc) and Json2Transport (Odoo 19 and later,
 * POST /json/2/model/method). Both expose the same few operations, so the rest of the connector does not know which
 * protocol runs. Every method makes one HTTP request (the first call may also sign in), never throws for anything
 * but OdooException, and never puts the key in an exception.
 */
interface OdooTransport {

	/**
	 * Short id of the protocol: jsonrpc or json2.
	 */
	public function id(): string;

	/**
	 * The id of the user the key belongs to. Signs in when the protocol needs it.
	 *
	 * @throws OdooException When the sign-in is refused or the server cannot be reached.
	 */
	public function userId(): int;

	/**
	 * Search and read in one transaction.
	 *
	 * @param string                                      $model   Model name such as sale.order.
	 * @param array<int,mixed>                            $domain  Odoo domain.
	 * @param array<int,string>                           $fields  Field names to read.
	 * @param array{limit?:int,offset?:int,order?:string} $options Paging and order.
	 * @param array<string,mixed>                         $context Context values.
	 * @return array<int,array<string,mixed>> Records.
	 *
	 * @throws OdooException On any failure.
	 */
	public function searchRead( string $model, array $domain, array $fields, array $options = array(), array $context = array() ): array;

	/**
	 * Read records by id.
	 *
	 * @param string              $model   Model name.
	 * @param array<int,int>      $ids     Record ids.
	 * @param array<int,string>   $fields  Field names.
	 * @param array<string,mixed> $context Context values.
	 * @return array<int,array<string,mixed>> Records.
	 *
	 * @throws OdooException On any failure.
	 */
	public function read( string $model, array $ids, array $fields, array $context = array() ): array;

	/**
	 * Create one record.
	 *
	 * @param string              $model   Model name.
	 * @param array<string,mixed> $values  Field values.
	 * @param array<string,mixed> $context Context values.
	 * @return int Id of the new record.
	 *
	 * @throws OdooException On any failure.
	 */
	public function create( string $model, array $values, array $context = array() ): int;

	/**
	 * Change records.
	 *
	 * @param string              $model   Model name.
	 * @param array<int,int>      $ids     Record ids.
	 * @param array<string,mixed> $values  Field values.
	 * @param array<string,mixed> $context Context values.
	 *
	 * @throws OdooException On any failure.
	 */
	public function write( string $model, array $ids, array $values, array $context = array() ): bool;

	/**
	 * Call a model-level method (one decorated with api.model in Odoo), for example search_count or fields_get.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<string,mixed> $params  Named parameters, as in the Python signature.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed Decoded result.
	 *
	 * @throws OdooException On any failure.
	 */
	public function callModel( string $model, string $method, array $params = array(), array $context = array() ): mixed;

	/**
	 * Call a method on records, for example action_confirm.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<int,int>      $ids     Record ids (may be empty for methods that work on an empty set, such as has_access).
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed Decoded result.
	 *
	 * @throws OdooException On any failure.
	 */
	public function callRecords( string $model, string $method, array $ids, array $params = array(), array $context = array() ): mixed;
}
