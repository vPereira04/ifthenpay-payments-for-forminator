<?php
/**
 * Inbound webhook handler.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api\Webhook;

use Ifthenpay\Forminator\Forminator\TransactionStore;
use Forminator_Form_Entry_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only path that ever creates the real Forminator entry for an ifthenpay
 * payment — see `Fields\IfthenpayField` and `Ajax\PaymentController` for why
 * entry creation is deferred this far. Uses `Forminator_Form_Entry_Model`
 * directly (a stable public API), replaying the field snapshot captured when
 * the payment started.
 */
class WebhookController {

	/**
	 * The rewrite endpoint's path segment: ifthenpay calls back into
	 * `/{SLUG}/{ref}/?apk=...&val=...&mtd=...&req=...`.
	 */
	public const SLUG = 'iftp_fm_callback';

	/**
	 * The query var flagging a request as hitting this endpoint.
	 */
	public const QUERY_VAR = 'iftp_fm_ep';

	/**
	 * The query var the rewrite rule captures the ref into.
	 */
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
	 * Sets up the controller.
	 *
	 * @param TransactionStore|null $transactions Transactions storage; a fresh instance is used when omitted.
	 * @param WebhookValidator|null $validator    Anti-phishing + amount validator; a fresh instance is used when omitted.
	 */
	public function __construct( ?TransactionStore $transactions = null, ?WebhookValidator $validator = null ) {
		$this->transactions = $transactions ?? new TransactionStore();
		$this->validator    = $validator ?? new WebhookValidator();
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
	 * Registers the rewrite rule and flushes it into `rewrite_rules` right
	 * away — called on activation, since the rule otherwise only takes
	 * effect after the next flush (a visit to Permalinks, or another
	 * plugin's own flush) and the endpoint would 404 until then.
	 */
	public static function flush(): void {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * The callback URL template registered with ifthenpay for this gateway
	 * key. `[ORDER_ID]`/`[ANTI_PHISHING_KEY]`/`[AMOUNT]`/`[PAYMENT_METHOD]`/
	 * `[REQUEST_ID]` are ifthenpay API placeholder tokens it substitutes with
	 * the real values on each call — no status placeholder, since ifthenpay
	 * only ever calls this back on a successful payment.
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
	 * Plain-text response, not `wp_die()` — this runs on a normal
	 * `template_redirect`, not `admin-ajax.php`, so `wp_die()` would render
	 * its full HTML die screen instead of the plain body ifthenpay expects.
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
		}
	}

	/**
	 * Replays the field snapshot captured by `Ajax\PaymentController` into a
	 * real Forminator entry, now that payment is confirmed. Also writes the
	 * ifthenpay status/amount/method onto the entry's own meta, so it's
	 * visible from Forminator's native per-form Entries screen too, not only
	 * `Admin\PaymentEntriesPage`.
	 *
	 * Meta `name` here has to be each field's element_id, not its label —
	 * that's what admin-page-entries.php's `build_field_mapper()` keys its
	 * columns by (`$mapper['meta_key'] = $field->slug`). The snapshot is
	 * already element_id-keyed for exactly this reason (see
	 * `Ajax\PaymentController::create_payment()`'s docblock).
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
