<?php
/**
 * Inbound webhook handler.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

use Ifthenpay\Forminator\Forminator\FieldSettingsStore;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Forminator_CForm_Front_Action;
use Forminator_CForm_Front_Mail;
use Forminator_Form_Entry_Model;
use Forminator_Form_Model;
use Forminator_Front_Action;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only path that creates the real Forminator entry for an ifthenpay payment.
 * I replay the field snapshot taken when the payment started through
 * `Forminator_Form_Entry_Model`, a stable public API.
 */
class WebhookController {

	/**
	 * Ifthenpay calls back into `/{SLUG}/{ref}/?apk=...&val=...&mtd=...&req=...`.
	 */
	public const SLUG = 'iftp_fm_callback';

	/**
	 * Flags a request as hitting this endpoint.
	 */
	public const QUERY_VAR = 'iftp_fm_ep';

	public const REF_VAR = 'iftp_fm_ref';

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Anti-phishing + amount validator.
	 *
	 * @var WebhookValidator
	 */
	private WebhookValidator $validator;

	/**
	 * Per-field ifthenpay settings storage.
	 *
	 * @var FieldSettingsStore
	 */
	private FieldSettingsStore $field_settings;

	/**
	 * Sets up the controller.
	 *
	 * @param TransactionStore|null   $transactions   Transactions storage.
	 * @param WebhookValidator|null   $validator      Anti-phishing + amount validator.
	 * @param FieldSettingsStore|null $field_settings Per-field settings storage.
	 */
	public function __construct( ?TransactionStore $transactions = null, ?WebhookValidator $validator = null, ?FieldSettingsStore $field_settings = null ) {
		$this->transactions   = $transactions ?? new TransactionStore();
		$this->validator      = $validator ?? new WebhookValidator();
		$this->field_settings = $field_settings ?? new FieldSettingsStore();
	}

	/**
	 * Wires up the rewrite endpoint ifthenpay calls back into.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * `/{SLUG}/{ref}/` → `index.php?{QUERY_VAR}=1&{REF_VAR}={ref}`.
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^' . self::SLUG . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::REF_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Registers the endpoint's query vars as public.
	 *
	 * @param string[] $vars WordPress's registered public query vars.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::REF_VAR;

		return $vars;
	}

	/**
	 * Registers and flushes the rewrite rule. Run on activation, or the endpoint
	 * 404s until something else flushes the rules.
	 */
	public static function flush(): void {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * The callback URL template registered with ifthenpay. It fills in the
	 * `[ORDER_ID]`/`[ANTI_PHISHING_KEY]`/`[AMOUNT]`/`[PAYMENT_METHOD]`/`[REQUEST_ID]`
	 * placeholders on each call. No status placeholder: ifthenpay only calls the
	 * webhook for paid payments.
	 */
	public static function url(): string {
		$params = 'apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]';

		// With plain permalinks the rewrite rule never matches and the pretty
		// URL 404s — the query vars are public, so the query-string form works there.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return home_url( '/' ) . '?' . self::QUERY_VAR . '=1&' . self::REF_VAR . '=[ORDER_ID]&' . $params;
		}

		return home_url( '/' . self::SLUG . '/' ) . '[ORDER_ID]?' . $params;
	}

	/**
	 * Validates and processes an inbound webhook call.
	 */
	public function handle(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$ref = sanitize_text_field( wp_unslash( (string) get_query_var( self::REF_VAR ) ) );

		if ( '' === $ref ) {
			self::respond( 400, 'Missing reference' );
		}

		$record = $this->transactions->get( $ref );

		if ( null === $record ) {
			self::respond( 404, 'Unknown reference' );
		}

		$payload = WebhookPayload::from_request( $ref );

		if ( ! $this->validator->is_valid( $payload, $record ) ) {
			self::respond( 403, 'Invalid anti-phishing key or amount' );
		}

		$this->handle_success( $payload, $record );

		self::respond( 200, 'OK' );
	}

	/**
	 * Sends a plain-text response and exits. Not `wp_die()`: outside admin-ajax it
	 * renders a full HTML screen instead of the plain body ifthenpay expects.
	 *
	 * @param int    $status The HTTP status code to send.
	 * @param string $body   The plain-text response body.
	 */
	private static function respond( int $status, string $body ): void {
		status_header( $status );
		echo esc_html( $body );
		exit;
	}

	/**
	 * Marks the transaction paid and creates the real Forminator entry.
	 *
	 * @param WebhookPayload       $payload The parsed webhook payload.
	 * @param array<string, mixed> $record  The stored transaction row.
	 */
	private function handle_success( WebhookPayload $payload, array $record ): void {
		// false = already paid (a repeat webhook), so the entry exists already.
		if ( ! $this->transactions->mark_paid( $payload->ref(), $payload->method(), $payload->request_id() ) ) {
			return;
		}

		$entry_id = $this->create_forminator_entry( $record, $payload );

		if ( null !== $entry_id ) {
			$this->transactions->record_entry_id( $payload->ref(), $entry_id );
			$this->send_notifications( $entry_id );
		}
	}

