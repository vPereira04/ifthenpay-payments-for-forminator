<?php
/**
 * Frontend payment-start AJAX handler.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Ajax;

use Ifthenpay\Forminator\Api\IfthenpayClient;
use Ifthenpay\Forminator\Api\IfthenpayPayload;
use Ifthenpay\Forminator\Forminator\FieldSettingsStore;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts an ifthenpay payment from the frontend, before Forminator's own
 * entry ever exists — see `Fields\IfthenpayField`'s docblock for why. I
 * store the submitted field values as a snapshot in `TransactionStore`;
 * `Api\Webhook\WebhookController` is what turns a paid row into a real
 * Forminator entry once payment is confirmed.
 */
class PaymentController {

	public const ACTION       = 'iftp_fm_create_payment';
	public const NONCE_ACTION = 'iftp_fm_payment';

	/**
	 * Query args that either we or ifthenpay's own redirect stamp onto the
	 * return URL. The frontend sends `window.location.href` as-is, so on a
	 * page that's still showing a previous return visit these are still in
	 * the address bar; left in place they'd get carried into this payment's
	 * own return URL and then have a fresh copy appended on top by us/
	 * ifthenpay again on the next round-trip, corrupting the confirmation
	 * popup's Amount/ID with values stacked from multiple payments.
	 */
	private const STALE_RETURN_ARGS = array( 'iftp_fm_pay', 'ref', 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'error' );

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
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Per-field ifthenpay settings storage.
	 *
	 * @var FieldSettingsStore
	 */
	private FieldSettingsStore $field_settings;

	/**
	 * Sets up the handler.
	 *
	 * @param SettingsRepository|null $settings       Settings storage; a fresh instance is used when omitted.
	 * @param IfthenpayClient|null    $client         Ifthenpay API client; a fresh instance is used when omitted.
	 * @param TransactionStore|null   $transactions   Transactions storage; a fresh instance is used when omitted.
	 * @param FieldSettingsStore|null $field_settings Per-field settings storage; a fresh instance is used when omitted.
	 */
	public function __construct(
		?SettingsRepository $settings = null,
		?IfthenpayClient $client = null,
		?TransactionStore $transactions = null,
		?FieldSettingsStore $field_settings = null
	) {
		$this->settings       = $settings ?? new SettingsRepository();
		$this->client         = $client ?? new IfthenpayClient();
		$this->transactions   = $transactions ?? new TransactionStore();
		$this->field_settings = $field_settings ?? new FieldSettingsStore();
	}

