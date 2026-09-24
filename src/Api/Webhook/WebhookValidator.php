<?php
/**
 * Webhook anti-phishing + amount validator.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anti-phishing + amount validation for an inbound webhook payload, checked
 * against the pending transaction record it claims to resolve.
 */
class WebhookValidator {

	private const AMOUNT_TOLERANCE = 0.01;

	/**
	 * Whether the payload's anti-phishing key and amount both match the stored record.
	 *
	 * @param WebhookPayload       $payload The parsed webhook payload.
	 * @param array<string, mixed> $record  As stored by TransactionStore.
	 */
	public function is_valid( WebhookPayload $payload, array $record ): bool {
		$gateway_key = (string) ( $record['gateway_key'] ?? '' );

		// "+ New Payment" rows store 'manual' here — base64('manual') is
		// trivial to forge, and those rows never come from ifthenpay anyway.
		if ( '' === $gateway_key || 'manual' === $gateway_key ) {
			return false;
		}

		// A '+' in the base64 key arrives as a space if ifthenpay didn't urlencode it.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- required by the ifthenpay anti-phishing key contract, not used for obfuscation.
		$decoded = base64_decode( str_replace( ' ', '+', $payload->apk() ), true );

		if ( false === $decoded || ! hash_equals( $gateway_key, $decoded ) ) {
			return false;
		}

		return abs( $payload->amount() - (float) $record['amount'] ) <= self::AMOUNT_TOLERANCE;
	}
}
