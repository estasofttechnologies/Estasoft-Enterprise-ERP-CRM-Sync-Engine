<?php
/**
 * State behind the WordPress stand-ins in RestWpStubs.php.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * One place that the stand-in functions read and write, so a test can set the scene and look at what happened. Tests that use it run
 * in their own PHP process (see RestStubCase), because the stand-in functions are global and other test areas define their own.
 */
final class RestRegistry {

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	public static array $caps = array();

	/**
	 * Whether a user is signed in.
	 *
	 * @var bool
	 */
	public static bool $loggedIn = true;

	/**
	 * Current user id.
	 *
	 * @var int
	 */
	public static int $userId = 7;

	/**
	 * Capabilities by user id, for user_can().
	 *
	 * @var array<int,array<int,string>>
	 */
	public static array $userCaps = array();

	/**
	 * Routes passed to register_rest_route(), in order.
	 *
	 * @var array<int,array{namespace:string,route:string,args:array<string,mixed>}>
	 */
	public static array $routes = array();

	/**
	 * Transients.
	 *
	 * @var array<string,mixed>
	 */
	public static array $transients = array();

	/**
	 * User meta by "id:key".
	 *
	 * @var array<string,mixed>
	 */
	public static array $userMeta = array();

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	public static array $options = array();

	/**
	 * Hooks added: type, name, callback, priority.
	 *
	 * @var array<int,array{0:string,1:string,2:mixed,3:int}>
	 */
	public static array $hooks = array();

	/**
	 * Scripts and styles: kind, handle, source, extra.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $assets = array();

	/**
	 * Redirects asked for.
	 *
	 * @var array<int,string>
	 */
	public static array $redirects = array();

	/**
	 * Submenu pages added.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $submenus = array();

	/**
	 * Dashboard widgets added.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $widgets = array();

	/**
	 * Screen options added.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $screenOptions = array();

	/**
	 * Whether the request is AJAX, cron or in the network admin.
	 *
	 * @var array<string,bool>
	 */
	public static array $request = array(
		'ajax'    => false,
		'cron'    => false,
		'network' => false,
		'admin'   => true,
	);

	/**
	 * Query arguments of the request ($_GET stand-in for the code under test is the real $_GET).
	 *
	 * @var array<string,mixed>
	 */
	public static array $screenId = array();

	/**
	 * Put everything back.
	 */
	public static function reset(): void {
		self::$caps          = array();
		self::$loggedIn      = true;
		self::$userId        = 7;
		self::$userCaps      = array();
		self::$routes        = array();
		self::$transients    = array();
		self::$userMeta      = array();
		self::$options       = array();
		self::$hooks         = array();
		self::$assets        = array();
		self::$redirects     = array();
		self::$submenus      = array();
		self::$widgets       = array();
		self::$screenOptions = array();
		self::$screenId      = array();
		self::$request       = array(
			'ajax'    => false,
			'cron'    => false,
			'network' => false,
			'admin'   => true,
		);
		$_GET                = array();
	}
}
