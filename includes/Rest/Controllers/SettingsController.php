<?php
/**
 * Routes: settings and the test email.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Notifications\NotificationService;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /settings` (view and operate), `PUT /settings` (configure) and `POST /notifications/test`. Core\Settings validates every
 * field and refuses the whole change when one is wrong, with one message per field.
 */
final class SettingsController extends BaseController {

	/**
	 * Fields of the settings that a request may carry.
	 */
	private const FIELDS = array( 'display_time', 'log_retention_days', 'log_excerpt_bytes', 'redaction', 'payload_cap_bytes', 'delete_on_uninstall', 'runtime_profile', 'global_concurrency', 'backlog_threshold', 'admin_bar', 'notifications' );

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/settings', 'GET', self::MANAGE, $this->show( ... ) );
		$this->add(
			'/settings',
			'PUT',
			self::CONFIGURE,
			$this->save( ... ),
			array(
				'display_time'        => self::enumArg( array( 'store', 'utc' ) ),
				'log_retention_days'  => array(
					'type' => 'integer',
					'enum' => Settings::RETENTION_DAYS,
				),
				'log_excerpt_bytes'   => array(
					'type' => 'integer',
					'enum' => Settings::EXCERPT_BYTES,
				),
				'redaction'           => self::boolArg(),
				'payload_cap_bytes'   => array(
					'type'    => 'integer',
					'minimum' => Ranges::PAYLOAD_CAP_BYTES[0],
					'maximum' => Ranges::PAYLOAD_CAP_BYTES[1],
				),
				'delete_on_uninstall' => self::boolArg(),
				'runtime_profile'     => self::enumArg( array( 'standard', 'high_volume' ) ),
				'global_concurrency'  => array(
					'type'    => 'integer',
					'minimum' => Ranges::GLOBAL_CONCURRENCY[0],
					'maximum' => Ranges::GLOBAL_CONCURRENCY[1],
				),
				'backlog_threshold'   => array(
					'type'    => 'integer',
					'minimum' => Ranges::BACKLOG[0],
					'maximum' => Ranges::BACKLOG[1],
				),
				'admin_bar'           => self::boolArg(),
				'notifications'       => array(
					'type'       => 'object',
					'properties' => array(
						'recipients'        => array(
							'type'     => 'array',
							'maxItems' => Ranges::MAX_RECIPIENTS,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 254,
							),
						),
						'on_paused'         => array( 'type' => 'boolean' ),
						'daily_digest'      => array( 'type' => 'boolean' ),
						'digest_time'       => array(
							'type'    => 'string',
							'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
						),
						'on_backlog'        => array( 'type' => 'boolean' ),
						'on_signin_expired' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
		$this->add(
			'/notifications/test',
			'POST',
			self::CONFIGURE,
			$this->sendTest( ... ),
			array(
				'recipient' => array(
					'type'   => 'string',
					'format' => 'email',
				),
			),
			false
		);
	}

	/**
	 * `GET /settings`.
	 *
	 * @return array<string,mixed>
	 */
	public function show(): array {
		$settings = $this->serviceAs( Services::SETTINGS, Settings::class );
		return $settings->get();
	}

	/**
	 * `PUT /settings`: save the fields that were sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function save( \WP_REST_Request $request ): array {
		$settings = $this->serviceAs( Services::SETTINGS, Settings::class );
		$changes  = array();
		foreach ( self::FIELDS as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$changes[ $field ] = $request->get_param( $field );
			}
		}
		return $settings->update( $changes );
	}

	/**
	 * `POST /notifications/test`: send a test email to the recipients, or to one address.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{sent_to:array<int,string>}
	 *
	 * @throws ApiException 502 when WordPress could not hand the email to the mail system.
	 */
	public function sendTest( \WP_REST_Request $request ): array {
		$notifications = $this->serviceAs( JobServices::NOTIFICATIONS, NotificationService::class );
		$recipient     = sanitize_email( (string) $request->get_param( 'recipient' ) );
		$result        = $notifications->sendTest( $recipient );
		if ( empty( $result['sent'] ) ) {
			$refusal = new ApiException( ApiException::MAIL_FAILED, esc_html__( 'The test email could not be sent. WordPress did not accept the message. Check the email settings of this site.', 'estasoft-enterprise-erpcrm-sync-engine' ), 502, 'needs_person' );
			throw $refusal;
		}
		$addresses = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $result['to'] ?? '' ) ) ), static fn( string $address ): bool => '' !== $address ) );
		return array( 'sent_to' => $addresses );
	}
}
