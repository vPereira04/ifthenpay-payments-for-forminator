<?php
/**
 * Ifthenpay HTTP API client.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the ifthenpay HTTP API.
 */
class IfthenpayClient {

	private const API_BASE     = 'https://api.ifthenpay.com';
	private const GATEWAY_TYPE = 'Forminator';
	private const CALLBACK_CMS = 'forminator';

	// The methods catalog is the same for every ifthenpay account and rarely changes, so I keep it for a while.
	public const CATALOG_TRANSIENT = 'iftp_fm_methods_catalog';
	private const CATALOG_TTL      = 12 * HOUR_IN_SECONDS;

	// Switching Gateway Keys back and forth re-reads the same rows; a short window keeps that to one call.
	public const GATEWAY_ROWS_TRANSIENT = 'iftp_fm_gateway_rows';
	private const GATEWAY_ROWS_TTL      = 5 * MINUTE_IN_SECONDS;

	// The status popup polls every 3-10s; I ask ifthenpay at most once per window per transaction.
	private const STATUS_TTL = 30;

	/**
	 * The last API/HTTP error message, if any.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * The last API/HTTP error message, if any.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * GET /gateway/get?boKey={key}&Type=Forminator, cached for GATEWAY_ROWS_TTL. A failed (empty) fetch isn't cached.
	 *
	 * @param string $backoffice_key The Backoffice Key to look up gateway rows for.
	 * @param bool   $fresh          Skip the cache, e.g. when connecting a key for the first time.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( string $backoffice_key, bool $fresh = false ): array {
		$key_hash = md5( $backoffice_key );
		$cached   = $fresh ? false : get_transient( self::GATEWAY_ROWS_TRANSIENT );

		if ( is_array( $cached ) && ( $cached['key_hash'] ?? '' ) === $key_hash && ! empty( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
			return $cached['rows'];
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'boKey' => $backoffice_key,
					'Type'  => self::GATEWAY_TYPE,
				),
				self::API_BASE . '/gateway/get'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = $this->decode_response( $response );

		if ( ! is_array( $body ) || array() === $body ) {
			return array();
		}

		$rows = isset( $body[0] ) ? $body : array( $body );

		set_transient(
			self::GATEWAY_ROWS_TRANSIENT,
			array(
				'key_hash' => $key_hash,
				'rows'     => $rows,
			),
			self::GATEWAY_ROWS_TTL
		);

		return $rows;
	}

	/**
	 * GET /gateway/methods/available, cached for CATALOG_TTL. A failed (empty) fetch isn't cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_methods(): array {
		$cached = get_transient( self::CATALOG_TRANSIENT );

		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::API_BASE . '/gateway/methods/available', array( 'timeout' => 15 ) );

		// An error page can still be JSON, and I don't want to cache that as the catalog for 12 hours.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body    = $this->decode_response( $response );
		$catalog = is_array( $body ) ? $body : array();

		if ( array() !== $catalog ) {
			set_transient( self::CATALOG_TRANSIENT, $catalog, self::CATALOG_TTL );
		}

		return $catalog;
	}

	/**
	 * POST /gateway/pinpay/{gateway_key}
	 *
	 * @param string               $gateway_key The Gateway Key to create the link under.
	 * @param array<string, mixed> $payload     The Pay by Link request payload.
	 * @return array{RedirectUrl: string}|false
	 */
	public function create_payment_link( string $gateway_key, array $payload ) {
		$this->last_error = '';

		$response = wp_remote_post(
			self::API_BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			return false;
		}

		$body = $this->decode_response( $response );

		if ( ! is_array( $body ) || empty( $body['RedirectUrl'] ) ) {
			$this->last_error = sprintf(
				'HTTP %d: %s',
				wp_remote_retrieve_response_code( $response ),
				wp_remote_retrieve_body( $response )
			);

			return false;
		}

		return $body;
	}

	/**
	 * GET /gateway/transaction/status/get?transactionId={id}, cached for STATUS_TTL.
	 * Failures are cached too, so a polling popup can't hammer ifthenpay while it's down.
	 *
	 * @param string $transaction_id The ifthenpay transaction id to look up.
	 * @return array<string, mixed>
	 */
	public function get_transaction_status( string $transaction_id ): array {
		$cache_key = 'iftp_fm_live_' . md5( $transaction_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			add_query_arg(
				array( 'transactionId' => $transaction_id ),
				self::API_BASE . '/gateway/transaction/status/get'
			),
			array( 'timeout' => 15 )
		);

		$status = array();

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body   = $this->decode_response( $response );
			$status = is_array( $body ) ? $body : array();
		}

		set_transient( $cache_key, $status, self::STATUS_TTL );

		return $status;
	}

	/**
	 * POST /endpoint/callback/activation/?cms=forminator — (re-)registers the webhook URL.
	 *
	 * @param string $gateway_key       The Gateway Key to register the webhook for.
	 * @param string $anti_phishing_key The key ifthenpay echoes back as `[ANTI_PHISHING_KEY]`.
	 * @param string $webhook_url       The webhook URL ifthenpay should call back.
	 */
	public function activate_callback( string $gateway_key, string $anti_phishing_key, string $webhook_url ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/endpoint/callback/activation/?cms=' . self::CALLBACK_CMS,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'apKey' => $anti_phishing_key,
						'chave' => $gateway_key,
						'urlCb' => $webhook_url,
					)
				),
				'timeout' => 15,
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * Decodes a wp_remote_* response body as JSON. Callers check is_wp_error() first.
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 * @return mixed
	 */
	private function decode_response( array $response ) {
		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
