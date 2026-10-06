<?php
/**
 * Payment Entries admin dashboard.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Ajax\PaymentController;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Mail\PaymentRequestEmail;
use Ifthenpay\Forminator\Plugin;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "ifthenpay Entries" admin screen.
 *
 * Forminator only lists real entries, so I cover the ifthenpay activity that has none yet (pending/failed/cancelled).
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class PaymentEntriesPage {

	private const PAGE_SLUG        = 'iftp-fm-transactions';
	private const DEFAULT_PER_PAGE = 50;
	private const PER_PAGE_OPTIONS = array( 10, 25, 50, 100, 250 );
	private const PER_PAGE_MIN     = 1;
	private const PER_PAGE_MAX     = 250;
	public const NONCE_ACTION      = 'iftp_fm_entries';
	private const EXPORT_ACTION    = 'iftp_fm_export';
	private const EXPORT_CSV       = 'csv';
	private const EXPORT_JSON      = 'json';
	private const HIDDEN_COLS_META = 'iftp_fm_entries_hidden_cols';
	private const COL_ORDER_META   = 'iftp_fm_entries_col_order';
	private const PER_PAGE_META    = 'iftp_fm_entries_per_page';

	// entries.js splits bigger selections into chunks this size; I refuse anything past BULK_MAX_REFS.
	private const BULK_CHUNK_SIZE = 250;
	private const BULK_MAX_REFS   = 1000;

	// Emails are slow (SMTP, maybe an ifthenpay call each), so they go in much smaller chunks, and a request
	// stops taking new refs after EMAIL_TIME_BUDGET seconds so it stays well inside max_execution_time.
	private const EMAIL_CHUNK_SIZE  = 10;
	private const EMAIL_TIME_BUDGET = 15;

	// send_payment_email() outcomes.
	private const EMAIL_SENT        = 'sent';
	private const EMAIL_INELIGIBLE  = 'ineligible';
	private const EMAIL_NO_LINK     = 'no_link';
	private const EMAIL_MAIL_FAILED = 'mail_failed';

	// The Created column shows "2 hours ago" up to this age, then the short date.
	private const RELATIVE_DATE_MAX_AGE = 30 * DAY_IN_SECONDS;

	// Default order. Reference and the checkbox column always stay.
	private const HIDEABLE_COLUMNS = array( 'customer', 'form', 'method', 'amount', 'status', 'entry', 'created' );

	// Method names that match no entity code or alias even after method_key() normalizes them.
	private const METHOD_SYNONYMS = array( 'CREDITCARD' => 'CCARD' );

	/**
	 * The current admin's column order, read once per request.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $column_order_cache = null;

	/**
	 * The current request's view state, read once per request.
	 *
	 * @var array{s: string, status: string, form_id: int, date_from: string, date_to: string, pay_method: string, amount_min: string, amount_max: string, per_page: int, paged: int, orderby: string, order: string}|null
	 */
	private ?array $current_filters_cache = null;

	/**
	 * Row-invariant strings, built once per request since a page can hold 250 rows.
	 *
	 * @var array<string, string>
	 */
	private array $render_memo = array();

	/**
	 * Column slug => translated label, built on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $column_labels_cache = null;

	/**
	 * Row labels, built on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $row_text_cache = null;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Settings storage, for the Method column's logos.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * The payment email editor behind the masthead's settings button.
	 *
	 * @var PaymentEmailSettings
	 */
	private PaymentEmailSettings $email_settings;

	/**
	 * Sets up the page.
	 *
	 * @param TransactionStore|null   $transactions Transactions storage; a fresh instance is used when omitted.
	 * @param SettingsRepository|null $settings     Settings storage.
	 */
	public function __construct( ?TransactionStore $transactions = null, ?SettingsRepository $settings = null ) {
		$this->transactions   = $transactions ?? new TransactionStore();
		$this->settings       = $settings ?? new SettingsRepository();
		$this->email_settings = new PaymentEmailSettings( $this->settings );
	}

	/**
	 * Registers the menu, assets, AJAX and export hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_iftp_fm_entry_details', array( $this, 'ajax_entry_details' ) );
		add_action( 'wp_ajax_iftp_fm_delete_entries', array( $this, 'ajax_delete_entries' ) );
		add_action( 'wp_ajax_iftp_fm_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_iftp_fm_create_entry', array( $this, 'ajax_create_entry' ) );
		add_action( 'wp_ajax_iftp_fm_save_columns', array( $this, 'ajax_save_columns' ) );
		add_action( 'wp_ajax_iftp_fm_resend_payment_email', array( $this, 'ajax_resend_payment_email' ) );
		add_action( 'wp_ajax_iftp_fm_send_payment_emails', array( $this, 'ajax_send_payment_emails' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );

		$this->email_settings->register();
	}

	/**
	 * Registers a top-level menu page.
	 *
	 * Not under Forminator's menu: its `manage_forminator` cap handling doesn't fit a plain submenu entry.
	 * Skipped until a Backoffice Key is connected, which also makes WP refuse the page URL.
	 */
	public function add_menu_page(): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		$hook = add_menu_page(
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-forminator' ),
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-forminator' ),
			Plugin::admin_cap(),
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-money-alt'
		);

		add_action( 'load-' . $hook, array( $this, 'remember_per_page' ) );
	}

	/**
	 * Saves the admin's per-page choice whenever the URL carries one.
	 */
	public function remember_per_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- per-user display preference from this screen's GET view, like core's list-table mode.
		if ( ! isset( $_GET['per_page'] ) ) {
			return;
		}

		$per_page = $this->current_filters()['per_page'];

		if ( self::DEFAULT_PER_PAGE === $per_page ) {
			delete_user_meta( get_current_user_id(), self::PER_PAGE_META );
		} elseif ( $this->saved_per_page() !== $per_page ) {
			update_user_meta( get_current_user_id(), self::PER_PAGE_META, $per_page );
		}
	}

	/**
	 * The current admin's saved per-page, or the default.
	 */
	private function saved_per_page(): int {
		return $this->sanitize_per_page( (string) get_user_meta( get_current_user_id(), self::PER_PAGE_META, true ) );
	}

	/**
	 * Enqueues this screen's own script/style, only on this screen.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		$entries_css = 'assets/css/entries.css';
		$entries_js  = 'assets/js/entries.js';
		$restore_js  = 'assets/js/entries-restore.js';

		$email_settings = $this->email_settings->enqueue();
		$entries_deps   = array( Plugin::METHOD_LOGOS_HANDLE );

		if ( false !== $email_settings['editor'] ) {
			$entries_deps[] = 'code-editor';
		}

		wp_enqueue_style( 'iftp-fm-entries', IFTP_FM_URL . $entries_css, array(), Plugin::asset_version( $entries_css ) );
		wp_enqueue_script( 'iftp-fm-entries', IFTP_FM_URL . $entries_js, $entries_deps, Plugin::asset_version( $entries_js ), true );

		// Head, not footer — it may redirect to the saved view, and I don't want the default one flashing first.
		wp_enqueue_script( 'iftp-fm-entries-restore', IFTP_FM_URL . $restore_js, array(), Plugin::asset_version( $restore_js ), false );

		global $wp_locale;

		$view = $this->url_args( $this->current_filters() );

		wp_localize_script(
			'iftp-fm-entries-restore',
			'iftpFmEntriesStore',
			array(
				// Per site and per user, so a shared browser doesn't mix admins' views.
				'key'      => 'iftp_fm_entries:' . get_current_blog_id() . ':' . get_current_user_id(),
				'pageSlug' => self::PAGE_SLUG,
				// Per-page lives in user meta (remember_per_page()), so the saved view leaves it out.
				'view'     => array_diff_key( $view, array( 'per_page' => true ) ),
			)
		);

		wp_localize_script(
			'iftp-fm-entries',
			'iftpFmEntries',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( self::NONCE_ACTION ),
				'exportUrl'          => admin_url( 'admin-post.php' ),
				'exportNonce'        => wp_create_nonce( self::EXPORT_ACTION ),
				'currentFilters'     => $this->current_filters(),
				// Plain labels for the confirm dialog; the bulk <select> has its own "Mark as …" wording.
				'statusLabels'       => $this->status_filters( false ),
				'defaultColumnOrder' => self::HIDEABLE_COLUMNS,
				'bulkChunkSize'      => self::BULK_CHUNK_SIZE,
				'emailChunkSize'     => self::EMAIL_CHUNK_SIZE,
				'emailSettings'      => $email_settings,
				// Same separator as number_format_i18n(), so counts JS writes match the ones PHP rendered.
				// Decoded, since some locales store it as "&nbsp;" and JS writes textContent.
				'numberFormat'       => array(
					'thousands' => html_entity_decode( $wp_locale instanceof \WP_Locale ? (string) $wp_locale->number_format['thousands_sep'] : ',', ENT_QUOTES, 'UTF-8' ),
				),
				'i18n'               => array(
					'confirmDeleteTitle'    => __( 'Delete selected entries?', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteMessage'  => __( 'This only removes their payment-tracking record here — any real Forminator entry already created from it is left untouched.', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of paid entries in the selection */
					'deleteKeepsPaid'       => __( 'Paid entries are payment history, so the %s paid ones in your selection are kept.', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteButton'   => __( 'Delete', 'ifthenpay-payments-for-forminator' ),
					'detailsLoading'        => __( 'Loading details…', 'ifthenpay-payments-for-forminator' ),
					'detailsError'          => __( 'Could not load the details. Close and open them again to retry.', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusTitle'    => __( 'Change status?', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: target status label, e.g. "Paid" */
					'confirmStatusMessage'  => __( 'Mark the selected entries as "%s".', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusButton'   => __( 'Change status', 'ifthenpay-payments-for-forminator' ),
					'deleteError'           => __( 'Could not delete the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'updateStatusError'     => __( 'Could not update the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'deleteToastSuccess'    => __( 'Selected entries deleted.', 'ifthenpay-payments-for-forminator' ),
					'statusToastSuccess'    => __( 'Selected entries updated.', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: entries done so far, 2: entries selected */
					'bulkDeleting'          => __( 'Deleting… %1$s of %2$s', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: entries done so far, 2: entries selected */
					'bulkUpdating'          => __( 'Updating… %1$s of %2$s', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: entries done before the error, 2: entries selected */
					'bulkPartialError'      => __( 'Stopped after %1$s of %2$s entries. The rest are still selected — try again.', 'ifthenpay-payments-for-forminator' ),
					'bulkLeaveWarning'      => __( 'A bulk action is still running. Leave anyway?', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: number of selected entries, 2: total number of entries */
					'selectedOfTotal'       => __( '%1$s selected out of %2$s', 'ifthenpay-payments-for-forminator' ),
					'newEntryCreating'      => __( 'Creating…', 'ifthenpay-payments-for-forminator' ),
					'newEntryToastSuccess'  => __( 'Payment recorded.', 'ifthenpay-payments-for-forminator' ),
					'newEntryError'         => __( 'Could not record the payment. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'copyLinkSuccess'       => __( 'Payment link copied.', 'ifthenpay-payments-for-forminator' ),
					'copyLinkError'         => __( 'Could not copy the link.', 'ifthenpay-payments-for-forminator' ),
					'copyRefSuccess'        => __( 'Reference copied.', 'ifthenpay-payments-for-forminator' ),
					'copyRefError'          => __( 'Could not copy the reference.', 'ifthenpay-payments-for-forminator' ),
					'confirmResendTitle'    => __( 'Send payment email?', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: customer email address */
					'confirmResendMessage'  => __( '%s will get an email with the payment button and the details they filled in.', 'ifthenpay-payments-for-forminator' ),
					'confirmResendButton'   => __( 'Send email', 'ifthenpay-payments-for-forminator' ),
					'resendSending'         => __( 'Sending…', 'ifthenpay-payments-for-forminator' ),
					'resendError'           => __( 'Could not send the payment email. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'bulkEmailTitle'        => __( 'Send payment emails?', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of selected entries */
					'bulkEmailMessage'      => __( 'The customers of the %s selected pending payments will each get an email with their payment button. Entries without a customer email are skipped.', 'ifthenpay-payments-for-forminator' ),
					'bulkEmailButton'       => __( 'Send emails', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: entries done so far, 2: entries selected */
					'bulkEmailing'          => __( 'Sending emails… %1$s of %2$s', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of emails sent */
					'bulkEmailSent'         => __( 'Payment emails sent: %s.', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of entries skipped */
					'bulkEmailSkipped'      => __( 'Skipped (no longer pending, or no customer email): %s.', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of emails that failed */
					'bulkEmailFailed'       => __( 'Failed: %s. They are still selected, so you can try again.', 'ifthenpay-payments-for-forminator' ),
					'columnsSaveError'      => __( 'Could not save your column choice.', 'ifthenpay-payments-for-forminator' ),
					'exportNothingSelected' => __( 'Select at least one entry to export.', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: column name, 2: new position, 3: number of movable columns */
					'columnMoved'           => __( '%1$s moved to position %2$s of %3$s.', 'ifthenpay-payments-for-forminator' ),
					'columnsReset'          => __( 'Columns reset to the default order, all shown.', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of selected entries */
					'bulkSelectedCount'     => __( '%s selected', 'ifthenpay-payments-for-forminator' ),
					'emailSaving'           => __( 'Saving…', 'ifthenpay-payments-for-forminator' ),
					'emailSaveError'        => __( 'Could not save the payment email. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'emailPreviewError'     => __( 'Could not refresh the preview.', 'ifthenpay-payments-for-forminator' ),
					'emailResetDone'        => __( 'Default template restored. Save to keep it.', 'ifthenpay-payments-for-forminator' ),
					'emailMediaTitle'       => __( 'Choose an image for the email', 'ifthenpay-payments-for-forminator' ),
					'emailMediaButton'      => __( 'Insert image', 'ifthenpay-payments-for-forminator' ),
				),
			)
		);
	}

	/**
	 * AJAX: one row's details panel, fetched the first time View opens it.
	 */
	public function ajax_entry_details(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$record = $this->transactions->get( sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ) );

		if ( null === $record ) {
			wp_send_json_error( array( 'message' => __( 'This entry no longer exists.', 'ifthenpay-payments-for-forminator' ) ), 404 );
		}

		ob_start();
		$this->render_details( $record );

		wp_send_json_success( array( 'html' => (string) ob_get_clean() ) );
	}

	/**
	 * AJAX: deletes the given refs, except paid ones, and returns the re-rendered current view.
	 */
	public function ajax_delete_entries(): void {
		$this->guard();

		$deleted = $this->transactions->delete_unpaid( $this->bulk_refs_from_request() );

		$view = $this->view_state_from_request( '' );

		wp_send_json_success(
			array_merge(
				array( 'deleted' => $deleted ),
				$this->rendered_view( $view )
			)
		);
	}

	/**
	 * AJAX: records a "New payment" and returns the re-rendered current view.
	 *
	 * The view state comes under `view_*` keys here so it can't clash with the new payment's own fields.
	 */
	public function ajax_create_entry(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$amount = (float) sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) );

		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter an amount greater than zero.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$customer_email = sanitize_text_field( wp_unslash( $_POST['customer_email'] ?? '' ) );

		if ( '' !== $customer_email && ! is_email( $customer_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email, or leave it blank.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$customer_name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );

		$ref = $this->transactions->create_manual( $customer_name, $customer_email, $amount, $status );

		if ( null === $ref ) {
			global $wpdb;

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: the database error */
						__( 'Could not save the payment: %s', 'ifthenpay-payments-for-forminator' ),
						$wpdb->last_error ? $wpdb->last_error : __( 'unknown database error', 'ifthenpay-payments-for-forminator' )
					),
				),
				500
			);
		}

		$view = $this->view_state_from_request( 'view_' );

		wp_send_json_success(
			array_merge(
				array( 'ref' => $ref ),
				$this->rendered_view( $view )
			)
		);
	}

	/**
	 * AJAX: bulk-sets the status of the given refs.
	 *
	 * An explicit admin override, so unlike the webhook it may move a record off "paid".
	 */
	public function ajax_update_status(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		$updated = $this->transactions->admin_set_status_many( $this->bulk_refs_from_request(), $status );

		$view = $this->view_state_from_request( '' );

		wp_send_json_success(
			array_merge(
				array(
					'updated' => $updated,
					'status'  => $status,
				),
				$this->rendered_view( $view )
			)
		);
	}

	/**
	 * AJAX: emails a pending payment's link to its customer again.
	 */
	public function ajax_resend_payment_email(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$record  = $this->transactions->get( sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ) );
		$outcome = $this->send_payment_email( $record );

		if ( self::EMAIL_INELIGIBLE === $outcome ) {
			wp_send_json_error( array( 'message' => __( 'This payment is no longer pending, or has no customer email to send to.', 'ifthenpay-payments-for-forminator' ) ), 409 );
		}

		if ( self::EMAIL_NO_LINK === $outcome ) {
			wp_send_json_error( array( 'message' => __( 'Could not create a payment link for this payment. Check the ifthenpay connection and enabled methods in the settings.', 'ifthenpay-payments-for-forminator' ) ), 502 );
		}

		if ( self::EMAIL_MAIL_FAILED === $outcome ) {
			wp_send_json_error( array( 'message' => __( 'Could not send the payment email. Check that this site can send mail.', 'ifthenpay-payments-for-forminator' ) ), 500 );
		}

		wp_send_json_success(
			array(
				/* translators: %s: customer email address */
				'message' => sprintf( __( 'Payment email sent to %s.', 'ifthenpay-payments-for-forminator' ), (string) ( $record['customer_email'] ?? '' ) ),
			)
		);
	}

	/**
	 * AJAX: the bulk "Send payment email", for one chunk of refs.
	 *
	 * Each send can mean an SMTP round trip and an ifthenpay call, so I stop taking refs once the time budget
	 * is spent. entries.js sends whatever I didn't get to again. Only asked for on the last chunk, I also
	 * return the re-rendered view, since a new payment link changes the row.
	 */
	public function ajax_send_payment_emails(): void {
		$this->guard();

		$refs    = $this->bulk_refs_from_request();
		$started = microtime( true );
		$result  = array(
			'sent'    => array(),
			'skipped' => array(),
			'failed'  => array(),
		);

		$this->transactions->prime( $refs );

		foreach ( $refs as $ref ) {
			if ( microtime( true ) - $started > self::EMAIL_TIME_BUDGET ) {
				break;
			}

			$outcome = $this->send_payment_email( $this->transactions->get( $ref ) );

			if ( self::EMAIL_SENT === $outcome ) {
				$result['sent'][] = $ref;
			} elseif ( self::EMAIL_INELIGIBLE === $outcome ) {
				$result['skipped'][] = $ref;
			} else {
				$result['failed'][] = $ref;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		if ( ! empty( $_POST['render'] ) ) {
			$result = array_merge( $result, $this->rendered_view( $this->view_state_from_request( '' ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Emails a pending payment its link, creating the link first if it never got one.
	 *
	 * I re-check can_resend_email() since the row may have been paid since the page loaded.
	 *
	 * @param array<string, mixed>|null $record The transaction row, or null when the ref is gone.
	 * @phpstan-param Transaction|null $record
	 * @return string One of the EMAIL_* outcomes.
	 */
	private function send_payment_email( ?array $record ): string {
		if ( null === $record || ! $this->can_resend_email( $record ) ) {
			return self::EMAIL_INELIGIBLE;
		}

		$payment_url = (string) $record['payment_url'];

		if ( '' === $payment_url ) {
			$payment_url = ( new PaymentController( $this->settings, null, $this->transactions ) )->create_link_for( $record );

			if ( '' === $payment_url ) {
				return self::EMAIL_NO_LINK;
			}
		}

		$sent = PaymentRequestEmail::send(
			array(
				'ref'            => (string) $record['ref'],
				'amount'         => (float) $record['amount'],
				'payment_url'    => $payment_url,
				'customer_name'  => (string) $record['customer_name'],
				'customer_email' => (string) $record['customer_email'],
				'form_id'        => (int) $record['form_id'],
			),
			$this->transactions->entry_data_pairs( $record ),
			$this->settings
		);

		return $sent ? self::EMAIL_SENT : self::EMAIL_MAIL_FAILED;
	}

	/**
	 * Whether the row is a pending ifthenpay payment with a valid customer email.
	 *
	 * An ad hoc ('manual') one has nothing to pay through.
	 *
	 * @param array<string, mixed> $record A transaction row.
	 */
	private function can_resend_email( array $record ): bool {
		return TransactionStore::STATUS_PENDING === $record['status']
			&& 'manual' !== (string) ( $record['gateway_key'] ?? '' )
			&& false !== is_email( (string) ( $record['customer_email'] ?? '' ) );
	}

	/**
	 * AJAX: remembers which columns the current admin hid, and their order.
	 */
	public function ajax_save_columns(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$requested = array_map( 'sanitize_key', wp_unslash( (array) ( $_POST['hidden'] ?? array() ) ) );
		$hidden    = array_values( array_intersect( self::HIDEABLE_COLUMNS, $requested ) );

		update_user_meta( get_current_user_id(), self::HIDDEN_COLS_META, $hidden );

		$response = array( 'hidden' => $hidden );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		if ( isset( $_POST['order'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
			$order = $this->normalize_column_order( array_map( 'sanitize_key', wp_unslash( (array) $_POST['order'] ) ) );

			update_user_meta( get_current_user_id(), self::COL_ORDER_META, $order );
			$response['order'] = $order;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Admin-post: streams the current view (or just the selected refs) as a CSV or JSON download.
	 */
	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! $this->can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() verified the nonce; filters_from() sanitizes every key.
		$view = $this->filters_from( $_REQUEST );
		$refs = $this->refs_from_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() verified the nonce.
		$format = isset( $_REQUEST['format'] ) && self::EXPORT_JSON === sanitize_key( wp_unslash( $_REQUEST['format'] ) ) ? self::EXPORT_JSON : self::EXPORT_CSV;

		$args = $this->store_filters( $view ) + array(
			'status'  => $view['status'],
			'orderby' => $view['orderby'],
			'order'   => $view['order'],
		);

		if ( array() !== $refs ) {
			$args['refs'] = $refs;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( self::EXPORT_JSON === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ifthenpay-entries-' . wp_date( 'Y-m-d' ) . '.' . $format . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response body; WP_Filesystem has no stream API.
		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		if ( self::EXPORT_JSON === $format ) {
			$this->stream_json( $out, $args );
		} else {
			$this->stream_csv( $out, $args );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen() above.
		fclose( $out );
		exit;
	}

	/**
	 * Writes the matching rows as CSV, with a header row.
	 *
	 * @param resource             $out  The open php://output handle.
	 * @param array<string, mixed> $args TransactionStore::iterate() filters.
	 */
	private function stream_csv( $out, array $args ): void {
		// A BOM so Excel reads the file as UTF-8.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, "\xEF\xBB\xBF" );

		$this->put_csv_row(
			$out,
			array(
				__( 'Reference', 'ifthenpay-payments-for-forminator' ),
				__( 'Form', 'ifthenpay-payments-for-forminator' ),
				__( 'Customer name', 'ifthenpay-payments-for-forminator' ),
				__( 'Customer email', 'ifthenpay-payments-for-forminator' ),
				__( 'Method', 'ifthenpay-payments-for-forminator' ),
				__( 'Amount', 'ifthenpay-payments-for-forminator' ),
				__( 'Status', 'ifthenpay-payments-for-forminator' ),
				__( 'Entry ID', 'ifthenpay-payments-for-forminator' ),
				__( 'Created', 'ifthenpay-payments-for-forminator' ),
				__( 'Last updated', 'ifthenpay-payments-for-forminator' ),
				__( 'Request ID', 'ifthenpay-payments-for-forminator' ),
				__( 'Transaction ID', 'ifthenpay-payments-for-forminator' ),
				__( 'Payment link', 'ifthenpay-payments-for-forminator' ),
			)
		);

		foreach ( $this->transactions->iterate( $args ) as $record ) {
			$entry_id = $record['entry_id'];

			$this->put_csv_row(
				$out,
				array(
					(string) $record['ref'],
					$this->export_form_label( (int) $record['form_id'] ),
					$record['customer_name'],
					$record['customer_email'],
					(string) $record['pay_method'],
					number_format( (float) $record['amount'], 2, '.', '' ),
					$this->status_label( (string) $record['status'] ),
					$entry_id > 0 ? (string) $entry_id : '',
					(string) wp_date( 'Y-m-d H:i:s', (int) $record['created_at'] ),
					(string) wp_date( 'Y-m-d H:i:s', (int) $record['updated_at'] ),
					(string) $record['request_id'],
					(string) $record['transaction_id'],
					$record['payment_url'],
				)
			);
		}
	}

	/**
	 * Writes the matching rows as a JSON array, one object per line so big exports never sit in memory.
	 *
	 * @param resource             $out  The open php://output handle.
	 * @param array<string, mixed> $args TransactionStore::iterate() filters.
	 */
	private function stream_json( $out, array $args ): void {
		$first = true;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, '[' );

		foreach ( $this->transactions->iterate( $args ) as $record ) {
			$entry_id = $record['entry_id'];

			// Stable English keys and raw values (status slug, ISO dates), since this is for machines.
			$json = wp_json_encode(
				array(
					'reference'      => (string) $record['ref'],
					'form_id'        => (int) $record['form_id'],
					'form'           => $this->export_form_label( (int) $record['form_id'] ),
					'customer_name'  => $record['customer_name'],
					'customer_email' => $record['customer_email'],
					'method'         => (string) $record['pay_method'],
					'amount'         => round( (float) $record['amount'], 2 ),
					'status'         => (string) $record['status'],
					'status_label'   => $this->status_label( (string) $record['status'] ),
					'entry_id'       => $entry_id > 0 ? $entry_id : null,
					'created_at'     => (string) wp_date( 'c', (int) $record['created_at'] ),
					'updated_at'     => (string) wp_date( 'c', (int) $record['updated_at'] ),
					'request_id'     => (string) $record['request_id'],
					'transaction_id' => (string) $record['transaction_id'],
					'payment_url'    => $record['payment_url'],
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);

			if ( false === $json ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
			fwrite( $out, ( $first ? '' : ',' ) . "\n  " . $json );
			$first = false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, ( $first ? '' : "\n" ) . "]\n" );
	}

	/**
	 * The plain-text form name for an export cell, or "Ad Hoc" for payments made outside a form.
	 *
	 * @param int $form_id The transaction's form ID (0 for ad hoc).
	 */
	private function export_form_label( int $form_id ): string {
		if ( 0 === $form_id ) {
			return __( 'Ad Hoc', 'ifthenpay-payments-for-forminator' );
		}

		return html_entity_decode( wp_strip_all_tags( $this->form_title( $form_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Writes one CSV line, guarding every cell against spreadsheet formula injection.
	 *
	 * @param resource           $out   The open php://output handle.
	 * @param array<int, string> $cells The row's cells, in column order.
	 */
	private function put_csv_row( $out, array $cells ): void {
		$safe = array_map(
			static function ( string $cell ): string {
				// A leading quote makes Excel/Sheets treat the cell as text.
				return ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) ? "'" . $cell : $cell;
			},
			$cells
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- see handle_export()'s fopen().
		fputcsv( $out, $safe, ',', '"', '' );
	}

	/**
	 * Verifies the request nonce, the admin capability and the connection.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! $this->can_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}
	}

	/**
	 * Whether the current user may use this screen: admin cap plus a connected Backoffice Key.
	 */
	private function can_access(): bool {
		return current_user_can( Plugin::admin_cap() ) && $this->settings->is_connected();
	}

	/**
	 * The selected refs, sent as one JSON-encoded `refs` field.
	 *
	 * Not `refs[]`: PHP drops input vars past `max_input_vars` (1000), which would eat the fields after a big selection.
	 *
	 * @return array<int, string>
	 */
	private function refs_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- callers verify the nonce; I sanitize each decoded ref, since sanitizing the JSON could corrupt it.
		$raw  = isset( $_POST['refs'] ) && is_string( $_POST['refs'] ) ? wp_unslash( $_POST['refs'] ) : '';
		$refs = json_decode( $raw, true );

		if ( ! is_array( $refs ) ) {
			return array();
		}

		$refs = array_map( 'sanitize_text_field', array_map( 'strval', array_filter( $refs, 'is_scalar' ) ) );

		return array_values( array_unique( array_filter( $refs, static fn( string $ref ): bool => '' !== $ref ) ) );
	}

	/**
	 * The bulk actions' refs, refusing an oversized batch.
	 *
	 * @return array<int, string>
	 */
	private function bulk_refs_from_request(): array {
		$refs = $this->refs_from_request();

		if ( count( $refs ) > self::BULK_MAX_REFS ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: maximum entries per request */
						__( 'Too many entries in one request (the limit is %s).', 'ifthenpay-payments-for-forminator' ),
						number_format_i18n( self::BULK_MAX_REFS )
					),
				),
				400
			);
		}

		return $refs;
	}

	/**
	 * Reads the browser's current filter/sort/pagination state from $_POST.
	 *
	 * @param string $prefix '' or 'view_'.
	 * @return array<string, mixed>
	 */
	private function view_state_from_request( string $prefix ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$search = sanitize_text_field( wp_unslash( $_POST[ $prefix . 's' ] ?? '' ) );
		// Always 'view_status': a bare 'status' is the target status in ajax_update_status().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$status = sanitize_text_field( wp_unslash( $_POST['view_status'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$form_id = absint( wp_unslash( $_POST[ $prefix . 'form_id' ] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$date_from = $this->sanitize_date( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'date_from' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$date_to = $this->sanitize_date( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'date_to' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$orderby = $this->sanitize_orderby( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'orderby' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$order = '' !== $orderby ? $this->sanitize_order( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'order' ] ?? '' ) ) ) : '';

		$per_page = $this->resolve_per_page(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
			sanitize_text_field( wp_unslash( $_POST[ $prefix . 'per_page' ] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
			sanitize_text_field( wp_unslash( $_POST[ $prefix . 'per_page_custom' ] ?? '' ) )
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$paged = max( 1, absint( wp_unslash( $_POST[ $prefix . 'paged' ] ?? 1 ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$pay_method = $this->sanitize_pay_method( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'pay_method' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$amount_min = $this->sanitize_amount( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'amount_min' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$amount_max = $this->sanitize_amount( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'amount_max' ] ?? '' ) ) );

		list( $amount_min, $amount_max ) = $this->ordered_amount_range( $amount_min, $amount_max );

		return array(
			's'          => $search,
			'status'     => $status,
			'form_id'    => $form_id,
			'date_from'  => $date_from,
			'date_to'    => $date_to,
			'pay_method' => $pay_method,
			'amount_min' => $amount_min,
			'amount_max' => $amount_max,
			'orderby'    => $orderby,
			'order'      => $order,
			'per_page'   => $per_page,
			'paged'      => $paged,
		);
	}

	/**
	 * Re-queries and renders the tbody, pagination and counts for a view state.
	 *
	 * @param array<string, mixed> $view See `view_state_from_request()`.
	 * @return array{rowsHtml: string, paginationHtml: string, summaryHtml: string, paged: int, total: int, totalLabel: string}
	 */
	private function rendered_view( array $view ): array {
		$shared_filters = $this->store_filters( $view );

		$result = $this->transactions->query(
			$shared_filters + array(
				'status'   => $view['status'],
				'orderby'  => $view['orderby'],
				'order'    => $view['order'],
				'per_page' => $view['per_page'],
				'paged'    => $view['paged'],
			)
		);

		$base = $this->url_args( array( 'paged' => $result['paged'] ) + $view );

		ob_start();
		$this->render_pagination( $result['paged'], $result['pages'], $base );
		$pagination_html = (string) ob_get_clean();

		return array(
			'rowsHtml'       => $this->render_rows_html( $result['items'] ),
			'paginationHtml' => $pagination_html,
			'summaryHtml'    => $this->render_summary( $this->transactions->status_summary( $shared_filters ), $base ),
			'paged'          => $result['paged'],
			'total'          => $result['total'],
			'totalLabel'     => $this->entries_range_label( $result['paged'], $view['per_page'], $result['total'] ),
		);
	}

	/**
	 * Renders the full admin page.
	 */
	public function render(): void {
		if ( ! $this->can_access() ) {
			return;
		}

		$filters       = $this->current_filters();
		$search        = $filters['s'];
		$status_filter = $filters['status'];
		$form_filter   = $filters['form_id'];
		$date_from     = $filters['date_from'];
		$date_to       = $filters['date_to'];
		$orderby       = $filters['orderby'];
		$order         = $filters['order'];
		$per_page      = $filters['per_page'];

		$is_custom_per_page = ! in_array( $per_page, self::PER_PAGE_OPTIONS, true );

		$available_forms = $this->available_forms();
		$hidden_cols     = $this->hidden_columns();
		$col_order       = $this->column_order();
		$chips           = $this->active_filter_chips( $filters );
		$view_args       = $this->url_args( $filters );

		$shared_filters = $this->store_filters( $filters );

		$result = $this->transactions->query(
			$shared_filters + array(
				'status'   => $status_filter,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $filters['paged'],
			)
		);

		$slice   = $result['items'];
		$total   = $result['total'];
		$pages   = $result['pages'];
		$paged   = $result['paged'];
		$summary = $this->transactions->status_summary( $shared_filters );

		?>
		<div id="iftp-fm-entries" class="wrap iftp-fm-entries" data-iftp-hidden-cols="<?php echo esc_attr( implode( ' ', $hidden_cols ) ); ?>" data-iftp-col-order="<?php echo esc_attr( implode( ' ', $col_order ) ); ?>">
			<header class="iftp-fm-entries__masthead">
				<div class="iftp-fm-entries__masthead-brand">
					<img class="iftp-fm-entries__logo" src="<?php echo esc_url( IFTP_FM_URL . 'assets/img/logo_ifthenpay_white.svg' ); ?>" alt="ifthenpay" width="94" height="24" />
					<span class="iftp-fm-entries__masthead-divider" aria-hidden="true"></span>
					<div class="iftp-fm-entries__masthead-text">
						<h1 id="iftp-fm-entries-title" class="iftp-fm-entries__title"><?php esc_html_e( 'Payment entries', 'ifthenpay-payments-for-forminator' ); ?></h1>
						<p class="iftp-fm-entries__subtitle"><?php esc_html_e( 'Every ifthenpay payment attempt across your Forminator forms, whether or not it produced a real entry yet.', 'ifthenpay-payments-for-forminator' ); ?></p>
					</div>
				</div>

				<div class="iftp-fm-entries__masthead-actions">
					<button type="button" class="iftp-fm-btn iftp-fm-btn--inverse iftp-fm-new-entry-trigger" data-iftp-new-entry-trigger>
						<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 3v10M3 8h10" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
						<?php esc_html_e( 'New payment', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
					<?php $this->email_settings->render_trigger(); ?>
				</div>
			</header>
			<hr class="wp-header-end" />

			<section class="iftp-fm-summary" aria-labelledby="iftp-fm-summary-title">
				<h2 id="iftp-fm-summary-title" class="screen-reader-text"><?php esc_html_e( 'Totals by status', 'ifthenpay-payments-for-forminator' ); ?></h2>
				<div class="iftp-fm-summary__body" data-iftp-summary>
					<?php $this->print_summary( $summary ); ?>
				</div>
			</section>

			<div class="iftp-fm-entries-card">
				<div class="iftp-fm-entries-card-header">
					<div class="iftp-fm-entries-toolbar-row">
						<nav class="iftp-fm-entries__status-nav" aria-label="<?php esc_attr_e( 'Filter by status', 'ifthenpay-payments-for-forminator' ); ?>">
							<ul class="iftp-fm-status-tabs">
								<?php // No counts here: the summary cards above already show them, and are filters too. ?>
								<?php foreach ( $this->status_filters() as $slug => $label ) : ?>
									<li>
										<?php
										$is_current = $slug === $status_filter;
										$status_url = $this->filtered_url(
											array(
												'status' => $slug,
												'paged'  => null,
											)
										);
										?>
										<a
											class="iftp-fm-status-tab<?php echo $is_current ? ' iftp-fm-status-tab--current' : ''; ?>"
											href="<?php echo esc_url( $status_url ); ?>"
											<?php echo $is_current ? ' aria-current="page"' : ''; ?>
										><?php echo esc_html( $label ); ?></a>
									</li>
								<?php endforeach; ?>
							</ul>
						</nav>

						<div class="iftp-fm-bulk-actions" data-iftp-bulk-actions hidden>
							<button type="button" class="iftp-fm-bulk-actions__clear" data-iftp-selection-cancel aria-label="<?php esc_attr_e( 'Clear selection', 'ifthenpay-payments-for-forminator' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
							</button>
							<span class="iftp-fm-bulk-actions__count" data-iftp-bulk-count></span>
							<label class="screen-reader-text" for="iftp-fm-bulk-select"><?php esc_html_e( 'Bulk actions', 'ifthenpay-payments-for-forminator' ); ?></label>
							<select id="iftp-fm-bulk-select" data-iftp-bulk-select>
								<option value=""><?php esc_html_e( 'Bulk actions', 'ifthenpay-payments-for-forminator' ); ?></option>
								<?php foreach ( $this->status_filters( false ) as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: target status label, e.g. "Paid" */
												__( 'Mark as %s', 'ifthenpay-payments-for-forminator' ),
												$label
											)
										);
										?>
									</option>
								<?php endforeach; ?>
								<?php /* Shown by entries.js only while every selected entry is pending. */ ?>
								<option value="email" data-iftp-bulk-email hidden disabled><?php esc_html_e( 'Send payment email', 'ifthenpay-payments-for-forminator' ); ?></option>
								<option value="export-csv"><?php esc_html_e( 'Export selected as CSV', 'ifthenpay-payments-for-forminator' ); ?></option>
								<option value="export-json"><?php esc_html_e( 'Export selected as JSON', 'ifthenpay-payments-for-forminator' ); ?></option>
								<?php /* Disabled by entries.js while every selected entry is paid. */ ?>
								<option value="delete" data-iftp-bulk-delete><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-forminator' ); ?></option>
							</select>
							<button type="button" class="iftp-fm-btn iftp-fm-btn--primary" data-iftp-bulk-apply><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-forminator' ); ?></button>
						</div>

						<div class="iftp-fm-entries__view-tools">
							<div class="iftp-fm-entries__search">
								<label class="screen-reader-text" for="iftp-fm-search"><?php esc_html_e( 'Search entries', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="search" id="iftp-fm-search" name="s" form="iftp-fm-filter-form" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search entries…', 'ifthenpay-payments-for-forminator' ); ?>" title="<?php esc_attr_e( 'Search by reference, customer, method or entry ID', 'ifthenpay-payments-for-forminator' ); ?>" />
								<?php /* Enter in the search needs a rendered default button; the panel's Apply is hidden. */ ?>
								<button type="submit" form="iftp-fm-filter-form" class="screen-reader-text" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Search', 'ifthenpay-payments-for-forminator' ); ?></button>
							</div>

							<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__filters-toggle" data-iftp-filters-toggle aria-expanded="false" aria-controls="iftp-fm-filter-panel" title="<?php esc_attr_e( 'Filters', 'ifthenpay-payments-for-forminator' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2.5 3.25h11L9.25 8.5v4.25l-2.5-1.25v-3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
								<span class="iftp-fm-entries__tool-label"><?php esc_html_e( 'Filters', 'ifthenpay-payments-for-forminator' ); ?></span>
								<?php if ( array() !== $chips ) : ?>
									<span class="iftp-fm-entries__filters-count" aria-hidden="true"><?php echo esc_html( number_format_i18n( count( $chips ) ) ); ?></span>
									<span class="screen-reader-text">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of active filters */
												_n( '(%s active)', '(%s active)', count( $chips ), 'ifthenpay-payments-for-forminator' ),
												number_format_i18n( count( $chips ) )
											)
										);
										?>
									</span>
								<?php endif; ?>
							</button>

							<div class="iftp-fm-entries__columns">
								<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__columns-trigger" data-iftp-columns-trigger aria-expanded="false" aria-controls="iftp-fm-columns-menu" title="<?php esc_attr_e( 'Columns', 'ifthenpay-payments-for-forminator' ); ?>">
									<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="2" y="2.75" width="12" height="10.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M6 2.75v10.5M10 2.75v10.5" stroke="currentColor" stroke-width="1.5"/></svg>
									<span class="iftp-fm-entries__tool-label"><?php esc_html_e( 'Columns', 'ifthenpay-payments-for-forminator' ); ?></span>
								</button>
								<?php $this->render_columns_menu( $col_order, $hidden_cols ); ?>
								<p class="screen-reader-text" data-iftp-col-status role="status"></p>
							</div>

							<div class="iftp-fm-entries__export">
								<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__export-trigger" data-iftp-export-trigger aria-expanded="false" aria-controls="iftp-fm-export-menu" title="<?php esc_attr_e( 'Export', 'ifthenpay-payments-for-forminator' ); ?>">
									<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 2.5v7.5M4.75 6.75 8 10l3.25-3.25M3 13.25h10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
									<span class="iftp-fm-entries__tool-label"><?php esc_html_e( 'Export', 'ifthenpay-payments-for-forminator' ); ?></span>
								</button>
								<?php $this->render_export_menu( $filters ); ?>
							</div>
						</div>
					</div>

					<div id="iftp-fm-filter-panel" class="iftp-fm-filter-panel" data-iftp-filter-panel hidden>
						<form id="iftp-fm-filter-form" class="iftp-fm-filter-panel__form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<?php $this->hidden_view_inputs( $view_args, array( 's', 'form_id', 'pay_method', 'amount_min', 'amount_max', 'date_from', 'date_to', 'paged' ) ); ?>

							<div class="iftp-fm-filter-panel__fields">
								<div class="iftp-fm-filter-panel__field iftp-fm-filter-panel__field--form">
									<label class="iftp-fm-filter-panel__label" for="iftp-fm-form-filter"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-forminator' ); ?></label>
									<select id="iftp-fm-form-filter" name="form_id">
										<option value=""><?php esc_html_e( 'All forms', 'ifthenpay-payments-for-forminator' ); ?></option>
										<?php foreach ( $available_forms as $id => $label ) : ?>
											<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $form_filter, $id ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="iftp-fm-filter-panel__field iftp-fm-filter-panel__field--method">
									<label class="iftp-fm-filter-panel__label" for="iftp-fm-method-filter"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-forminator' ); ?></label>
									<select id="iftp-fm-method-filter" name="pay_method">
										<option value=""><?php esc_html_e( 'All methods', 'ifthenpay-payments-for-forminator' ); ?></option>
										<?php foreach ( $this->transactions->distinct_methods() as $method ) : ?>
											<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filters['pay_method'], $method ); ?>><?php echo esc_html( $method ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="iftp-fm-filter-panel__field iftp-fm-filter-panel__field--amount" role="group" aria-labelledby="iftp-fm-filter-amount-label">
									<span id="iftp-fm-filter-amount-label" class="iftp-fm-filter-panel__label"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-forminator' ); ?></span>
									<div class="iftp-fm-filter-panel__range">
										<label class="screen-reader-text" for="iftp-fm-amount-min"><?php esc_html_e( 'Minimum amount', 'ifthenpay-payments-for-forminator' ); ?></label>
										<div class="iftp-fm-form-affix">
											<input type="number" id="iftp-fm-amount-min" name="amount_min" step="0.01" min="0" inputmode="decimal" value="<?php echo esc_attr( $filters['amount_min'] ); ?>" placeholder="<?php esc_attr_e( 'Min', 'ifthenpay-payments-for-forminator' ); ?>" />
											<span class="iftp-fm-form-affix__suffix" aria-hidden="true">€</span>
										</div>
										<span class="iftp-fm-filter-panel__range-sep" aria-hidden="true">&ndash;</span>
										<label class="screen-reader-text" for="iftp-fm-amount-max"><?php esc_html_e( 'Maximum amount', 'ifthenpay-payments-for-forminator' ); ?></label>
										<div class="iftp-fm-form-affix">
											<input type="number" id="iftp-fm-amount-max" name="amount_max" step="0.01" min="0" inputmode="decimal" value="<?php echo esc_attr( $filters['amount_max'] ); ?>" placeholder="<?php esc_attr_e( 'Max', 'ifthenpay-payments-for-forminator' ); ?>" />
											<span class="iftp-fm-form-affix__suffix" aria-hidden="true">€</span>
										</div>
									</div>
								</div>

								<div class="iftp-fm-filter-panel__field iftp-fm-filter-panel__field--created" role="group" aria-labelledby="iftp-fm-filter-created-label">
									<span id="iftp-fm-filter-created-label" class="iftp-fm-filter-panel__label"><?php esc_html_e( 'Created', 'ifthenpay-payments-for-forminator' ); ?></span>
									<div class="iftp-fm-filter-panel__range">
										<label class="screen-reader-text" for="iftp-fm-date-from"><?php esc_html_e( 'From date', 'ifthenpay-payments-for-forminator' ); ?></label>
										<input type="date" id="iftp-fm-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
										<span class="iftp-fm-filter-panel__range-sep" aria-hidden="true">&ndash;</span>
										<label class="screen-reader-text" for="iftp-fm-date-to"><?php esc_html_e( 'To date', 'ifthenpay-payments-for-forminator' ); ?></label>
										<input type="date" id="iftp-fm-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
									</div>
									<ul class="iftp-fm-date-presets" aria-label="<?php esc_attr_e( 'Quick date ranges', 'ifthenpay-payments-for-forminator' ); ?>">
										<?php foreach ( $this->date_presets() as $preset ) : ?>
											<?php
											$is_current = $preset['from'] === $date_from && $preset['to'] === $date_to;
											$preset_url = $this->filtered_url(
												array(
													'date_from' => $preset['from'],
													'date_to'   => $preset['to'],
													'paged'     => null,
												)
											);
											?>
											<li>
												<a class="iftp-fm-date-preset<?php echo $is_current ? ' iftp-fm-date-preset--current' : ''; ?>" href="<?php echo esc_url( $preset_url ); ?>" data-iftp-date-preset data-iftp-date-from="<?php echo esc_attr( $preset['from'] ); ?>" data-iftp-date-to="<?php echo esc_attr( $preset['to'] ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $preset['label'] ); ?></a>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							</div>

							<div class="iftp-fm-filter-panel__actions">
								<?php if ( array() !== $chips ) : ?>
									<a class="iftp-fm-btn iftp-fm-btn--link iftp-fm-filter-panel__clear" href="<?php echo esc_url( $this->clear_filters_url() ); ?>"><?php esc_html_e( 'Clear filters', 'ifthenpay-payments-for-forminator' ); ?></a>
								<?php endif; ?>
								<button type="submit" class="iftp-fm-btn iftp-fm-btn--primary iftp-fm-filter-panel__apply"><?php esc_html_e( 'Apply filters', 'ifthenpay-payments-for-forminator' ); ?></button>
							</div>
						</form>
					</div>

					<?php if ( array() !== $chips ) : ?>
						<div class="iftp-fm-active-filters">
							<ul class="iftp-fm-active-filters__list" aria-label="<?php esc_attr_e( 'Active filters', 'ifthenpay-payments-for-forminator' ); ?>">
								<?php foreach ( $chips as $chip ) : ?>
									<li>
										<a class="iftp-fm-filter-chip" href="<?php echo esc_url( $chip['url'] ); ?>">
											<span class="screen-reader-text"><?php esc_html_e( 'Remove filter:', 'ifthenpay-payments-for-forminator' ); ?></span>
											<span class="iftp-fm-filter-chip__key"><?php echo esc_html( $chip['key'] ); ?></span>
											<span class="iftp-fm-filter-chip__value" title="<?php echo esc_attr( $chip['value'] ); ?>"><?php echo esc_html( $chip['value'] ); ?></span>
											<svg class="iftp-fm-filter-chip__remove" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M3 3l6 6M9 3l-6 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
							<a class="iftp-fm-btn iftp-fm-btn--link iftp-fm-active-filters__clear" href="<?php echo esc_url( $this->clear_filters_url() ); ?>"><?php esc_html_e( 'Clear filters', 'ifthenpay-payments-for-forminator' ); ?></a>
						</div>
					<?php endif; ?>
				</div>

				<div class="iftp-fm-entries-stage">
				<div class="iftp-fm-entries-table-wrap" role="region" aria-labelledby="iftp-fm-entries-title" tabindex="0">
					<table class="iftp-fm-entries-table">
						<thead>
							<tr>
								<th class="iftp-fm-col-check">
									<input type="checkbox" data-iftp-select-all aria-label="<?php esc_attr_e( 'Select all', 'ifthenpay-payments-for-forminator' ); ?>" />
								</th>
								<th data-col="ref"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-forminator' ); ?></th>
								<?php
								foreach ( $col_order as $slug ) {
									$this->render_head_cell( $slug, $orderby, $order );
								}
								?>
							</tr>
						</thead>
						<tbody data-iftp-entries-body>
							<?php $this->print_rows( $slice ); ?>
						</tbody>
					</table>
				</div>

				<?php // Same mark as the empty state, so a load that ends empty settles straight into it. ?>
				<div class="iftp-fm-entries-loader" aria-hidden="true">
					<span class="iftp-fm-mark iftp-fm-mark--busy">
						<span class="iftp-fm-mark__disc">
							<img class="iftp-fm-mark__logo" src="<?php echo esc_url( IFTP_FM_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="28" height="28" />
						</span>
						<span class="iftp-fm-mark__orbit"></span>
					</span>
				</div>
				</div>

				<div class="iftp-fm-entries-card-footer" data-iftp-entries-footer>
					<div class="iftp-fm-footer-status" aria-live="polite">
						<span class="iftp-fm-entries-count" data-iftp-total-count data-iftp-total="<?php echo esc_attr( (string) $total ); ?>">
							<?php echo esc_html( $this->entries_range_label( $paged, $per_page, $total ) ); ?>
						</span>
						<span class="iftp-fm-entries-selection" data-iftp-selection-summary hidden>
							<span data-iftp-selection-text></span>
							<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-selection-cancel" data-iftp-selection-cancel><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?></button>
						</span>
					</div>

					<div class="iftp-fm-entries-card-footer__nav">
						<form class="iftp-fm-per-page" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-iftp-per-page-form>
							<?php $this->hidden_view_inputs( $view_args, array( 'per_page', 'paged' ) ); ?>
							<label class="iftp-fm-per-page__label" for="iftp-fm-per-page"><?php esc_html_e( 'Entries per page', 'ifthenpay-payments-for-forminator' ); ?></label>
							<select id="iftp-fm-per-page" class="iftp-fm-per-page__select" name="per_page" data-iftp-per-page>
								<?php foreach ( self::PER_PAGE_OPTIONS as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( number_format_i18n( $option ) ); ?></option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $is_custom_per_page ); ?>><?php esc_html_e( 'Custom…', 'ifthenpay-payments-for-forminator' ); ?></option>
							</select>
							<span class="iftp-fm-per-page__custom" data-iftp-per-page-custom<?php echo $is_custom_per_page ? '' : ' hidden'; ?>>
								<label class="screen-reader-text" for="iftp-fm-per-page-custom"><?php esc_html_e( 'Custom entries per page', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="number" id="iftp-fm-per-page-custom" name="per_page_custom" min="<?php echo esc_attr( (string) self::PER_PAGE_MIN ); ?>" max="<?php echo esc_attr( (string) self::PER_PAGE_MAX ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $per_page ); ?>" />
								<span class="iftp-fm-per-page__hint" aria-hidden="true"><?php echo esc_html( number_format_i18n( self::PER_PAGE_MIN ) . '–' . number_format_i18n( self::PER_PAGE_MAX ) ); ?></span>
							</span>
							<button type="submit" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-per-page__submit" data-iftp-per-page-submit><?php esc_html_e( 'Go', 'ifthenpay-payments-for-forminator' ); ?></button>
						</form>

						<div data-iftp-pagination><?php $this->render_pagination( $paged, $pages ); ?></div>
					</div>
				</div>
			</div>

			<?php $this->render_confirm_modal(); ?>
			<?php $this->render_create_entry_modal(); ?>
			<?php $this->email_settings->render_modal(); ?>
			<?php $this->render_toast(); ?>
			<?php $this->render_scroll_to_top_button(); ?>
		</div>
		<?php
	}

	/**
	 * The "New payment" popup, for a payment taken outside the checkout.
	 *
	 * The real validation happens in ajax_create_entry().
	 */
	private function render_create_entry_modal(): void {
		$statuses = array(
			TransactionStore::STATUS_PENDING => __( 'Pending', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_PAID    => __( 'Paid', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_FAILED  => __( 'Failed', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_EXPIRED => __( 'Expired', 'ifthenpay-payments-for-forminator' ),
		);
		?>
		<div class="iftp-fm-modal" data-iftp-new-entry-modal hidden>
			<div class="iftp-fm-modal-overlay" data-iftp-new-entry-overlay></div>
			<div class="iftp-fm-modal-box iftp-fm-modal-box--form" role="dialog" aria-modal="true" aria-labelledby="iftp-fm-new-entry-title">
				<h2 id="iftp-fm-new-entry-title"><?php esc_html_e( 'New payment', 'ifthenpay-payments-for-forminator' ); ?></h2>
				<p><?php esc_html_e( 'Record a payment taken outside the normal checkout — it appears in this list like any other entry, filed under "Ad Hoc".', 'ifthenpay-payments-for-forminator' ); ?></p>

				<form data-iftp-new-entry-form>
					<p class="iftp-fm-form-error" data-iftp-new-entry-error role="alert" hidden></p>

					<div class="iftp-fm-form-row">
						<div class="iftp-fm-form-field">
							<label for="iftp-fm-ne-name"><?php esc_html_e( 'Customer name', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="text" id="iftp-fm-ne-name" name="customer_name" />
						</div>
						<div class="iftp-fm-form-field">
							<label for="iftp-fm-ne-email"><?php esc_html_e( 'Customer email', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="email" id="iftp-fm-ne-email" name="customer_email" />
						</div>
					</div>

					<div class="iftp-fm-form-row">
						<div class="iftp-fm-form-field">
							<label for="iftp-fm-ne-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-forminator' ); ?> <span class="iftp-fm-form-required" aria-hidden="true">*</span></label>
							<div class="iftp-fm-form-affix">
								<input type="number" id="iftp-fm-ne-amount" name="amount" step="0.01" min="0.01" inputmode="decimal" required />
								<span class="iftp-fm-form-affix__suffix" aria-hidden="true">€</span>
							</div>
						</div>
						<div class="iftp-fm-form-field">
							<label for="iftp-fm-ne-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-forminator' ); ?></label>
							<select id="iftp-fm-ne-status" name="status">
								<?php foreach ( $statuses as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="iftp-fm-modal-actions">
						<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-modal-cancel" data-iftp-new-entry-cancel>
							<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
						<button type="submit" class="iftp-fm-btn iftp-fm-btn--primary" data-iftp-new-entry-submit>
							<?php esc_html_e( 'Create', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * One reusable confirm dialog for bulk/row actions.
	 *
	 * The JS fills in the text per action; the defaults are a fallback so it's never an empty box.
	 */
	private function render_confirm_modal(): void {
		?>
		<div class="iftp-fm-modal" data-iftp-modal hidden>
			<div class="iftp-fm-modal-overlay" data-iftp-modal-overlay></div>
			<div class="iftp-fm-modal-box" role="alertdialog" aria-modal="true" aria-labelledby="iftp-fm-modal-title">
				<h2 id="iftp-fm-modal-title" data-iftp-modal-title><?php esc_html_e( 'Are you sure?', 'ifthenpay-payments-for-forminator' ); ?></h2>
				<p data-iftp-modal-message><?php esc_html_e( 'This action cannot be undone.', 'ifthenpay-payments-for-forminator' ); ?></p>
				<div class="iftp-fm-modal-actions">
					<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-modal-cancel" data-iftp-modal-cancel>
						<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
					<button type="button" class="iftp-fm-btn iftp-fm-btn--primary" data-iftp-modal-confirm><?php esc_html_e( 'Confirm', 'ifthenpay-payments-for-forminator' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One reusable toast for AJAX-action feedback, driven by entries.js.
	 */
	private function render_toast(): void {
		?>
		<div class="iftp-fm-toast" data-iftp-toast hidden role="status" aria-live="polite">
			<span data-iftp-toast-message></span>
		</div>
		<?php
	}

	/**
	 * Sticky "scroll to top" button, driven by entries.js.
	 */
	private function render_scroll_to_top_button(): void {
		?>
		<button type="button" class="iftp-fm-scroll-btn" data-iftp-scroll-btn aria-label="<?php esc_attr_e( 'Scroll to top', 'ifthenpay-payments-for-forminator' ); ?>">
			<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4.5 11.25 9 6.75l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</button>
		<?php
	}

	/**
	 * The Columns popover: show/hide and reorder the hideable columns.
	 *
	 * @param array<int, string> $col_order   From column_order().
	 * @param array<int, string> $hidden_cols From hidden_columns().
	 */
	private function render_columns_menu( array $col_order, array $hidden_cols ): void {
		$labels = $this->column_labels();
		$last   = count( $col_order ) - 1;
		?>
		<div id="iftp-fm-columns-menu" class="iftp-fm-columns-menu" data-iftp-columns-menu role="group" aria-labelledby="iftp-fm-columns-menu-title" hidden>
			<p id="iftp-fm-columns-menu-title" class="iftp-fm-columns-menu__title"><?php esc_html_e( 'Choose and order columns', 'ifthenpay-payments-for-forminator' ); ?></p>

			<div class="iftp-fm-columns-menu__locked">
				<svg class="iftp-fm-columns-menu__locked-icon" width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><rect x="2.75" y="6.25" width="8.5" height="6" rx="1.25" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M4.75 6.25V4.5a2.25 2.25 0 0 1 4.5 0v1.75" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
				<span class="iftp-fm-columns-menu__locked-label"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-forminator' ); ?></span>
				<span class="iftp-fm-columns-menu__locked-hint"><?php esc_html_e( 'Always shown', 'ifthenpay-payments-for-forminator' ); ?></span>
			</div>

			<ul class="iftp-fm-columns-menu__list" data-iftp-col-list>
				<?php foreach ( $col_order as $i => $slug ) : ?>
					<?php
					$label = $labels[ $slug ] ?? $slug;
					/* translators: %s: column name */
					$move_up = sprintf( __( 'Move %s up', 'ifthenpay-payments-for-forminator' ), $label );
					/* translators: %s: column name */
					$move_down = sprintf( __( 'Move %s down', 'ifthenpay-payments-for-forminator' ), $label );
					?>
					<li class="iftp-fm-columns-menu__item" data-iftp-col-item="<?php echo esc_attr( $slug ); ?>">
						<span class="iftp-fm-columns-menu__handle" data-iftp-col-drag draggable="true" aria-hidden="true">
							<svg width="10" height="16" viewBox="0 0 10 16" aria-hidden="true" focusable="false"><g fill="currentColor"><circle cx="3" cy="4" r="1.25"/><circle cx="7" cy="4" r="1.25"/><circle cx="3" cy="8" r="1.25"/><circle cx="7" cy="8" r="1.25"/><circle cx="3" cy="12" r="1.25"/><circle cx="7" cy="12" r="1.25"/></g></svg>
						</span>
						<label class="iftp-fm-columns-menu__option">
							<input type="checkbox" data-iftp-col-toggle="<?php echo esc_attr( $slug ); ?>" <?php checked( ! in_array( $slug, $hidden_cols, true ) ); ?> />
							<span class="iftp-fm-columns-menu__option-label"><?php echo esc_html( $label ); ?></span>
						</label>
						<span class="iftp-fm-columns-menu__move">
							<button type="button" class="iftp-fm-columns-menu__move-btn" data-iftp-col-move="up" aria-label="<?php echo esc_attr( $move_up ); ?>"<?php echo 0 === $i ? ' aria-disabled="true"' : ''; ?>>
								<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M3 7.5 6 4.5l3 3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<button type="button" class="iftp-fm-columns-menu__move-btn" data-iftp-col-move="down" aria-label="<?php echo esc_attr( $move_down ); ?>"<?php echo $last === $i ? ' aria-disabled="true"' : ''; ?>>
								<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="iftp-fm-columns-menu__footer">
				<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-columns-menu__reset" data-iftp-col-reset><?php esc_html_e( 'Reset columns', 'ifthenpay-payments-for-forminator' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * The Export popover: one download link per format.
	 *
	 * @param array<string, mixed> $view See `current_filters()`.
	 */
	private function render_export_menu( array $view ): void {
		$formats = array(
			self::EXPORT_CSV  => array( __( 'CSV', 'ifthenpay-payments-for-forminator' ), __( 'For Excel or Google Sheets', 'ifthenpay-payments-for-forminator' ) ),
			self::EXPORT_JSON => array( __( 'JSON', 'ifthenpay-payments-for-forminator' ), __( 'For developers and integrations', 'ifthenpay-payments-for-forminator' ) ),
		);
		?>
		<div id="iftp-fm-export-menu" class="iftp-fm-export-menu" data-iftp-export-menu role="group" aria-labelledby="iftp-fm-export-menu-title" hidden>
			<p id="iftp-fm-export-menu-title" class="iftp-fm-export-menu__title"><?php esc_html_e( 'Export this view as', 'ifthenpay-payments-for-forminator' ); ?></p>
			<ul class="iftp-fm-export-menu__list">
				<?php foreach ( $formats as $format => [ $label, $hint ] ) : ?>
					<li>
						<a class="iftp-fm-export-menu__option" href="<?php echo esc_url( $this->export_url( $view, $format ) ); ?>" data-iftp-export-option>
							<span class="iftp-fm-export-menu__option-label"><?php echo esc_html( $label ); ?></span>
							<span class="iftp-fm-export-menu__option-hint"><?php echo esc_html( $hint ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The tbody contents for a page of entries, for the AJAX responses.
	 *
	 * @param array<int, array<string, mixed>> $slice The page of transaction rows to render.
	 */
	private function render_rows_html( array $slice ): string {
		ob_start();
		$this->print_rows( $slice );

		return (string) ob_get_clean();
	}

	/**
	 * Prints the tbody contents for a page of entries.
	 *
	 * @param array<int, array<string, mixed>> $slice The page of transaction rows to render.
	 */
	private function print_rows( array $slice ): void {
		if ( array() === $slice ) {
			?>
			<tr>
				<td colspan="9" class="iftp-fm-entries-empty">
					<div class="iftp-fm-empty-state">
						<span class="iftp-fm-mark iftp-fm-empty-state__icon" aria-hidden="true">
							<span class="iftp-fm-mark__disc">
								<img class="iftp-fm-mark__logo" src="<?php echo esc_url( IFTP_FM_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="28" height="28" />
							</span>
							<span class="iftp-fm-mark__orbit"></span>
						</span>
						<p class="iftp-fm-empty-state__text"><?php esc_html_e( 'No ifthenpay payments match this view.', 'ifthenpay-payments-for-forminator' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
		} else {
			// One query for every form on the page, instead of one per form in form_title().
			_prime_post_caches( array_unique( array_filter( array_map( 'intval', array_column( $slice, 'form_id' ) ) ) ), false, false );

			foreach ( $slice as $record ) {
				$this->render_row( $record );
			}
		}
	}

	/**
	 * Renders one transaction as a row plus its hidden details row.
	 *
	 * The details row stays an empty shell; entries.js fills it from ajax_entry_details() on first open.
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_row( array $record ): void {
		$row_id     = 'iftp-fm-details-' . sanitize_html_class( (string) $record['ref'] );
		$text       = $this->row_text();
		$copy_label = sprintf( $text['copy_reference_n'], (string) $record['ref'] );
		$is_paid    = TransactionStore::STATUS_PAID === $record['status'];
		?>
		<tr class="iftp-fm-entry-row" data-iftp-ref="<?php echo esc_attr( (string) $record['ref'] ); ?>">
			<td class="iftp-fm-col-check">
				<input type="checkbox" class="iftp-fm-row-check" data-iftp-row-check data-iftp-status="<?php echo esc_attr( (string) $record['status'] ); ?>" value="<?php echo esc_attr( (string) $record['ref'] ); ?>" aria-label="<?php echo esc_attr( $text['select_entry'] ); ?>" />
			</td>
			<td data-col="ref">
				<button type="button" class="iftp-fm-ref-copy" data-iftp-copy-ref="<?php echo esc_attr( (string) $record['ref'] ); ?>" title="<?php echo esc_attr( $text['copy_reference'] ); ?>" aria-label="<?php echo esc_attr( $copy_label ); ?>">
					<strong><?php echo esc_html( (string) $record['ref'] ); ?></strong>
					<span class="iftp-fm-ref-copy__icon" aria-hidden="true"></span>
				</button>
				<div class="iftp-fm-row-actions">
					<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-details-toggle" data-details-target="<?php echo esc_attr( $row_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>">
						<?php echo esc_html( $text['view'] ); ?>
					</button>
					<?php $this->render_link_action( $record ); ?>
					<?php // Paid rows are payment history, so no Delete; the server refuses them too. ?>
					<?php if ( ! $is_paid ) : ?>
						<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
						<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-row-action--delete" data-iftp-row-delete="<?php echo esc_attr( (string) $record['ref'] ); ?>">
							<?php echo esc_html( $text['delete'] ); ?>
						</button>
					<?php endif; ?>
				</div>
			</td>
			<?php
			foreach ( $this->column_order() as $slug ) {
				$this->render_cell( $slug, $record );
			}
			?>
		</tr>
		<tr id="<?php echo esc_attr( $row_id ); ?>" class="iftp-fm-entry-details" data-iftp-details-ref="<?php echo esc_attr( (string) $record['ref'] ); ?>" hidden>
			<td colspan="9"></td>
		</tr>
		<?php
	}

	/**
	 * The row's payment-link action: "Copy link" while it can still be paid, otherwise a greyed-out reason.
	 *
	 * Only pending and expired rows get one; for the rest a link means nothing any more.
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_link_action( array $record ): void {
		$text        = $this->row_text();
		$payment_url = (string) ( $record['payment_url'] ?? '' );
		$status      = (string) $record['status'];

		if ( TransactionStore::STATUS_PENDING === $status && '' !== $payment_url ) {
			?>
			<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
			<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-row-action--copy" data-iftp-copy-link="<?php echo esc_attr( $payment_url ); ?>"><?php echo esc_html( $text['copy_link'] ); ?></button>
			<?php
			return;
		}

		if ( TransactionStore::STATUS_PENDING === $status ) {
			$label = $text['no_link'];
			$hint  = TransactionStore::MANUAL_GATEWAY_KEY === $record['gateway_key'] ? $text['no_link_adhoc_hint'] : $text['no_link_hint'];
		} elseif ( TransactionStore::STATUS_EXPIRED === $status && '' !== $payment_url ) {
			$label = $text['link_expired'];
			$hint  = $text['link_expired_hint'];
		} else {
			return;
		}
		?>
		<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
		<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action" title="<?php echo esc_attr( $hint ); ?>" disabled><?php echo esc_html( $label ); ?></button>
		<?php
	}

	/**
	 * One hideable column's header cell.
	 *
	 * @param string $slug    One of HIDEABLE_COLUMNS.
	 * @param string $orderby The current view's orderby, if any.
	 * @param string $order   The current view's order, if any.
	 */
	private function render_head_cell( string $slug, string $orderby, string $order ): void {
		switch ( $slug ) {
			case 'customer':
				?>
				<th data-col="customer" aria-sort="<?php echo esc_attr( $this->aria_sort( 'customer_name', $orderby, $order ) ); ?>"><?php echo wp_kses_post( $this->sort_link( 'customer_name', __( 'Customer', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
				<?php
				break;
			case 'form':
				?>
				<th data-col="form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-forminator' ); ?></th>
				<?php
				break;
			case 'method':
				?>
				<th data-col="method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-forminator' ); ?></th>
				<?php
				break;
			case 'amount':
				?>
				<th class="iftp-fm-col-amount" data-col="amount" aria-sort="<?php echo esc_attr( $this->aria_sort( 'amount', $orderby, $order ) ); ?>"><?php echo wp_kses_post( $this->sort_link( 'amount', __( 'Amount', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
				<?php
				break;
			case 'status':
				?>
				<th data-col="status" aria-sort="<?php echo esc_attr( $this->aria_sort( 'status', $orderby, $order ) ); ?>"><?php echo wp_kses_post( $this->sort_link( 'status', __( 'Status', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
				<?php
				break;
			case 'entry':
				?>
				<th data-col="entry"><?php esc_html_e( 'Entry', 'ifthenpay-payments-for-forminator' ); ?></th>
				<?php
				break;
			case 'created':
				?>
				<th data-col="created" aria-sort="<?php echo esc_attr( $this->aria_sort( 'created_at', $orderby, $order ) ); ?>"><?php echo wp_kses_post( $this->sort_link( 'created_at', __( 'Created', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
				<?php
				break;
		}
	}

	/**
	 * One hideable column's body cell for a row.
	 *
	 * @param string               $slug   One of HIDEABLE_COLUMNS.
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_cell( string $slug, array $record ): void {
		switch ( $slug ) {
			case 'customer':
				$customer_name  = (string) ( $record['customer_name'] ?? '' );
				$customer_email = (string) ( $record['customer_email'] ?? '' );
				?>
				<td data-col="customer" data-label="<?php echo esc_attr( $this->column_labels()['customer'] ); ?>">
					<?php if ( '' === $customer_name && '' === $customer_email ) : ?>
						<?php $this->print_empty_value( __( 'No customer', 'ifthenpay-payments-for-forminator' ) ); ?>
					<?php else : ?>
						<div class="iftp-fm-customer">
							<?php if ( '' !== $customer_name ) : ?>
								<span class="iftp-fm-customer__name" title="<?php echo esc_attr( $customer_name ); ?>"><?php echo esc_html( $customer_name ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $customer_email && false === strpbrk( $customer_email, '?&#%' ) ) : ?>
								<a class="iftp-fm-customer__email" href="<?php echo esc_url( 'mailto:' . $customer_email ); ?>" title="<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a>
							<?php elseif ( '' !== $customer_email ) : ?>
								<?php // I skip the mailto link here — is_email() lets ?/&/# through, which would inject mail headers. ?>
								<span class="iftp-fm-customer__email" title="<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</td>
				<?php
				break;
			case 'form':
				?>
				<td data-col="form" data-label="<?php echo esc_attr( $this->column_labels()['form'] ); ?>"><?php $this->render_form_cell( (int) $record['form_id'] ); ?></td>
				<?php
				break;
			case 'method':
				?>
				<td data-col="method" data-label="<?php echo esc_attr( $this->column_labels()['method'] ); ?>"><?php $this->render_method_cell( (string) $record['pay_method'], (string) $record['status'] ); ?></td>
				<?php
				break;
			case 'amount':
				?>
				<td class="iftp-fm-col-amount" data-col="amount"><?php echo esc_html( $this->format_amount( (float) $record['amount'] ) ); ?></td>
				<?php
				break;
			case 'status':
				?>
				<td data-col="status"><?php $this->print_status_badge( (string) $record['status'] ); ?></td>
				<?php
				break;
			case 'entry':
				?>
				<td data-col="entry" data-label="<?php echo esc_attr( $this->column_labels()['entry'] ); ?>"><?php $this->render_entry_cell( $record ); ?></td>
				<?php
				break;
			case 'created':
				?>
				<td data-col="created" data-label="<?php echo esc_attr( $this->column_labels()['created'] ); ?>"><?php $this->print_created( (int) $record['created_at'] ); ?></td>
				<?php
				break;
		}
	}

	/**
	 * Renders a row's expandable details panel.
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_details( array $record ): void {
		$text = $this->row_text();
		?>
		<div class="iftp-fm-details-panel">
			<div class="iftp-fm-details-grid">
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['reference'] ); ?></span>
					<code><?php echo esc_html( (string) $record['ref'] ); ?></code>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['request_id'] ); ?></span>
					<span><?php echo esc_html( '' !== $record['request_id'] ? (string) $record['request_id'] : '—' ); ?></span>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['transaction_id'] ); ?></span>
					<span><?php echo esc_html( '' !== $record['transaction_id'] ? (string) $record['transaction_id'] : '—' ); ?></span>
				</div>
				<div class="iftp-fm-detail-item iftp-fm-detail-item--wide">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['payment_link'] ); ?></span>
					<?php $payment_url = (string) ( $record['payment_url'] ?? '' ); ?>
					<?php if ( '' !== $payment_url ) : ?>
						<a href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $payment_url ); ?></a>
					<?php else : ?>
						<span><?php $this->print_empty_value( __( 'No payment link', 'ifthenpay-payments-for-forminator' ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['form_id'] ); ?></span>
					<span><?php echo esc_html( (string) $record['form_id'] ); ?></span>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['last_updated'] ); ?></span>
					<span title="<?php echo esc_attr( (string) wp_date( $this->datetime_format(), (int) $record['updated_at'] ) ); ?>"><?php echo esc_html( (string) wp_date( $this->short_datetime_format(), (int) $record['updated_at'] ) ); ?></span>
				</div>
			</div>
			<?php if ( $this->can_resend_email( $record ) ) : ?>
				<div class="iftp-fm-details-actions">
					<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary" data-iftp-resend-email="<?php echo esc_attr( (string) $record['ref'] ); ?>" data-iftp-resend-to="<?php echo esc_attr( (string) $record['customer_email'] ); ?>">
						<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="1.75" y="3.25" width="12.5" height="9.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m2.5 4.5 5.5 4 5.5-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
						<span data-iftp-resend-label><?php echo esc_html( $text['resend_email'] ); ?></span>
					</button>
					<span class="iftp-fm-details-actions__hint">
						<?php
						echo esc_html( sprintf( $text['resend_hint'], (string) $record['customer_email'] ) );
						?>
					</span>
				</div>
			<?php endif; ?>
			<?php
			$fields = $this->transactions->entry_data_pairs( $record );

			if ( array() !== $fields ) :
				?>
				<div class="iftp-fm-details-fields">
					<span class="iftp-fm-detail-label"><?php echo esc_html( $text['submitted_fields'] ); ?></span>
					<dl class="iftp-fm-details-fields-list">
						<?php foreach ( $fields as $pair ) : ?>
							<div class="iftp-fm-details-fields-list__row">
								<dt><?php echo esc_html( $pair['label'] ); ?></dt>
								<dd><?php echo esc_html( $pair['value'] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</div>
				<?php
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Renders the Form column: the form's title, or "Ad Hoc" for a "New payment" (form_id 0).
	 *
	 * @param int $form_id The transaction's form_id column.
	 */
	private function render_form_cell( int $form_id ): void {
		if ( 0 === $form_id ) {
			esc_html_e( 'Ad Hoc', 'ifthenpay-payments-for-forminator' );

			return;
		}

		echo esc_html( $this->form_title( $form_id ) );
	}

	/**
	 * Renders the Entry column: a link to the Forminator entry, or why there's none.
	 *
	 * Forminator only creates the entry once the payment is paid, so unpaid rows say so instead of a bare dash.
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_entry_cell( array $record ): void {
		if ( empty( $record['entry_id'] ) ) {
			$status = (string) $record['status'];

			if ( TransactionStore::STATUS_PAID === $status ) {
				$this->print_empty_value( __( 'No entry', 'ifthenpay-payments-for-forminator' ) );

				return;
			}
			?>
			<span class="iftp-fm-entry-none" title="<?php esc_attr_e( 'Forminator creates the entry only once the payment is paid.', 'ifthenpay-payments-for-forminator' ); ?>">
				<?php
				if ( TransactionStore::STATUS_PENDING === $status ) {
					esc_html_e( 'Not paid yet', 'ifthenpay-payments-for-forminator' );
				} else {
					esc_html_e( 'Not paid', 'ifthenpay-payments-for-forminator' );
				}
				?>
			</span>
			<?php

			return;
		}
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=forminator-entries&form_id=' . (int) $record['form_id'] ) ); ?>">
			#<?php echo esc_html( (string) (int) $record['entry_id'] ); ?>
		</a>
		<?php
	}

	/**
	 * Renders the Method column as a logo+label pill.
	 *
	 * The pay_method is only set once a payment confirms paid (or for "Ad Hoc"), so pending and expired rows get a placeholder.
	 *
	 * @param string $pay_method The transaction's pay_method column.
	 * @param string $status     The transaction's status column.
	 */
	private function render_method_cell( string $pay_method, string $status ): void {
		if ( '' === $pay_method && TransactionStore::STATUS_PENDING === $status ) {
			?>
			<span class="iftp-fm-method-pill iftp-fm-method-pill--placeholder" title="<?php esc_attr_e( 'The customer hasn\'t picked a payment method yet.', 'ifthenpay-payments-for-forminator' ); ?>"><?php esc_html_e( 'Awaiting selection', 'ifthenpay-payments-for-forminator' ); ?></span>
			<?php

			return;
		}

		if ( '' === $pay_method && TransactionStore::STATUS_EXPIRED === $status ) {
			?>
			<span class="iftp-fm-method-pill iftp-fm-method-pill--placeholder" title="<?php esc_attr_e( 'The payment expired before the customer picked a payment method.', 'ifthenpay-payments-for-forminator' ); ?>"><?php esc_html_e( 'Expired payment', 'ifthenpay-payments-for-forminator' ); ?></span>
			<?php

			return;
		}

		if ( '' === $pay_method ) {
			$this->print_empty_value( __( 'No payment method', 'ifthenpay-payments-for-forminator' ) );

			return;
		}

		if ( TransactionStore::AD_HOC_METHOD === $pay_method ) {
			?>
			<span class="iftp-fm-method-pill iftp-fm-method-pill--adhoc">
				<svg class="iftp-fm-method-pill__icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="1.5" y="4" width="13" height="8" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.25"/><circle cx="8" cy="8" r="1.75" fill="none" stroke="currentColor" stroke-width="1.25"/></svg>
				<?php echo esc_html( $pay_method ); ?>
			</span>
			<?php

			return;
		}

		$logos = $this->method_catalog()[ self::method_key( $pay_method ) ] ?? null;

		if ( null === $logos ) {
			?>
			<span class="iftp-fm-method-pill iftp-fm-method-pill--plain"><?php echo esc_html( $pay_method ); ?></span>
			<?php

			return;
		}
		?>
		<span class="iftp-fm-method-pill<?php echo '' === $logos['logo'] ? ' iftp-fm-method-pill--plain' : ''; ?>" data-entity="<?php echo esc_attr( $logos['entity'] ); ?>">
			<?php if ( '' !== $logos['logo'] ) : ?>
				<img class="iftp-fm-method-pill__logo" src="<?php echo esc_url( $logos['logo'] ); ?>"<?php echo '' !== $logos['logo_dark'] ? ' data-logo-dark="' . esc_url( $logos['logo_dark'] ) . '"' : ''; ?> alt="" loading="lazy" />
			<?php endif; ?>
			<?php echo esc_html( $pay_method ); ?>
		</span>
		<?php
	}

	/**
	 * Connected methods keyed by method_key() of their entity code and alias, with light/dark logo URLs.
	 *
	 * @var array<string, array{entity: string, logo: string, logo_dark: string}>|null
	 */
	private ?array $method_catalog_cache = null;

	/**
	 * Builds the method catalog, memoized for the request.
	 *
	 * The pay_method can hold the entity code (`MBWAY`) or a display name (`MB WAY`, `Cofidis Pay`), so I index both.
	 *
	 * @return array<string, array{entity: string, logo: string, logo_dark: string}>
	 */
	private function method_catalog(): array {
		if ( null !== $this->method_catalog_cache ) {
			return $this->method_catalog_cache;
		}

		$catalog = array();

		foreach ( $this->settings->get_methods() as $method ) {
			$entity = $method['entity'];

			if ( '' === $entity ) {
				continue;
			}

			$logos = array(
				'entity'    => $entity,
				'logo'      => (string) ( $method['logo'] ?? '' ),
				'logo_dark' => (string) ( $method['logo_dark'] ?? '' ),
			);

			$catalog[ self::method_key( $entity ) ] = $logos;
			$catalog                               += array( self::method_key( $method['alias'] ) => $logos );
		}

		unset( $catalog[''] );

		$this->method_catalog_cache = $catalog;

		return $this->method_catalog_cache;
	}

	/**
	 * Normalizes a method name: `MB WAY`, `mbway` and `MBWAY` are one key.
	 *
	 * @param string $name An entity code, alias or display name.
	 */
	private static function method_key( string $name ): string {
		$key = (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $name ) );

		return self::METHOD_SYNONYMS[ $key ] ?? $key;
	}

	/**
	 * Form titles by id, memoized per request.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	/**
	 * The given form's title, or "#<id>" when it can't be resolved.
	 *
	 * @param int $form_id The Forminator form id.
	 */
	private function form_title( int $form_id ): string {
		if ( array_key_exists( $form_id, $this->form_title_cache ) ) {
			return $this->form_title_cache[ $form_id ];
		}

		$title = get_the_title( $form_id );

		$this->form_title_cache[ $form_id ] = ( '' !== $title ? $title : '#' . $form_id );

		return $this->form_title_cache[ $form_id ];
	}

	/**
	 * Prints a status badge.
	 *
	 * @param string $status One of TransactionStore::ALL_STATUSES.
	 */
	private function print_status_badge( string $status ): void {
		echo '<span class="iftp-fm-badge iftp-fm-badge--' . esc_attr( $status ) . '">' . esc_html( $this->status_label( $status ) ) . '</span>';
	}

	/**
	 * Prints the dash for an empty cell, the way core list tables do: screen readers get the label, not "em dash".
	 *
	 * @param string $label What's missing, already translated.
	 */
	private function print_empty_value( string $label ): void {
		echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
	}

	/**
	 * A status's display label.
	 *
	 * @param string $status One of TransactionStore::ALL_STATUSES.
	 */
	private function status_label( string $status ): string {
		// Cancelled rows show as "Failed" — same badge styling, no separate label.
		$label_status = TransactionStore::STATUS_CANCELLED === $status ? TransactionStore::STATUS_FAILED : $status;

		return $this->render_memo[ 'status_' . $label_status ] ??= $this->status_filters( false )[ $label_status ] ?? $label_status;
	}

	/**
	 * The totals summary's inner list, for the AJAX responses.
	 *
	 * @param array<string, array{count: int, amount: float}> $summary From `TransactionStore::status_summary()`.
	 * @param array<string, mixed>|null                       $base    url_args() of the view, since AJAX has no `$_GET`.
	 */
	private function render_summary( array $summary, ?array $base = null ): string {
		ob_start();
		$this->print_summary( $summary, $base );

		return (string) ob_get_clean();
	}

	/**
	 * Prints the totals summary's inner list, one card per status, each linking to that status's tab.
	 *
	 * @param array<string, array{count: int, amount: float}> $summary From `TransactionStore::status_summary()`.
	 * @param array<string, mixed>|null                       $base    url_args() of the view; defaults to the current request's.
	 */
	private function print_summary( array $summary, ?array $base = null ): void {
		$base = $base ?? $this->url_args( $this->current_filters() );

		// One path per icon, drawn on a 16px grid like the page's other icons.
		$icons = array(
			TransactionStore::STATUS_PAID    => 'm3.5 8.5 3 3 6-7',
			TransactionStore::STATUS_PENDING => 'M14 8A6 6 0 1 1 2 8a6 6 0 0 1 12 0ZM8 4.75V8l2.25 1.5',
			TransactionStore::STATUS_FAILED  => 'M4.5 4.5l7 7M11.5 4.5l-7 7',
			TransactionStore::STATUS_EXPIRED => 'M4 2.25h8M4 13.75h8M5 2.25v1.5c0 2 3 2.75 3 4.25s-3 2.25-3 4.25v1.5M11 2.25v1.5c0 2-3 2.75-3 4.25s3 2.25 3 4.25v1.5',
		);
		?>
		<ul class="iftp-fm-summary__list">
			<?php foreach ( $icons as $slug => $icon_path ) : ?>
				<?php
				$count      = (int) ( $summary[ $slug ]['count'] ?? 0 );
				$amount     = (float) ( $summary[ $slug ]['amount'] ?? 0 );
				$is_current = $slug === $base['status'];
				$classes    = 'iftp-fm-summary__item' . ( 0 === $count ? ' iftp-fm-summary__item--empty' : '' ) . ( $is_current ? ' iftp-fm-summary__item--current' : '' );
				// Clicking the selected card again clears it back to "All", like a toggle.
				$status_url = $this->filtered_url(
					array(
						'status' => $is_current ? '' : $slug,
						'paged'  => null,
					),
					false,
					$base
				);
				?>
				<li class="<?php echo esc_attr( $classes ); ?>">
					<a class="iftp-fm-summary__link" href="<?php echo esc_url( $status_url ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>>
						<span class="iftp-fm-summary__label"><?php $this->print_status_badge( $slug ); ?></span>
						<span class="iftp-fm-summary__amount"><?php echo esc_html( $this->format_amount( $amount ) ); ?></span>
						<span class="iftp-fm-summary__count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of entries */
									_n( '%s entry', '%s entries', $count, 'ifthenpay-payments-for-forminator' ),
									number_format_i18n( $count )
								)
							);
							?>
						</span>
						<?php if ( $is_current ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Selected. Activate to show all entries.', 'ifthenpay-payments-for-forminator' ); ?></span>
						<?php endif; ?>
						<span class="iftp-fm-summary__icon iftp-fm-summary__icon--<?php echo esc_attr( $slug ); ?>" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 16 16" focusable="false"><path d="<?php echo esc_attr( $icon_path ); ?>" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The column labels, slug => label, translated once per request.
	 *
	 * @return array<string, string>
	 */
	private function column_labels(): array {
		$this->column_labels_cache ??= array(
			'customer' => __( 'Customer', 'ifthenpay-payments-for-forminator' ),
			'form'     => __( 'Form', 'ifthenpay-payments-for-forminator' ),
			'method'   => __( 'Method', 'ifthenpay-payments-for-forminator' ),
			'amount'   => __( 'Amount', 'ifthenpay-payments-for-forminator' ),
			'status'   => __( 'Status', 'ifthenpay-payments-for-forminator' ),
			'entry'    => __( 'Entry', 'ifthenpay-payments-for-forminator' ),
			'created'  => __( 'Created', 'ifthenpay-payments-for-forminator' ),
		);

		return $this->column_labels_cache;
	}

	/**
	 * The row and details strings, translated once per request.
	 *
	 * @return array<string, string>
	 */
	private function row_text(): array {
		$this->row_text_cache ??= array(
			'select_entry'       => __( 'Select entry', 'ifthenpay-payments-for-forminator' ),
			'copy_reference'     => __( 'Copy reference', 'ifthenpay-payments-for-forminator' ),
			/* translators: %s: payment reference */
			'copy_reference_n'   => __( 'Copy reference %s', 'ifthenpay-payments-for-forminator' ),
			'view'               => __( 'View', 'ifthenpay-payments-for-forminator' ),
			'copy_link'          => __( 'Copy link', 'ifthenpay-payments-for-forminator' ),
			'no_link'            => __( 'No link yet', 'ifthenpay-payments-for-forminator' ),
			'no_link_hint'       => __( 'No payment link was created for this payment yet. Sending the payment email creates one.', 'ifthenpay-payments-for-forminator' ),
			'no_link_adhoc_hint' => __( 'Ad hoc payments are recorded by hand, so they have no payment link.', 'ifthenpay-payments-for-forminator' ),
			'link_expired'       => __( 'Link expired', 'ifthenpay-payments-for-forminator' ),
			'link_expired_hint'  => __( 'This payment expired, so its link can no longer be paid.', 'ifthenpay-payments-for-forminator' ),
			'delete'             => __( 'Delete', 'ifthenpay-payments-for-forminator' ),
			'reference'          => __( 'Reference', 'ifthenpay-payments-for-forminator' ),
			'request_id'         => __( 'Request ID', 'ifthenpay-payments-for-forminator' ),
			'transaction_id'     => __( 'Transaction ID', 'ifthenpay-payments-for-forminator' ),
			'payment_link'       => __( 'Payment link', 'ifthenpay-payments-for-forminator' ),
			'form_id'            => __( 'Form ID', 'ifthenpay-payments-for-forminator' ),
			'last_updated'       => __( 'Last updated', 'ifthenpay-payments-for-forminator' ),
			'resend_email'       => __( 'Send payment email', 'ifthenpay-payments-for-forminator' ),
			/* translators: %s: customer email address */
			'resend_hint'        => __( 'Sends the payment button to %s', 'ifthenpay-payments-for-forminator' ),
			'submitted_fields'   => __( 'Submitted fields', 'ifthenpay-payments-for-forminator' ),
		);

		return $this->row_text_cache;
	}

	/**
	 * The site's date + time format, read once per request. Used for the full date on hover.
	 */
	private function datetime_format(): string {
		return $this->render_memo['datetime_format'] ??= (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
	}

	/**
	 * A compact date + time format for the table, translatable so each locale gets its own order.
	 */
	private function short_datetime_format(): string {
		/* translators: compact date and time format for the payment entries table, see https://www.php.net/manual/datetime.format.php */
		return $this->render_memo['short_datetime_format'] ??= _x( 'd/m/Y H:i', 'short date and time format', 'ifthenpay-payments-for-forminator' );
	}

	/**
	 * Prints the Created cell: "2 hours ago" while recent, the short date after that, the full date on hover.
	 *
	 * @param int $timestamp The row's created_at.
	 */
	private function print_created( int $timestamp ): void {
		$age = time() - $timestamp;

		if ( $age >= 0 && $age < self::RELATIVE_DATE_MAX_AGE ) {
			/* translators: %s: time since the payment was created, e.g. "2 hours" */
			$label = sprintf( __( '%s ago', 'ifthenpay-payments-for-forminator' ), human_time_diff( $timestamp ) );
		} else {
			$label = (string) wp_date( $this->short_datetime_format(), $timestamp );
		}

		printf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( (string) wp_date( 'c', $timestamp ) ),
			esc_attr( (string) wp_date( $this->datetime_format(), $timestamp ) ),
			esc_html( $label )
		);
	}

	/**
	 * An amount in the site's number format, e.g. "4.134,69 €" in Portuguese or "4,134.69 €" in English.
	 *
	 * @param float $amount The amount in euros.
	 */
	private function format_amount( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	/**
	 * The current admin's hidden columns, limited to HIDEABLE_COLUMNS.
	 *
	 * @return array<int, string>
	 */
	private function hidden_columns(): array {
		$stored = get_user_meta( get_current_user_id(), self::HIDDEN_COLS_META, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_intersect( self::HIDEABLE_COLUMNS, $stored ) );
	}

	/**
	 * The current admin's column order: every hideable slug, once each.
	 *
	 * @return array<int, string>
	 */
	private function column_order(): array {
		if ( null === $this->column_order_cache ) {
			$this->column_order_cache = $this->normalize_column_order( get_user_meta( get_current_user_id(), self::COL_ORDER_META, true ) );
		}

		return $this->column_order_cache;
	}

	/**
	 * Keeps known slugs in the given order and appends any missing ones.
	 *
	 * @param mixed $requested Stored meta or the sanitized `order[]` POST.
	 * @return array<int, string>
	 */
	private function normalize_column_order( $requested ): array {
		$order = is_array( $requested )
			? array_values( array_unique( array_intersect( array_filter( $requested, 'is_string' ), self::HIDEABLE_COLUMNS ) ) )
			: array();

		return array_merge( $order, array_values( array_diff( self::HIDEABLE_COLUMNS, $order ) ) );
	}

	/**
	 * The quick date-range chips, as Y-m-d bounds in the site's timezone.
	 *
	 * @return array<int, array{label: string, from: string, to: string}>
	 */
	private function date_presets(): array {
		$today = current_datetime();

		return array(
			array(
				'label' => __( 'Today', 'ifthenpay-payments-for-forminator' ),
				'from'  => $today->format( 'Y-m-d' ),
				'to'    => $today->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'Last 7 days', 'ifthenpay-payments-for-forminator' ),
				'from'  => $today->modify( '-6 days' )->format( 'Y-m-d' ),
				'to'    => $today->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'This month', 'ifthenpay-payments-for-forminator' ),
				'from'  => $today->modify( 'first day of this month' )->format( 'Y-m-d' ),
				'to'    => $today->modify( 'last day of this month' )->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'Last month', 'ifthenpay-payments-for-forminator' ),
				'from'  => $today->modify( 'first day of last month' )->format( 'Y-m-d' ),
				'to'    => $today->modify( 'last day of last month' )->format( 'Y-m-d' ),
			),
		);
	}

	/**
	 * The "Form" filter options: every form with a transaction, id => title.
	 *
	 * @return array<int, string>
	 */
	private function available_forms(): array {
		$forms    = array();
		$form_ids = $this->transactions->distinct_form_ids();

		_prime_post_caches( $form_ids, false, false );

		foreach ( $form_ids as $form_id ) {
			$forms[ $form_id ] = $this->form_title( $form_id );
		}

		asort( $forms );

		return $forms;
	}

	/**
	 * A sortable column header link.
	 *
	 * @param string $key             The column's orderby key (e.g. `ref`).
	 * @param string $label           The column's display label.
	 * @param string $current_orderby The current view's orderby, if any.
	 * @param string $current_order   The current view's order ('asc'/'desc'), if any.
	 */
	private function sort_link( string $key, string $label, string $current_orderby, string $current_order ): string {
		$is_sorted  = $key === $current_orderby;
		$next_order = ( $is_sorted && 'asc' === $current_order ) ? 'desc' : 'asc';

		$classes = 'iftp-fm-sortable';

		if ( $is_sorted ) {
			$classes .= ' iftp-fm-sortable--' . $current_order;
		}

		return sprintf(
			'<a class="%1$s" href="%2$s">%3$s <span class="iftp-fm-sort-icon" aria-hidden="true"></span></a>',
			esc_attr( $classes ),
			esc_url(
				$this->filtered_url(
					array(
						'orderby' => $key,
						'order'   => $next_order,
						'paged'   => null,
					)
				)
			),
			esc_html( $label )
		);
	}

	/**
	 * The th's aria-sort value for a sortable column.
	 *
	 * @param string $key             The column's orderby key.
	 * @param string $current_orderby The current view's orderby, if any.
	 * @param string $current_order   The current view's order, if any.
	 */
	private function aria_sort( string $key, string $current_orderby, string $current_order ): string {
		if ( $key !== $current_orderby ) {
			return 'none';
		}

		return 'asc' === $current_order ? 'ascending' : 'descending';
	}

	/**
	 * Renders the pagination, windowed around the current page.
	 *
	 * @param int                       $paged The current page number.
	 * @param int                       $pages The total number of pages.
	 * @param array<string, mixed>|null $base View state to build links from; AJAX needs it since $_GET is admin-ajax.php's.
	 */
	private function render_pagination( int $paged, int $pages, ?array $base = null ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$args         = $base ?? $this->url_args( $this->current_filters() );
		$window_start = max( 1, $paged - 2 );
		$window_end   = min( $paged + 2, $pages );
		?>
		<nav class="iftp-fm-pagination" aria-label="<?php esc_attr_e( 'Entries pagination', 'ifthenpay-payments-for-forminator' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a class="iftp-fm-page-btn iftp-fm-page-btn--prev" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged - 1 ), false, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'ifthenpay-payments-for-forminator' ); ?>"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M10 3.5 5.5 8l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
			<?php endif; ?>

			<?php if ( $window_start > 1 ) : ?>
				<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => 1 ), false, $base ) ); ?>">1</a>
				<?php if ( $window_start > 2 ) : ?>
					<?php $this->render_page_jump( $pages, $args ); ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php for ( $page = $window_start; $page <= $window_end; $page++ ) : ?>
				<?php if ( $page === $paged ) : ?>
					<span class="iftp-fm-page-number iftp-fm-page-number--current" aria-current="page"><?php echo esc_html( (string) $page ); ?></span>
				<?php else : ?>
					<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $page ), false, $base ) ); ?>"><?php echo esc_html( (string) $page ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $window_end < $pages ) : ?>
				<?php if ( $window_end < $pages - 1 ) : ?>
					<?php $this->render_page_jump( $pages, $args ); ?>
				<?php endif; ?>
				<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $pages ), false, $base ) ); ?>"><?php echo esc_html( (string) $pages ); ?></a>
			<?php endif; ?>

			<?php if ( $paged < $pages ) : ?>
				<a class="iftp-fm-page-btn iftp-fm-page-btn--next" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged + 1 ), false, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'ifthenpay-payments-for-forminator' ); ?>"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * A page-jump box in place of a pagination ellipsis, as its own GET form so it works without JS.
	 *
	 * @param int                  $pages The total number of pages.
	 * @param array<string, mixed> $args  The view's URL args.
	 */
	private function render_page_jump( int $pages, array $args ): void {
		?>
		<form class="iftp-fm-page-jump" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<?php $this->hidden_view_inputs( $args, array( 'paged' ) ); ?>
			<input type="number" class="iftp-fm-page-jump__input" name="paged" min="1" max="<?php echo esc_attr( (string) $pages ); ?>" step="1" inputmode="numeric" required autocomplete="off" placeholder="&hellip;" aria-label="<?php esc_attr_e( 'Go to page', 'ifthenpay-payments-for-forminator' ); ?>" />
		</form>
		<?php
	}

	/**
	 * The "X - Y out of Z" footer label, blank when there's nothing (the empty state says it).
	 *
	 * @param int $paged    The current page number.
	 * @param int $per_page The number of rows per page.
	 * @param int $total    The total number of matching rows.
	 */
	private function entries_range_label( int $paged, int $per_page, int $total ): string {
		if ( $total <= 0 ) {
			return '';
		}

		$first = ( $paged - 1 ) * $per_page + 1;
		$last  = min( $paged * $per_page, $total );

		return sprintf(
			/* translators: 1: first entry number shown, 2: last entry number shown, 3: total number of entries */
			__( '%1$s – %2$s out of %3$s', 'ifthenpay-payments-for-forminator' ),
			number_format_i18n( $first ),
			number_format_i18n( $last ),
			number_format_i18n( $total )
		);
	}

	/**
	 * Rebuilds the current URL with the given params overridden (null removes one).
	 *
	 * @param array<string, mixed>      $overrides The params to override.
	 * @param bool                      $reset Whether to discard every existing param instead.
	 * @param array<string, mixed>|null $base View state to build from instead of $_GET (needed from AJAX).
	 */
	private function filtered_url( array $overrides, bool $reset = false, ?array $base = null ): string {
		if ( $reset ) {
			return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		}

		$args = array( 'page' => self::PAGE_SLUG ) + ( $base ?? $this->url_args( $this->current_filters() ) );

		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = $value;
			}
		}

		return add_query_arg( $this->query_values( $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Hidden inputs that carry a view's state into a plain GET form.
	 *
	 * @param array<string, mixed> $args url_args() output.
	 * @param array<int, string>   $omit Keys the form sets itself.
	 */
	private function hidden_view_inputs( array $args, array $omit ): void {
		foreach ( array( 'page' => self::PAGE_SLUG ) + $args as $key => $value ) {
			if ( in_array( $key, $omit, true ) || '' === $value || null === $value ) {
				continue;
			}

			if ( 'per_page' === $key && self::DEFAULT_PER_PAGE === (int) $value ) {
				continue;
			}

			echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	/**
	 * One chip per applied panel filter, each linking to the view without it.
	 *
	 * @param array<string, mixed> $view current_filters() output.
	 * @return array<int, array{key: string, value: string, url: string}>
	 */
	private function active_filter_chips( array $view ): array {
		$chips = array();

		$form_id = (int) $view['form_id'];

		if ( $form_id > 0 ) {
			$chips[] = array(
				'key'   => __( 'Form:', 'ifthenpay-payments-for-forminator' ),
				'value' => $this->form_title( $form_id ),
				'url'   => $this->filtered_url(
					array(
						'form_id' => null,
						'paged'   => null,
					)
				),
			);
		}

		$pay_method = (string) $view['pay_method'];

		if ( '' !== $pay_method ) {
			$chips[] = array(
				'key'   => __( 'Method:', 'ifthenpay-payments-for-forminator' ),
				'value' => $pay_method,
				'url'   => $this->filtered_url(
					array(
						'pay_method' => null,
						'paged'      => null,
					)
				),
			);
		}

		$amount = $this->range_label(
			'' !== $view['amount_min'] ? $this->format_amount( (float) $view['amount_min'] ) : '',
			'' !== $view['amount_max'] ? $this->format_amount( (float) $view['amount_max'] ) : '',
			/* translators: %s: an amount or a date */
			__( 'up to %s', 'ifthenpay-payments-for-forminator' )
		);

		if ( '' !== $amount ) {
			$chips[] = array(
				'key'   => __( 'Amount:', 'ifthenpay-payments-for-forminator' ),
				'value' => $amount,
				'url'   => $this->filtered_url(
					array(
						'amount_min' => null,
						'amount_max' => null,
						'paged'      => null,
					)
				),
			);
		}

		$date_from = (string) $view['date_from'];
		$date_to   = (string) $view['date_to'];
		$created   = '';

		// A range that matches a quick preset reads better as its label.
		foreach ( $this->date_presets() as $preset ) {
			if ( '' !== $date_from && $preset['from'] === $date_from && $preset['to'] === $date_to ) {
				$created = $preset['label'];
				break;
			}
		}

		if ( '' === $created ) {
			$created = $this->range_label(
				'' !== $date_from ? (string) wp_date( get_option( 'date_format' ), $this->day_bound( $date_from, '12:00:00' ) ) : '',
				'' !== $date_to ? (string) wp_date( get_option( 'date_format' ), $this->day_bound( $date_to, '12:00:00' ) ) : '',
				/* translators: %s: an amount or a date */
				__( 'until %s', 'ifthenpay-payments-for-forminator' )
			);
		}

		if ( '' !== $created ) {
			$chips[] = array(
				'key'   => __( 'Created:', 'ifthenpay-payments-for-forminator' ),
				'value' => $created,
				'url'   => $this->filtered_url(
					array(
						'date_from' => null,
						'date_to'   => null,
						'paged'     => null,
					)
				),
			);
		}

		return $chips;
	}

	/**
	 * A chip's range text: "A – B", "from A", or the given upper-only wording.
	 *
	 * @param string $low   Formatted lower bound, or ''.
	 * @param string $high  Formatted upper bound, or ''.
	 * @param string $up_to Translated upper-only format with one %s.
	 */
	private function range_label( string $low, string $high, string $up_to ): string {
		if ( '' !== $low && '' !== $high ) {
			/* translators: 1: lower bound, 2: upper bound */
			return sprintf( __( '%1$s – %2$s', 'ifthenpay-payments-for-forminator' ), $low, $high );
		}

		if ( '' !== $low ) {
			/* translators: %s: an amount or a date */
			return sprintf( __( 'from %s', 'ifthenpay-payments-for-forminator' ), $low );
		}

		return '' !== $high ? sprintf( $up_to, $high ) : '';
	}

	/**
	 * The "Clear filters" link: drops the panel filters, keeps search/status/sort/per-page.
	 */
	private function clear_filters_url(): string {
		return $this->filtered_url(
			array(
				'form_id'    => null,
				'pay_method' => null,
				'amount_min' => null,
				'amount_max' => null,
				'date_from'  => null,
				'date_to'    => null,
				'paged'      => null,
			)
		);
	}

	/**
	 * The export link for the given view and format, without pagination.
	 *
	 * @param array<string, mixed> $view   See `current_filters()`.
	 * @param string               $format One of the EXPORT_* formats.
	 */
	private function export_url( array $view, string $format ): string {
		$args = $this->url_args( $view );

		unset( $args['per_page'], $args['paged'] );

		$args = array(
			'action'   => self::EXPORT_ACTION,
			'_wpnonce' => wp_create_nonce( self::EXPORT_ACTION ),
			'format'   => $format,
		) + $args;

		return add_query_arg( $this->query_values( $args ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * Drops blank params and URL-encodes the rest.
	 *
	 * The add_query_arg() call doesn't encode values, so a search like "a&b" would split the query.
	 *
	 * @param array<string, mixed> $args Query params.
	 * @return array<string, string>
	 */
	private function query_values( array $args ): array {
		$args = array_filter(
			$args,
			static function ( $value ): bool {
				return '' !== $value && null !== $value;
			}
		);

		return array_map(
			static function ( $value ): string {
				return rawurlencode( (string) $value );
			},
			$args
		);
	}

	/**
	 * A view's filter/sort/pagination state as URL params (no `page`).
	 *
	 * @param array<string, mixed> $view See `current_filters()`.
	 * @return array<string, mixed>
	 */
	private function url_args( array $view ): array {
		return array(
			's'          => $view['s'],
			'status'     => $view['status'],
			'form_id'    => $view['form_id'] > 0 ? (string) $view['form_id'] : '',
			'date_from'  => $view['date_from'],
			'date_to'    => $view['date_to'],
			'pay_method' => $view['pay_method'],
			'amount_min' => $view['amount_min'],
			'amount_max' => $view['amount_max'],
			'orderby'    => $view['orderby'],
			'order'      => $view['order'],
			'per_page'   => $view['per_page'],
			'paged'      => $view['paged'],
		);
	}

	/**
	 * The store's where-args: everything but status/sort/pagination.
	 *
	 * @param array<string, mixed> $view See `current_filters()`.
	 * @return array<string, mixed>
	 */
	private function store_filters( array $view ): array {
		return array(
			'form_id'    => $view['form_id'],
			'date_from'  => $this->day_bound( $view['date_from'], '00:00:00' ),
			'date_to'    => $this->day_bound( $view['date_to'], '23:59:59' ),
			'search'     => $view['s'],
			'pay_method' => $view['pay_method'],
			'amount_min' => '' !== $view['amount_min'] ? (float) $view['amount_min'] : null,
			'amount_max' => '' !== $view['amount_max'] ? (float) $view['amount_max'] : null,
		);
	}

	/**
	 * The current request's view state, read once per request.
	 *
	 * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, pay_method: string, amount_min: string, amount_max: string, per_page: int, paged: int, orderby: string, order: string}
	 */
	private function current_filters(): array {
		if ( null === $this->current_filters_cache ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state from this screen's query string; filters_from() sanitizes every key.
			$this->current_filters_cache = $this->filters_from( $_GET );
		}

		return $this->current_filters_cache;
	}

	/**
	 * Reads and sanitizes the view state from a raw (still slashed) request array.
	 *
	 * @param array<string, mixed> $source The raw request array.
	 * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, pay_method: string, amount_min: string, amount_max: string, per_page: int, paged: int, orderby: string, order: string}
	 */
	private function filters_from( array $source ): array {
		$orderby = $this->sanitize_orderby( sanitize_text_field( wp_unslash( $source['orderby'] ?? '' ) ) );

		list( $amount_min, $amount_max ) = $this->ordered_amount_range(
			$this->sanitize_amount( sanitize_text_field( wp_unslash( $source['amount_min'] ?? '' ) ) ),
			$this->sanitize_amount( sanitize_text_field( wp_unslash( $source['amount_max'] ?? '' ) ) )
		);

		return array(
			's'          => sanitize_text_field( wp_unslash( $source['s'] ?? '' ) ),
			'status'     => sanitize_text_field( wp_unslash( $source['status'] ?? '' ) ),
			'form_id'    => absint( wp_unslash( $source['form_id'] ?? 0 ) ),
			'date_from'  => $this->sanitize_date( sanitize_text_field( wp_unslash( $source['date_from'] ?? '' ) ) ),
			'date_to'    => $this->sanitize_date( sanitize_text_field( wp_unslash( $source['date_to'] ?? '' ) ) ),
			'pay_method' => $this->sanitize_pay_method( sanitize_text_field( wp_unslash( $source['pay_method'] ?? '' ) ) ),
			'amount_min' => $amount_min,
			'amount_max' => $amount_max,
			'per_page'   => isset( $source['per_page'] )
				? $this->resolve_per_page( sanitize_text_field( wp_unslash( $source['per_page'] ) ), sanitize_text_field( wp_unslash( $source['per_page_custom'] ?? '' ) ) )
				: $this->saved_per_page(),
			'paged'      => max( 1, absint( wp_unslash( $source['paged'] ?? 1 ) ) ),
			'orderby'    => $orderby,
			'order'      => '' !== $orderby ? $this->sanitize_order( sanitize_text_field( wp_unslash( $source['order'] ?? '' ) ) ) : '',
		);
	}

	/**
	 * A filter date as a timestamp in the site's timezone, or 0 for no bound.
	 *
	 * Not strtotime(): it reads UTC, which would shift the filter off from the dates wp_date() shows.
	 *
	 * @param string $date A Y-m-d date from sanitize_date(), or ''.
	 * @param string $time The time of day to pin it to.
	 */
	private function day_bound( string $date, string $time ): int {
		if ( '' === $date ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Validates a Y-m-d date, discarding anything malformed.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_date( string $raw ): string {
		$value = sanitize_text_field( $raw );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );

		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Validates orderby against the sortable columns ('' means the default order).
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_orderby( string $raw ): string {
		$value = sanitize_text_field( $raw );

		// Not ref: references are random strings, so sorting by them tells the admin nothing.
		return in_array( $value, array( 'customer_name', 'amount', 'status', 'created_at' ), true ) ? $value : '';
	}

	/**
	 * Accepts only a method that actually appears in the table, else ''.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_pay_method( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}

		return in_array( $raw, $this->transactions->distinct_methods(), true ) ? $raw : '';
	}

	/**
	 * Normalizes an amount bound to a dot-decimal string, or '' for no bound.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_amount( string $raw ): string {
		$value = str_replace( ',', '.', trim( $raw ) );

		if ( ! preg_match( '/^\d{1,12}(?:\.\d+)?$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Swaps an inverted amount range, so "50 – 10" means 10 to 50.
	 *
	 * @param string $min From sanitize_amount().
	 * @param string $max From sanitize_amount().
	 * @return array{0: string, 1: string}
	 */
	private function ordered_amount_range( string $min, string $max ): array {
		if ( '' !== $min && '' !== $max && (float) $min > (float) $max ) {
			return array( $max, $min );
		}

		return array( $min, $max );
	}

	/**
	 * Validates order as 'asc'/'desc', defaulting to 'desc'.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_order( string $raw ): string {
		$value = strtolower( sanitize_text_field( $raw ) );

		return 'asc' === $value ? 'asc' : 'desc';
	}

	/**
	 * Clamps a per-page value to [PER_PAGE_MIN, PER_PAGE_MAX]; non-numeric means the default.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_per_page( string $raw ): int {
		$raw = sanitize_text_field( $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return self::DEFAULT_PER_PAGE;
		}

		return max( self::PER_PAGE_MIN, min( self::PER_PAGE_MAX, (int) $raw ) );
	}

	/**
	 * Resolves the per-page select, using the custom box's value for "custom".
	 *
	 * @param string $raw    The per_page value, already sanitize_text_field()'d.
	 * @param string $custom The per_page_custom value, already sanitize_text_field()'d.
	 */
	private function resolve_per_page( string $raw, string $custom ): int {
		return $this->sanitize_per_page( 'custom' === $raw ? $custom : $raw );
	}

	/**
	 * The status slug => label map.
	 *
	 * @param bool $with_all Whether to include the "All" (`''`) entry.
	 * @return array<string, string>
	 */
	private function status_filters( bool $with_all = true ): array {
		$filters = array();

		if ( $with_all ) {
			$filters[''] = __( 'All', 'ifthenpay-payments-for-forminator' );
		}

		// Cancelled is folded into "Failed" in this list; only the post-payment popup shows it on its own.
		$labels = array(
			TransactionStore::STATUS_PENDING => __( 'Pending', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_PAID    => __( 'Paid', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_FAILED  => __( 'Failed', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_EXPIRED => __( 'Expired', 'ifthenpay-payments-for-forminator' ),
		);

		return $filters + $labels;
	}
}
