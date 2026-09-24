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
	 * GET /gateway/get?boKey={key}&Type=Forminator
	 *
	 * @param string $backoffice_key The Backoffice Key to look up gateway rows for.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( string $backoffice_key ): array {
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

		$body = $this->decode_response( $response );

		if ( ! is_array( $body ) ) {
			return array();
		}

		return isset( $body[0] ) ? $body : array( $body );
	}

	/**
	 * GET /gateway/methods/available
	 *
	 * @return array<int, array{Entity: string, Method: string, IsVisible: bool, Position: int}>
	 */
	public function get_available_methods(): array {
		$response = wp_remote_get( self::API_BASE . '/gateway/methods/available', array( 'timeout' => 15 ) );

		$body = $this->decode_response( $response );

		return is_array( $body ) ? $body : array();
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
	 * GET /gateway/transaction/status/get?transactionId={id}
	 *
	 * @param string $transaction_id The ifthenpay transaction id to look up.
	 * @return array<string, mixed>
	 */
	public function get_transaction_status( string $transaction_id ): array {
		$response = wp_remote_get(
			add_query_arg(
				array( 'transactionId' => $transaction_id ),
				self::API_BASE . '/gateway/transaction/status/get'
			),
			array( 'timeout' => 15 )
		);

		$body = $this->decode_response( $response );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * POST /endpoint/callback/activation/?cms=forminator
	 *
	 * Registers (or re-registers) the webhook URL for this gateway key.
	 *
	 * @param string $gateway_key The Gateway Key to register the webhook for.
	 * @param string $webhook_url The webhook URL ifthenpay should call back.
	 */
	public function activate_callback( string $gateway_key, string $webhook_url ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/endpoint/callback/activation/?cms=' . self::CALLBACK_CMS,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by the ifthenpay callback-activation API contract, not used for obfuscation.
						'apKey' => base64_encode( $gateway_key ),
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
	 * Decodes a wp_remote_* response body as JSON, or null on transport error.
	 *
	 * @param array<string, mixed>|\WP_Error $response The wp_remote_get()/wp_remote_post() return value.
	 * @return mixed
	 */
	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
