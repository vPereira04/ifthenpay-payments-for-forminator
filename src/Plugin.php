<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator;

use Ifthenpay\Forminator\Admin\ConnectSettings;
use Ifthenpay\Forminator\Admin\FieldSettingsModal;
use Ifthenpay\Forminator\Admin\GatewaySettingsPage;
use Ifthenpay\Forminator\Admin\PaymentEntriesPage;
use Ifthenpay\Forminator\Ajax\Controller as AjaxController;
use Ifthenpay\Forminator\Ajax\PaymentController;
use Ifthenpay\Forminator\Ajax\StatusController;
use Ifthenpay\Forminator\Api\Webhook\WebhookController;
use Ifthenpay\Forminator\Api\Webhook\WebhookRegistrar;
use Ifthenpay\Forminator\Fields\IfthenpayField;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap — wires every piece together once, on `plugins_loaded`.
 */
class Plugin {

	/**
	 * Script handle for the shared dark-background method logo swap.
	 */
	public const METHOD_LOGOS_HANDLE = 'iftp-fm-method-logos';

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The singleton instance, created on first access.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Singleton — use instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Wires up every hook and registers every AJAX/admin/frontend piece.
	 */
	public function boot(): void {
		// Safety net for a site that already had the plugin active before
		// this table existed — register_activation_hook() alone only fires
		// on a fresh install/reactivation.
		TransactionStore::maybe_create_table();
		add_action( 'admin_init', array( new TransactionStore(), 'maybe_backfill_customers' ) );

		add_filter( 'forminator_fields', array( $this, 'register_field' ) );

		( new ConnectSettings() )->register();
		( new GatewaySettingsPage() )->register();
		( new FieldSettingsModal() )->register();
		( new PaymentEntriesPage() )->register();
		( new AjaxController() )->register();
		( new PaymentController() )->register();
		( new StatusController() )->register();
		( new WebhookController() )->register();
		( new WebhookRegistrar() )->register();

		add_action( 'init', array( $this, 'register_shared_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
	}

	/**
	 * Registers the dark-background method logo swap once, so the frontend
	 * field and every admin screen showing a logo just list it as a dependency.
	 */
	public function register_shared_assets(): void {
		$logos_js = 'assets/js/method-logos.js';

		wp_register_script( self::METHOD_LOGOS_HANDLE, IFTP_FM_URL . $logos_js, array(), (string) filemtime( IFTP_FM_PATH . $logos_js ), true );
	}

	/**
	 * Registers the ifthenpay field into Forminator's builder.
	 *
	 * @param array<int, object> $fields Forminator's registered field instances.
	 * @return array<int, object>
	 */
	public function register_field( array $fields ): array {
		$fields[] = new IfthenpayField();

		return $fields;
	}

	/**
	 * Only enqueued on a page that could plausibly show our field (any
	 * singular content, since Forminator forms are usually embedded via
	 * shortcode/block rather than a dedicated page type) — the script itself
	 * is a no-op if it finds no `.forminator-ifthenpay-input` on the page.
	 */
	public function maybe_enqueue_frontend_assets(): void {
		wp_enqueue_style( 'iftp-fm-frontend', IFTP_FM_URL . 'assets/css/frontend.css', array(), IFTP_FM_VERSION );
		wp_enqueue_script( 'iftp-fm-frontend', IFTP_FM_URL . 'assets/js/frontend.js', array( 'jquery', self::METHOD_LOGOS_HANDLE ), IFTP_FM_VERSION, true );

		$settings = new SettingsRepository();
		$context  = $this->resolve_return_context();
		$record   = null !== $context ? ( new TransactionStore() )->get( $context['ref'] ) : null;

		wp_localize_script(
			'iftp-fm-frontend',
			'iftpFmFrontend',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( PaymentController::NONCE_ACTION ),
				'statusNonce'     => wp_create_nonce( 'iftp_fm_status' ),
				'paidPageUrl'     => $settings->get_paid_page_url(),
				'return'          => $context,
				'i18n'            => array(
					'redirecting' => __( 'Redirecting to secure payment…', 'ifthenpay-payments-for-forminator' ),
					'error'       => __( 'Something went wrong starting your payment. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'confirming'  => __( 'Confirming your payment…', 'ifthenpay-payments-for-forminator' ),
					'ok'          => __( 'OK', 'ifthenpay-payments-for-forminator' ),
					'close'       => __( 'Close', 'ifthenpay-payments-for-forminator' ),
					'openPage'    => __( 'Continue', 'ifthenpay-payments-for-forminator' ),
					'newTab'      => __( '(opens in a new tab)', 'ifthenpay-payments-for-forminator' ),
				),
				'titles'          => array(
					'paid'      => $this->status_title( 'paid' ),
					'pending'   => $this->status_title( 'pending' ),
					'failed'    => $this->status_title( 'failed' ),
					'cancelled' => $this->status_title( 'cancelled' ),
				),
				'messages'        => array(
					'paid'      => $this->status_message( 'paid' ),
					'pending'   => $this->status_message( 'pending' ),
					'failed'    => $this->status_message( 'failed' ),
					'cancelled' => $this->status_message( 'cancelled' ),
				),
				'paidFields'      => self::paid_fields( $record ),
			)
		);
	}

	/**
	 * Reads the `iftp_fm_pay`/`ref`/`transaction_id` return-URL params and
	 * resolves them against the stored transaction, or null if this isn't a return visit.
	 *
	 * @return array{ref: string, status: string, transactionId: string}|null
	 */
	private function resolve_return_context(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only return-URL params from ifthenpay's own redirect, resolved against the stored transaction below.
		$query_status = sanitize_text_field( wp_unslash( $_GET['iftp_fm_pay'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only return-URL params from ifthenpay's own redirect, resolved against the stored transaction below.
		$ref = sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) );

		if ( '' === $query_status || '' === $ref ) {
			return null;
		}

		$transactions = new TransactionStore();
		$record       = $transactions->get( $ref );

		if ( null === $record ) {
			return null;
		}

		$status = (string) $record['status'];

		// ifthenpay only ever sends a webhook for a paid payment, so this
		// redirect is the only signal I get for a decline/cancel — I persist
		// it, otherwise the row (and the status poll) stays "pending" forever.
		// mark_paid() still overrides it if the payment is confirmed later.
		if ( TransactionStore::STATUS_PENDING === $status ) {
			if ( 'error' === $query_status ) {
				$transactions->mark_failed( $ref );
				$status = TransactionStore::STATUS_FAILED;
			} elseif ( 'cancel' === $query_status ) {
				$transactions->mark_cancelled( $ref );
				$status = TransactionStore::STATUS_CANCELLED;
			}
		}

		return array(
			'ref'           => $ref,
			'status'        => $status,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only return-URL param from ifthenpay's own redirect.
			'transactionId' => 'success' === $query_status ? sanitize_text_field( wp_unslash( $_GET['transaction_id'] ?? '' ) ) : '',
		);
	}

	/**
	 * The confirmation message to show for a status: the admin-configured one if set, else the default.
	 *
	 * @param string $status One of TransactionStore's STATUS_* constants.
	 */
	public static function status_message( string $status ): string {
		$configured = ( new SettingsRepository() )->get_confirmation_message( $status );

		return '' !== $configured ? $configured : self::default_status_message( $status );
	}

	/**
	 * The confirmation title to show for a status: the admin-configured one if set, else the default.
	 *
	 * @param string $status One of TransactionStore's STATUS_* constants.
	 */
	public static function status_title( string $status ): string {
		$configured = ( new SettingsRepository() )->get_confirmation_title( $status );

		return '' !== $configured ? $configured : self::default_status_title( $status );
	}

	/**
	 * The paid transaction's submitted field values, for the popup's
	 * "Submitted Fields" table — empty unless the "Show submitted field
	 * values in the paid popup" setting is on and the record has a
	 * field snapshot to show.
	 *
	 * @param array<string, mixed>|null $record The transaction row, when known.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function paid_fields( ?array $record ): array {
		if ( null === $record || ! ( new SettingsRepository() )->get_show_entry_data() ) {
			return array();
		}

		return ( new TransactionStore() )->entry_data_pairs( $record );
	}

	/**
	 * The built-in confirmation message for a status.
	 *
	 * @param string $status One of TransactionStore's STATUS_* constants.
	 */
	public static function default_status_message( string $status ): string {
		switch ( $status ) {
			case TransactionStore::STATUS_PAID:
				return __( 'Payment confirmed. Thank you!', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_FAILED:
				return __( 'Payment failed. Please try again.', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_CANCELLED:
				return __( 'Payment cancelled.', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_EXPIRED:
				return __( 'This payment link expired.', 'ifthenpay-payments-for-forminator' );
			default:
				return __( "We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete.", 'ifthenpay-payments-for-forminator' );
		}
	}

	/**
	 * The built-in confirmation title for a status.
	 *
	 * @param string $status One of TransactionStore's STATUS_* constants.
	 */
	public static function default_status_title( string $status ): string {
		switch ( $status ) {
			case TransactionStore::STATUS_PAID:
				return __( 'Paid Successfully!', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_FAILED:
				return __( 'Payment Failed', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_CANCELLED:
				return __( 'Payment Cancelled', 'ifthenpay-payments-for-forminator' );
			case TransactionStore::STATUS_EXPIRED:
				return __( 'Payment Link Expired', 'ifthenpay-payments-for-forminator' );
			default:
				return __( 'Payment Pending', 'ifthenpay-payments-for-forminator' );
		}
	}
}