	/**
	 * Registers the priv and nopriv AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'create_payment' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'create_payment' ) );
	}

	/**
	 * Validates the submission, starts the ifthenpay payment, and stores the pending transaction.
	 */
	public function create_payment(): void {
		// A JSON error instead of check_ajax_referer()'s bare "-1", so a
		// stale nonce from a cached page tells the payer to reload.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page and try again.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}

		$form_id    = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
		$field_slug = sanitize_text_field( wp_unslash( $_POST['field_slug'] ?? '' ) );
		// Only ever sent back to this site — otherwise anyone could start a
		// payment that lands the payer on a third-party page afterwards.
		$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), '' );
		$return_url = '' !== $return_url ? remove_query_arg( self::STALE_RETURN_ARGS, $return_url ) : '';

		// An element_id => {label, value} map of the submitted (non-payment)
		// fields — keyed by element_id (not label) because that's what
		// WebhookController needs to write matching entry meta later; see
		// its own docblock for why the key has to be the element_id.
		$fields = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each label/value pair is sanitized individually in the loop body below.
		foreach ( (array) wp_unslash( $_POST['fields'] ?? array() ) as $element_id => $pair ) {
			$element_id = sanitize_text_field( (string) $element_id );

			if ( '' === $element_id || ! is_array( $pair ) ) {
				continue;
			}

			$label = isset( $pair['label'] ) && is_scalar( $pair['label'] ) ? sanitize_text_field( (string) $pair['label'] ) : $element_id;
			$value = isset( $pair['value'] ) && is_scalar( $pair['value'] ) ? sanitize_text_field( (string) $pair['value'] ) : '';

			$fields[ $element_id ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$amount = $this->resolve_amount( $form_id, $field_slug );

		if ( $form_id <= 0 || $amount <= 0 || '' === $return_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment request.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		if ( ! $this->settings->is_connected() || '' === $this->settings->get_gateway_key() ) {
			wp_send_json_error( array( 'message' => __( 'Ifthenpay is not configured yet. Please contact the site administrator.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$enabled_methods = $this->settings->get_enabled_methods();

		if ( array() === $enabled_methods ) {
			wp_send_json_error( array( 'message' => __( 'No ifthenpay payment methods are enabled yet. Please contact the site administrator.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$ref         = $this->generate_reference( $form_id );
		$gateway_key = $this->settings->get_gateway_key();

		$payload = IfthenpayPayload::build_payment_payload(
			$ref,
			$amount,
			$this->settings->get_description(),
			$enabled_methods,
			$this->build_return_url( 'success', $ref, $return_url ),
			$this->build_return_url( 'error', $ref, $return_url ),
			$this->build_return_url( 'cancel', $ref, $return_url ),
			IfthenpayPayload::locale_to_lang( get_locale() ),
			$this->settings->get_default_method_position()
		);

		$result = $this->client->create_payment_link( $gateway_key, $payload );

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional production error log for admin troubleshooting of failed ifthenpay API calls.
			error_log( 'ifthenpay Payments for Forminator: create_payment_link failed - ' . $this->client->get_last_error() );

			wp_send_json_error( array( 'message' => __( 'Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->transactions->store_pending( $ref, $form_id, $amount, $gateway_key, $fields, (string) $result['RedirectUrl'] );

		wp_send_json_success(
			array(
				'redirect_url' => $result['RedirectUrl'],
				'ref'          => $ref,
			)
		);
	}

	/**
	 * A fixed amount always comes from the saved field settings, never the
	 * request — the browser only gets a say for a variable amount, where the
	 * payer's own input is the price by design. 0 means the form has no
	 * ifthenpay field to charge from.
	 *
	 * @param int    $form_id    The form the payment belongs to.
	 * @param string $field_slug The ifthenpay field's slug, or ''.
	 */
	private function resolve_amount( int $form_id, string $field_slug ): float {
		$config = $this->field_settings->payment_config( $form_id, $field_slug );

		if ( null === $config ) {
			return 0.0;
		}

		if ( 'fixed' === $config['amount_type'] ) {
			return round( $config['amount'], 2 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked at the top of create_payment(), the only caller.
		return round( (float) sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) ), 2 );
	}

	/**
	 * `sub_id`-style sequential refs aren't available yet — no Forminator
	 * entry exists at this point — so I use a random reference instead,
	 * prefixed with the form id for at-a-glance grouping in the
	 * transactions admin list.
	 *
	 * @param int $form_id The form the payment belongs to.
	 */
	private function generate_reference( int $form_id ): string {
		do {
			$ref = 'FM_' . $form_id . '_' . strtoupper( wp_generate_password( 10, false, false ) );
		} while ( null !== $this->transactions->get( $ref ) );

		return $ref;
	}

	/**
	 * The success URL also carries a `[TRANSACTIONID]` placeholder ifthenpay
	 * fills in — only on success, since error/cancel never have a
	 * transaction to look up.
	 *
	 * @param string $status   One of 'success', 'error', 'cancel'.
	 * @param string $ref      The transaction reference.
	 * @param string $base_url The return URL supplied by the frontend.
	 */
	private function build_return_url( string $status, string $ref, string $base_url ): string {
		$args = array(
			'iftp_fm_pay' => $status,
			'ref'         => $ref,
		);

		if ( 'success' === $status ) {
			$args['transaction_id'] = '[TRANSACTIONID]';
		}

		return add_query_arg( $args, $base_url );
	}
}
