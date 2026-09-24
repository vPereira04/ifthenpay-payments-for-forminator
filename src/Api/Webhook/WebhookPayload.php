<?php
/**
 * Parsed inbound webhook payload.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the raw `$_GET` params ifthenpay's webhook calls in with, so the
 * controller and validator both work off one typed shape instead of poking
 * at superglobals themselves.
 *
 * No status/outcome field: ifthenpay only ever calls this webhook back on a
 * successful payment (see `WebhookController::url()`'s docblock), so a
 * request reaching here is a paid notice by definition once it validates.
 */
class WebhookPayload {

	/**
	 * The transaction reference, taken from the callback URL's path segment.
	 *
	 * @var string
	 */
	private string $ref;

	/**
	 * The base64-encoded anti-phishing key.
	 *
	 * @var string
	 */
	private string $apk;

	/**
	 * The reported paid amount.
	 *
	 * @var float
	 */
	private float $amount;

	/**
	 * The payment method used.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * The ifthenpay request id.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * Sets up the payload.
	 *
	 * @param string $ref        The transaction reference.
	 * @param string $apk        The base64-encoded anti-phishing key.
	 * @param float  $amount     The reported paid amount.
	 * @param string $method     The payment method used.
	 * @param string $request_id The ifthenpay request id.
	 */
	private function __construct( string $ref, string $apk, float $amount, string $method, string $request_id ) {
		$this->ref        = $ref;
		$this->apk        = $apk;
		$this->amount     = $amount;
		$this->method     = $method;
		$this->request_id = $request_id;
	}

	/**
	 * Builds a payload from the ref the rewrite rule captured plus the current request's `$_GET` params.
	 *
	 * @param string $ref The transaction reference, from `WebhookController::REF_VAR`.
	 */
	public static function from_request( string $ref ): self {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server webhook, verified via anti-phishing key instead (see WebhookValidator).
		return new self(
			$ref,
			sanitize_text_field( wp_unslash( $_GET['apk'] ?? '' ) ),
			(float) sanitize_text_field( wp_unslash( $_GET['val'] ?? '0' ) ),
			sanitize_text_field( wp_unslash( $_GET['mtd'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_GET['req'] ?? '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The transaction reference.
	 */
	public function ref(): string {
		return $this->ref;
	}

	/**
	 * The base64-encoded anti-phishing key.
	 */
	public function apk(): string {
		return $this->apk;
	}

	/**
	 * The reported paid amount.
	 */
	public function amount(): float {
		return $this->amount;
	}

	/**
	 * The payment method used.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * The ifthenpay request id.
	 */
	public function request_id(): string {
		return $this->request_id;
	}
}
