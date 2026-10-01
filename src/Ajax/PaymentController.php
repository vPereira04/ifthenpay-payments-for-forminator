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
use Ifthenpay\Forminator\Forminator\VariableAmount;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts a payment from the frontend, before any Forminator entry exists.
 * I keep the submitted values as a snapshot; the webhook turns a paid row
 * into the real entry.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class PaymentController {

	public const ACTION       = 'iftp_fm_create_payment';
	public const NONCE_ACTION = 'iftp_fm_payment';
	public const RETURN_SIG   = 'iftp_fm_sig';

	/**
	 * Args we or ifthenpay stamp on the return URL. I strip them from the
	 * incoming one so a second payment doesn't stack them. Keep in sync with
	 * STALE_RETURN_ARGS in frontend.js.
	 */
	private const STALE_RETURN_ARGS = array( 'iftp_fm_pay', 'ref', self::RETURN_SIG, 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'error' );

	/**
	 * Caps on what an anonymous request can put into the snapshot.
	 */
	private const MAX_FIELDS      = 200;
	private const MAX_VALUE_CHARS = 5000;

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
	 * Server-side price for variable amounts.
	 *
	 * @var VariableAmount
	 */
	private VariableAmount $variable_amount;

	/**
	 * Sets up the handler.
	 *
	 * @param SettingsRepository|null $settings        Settings storage.
	 * @param IfthenpayClient|null    $client          Ifthenpay API client.
	 * @param TransactionStore|null   $transactions    Transactions storage.
	 * @param FieldSettingsStore|null $field_settings  Per-field settings storage.
	 * @param VariableAmount|null     $variable_amount Server-side price for variable amounts.
	 */
	public function __construct(
		?SettingsRepository $settings = null,
		?IfthenpayClient $client = null,
		?TransactionStore $transactions = null,
		?FieldSettingsStore $field_settings = null,
		?VariableAmount $variable_amount = null
	) {
		$this->settings        = $settings ?? new SettingsRepository();
		$this->client          = $client ?? new IfthenpayClient();
		$this->transactions    = $transactions ?? new TransactionStore();
		$this->field_settings  = $field_settings ?? new FieldSettingsStore();
		$this->variable_amount = $variable_amount ?? new VariableAmount();
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
		// A JSON error instead of check_ajax_referer()'s bare "-1", so a stale
		// nonce on a cached page tells the payer to reload.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page and try again.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}

		$form_id    = isset( $_POST['form_id'] ) && is_string( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$field_slug = isset( $_POST['field_slug'] ) && is_string( $_POST['field_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['field_slug'] ) ) : '';
		$return_url = isset( $_POST['return_url'] ) && is_string( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : '';

		// Only ever back to this site, or anyone could start a payment that lands the payer elsewhere.
		$return_url = wp_validate_redirect( $return_url, '' );
		$return_url = '' !== $return_url ? remove_query_arg( self::STALE_RETURN_ARGS, $return_url ) : '';

		$amount = $this->resolve_amount( $form_id, $field_slug );

		if ( $form_id <= 0 || $amount <= 0 || '' === $return_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment request.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		if ( ! $this->settings->is_connected() || '' === $this->settings->get_gateway_key() ) {
			wp_send_json_error( array( 'message' => __( 'ifthenpay is not configured yet. Please contact the site administrator.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		if ( array() === $this->settings->get_enabled_methods() ) {
			wp_send_json_error( array( 'message' => __( 'No ifthenpay payment methods are enabled yet. Please contact the site administrator.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$ref          = $this->transactions->unique_ref( 'FM_' . $form_id . '_', 10 );
		$gateway_key  = $this->settings->get_gateway_key();
		$redirect_url = $this->request_link( $gateway_key, $ref, $amount, $return_url );

		if ( '' === $redirect_url ) {
			wp_send_json_error( array( 'message' => __( 'Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->transactions->store_pending( $ref, $form_id, $amount, $gateway_key, $this->submitted_fields( $form_id ), $redirect_url );

		wp_send_json_success(
			array(
				'redirect_url' => $redirect_url,
				'ref'          => $ref,
			)
		);
	}

	/**
	 * The posted `fields[element_id][label|value]` snapshot. I keep only the form's own
	 * fields (`name-1`, `name-1-first-name`, repeater copies like `email-1-2`), so nothing
	 * else can reach the entry.
	 *
	 * @param int $form_id The form the payment belongs to.
	 * @return array<string, array{label: string, value: string}>
	 */
	private function submitted_fields( int $form_id ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- create_payment() verified the nonce; each label/value is sanitized below.
		$raw    = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$slugs  = $this->field_settings->value_field_slugs( $form_id );
		$fields = array();

		foreach ( array_slice( $raw, 0, self::MAX_FIELDS, true ) as $element_id => $pair ) {
			$element_id = sanitize_text_field( (string) $element_id );

			if ( ! is_array( $pair ) || ! self::belongs_to( $element_id, $slugs ) ) {
				continue;
			}

			$label = isset( $pair['label'] ) && is_scalar( $pair['label'] ) ? sanitize_text_field( (string) $pair['label'] ) : '';
			$value = isset( $pair['value'] ) && is_scalar( $pair['value'] ) ? sanitize_textarea_field( (string) $pair['value'] ) : '';

			$fields[ $element_id ] = array(
				'label' => '' !== $label ? $label : $element_id,
				'value' => mb_substr( $value, 0, self::MAX_VALUE_CHARS ),
			);
		}

		return $fields;
	}

	/**
	 * Whether an input name is one of the fields, or a part/copy of one.
	 *
	 * @param string   $element_id The posted input name.
	 * @param string[] $slugs      The form's field slugs.
	 */
	private static function belongs_to( string $element_id, array $slugs ): bool {
		foreach ( $slugs as $slug ) {
			if ( $element_id === $slug || 0 === strpos( $element_id, $slug . '-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates and stores a link for a pending row that never got one ("Resend
	 * payment email"). I don't know the page they paid from, so the link
	 * returns to the home page; the popup works there too.
	 *
	 * @param array<string, mixed> $record A pending transaction row.
	 * @phpstan-param Transaction $record
	 * @return string The new link, or '' when ifthenpay isn't set up or refused it.
	 */
	public function create_link_for( array $record ): string {
		$gateway_key = $this->settings->get_gateway_key();

		if ( ! $this->settings->is_connected() || '' === $gateway_key || array() === $this->settings->get_enabled_methods() ) {
			return '';
		}

		$ref          = $record['ref'];
		$redirect_url = $this->request_link( $gateway_key, $ref, $record['amount'], home_url( '/' ) );

		if ( '' !== $redirect_url ) {
			$this->transactions->record_payment_link( $ref, $redirect_url, $gateway_key );
		}

		return $redirect_url;
	}

	/**
	 * Asks ifthenpay for a Pay by Link URL for this ref and amount.
	 *
	 * @param string $gateway_key The Gateway Key to create the link under.
	 * @param string $ref         The transaction reference.
	 * @param float  $amount      The amount to charge.
	 * @param string $return_url  Where ifthenpay sends the payer back to.
	 * @return string The link, or '' on failure (logged).
	 */
	private function request_link( string $gateway_key, string $ref, float $amount, string $return_url ): string {
		$payload = IfthenpayPayload::build_payment_payload(
			$ref,
			$amount,
			$this->settings->get_description(),
			$this->settings->get_enabled_methods(),
			$this->build_return_url( 'success', $ref, $return_url ),
			$this->build_return_url( 'error', $ref, $return_url ),
			$this->build_return_url( 'cancel', $ref, $return_url ),
			IfthenpayPayload::locale_to_lang( get_locale() ),
			$this->settings->get_default_method_position(),
			$this->settings->get_expiry_days()
		);

		$result = $this->client->create_payment_link( $gateway_key, $payload );

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- I log failed API calls on purpose, for admin troubleshooting.
			error_log( 'ifthenpay Payments for Forminator: create_payment_link failed - ' . $this->client->get_last_error() );

			return '';
		}

		return (string) $result['RedirectUrl'];
	}

	/**
	 * The amount to charge, always worked out server-side: a fixed amount from the
	 * saved field settings, a variable one from the submitted form (see VariableAmount).
	 * The browser's own total is never trusted. 0 means there's nothing valid to charge.
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

		$amount = $this->variable_amount->resolve( $form_id, $config['variable'], $this->posted_form_data() );

		return null !== $amount ? round( $amount, 2 ) : 0.0;
	}

	/**
	 * The whole form as Forminator would receive it, JSON-encoded by frontend.js
	 * so a big form can't hit max_input_vars.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_form_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- create_payment() verified the nonce; the decoded values are sanitized below, sanitizing the JSON itself could corrupt it.
		$raw  = isset( $_POST['form_data'] ) && is_string( $_POST['form_data'] ) ? wp_unslash( $_POST['form_data'] ) : '';
		$data = json_decode( $raw, true );

		return is_array( $data ) ? map_deep( $data, 'sanitize_text_field' ) : array();
	}

	/**
	 * A return URL for one outcome. Only success gets the `[TRANSACTIONID]`
	 * placeholder, since error/cancel have no transaction to look up.
	 *
	 * @param string $status   One of 'success', 'error', 'cancel'.
	 * @param string $ref      The transaction reference.
	 * @param string $base_url The return URL supplied by the frontend.
	 */
	private function build_return_url( string $status, string $ref, string $base_url ): string {
		$args = array(
			'iftp_fm_pay'    => $status,
			'ref'            => $ref,
			self::RETURN_SIG => self::return_signature( $ref, $status ),
		);

		if ( 'success' === $status ) {
			$args['transaction_id'] = '[TRANSACTIONID]';
		}

		return add_query_arg( $args, $base_url );
	}

	/**
	 * Signs a return URL's ref + outcome. The payer comes back through a plain
	 * GET from ifthenpay, so there's no nonce to check; this proves I issued
	 * the URL, and that nobody flipped a success URL into a cancel one.
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of 'success', 'error', 'cancel'.
	 */
	public static function return_signature( string $ref, string $status ): string {
		return wp_hash( 'iftp_fm_return|' . $ref . '|' . $status );
	}
}
