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
 * The ifthenpay webhook's `$_GET` params, parsed into one typed shape.
 * No status field: ifthenpay only calls the webhook for paid payments.
 */
class WebhookPayload {

	/**
	 * The transaction reference, taken from the callback URL's path segment.
	 *
	 * @var string
	 */
	private string $ref;

	/**
	 * The anti-phishing key.
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
	 * @param string $apk        The anti-phishing key.
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server webhook; WebhookValidator checks the anti-phishing key instead.
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
	 * The anti-phishing key.
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
