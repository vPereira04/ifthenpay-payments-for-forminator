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
use Ifthenpay\Forminator\Cron\ExpiredPaymentsCron;
use Ifthenpay\Forminator\Fields\IfthenpayField;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Privacy\PersonalData;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every piece together once, on `plugins_loaded`.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class Plugin {

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
	 * Registers every hook, AJAX action and admin screen.
	 */
	public function boot(): void {
		// Activation creates the table too; this catches schema upgrades on update.
		TransactionStore::maybe_create_table();

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
		( new ExpiredPaymentsCron() )->register();
		( new PersonalData() )->register();

		add_action( 'init', array( $this, 'register_shared_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_for_return' ) );
		add_action( 'forminator_custom_forms_enqueue_scripts', array( $this, 'maybe_enqueue_for_form' ) );
	}

	/**
	 * Registers the dark-background logo swap that the frontend and admin scripts depend on.
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
	 * Loads the popup on ifthenpay's return redirect, which can land on a page without the form (e.g. the home page).
	 */
	public function maybe_enqueue_for_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks whether to load assets; resolve_return_context() checks the signature.
		if ( isset( $_GET['iftp_fm_pay'] ) ) {
			$this->enqueue_frontend_assets();
		}
	}

	/**
	 * Loads with any form that has our field. Forminator fires this while rendering the shortcode, AJAX-loaded
	 * forms included (it renders a placeholder then), so the footer still prints what I enqueue here.
	 *
	 * @param object $render_obj Forminator's form renderer.
	 */
	public function maybe_enqueue_for_form( $render_obj ): void {
		if ( is_object( $render_obj ) && method_exists( $render_obj, 'has_field_type' ) && $render_obj->has_field_type( 'ifthenpay' ) ) {
			$this->enqueue_frontend_assets();
		}
	}

	/**
	 * Enqueues and localizes the frontend script once, however many forms ask for it.
	 */
	private function enqueue_frontend_assets(): void {
		if ( wp_script_is( 'iftp-fm-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( 'iftp-fm-frontend', IFTP_FM_URL . 'assets/css/frontend.css', array(), IFTP_FM_VERSION );
		wp_enqueue_script( 'iftp-fm-frontend', IFTP_FM_URL . 'assets/js/frontend.js', array( 'jquery', self::METHOD_LOGOS_HANDLE ), IFTP_FM_VERSION, true );

		$settings = new SettingsRepository();
		$context  = $this->resolve_return_context();
		$record   = null !== $context ? ( new TransactionStore() )->get( $context['ref'] ) : null;

		wp_localize_script(
			'iftp-fm-frontend',
			'iftpFmFrontend',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( PaymentController::NONCE_ACTION ),
				'statusNonce' => wp_create_nonce( 'iftp_fm_status' ),
				'paidPageUrl' => $settings->get_paid_page_url(),
				'return'      => $context,
				'i18n'        => array(
					'redirecting' => __( 'Redirecting to secure payment…', 'ifthenpay-payments-for-forminator' ),
					'error'       => __( 'Something went wrong starting your payment. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'confirming'  => __( 'Confirming your payment…', 'ifthenpay-payments-for-forminator' ),
					'ok'          => __( 'OK', 'ifthenpay-payments-for-forminator' ),
					'close'       => __( 'Close', 'ifthenpay-payments-for-forminator' ),
					'openPage'    => __( 'Continue', 'ifthenpay-payments-for-forminator' ),
					'newTab'      => __( '(opens in a new tab)', 'ifthenpay-payments-for-forminator' ),
				),
				'titles'      => array(
					'paid'      => $this->status_title( 'paid' ),
					'pending'   => $this->status_title( 'pending' ),
					'failed'    => $this->status_title( 'failed' ),
					'cancelled' => $this->status_title( 'cancelled' ),
				),
				'messages'    => array(
					'paid'      => $this->status_message( 'paid' ),
					'pending'   => $this->status_message( 'pending' ),
					'failed'    => $this->status_message( 'failed' ),
					'cancelled' => $this->status_message( 'cancelled' ),
				),
				'paidFields'  => self::paid_fields( $record ),
			)
		);
	}

	/**
	 * Resolves ifthenpay's return redirect against the stored transaction, or null when this isn't one.
	 *
	 * @return array{ref: string, status: string, transactionId: string}|null
	 */
	private function resolve_return_context(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- ifthenpay's redirect can't carry a nonce; the signature below authenticates it instead.
		$query_status = sanitize_text_field( wp_unslash( $_GET['iftp_fm_pay'] ?? '' ) );
		$ref          = sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) );
		$signature    = sanitize_text_field( wp_unslash( $_GET[ PaymentController::RETURN_SIG ] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $query_status || '' === $ref || ! hash_equals( PaymentController::return_signature( $ref, $query_status ), $signature ) ) {
			return null;
		}

		$transactions = new TransactionStore();
		$record       = $transactions->get( $ref );

		if ( null === $record ) {
			return null;
		}

		$status = $record['status'];

		// ifthenpay only calls the webhook for paid payments, so this redirect is
		// my only signal for a decline or cancel. A later webhook still wins.
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed return URL, verified above.
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
	 * The submitted values for the paid popup, when that setting is on.
	 *
	 * @param Transaction|null $record The transaction row, when known.
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
