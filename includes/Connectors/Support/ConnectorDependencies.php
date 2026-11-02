<?php
/**
 * Shared services handed to every connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\Export\ExportStore;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Everything a connector may use. Network access goes through `$http` only.
 */
final class ConnectorDependencies {

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http   The engine's HTTP client (the only way out to the network).
	 * @param Clock               $clock  Time source.
	 * @param OAuthTokenManager   $tokens Token storage, refresh and grants for OAuth connectors.
	 * @param ExportStore         $exports Protected folder for export files.
	 * @param Logger              $logger Log (secrets are redacted by the logger as well).
	 * @param RequestPacer|null   $pacer  Shared pacing of calls per connection; null paces inside one request only.
	 */
	public function __construct(
		public readonly HttpClientInterface $http,
		public readonly Clock $clock,
		public readonly OAuthTokenManager $tokens,
		public readonly ExportStore $exports,
		public readonly Logger $logger,
		public readonly ?RequestPacer $pacer = null,
	) {
	}
}