	/**
	 * Sends the form's own email notifications for the new entry. Forminator only
	 * sends them from its AJAX submit, which a webhook never goes through, so I
	 * replay the setup of `Forminator_CForm_User_Data::resend_notification_email()`.
	 *
	 * @param int $entry_id The Forminator entry just created.
	 */
	private function send_notifications( int $entry_id ): void {
		if (
			! class_exists( Forminator_CForm_Front_Mail::class )
			|| ! class_exists( Forminator_Form_Entry_Model::class )
			|| ! class_exists( Forminator_Form_Model::class )
			|| ! class_exists( Forminator_Front_Action::class )
			|| ! class_exists( Forminator_CForm_Front_Action::class )
			|| ! function_exists( 'recreate_prepared_data' )
		) {
			return;
		}

		// A mail failure mustn't fail the webhook — the payment is already recorded.
		try {
			// A fresh model, so meta_data holds what set_fields() just wrote.
			$entry = new Forminator_Form_Entry_Model( $entry_id );
			$form  = FieldSettingsStore::load_model( (int) $entry->form_id );

			if ( null === $form ) {
				return;
			}

			Forminator_Front_Action::$module_id       = (int) $entry->form_id;
			Forminator_Front_Action::$module_object   = $form;
			Forminator_Front_Action::$prepared_data   = recreate_prepared_data( $form, $entry );
			Forminator_Front_Action::$module_settings = $form->get_form_settings();
			Forminator_CForm_Front_Action::check_fields_visibility();

			( new Forminator_CForm_Front_Mail() )->process_mail( $form, $entry );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- I log failed notification emails on purpose, for admin troubleshooting.
			error_log( 'ifthenpay Payments for Forminator: notification email failed for entry ' . $entry_id . ' - ' . $e->getMessage() );
		}
	}

	/**
	 * Replays the payment's field snapshot into a real Forminator entry, plus
	 * ifthenpay status/amount/method meta so Forminator's own Entries screen shows it.
	 *
	 * Meta names must be element_ids, not labels: Forminator's `build_field_mapper()`
	 * keys the Entries columns by field slug.
	 *
	 * @param array<string, mixed> $record  The stored transaction row.
	 * @param WebhookPayload       $payload The parsed webhook payload.
	 */
	private function create_forminator_entry( array $record, WebhookPayload $payload ): ?int {
		if ( ! class_exists( Forminator_Form_Entry_Model::class ) ) {
			return null;
		}

		$snapshot = json_decode( (string) ( $record['field_snapshot'] ?? '' ), true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		$model             = new Forminator_Form_Entry_Model();
		$model->entry_type = 'custom-forms';
		$model->form_id    = (int) $record['form_id'];
		$model->draft_id   = 0;
		$model->is_spam    = false;

		if ( ! $model->save() ) {
			return null;
		}

		$meta = array();

		foreach ( $snapshot as $element_id => $pair ) {
			$value = is_array( $pair ) ? ( $pair['value'] ?? '' ) : $pair;

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$meta[] = array(
				'name'  => (string) $element_id,
				'value' => (string) $value,
			);
		}

		// The snapshot never includes our own field, so without this it shows
		// as "N/A" in {all_fields} emails and the native Entries column.
		$payment_url = (string) ( $record['payment_url'] ?? '' );
		/* translators: %s: the ifthenpay payment page URL. */
		$field_value = '' !== $payment_url ? sprintf( __( 'Paid - %s', 'ifthenpay-payments-for-forminator' ), $payment_url ) : __( 'Paid', 'ifthenpay-payments-for-forminator' );

		foreach ( $this->field_settings->ifthenpay_field_slugs( (int) $record['form_id'] ) as $field_slug ) {
			$meta[] = array(
				'name'  => $field_slug,
				'value' => $field_value,
			);
		}

		$meta[] = array(
			'name'  => 'ifthenpay_ref',
			'value' => (string) $record['ref'],
		);
		$meta[] = array(
			'name'  => 'ifthenpay_status',
			'value' => 'paid',
		);
		$meta[] = array(
			'name'  => 'ifthenpay_amount',
			'value' => number_format( (float) $record['amount'], 2, '.', '' ),
		);
		$meta[] = array(
			'name'  => 'ifthenpay_pay_method',
			'value' => $payload->method(),
		);
		$meta[] = array(
			'name'  => 'ifthenpay_request_id',
			'value' => $payload->request_id(),
		);

		$model->set_fields( $meta );

		return (int) $model->entry_id;
	}
}
