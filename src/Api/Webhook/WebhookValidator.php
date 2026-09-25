<?php
/**
 * Webhook anti-phishing + amount validator.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

use Ifthenpay\Forminator\Repository\SettingsRepository;

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
	 * Settings storage, holding the anti-phishing key per Gateway Key.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the validator.
	 *
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Whether the payload's anti-phishing key and amount both match the stored record.
	 *
	 * @param WebhookPayload       $payload The parsed webhook payload.
	 * @param array<string, mixed> $record  As stored by TransactionStore.
	 */
	public function is_valid( WebhookPayload $payload, array $record ): bool {
		$gateway_key = (string) ( $record['gateway_key'] ?? '' );

		// "+ New Payment" rows store 'manual' here and never come from ifthenpay.
		if ( '' === $gateway_key || 'manual' === $gateway_key ) {
			return false;
		}

		$expected = $this->settings->get_anti_phishing_key( $gateway_key );
		$received = $payload->apk();

		// No random key yet means the callback is still registered with the old
		// base64(gateway key) scheme, so I accept that until the next re-registration.
		if ( '' === $expected ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- legacy anti-phishing scheme for callbacks registered before random keys, not used for obfuscation.
			$expected = base64_encode( $gateway_key );
			// A '+' in the base64 key arrives as a space if ifthenpay didn't urlencode it.
			$received = str_replace( ' ', '+', $received );
		}

		if ( ! hash_equals( $expected, $received ) ) {
			return false;
		}

		return abs( $payload->amount() - (float) $record['amount'] ) <= self::AMOUNT_TOLERANCE;
	}
}
