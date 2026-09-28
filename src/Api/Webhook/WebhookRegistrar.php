<?php
/**
 * Webhook callback registration with ifthenpay.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

use Ifthenpay\Forminator\Api\IfthenpayClient;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the webhook callback URL with ifthenpay, and re-registers it
 * whenever the URL drifts from the one ifthenpay last accepted — a
 * permalink structure change or a domain migration both change
 * `WebhookController::url()`, and ifthenpay keeps calling the old one.
 */
class WebhookRegistrar {

	/**
	 * Throttles re-sync attempts while ifthenpay keeps rejecting them.
	 */
	private const RETRY_LOCK = 'iftp_fm_callback_resync_lock';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the registrar.
	 *
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
	 * @param IfthenpayClient|null    $client   Ifthenpay API client; a fresh instance is used when omitted.
	 */
	public function __construct( ?SettingsRepository $settings = null, ?IfthenpayClient $client = null ) {
		$this->settings = $settings ?? new SettingsRepository();
		$this->client   = $client ?? new IfthenpayClient();
	}

	/**
	 * Wires up the drift check.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_resync' ) );
	}

	/**
	 * Registers (or re-registers) the webhook callback for the given Gateway Key.
	 *
	 * I reuse the Gateway Key's existing anti-phishing key — rotating it on
	 * every save would break callbacks for payments already in flight.
	 *
	 * @param string $gateway_key The Gateway Key to register the webhook for.
	 */
	public function register_webhook( string $gateway_key ): bool {
		if ( '' === $gateway_key ) {
			return false;
		}

		$anti_phishing_key = $this->settings->get_anti_phishing_key( $gateway_key );
		$is_new            = '' === $anti_phishing_key;

		if ( $is_new ) {
			$anti_phishing_key = wp_generate_password( 32, false, false );
		}

		if ( ! $this->client->activate_callback( $gateway_key, $anti_phishing_key, WebhookController::url() ) ) {
			return false;
		}

		// Only stored once ifthenpay accepted it, otherwise it's still calling back with the old key.
		if ( $is_new ) {
			$this->settings->set_anti_phishing_key( $gateway_key, $anti_phishing_key );
		}

		$this->settings->set_callback_hash( self::hash( $gateway_key ) );

		return true;
	}

	/**
	 * Re-registers the callback when the current URL no longer matches the
	 * one ifthenpay accepted. Only a string compare on the happy path.
	 */
	public function maybe_resync(): void {
		// The settings AJAX handlers register on their own, so I skip them here.
		if ( wp_doing_ajax() || ! current_user_can( forminator_get_admin_cap() ) ) {
			return;
		}

		$gateway_key = $this->settings->get_gateway_key();

		if ( '' === $gateway_key || self::hash( $gateway_key ) === $this->settings->get_callback_hash() ) {
			return;
		}

		if ( false !== get_transient( self::RETRY_LOCK ) ) {
			return;
		}

		if ( ! $this->register_webhook( $gateway_key ) ) {
			set_transient( self::RETRY_LOCK, 1, 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * A hash rather than the URL itself — a migration's search-replace would
	 * rewrite a stored URL to the new domain and hide the drift.
	 *
	 * @param string $gateway_key The Gateway Key the callback is registered for.
	 */
	private static function hash( string $gateway_key ): string {
		return md5( $gateway_key . '|' . WebhookController::url() );
	}
}
