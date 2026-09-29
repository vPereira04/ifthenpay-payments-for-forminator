<?php
/**
 * Payment Entries admin dashboard.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Plugin;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Payment Entries" screen — its own top-level admin menu page, not
 * a full stats/chart dashboard. Forminator already has a native per-form
 * Entries screen (unlike Contact Form 7/Ninja Forms, which had none), so
 * this only needs to cover what has no other home until a real Forminator
 * entry exists: pending/failed/cancelled ifthenpay activity, plus a link
 * through to the real entry once one's been created, and a way to record a
 * payment taken outside the normal checkout ("New payment").
 *
 * Sort/filter/pagination links are plain GET navigations (see
 * `filtered_url()`), same as the reference this was rebuilt from
 * (`ifthenpay-payments-for-ninja-forms`'s `EntriesPage`) actually behaves —
 * only delete/status-change/create go through AJAX, updating the table in
 * place without a full reload (plus saving the Columns choice). CSV export
 * is a plain admin-post.php download.
 */
class PaymentEntriesPage {

	private const PAGE_SLUG        = 'iftp-fm-transactions';
	private const DEFAULT_PER_PAGE = 10;
	private const PER_PAGE_OPTIONS = array( 5, 10, 20, 30, 50 );
	private const PER_PAGE_MIN     = 1;
	private const PER_PAGE_MAX     = 250;
	private const NONCE_ACTION     = 'iftp_fm_entries';
	private const EXPORT_ACTION    = 'iftp_fm_export';
	private const HIDDEN_COLS_META = 'iftp_fm_entries_hidden_cols';
	private const COL_ORDER_META   = 'iftp_fm_entries_col_order';

	/**
	 * Columns the admin can hide from the Columns menu, in default order.
	 * Reference and the checkbox column always stay.
	 */
	private const HIDEABLE_COLUMNS = array( 'customer', 'form', 'method', 'amount', 'status', 'entry', 'created' );

	/**
	 * The current admin's column order, read once per request.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $column_order_cache = null;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Settings storage — only needed here for the connected methods'
	 * logos in the Method column.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the page.
	 *
	 * @param TransactionStore|null   $transactions Transactions storage; a fresh instance is used when omitted.
	 * @param SettingsRepository|null $settings     Settings storage; a fresh instance is used when omitted.
	 */
	public function __construct( ?TransactionStore $transactions = null, ?SettingsRepository $settings = null ) {
		$this->transactions = $transactions ?? new TransactionStore();
		$this->settings     = $settings ?? new SettingsRepository();
	}

	/**
	 * Registers the menu, assets, AJAX and export hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_iftp_fm_delete_entries', array( $this, 'ajax_delete_entries' ) );
		add_action( 'wp_ajax_iftp_fm_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_iftp_fm_create_entry', array( $this, 'ajax_create_entry' ) );
		add_action( 'wp_ajax_iftp_fm_save_columns', array( $this, 'ajax_save_columns' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Registers our own top-level admin menu page — sidestepping Forminator's
	 * `'forminator'` menu, whose own capability handling (`manage_forminator`)
	 * doesn't line up with a plain `add_submenu_page()` entry underneath it.
	 */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-forminator' ),
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-forminator' ),
			forminator_get_admin_cap(),
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-money-alt'
		);
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

		wp_enqueue_style( 'iftp-fm-entries', IFTP_FM_URL . $entries_css, array(), (string) filemtime( IFTP_FM_PATH . $entries_css ) );
		wp_enqueue_script( 'iftp-fm-entries', IFTP_FM_URL . $entries_js, array( Plugin::METHOD_LOGOS_HANDLE ), (string) filemtime( IFTP_FM_PATH . $entries_js ), true );

		// Head, not footer — it may redirect to the saved view, and I don't want the default one flashing first.
		wp_enqueue_script( 'iftp-fm-entries-restore', IFTP_FM_URL . $restore_js, array(), (string) filemtime( IFTP_FM_PATH . $restore_js ), false );

		$view = $this->url_args( $this->current_filters() );

		wp_localize_script(
			'iftp-fm-entries-restore',
			'iftpFmEntriesStore',
			array(
				// Per site and per user, so a shared browser doesn't mix admins' views.
				'key'            => 'iftp_fm_entries:' . get_current_blog_id() . ':' . get_current_user_id(),
				'pageSlug'       => self::PAGE_SLUG,
				'defaultPerPage' => self::DEFAULT_PER_PAGE,
				'perPage'        => $view['per_page'],
				'view'           => array_diff_key( $view, array( 'per_page' => true ) ),
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
				// Plain status => label map (no "Mark as …" wording) — the
				// bulk-action <select>'s own option text already has that
				// phrasing baked in (see render()); this is only for
				// building the confirm dialog's message.
				'statusLabels'       => $this->status_filters( false ),
				'defaultColumnOrder' => self::HIDEABLE_COLUMNS,
				'i18n'               => array(
					'confirmDeleteTitle'    => __( 'Delete selected entries?', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteMessage'  => __( 'This only removes their payment-tracking record here — any real Forminator entry already created from it is left untouched.', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteButton'   => __( 'Delete', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusTitle'    => __( 'Change status?', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: target status label, e.g. "Paid" */
					'confirmStatusMessage'  => __( 'Mark the selected entries as "%s".', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusButton'   => __( 'Change status', 'ifthenpay-payments-for-forminator' ),
					'deleteError'           => __( 'Could not delete the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'updateStatusError'     => __( 'Could not update the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'deleteToastSuccess'    => __( 'Selected entries deleted.', 'ifthenpay-payments-for-forminator' ),
					'statusToastSuccess'    => __( 'Selected entries updated.', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: number of selected entries, 2: total number of entries */
					'selectedOfTotal'       => __( '%1$s selected out of %2$s', 'ifthenpay-payments-for-forminator' ),
					'newEntryCreating'      => __( 'Creating…', 'ifthenpay-payments-for-forminator' ),
					'newEntryToastSuccess'  => __( 'Payment recorded.', 'ifthenpay-payments-for-forminator' ),
					'newEntryError'         => __( 'Could not record the payment. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'copyLinkSuccess'       => __( 'Payment link copied.', 'ifthenpay-payments-for-forminator' ),
					'copyLinkError'         => __( 'Could not copy the link.', 'ifthenpay-payments-for-forminator' ),
					'columnsSaveError'      => __( 'Could not save your column choice.', 'ifthenpay-payments-for-forminator' ),
					'exportNothingSelected' => __( 'Select at least one entry to export.', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: column name, 2: new position, 3: number of movable columns */
					'columnMoved'           => __( '%1$s moved to position %2$s of %3$s.', 'ifthenpay-payments-for-forminator' ),
					'columnsReset'          => __( 'Columns reset to the default order, all shown.', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: number of selected entries */
					'bulkSelectedCount'     => __( '%s selected', 'ifthenpay-payments-for-forminator' ),
				),
			)
		);
	}

	/**
	 * AJAX: deletes the given refs, then re-queries the browser's current
	 * filtered/sorted/paginated view and returns a fresh tbody +
	 * pagination — so the next page's rows shift up to refill the gap,
	 * same as a reload would show.
	 */
	public function ajax_delete_entries(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$refs    = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['refs'] ?? array() ) ) );
		$deleted = 0;

		foreach ( $refs as $ref ) {
			if ( '' === $ref || null === $this->transactions->get( $ref ) ) {
				continue;
			}

			$this->transactions->delete( $ref );
			++$deleted;
		}

		$view = $this->view_state_from_request( '' );

		wp_send_json_success(
			array_merge(
				array( 'deleted' => $deleted ),
				$this->rendered_view( $view )
			)
		);
	}

	/**
	 * AJAX: the "New payment" popup's submit — validates, records it via
	 * `TransactionStore::create_manual()`, then refreshes the current
	 * filtered/sorted/paginated view the same way `ajax_delete_entries()`
	 * does, so the new entry shows up immediately if it matches what's
	 * currently on screen. The view's own filter state arrives under
	 * `view_*` keys here (unlike the other AJAX handlers), since a plain
	 * `form_id` key would be ambiguous with the view's own "All forms"
	 * filter — every entry created here is ad hoc, never filed under a
	 * form the admin picks.
	 */
	public function ajax_create_entry(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$amount = (float) sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) );

		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter an amount greater than zero.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$customer_email = sanitize_text_field( wp_unslash( $_POST['customer_email'] ?? '' ) );

		if ( '' !== $customer_email && ! is_email( $customer_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email, or leave it blank.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
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
	 * AJAX: bulk-corrects the status of the given refs. This is an
	 * explicit admin override, so unlike the webhook path it's allowed to
	 * move a record off "paid" too.
	 */
	public function ajax_update_status(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$refs    = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['refs'] ?? array() ) ) );
		$updated = 0;

		foreach ( $refs as $ref ) {
			if ( '' === $ref || null === $this->transactions->get( $ref ) ) {
				continue;
			}

			if ( $this->transactions->admin_set_status( $ref, $status ) ) {
				++$updated;
			}
		}

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
	 * AJAX: remembers which columns the current admin hid, and their order.
	 * Anything outside HIDEABLE_COLUMNS is dropped. The order is only
	 * touched when `order` was sent.
	 */
	public function ajax_save_columns(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$requested = array_map( 'sanitize_key', wp_unslash( (array) ( $_POST['hidden'] ?? array() ) ) );
		$hidden    = array_values( array_intersect( self::HIDEABLE_COLUMNS, $requested ) );

		update_user_meta( get_current_user_id(), self::HIDDEN_COLS_META, $hidden );

		$response = array( 'hidden' => $hidden );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		if ( isset( $_POST['order'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
			$order = $this->normalize_column_order( array_map( 'sanitize_key', wp_unslash( (array) $_POST['order'] ) ) );

			update_user_meta( get_current_user_id(), self::COL_ORDER_META, $order );
			$response['order'] = $order;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Admin-post: streams the current filtered/sorted view as a CSV
	 * download — or, when the bulk "Export selected" posts `refs[]`, only
	 * those rows. Never returns.
	 */
	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already checked above via check_admin_referer(); filters_from() sanitizes every key it reads.
		$view = $this->filters_from( $_REQUEST );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via check_admin_referer().
		$refs = array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['refs'] ?? array() ) ) ) ) );

		$args = $this->store_filters( $view ) + array(
			'status'  => $view['status'],
			'orderby' => $view['orderby'],
			'order'   => $view['order'],
		);

		if ( array() !== $refs ) {
			$args['refs'] = $refs;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ifthenpay-entries-' . wp_date( 'Y-m-d' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download straight to the response body; WP_Filesystem has no stream API.
		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		// A BOM so Excel reads the file as UTF-8.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see fopen() above.
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
			$form_id  = (int) $record['form_id'];
			$entry_id = (int) ( $record['entry_id'] ?? 0 );

			$this->put_csv_row(
				$out,
				array(
					(string) $record['ref'],
					0 === $form_id ? __( 'Ad Hoc', 'ifthenpay-payments-for-forminator' ) : html_entity_decode( wp_strip_all_tags( $this->form_title( $form_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					(string) ( $record['customer_name'] ?? '' ),
					(string) ( $record['customer_email'] ?? '' ),
					(string) $record['pay_method'],
					number_format( (float) $record['amount'], 2, '.', '' ),
					$this->status_label( (string) $record['status'] ),
					$entry_id > 0 ? (string) $entry_id : '',
					(string) wp_date( 'Y-m-d H:i:s', (int) $record['created_at'] ),
					(string) wp_date( 'Y-m-d H:i:s', (int) $record['updated_at'] ),
					(string) $record['request_id'],
					(string) $record['transaction_id'],
					(string) ( $record['payment_url'] ?? '' ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen() above.
		fclose( $out );
		exit;
	}

	/**
	 * Writes one CSV line, with every cell guarded against spreadsheet
	 * formula injection.
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
	 * Verifies the request nonce and the settings-page capability. Every
	 * AJAX handler above calls this first.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}
	}

	/**
	 * Reads the browser's current filter/sort/pagination state from
	 * $_POST, under either the bare keys (delete/status-update — the
	 * request already means "this view") or `view_*`-prefixed keys
	 * (create — see `ajax_create_entry()`'s docblock for why).
	 *
	 * @param string $prefix '' or 'view_'.
	 * @return array<string, mixed>
	 */
	private function view_state_from_request( string $prefix ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call (every caller runs guard() first).
		$search = sanitize_text_field( wp_unslash( $_POST[ $prefix . 's' ] ?? '' ) );
		// The view's own status filter is always under 'view_status',
		// never just $prefix.'status' — with $prefix '', that would
		// collide with ajax_update_status()'s own bare 'status' POST key
		// (the target status to apply), which is a different thing
		// entirely.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$status = sanitize_text_field( wp_unslash( $_POST['view_status'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$form_id = absint( wp_unslash( $_POST[ $prefix . 'form_id' ] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$date_from = $this->sanitize_date( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'date_from' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$date_to = $this->sanitize_date( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'date_to' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$orderby = $this->sanitize_orderby( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'orderby' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$order = '' !== $orderby ? $this->sanitize_order( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'order' ] ?? '' ) ) ) : '';

		$per_page = $this->resolve_per_page(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
			sanitize_text_field( wp_unslash( $_POST[ $prefix . 'per_page' ] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
			sanitize_text_field( wp_unslash( $_POST[ $prefix . 'per_page_custom' ] ?? '' ) )
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$paged = max( 1, absint( wp_unslash( $_POST[ $prefix . 'paged' ] ?? 1 ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$pay_method = $this->sanitize_pay_method( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'pay_method' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$amount_min = $this->sanitize_amount( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'amount_min' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
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
	 * Re-queries the store for the given view state and renders the tbody
	 * + pagination + footer count for it — the shared tail end of every
	 * AJAX handler here.
	 *
	 * @param array<string, mixed> $view See `view_state_from_request()`.
	 * @return array{rowsHtml: string, paginationHtml: string, summaryHtml: string, paged: int, counts: array<string, int>, total: int, totalLabel: string}
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

		$counts = $this->transactions->status_counts( $shared_filters );

		$base = $this->url_args( array( 'paged' => $result['paged'] ) + $view );

		ob_start();
		$this->render_pagination( $result['paged'], $result['pages'], $base );
		$pagination_html = (string) ob_get_clean();

		return array(
			'rowsHtml'       => $this->render_rows_html( $result['items'] ),
			'paginationHtml' => $pagination_html,
			'summaryHtml'    => $this->render_summary( $this->transactions->status_summary( $shared_filters ) ),
			'paged'          => $result['paged'],
			'counts'         => $counts,
			'total'          => $result['total'],
			'totalLabel'     => $this->entries_range_label( $result['paged'], $view['per_page'], $result['total'] ),
		);
	}

	/**
	 * Renders the full admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
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
		$counts  = $this->transactions->status_counts( $shared_filters );
		$summary = $this->transactions->status_summary( $shared_filters );

		// The store has no "All" bucket — I sum it here, same as entries.js does after AJAX.
		$counts[''] = array_sum( $counts );

		$export_url = $this->export_url( $filters );
		?>
		<div class="wrap iftp-fm-entries" data-iftp-hidden-cols="<?php echo esc_attr( implode( ' ', $hidden_cols ) ); ?>" data-iftp-col-order="<?php echo esc_attr( implode( ' ', $col_order ) ); ?>">
			<header class="iftp-fm-entries__masthead">
				<div class="iftp-fm-entries__masthead-brand">
					<img class="iftp-fm-entries__logo" src="<?php echo esc_url( IFTP_FM_URL . 'assets/img/logo_ifthenpay_white.svg' ); ?>" alt="ifthenpay" width="94" height="24" />
					<span class="iftp-fm-entries__masthead-divider" aria-hidden="true"></span>
					<div class="iftp-fm-entries__masthead-text">
						<h1 id="iftp-fm-entries-title" class="iftp-fm-entries__title"><?php esc_html_e( 'Payment entries', 'ifthenpay-payments-for-forminator' ); ?></h1>
						<p class="iftp-fm-entries__subtitle"><?php esc_html_e( 'Every ifthenpay payment attempt across your Forminator forms, whether or not it produced a real entry yet.', 'ifthenpay-payments-for-forminator' ); ?></p>
					</div>
				</div>

				<button type="button" class="iftp-fm-btn iftp-fm-btn--inverse iftp-fm-new-entry-trigger" data-iftp-new-entry-trigger>
					<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 3v10M3 8h10" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
					<?php esc_html_e( 'New payment', 'ifthenpay-payments-for-forminator' ); ?>
				</button>
			</header>
			<hr class="wp-header-end" />

			<section class="iftp-fm-summary" aria-labelledby="iftp-fm-summary-title">
				<h2 id="iftp-fm-summary-title" class="screen-reader-text"><?php esc_html_e( 'Totals by status', 'ifthenpay-payments-for-forminator' ); ?></h2>
				<div class="iftp-fm-summary__body" data-iftp-summary>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_summary() escapes every value itself.
					echo $this->render_summary( $summary );
					?>
				</div>
			</section>

			<div class="iftp-fm-entries-card">
				<div class="iftp-fm-entries-card-header">
					<div class="iftp-fm-entries-toolbar-row">
						<nav class="iftp-fm-entries__status-nav" aria-label="<?php esc_attr_e( 'Filter by status', 'ifthenpay-payments-for-forminator' ); ?>">
							<ul class="iftp-fm-status-tabs">
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
										><?php echo esc_html( $label ); ?> <span class="count"><?php echo esc_html( (string) ( $counts[ $slug ] ?? 0 ) ); ?></span></a>
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
								<option value="export"><?php esc_html_e( 'Export selected', 'ifthenpay-payments-for-forminator' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-forminator' ); ?></option>
							</select>
							<button type="button" class="iftp-fm-btn iftp-fm-btn--primary" data-iftp-bulk-apply><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-forminator' ); ?></button>
						</div>

						<div class="iftp-fm-entries__view-tools">
							<div class="iftp-fm-entries__search">
								<label class="screen-reader-text" for="iftp-fm-search"><?php esc_html_e( 'Search entries', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="search" id="iftp-fm-search" name="s" form="iftp-fm-filter-form" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search entries…', 'ifthenpay-payments-for-forminator' ); ?>" title="<?php esc_attr_e( 'Search by reference, customer or method', 'ifthenpay-payments-for-forminator' ); ?>" />
								<?php /* Enter in the search needs a rendered default button; the panel's Apply is hidden. */ ?>
								<button type="submit" form="iftp-fm-filter-form" class="screen-reader-text" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Search', 'ifthenpay-payments-for-forminator' ); ?></button>
							</div>

							<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__filters-toggle" data-iftp-filters-toggle aria-expanded="false" aria-controls="iftp-fm-filter-panel">
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
								<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__columns-trigger" data-iftp-columns-trigger aria-expanded="false" aria-controls="iftp-fm-columns-menu">
									<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="2" y="2.75" width="12" height="10.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M6 2.75v10.5M10 2.75v10.5" stroke="currentColor" stroke-width="1.5"/></svg>
									<span class="iftp-fm-entries__tool-label"><?php esc_html_e( 'Columns', 'ifthenpay-payments-for-forminator' ); ?></span>
								</button>
								<?php $this->render_columns_menu( $col_order, $hidden_cols ); ?>
								<p class="screen-reader-text" data-iftp-col-status role="status"></p>
							</div>

							<a class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-entries__tool iftp-fm-entries__export" href="<?php echo esc_url( $export_url ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 2.5v7.5M4.75 6.75 8 10l3.25-3.25M3 13.25h10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<span class="iftp-fm-entries__tool-label"><?php esc_html_e( 'Export CSV', 'ifthenpay-payments-for-forminator' ); ?></span>
							</a>
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

				<div class="iftp-fm-entries-table-wrap" role="region" aria-labelledby="iftp-fm-entries-title" tabindex="0">
					<table class="iftp-fm-entries-table">
						<thead>
							<tr>
								<th class="iftp-fm-col-check">
									<input type="checkbox" data-iftp-select-all aria-label="<?php esc_attr_e( 'Select all', 'ifthenpay-payments-for-forminator' ); ?>" />
								</th>
								<th data-col="ref" aria-sort="<?php echo esc_attr( $this->aria_sort( 'ref', $orderby, $order ) ); ?>"><?php echo wp_kses_post( $this->sort_link( 'ref', __( 'Reference', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
								<?php
								foreach ( $col_order as $slug ) {
									$this->render_head_cell( $slug, $orderby, $order );
								}
								?>
							</tr>
						</thead>
						<tbody data-iftp-entries-body>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_rows_html() escapes every dynamic value itself (esc_html/esc_attr/esc_url per field in render_row()/render_details()); wrapping the result in wp_kses_post() here was silently deleting every <input> checkbox, since 'input' isn't in the default post-context allowed-tags list and, being a void element, leaves nothing behind once stripped. The AJAX handlers below (see rendered_view()) already return this same markup unfiltered for the same reason.
							echo $this->render_rows_html( $slice );
							?>
						</tbody>
					</table>
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
			<?php $this->render_toast(); ?>
			<?php $this->render_scroll_to_top_button(); ?>
		</div>
		<?php
	}

	/**
	 * The "New payment" popup — for recording a payment taken outside
	 * the normal checkout (over the phone, in person), same idea as the
	 * Ninja Forms integration's own popup, at Victor's request. A static
	 * form submitted via AJAX to `ajax_create_entry()`, which does the
	 * real validation — this only needs to get the right fields/options in
	 * front of the admin.
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
	 * One reusable confirm dialog for bulk/row actions, in place of the
	 * jarring native window.confirm(). JS overwrites the title/message/
	 * button text per action right before opening it (confirmAction() in
	 * entries.js — the title always stays the generic "Change status?"/
	 * "Delete selected entries?" wording; only the message is rebuilt per
	 * call, e.g. with the target status name). The defaults below are only
	 * a safety net in case that ever doesn't run — without them this
	 * rendered as an empty box with two buttons and no text at all.
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
	 * One reusable toast for AJAX-action feedback, in place of
	 * window.alert(). Filled in and shown/hidden entirely by JS.
	 */
	private function render_toast(): void {
		?>
		<div class="iftp-fm-toast" data-iftp-toast hidden role="status" aria-live="polite">
			<span data-iftp-toast-message></span>
		</div>
		<?php
	}

	/**
	 * Sticky "scroll to top" button with an inline chevron SVG — shown once
	 * scrolled down (entirely handled by entries.js).
	 */
	private function render_scroll_to_top_button(): void {
		?>
		<button type="button" class="iftp-fm-scroll-btn" data-iftp-scroll-btn aria-label="<?php esc_attr_e( 'Scroll to top', 'ifthenpay-payments-for-forminator' ); ?>">
			<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4.5 11.25 9 6.75l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</button>
		<?php
	}

	/**
	 * The Columns popover: Reference as a locked row, then every hideable
	 * column in the admin's order with a drag handle, a visibility
	 * checkbox and Move up/down buttons.
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
	 * The tbody contents for a page of entries. Shared by the initial
	 * render and every AJAX handler, so a deleted row's page refills from
	 * the next page instead of just leaving a gap.
	 *
	 * @param array<int, array<string, mixed>> $slice The page of transaction rows to render.
	 */
	private function render_rows_html( array $slice ): string {
		ob_start();

		if ( array() === $slice ) {
			?>
			<tr>
				<td colspan="9" class="iftp-fm-entries-empty">
					<div class="iftp-fm-empty-state">
						<span class="iftp-fm-empty-state__icon" aria-hidden="true">
							<img src="<?php echo esc_url( IFTP_FM_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="28" height="28" />
						</span>
						<p class="iftp-fm-empty-state__text"><?php esc_html_e( 'No ifthenpay payments match this view.', 'ifthenpay-payments-for-forminator' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
		} else {
			foreach ( $slice as $record ) {
				$this->render_row( $record );
			}
		}

		return (string) ob_get_clean();
	}

	/**
	 * Renders one transaction as a row plus its (initially hidden)
	 * details row.
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_row( array $record ): void {
		$row_id      = 'iftp-fm-details-' . sanitize_html_class( (string) $record['ref'] );
		$payment_url = (string) ( $record['payment_url'] ?? '' );
		?>
		<tr class="iftp-fm-entry-row" data-iftp-ref="<?php echo esc_attr( (string) $record['ref'] ); ?>">
			<td class="iftp-fm-col-check">
				<input type="checkbox" class="iftp-fm-row-check" data-iftp-row-check value="<?php echo esc_attr( (string) $record['ref'] ); ?>" aria-label="<?php esc_attr_e( 'Select entry', 'ifthenpay-payments-for-forminator' ); ?>" />
			</td>
			<td data-col="ref">
				<strong><?php echo esc_html( (string) $record['ref'] ); ?></strong>
				<div class="iftp-fm-row-actions">
					<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-details-toggle" data-details-target="<?php echo esc_attr( $row_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>">
						<?php esc_html_e( 'View', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
					<?php if ( '' !== $payment_url && TransactionStore::STATUS_PENDING === $record['status'] ) : ?>
						<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
						<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-row-action--copy" data-iftp-copy-link="<?php echo esc_attr( $payment_url ); ?>"><?php esc_html_e( 'Copy link', 'ifthenpay-payments-for-forminator' ); ?></button>
					<?php endif; ?>
					<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
					<button type="button" class="iftp-fm-btn iftp-fm-btn--link iftp-fm-row-action iftp-fm-row-action--delete" data-iftp-row-delete="<?php echo esc_attr( (string) $record['ref'] ); ?>">
						<?php esc_html_e( 'Delete', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
				</div>
			</td>
			<?php
			foreach ( $this->column_order() as $slug ) {
				$this->render_cell( $slug, $record );
			}
			?>
		</tr>
		<tr id="<?php echo esc_attr( $row_id ); ?>" class="iftp-fm-entry-details" hidden>
			<td colspan="9">
				<?php $this->render_details( $record ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * One hideable column's header cell — the same `<th>` for a slug
	 * wherever the admin moved it.
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
				<td data-col="customer" data-label="<?php esc_attr_e( 'Customer', 'ifthenpay-payments-for-forminator' ); ?>">
					<?php if ( '' === $customer_name && '' === $customer_email ) : ?>
						&mdash;
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
				<td data-col="form" data-label="<?php esc_attr_e( 'Form', 'ifthenpay-payments-for-forminator' ); ?>"><?php $this->render_form_cell( (int) $record['form_id'] ); ?></td>
				<?php
				break;
			case 'method':
				?>
				<td data-col="method" data-label="<?php esc_attr_e( 'Method', 'ifthenpay-payments-for-forminator' ); ?>"><?php $this->render_method_cell( (string) $record['pay_method'] ); ?></td>
				<?php
				break;
			case 'amount':
				?>
				<td class="iftp-fm-col-amount" data-col="amount"><?php echo esc_html( number_format( (float) $record['amount'], 2, ',', '.' ) . ' €' ); ?></td>
				<?php
				break;
			case 'status':
				?>
				<td data-col="status"><?php echo wp_kses_post( $this->status_badge( (string) $record['status'] ) ); ?></td>
				<?php
				break;
			case 'entry':
				?>
				<td data-col="entry" data-label="<?php esc_attr_e( 'Entry', 'ifthenpay-payments-for-forminator' ); ?>"><?php $this->render_entry_cell( $record ); ?></td>
				<?php
				break;
			case 'created':
				?>
				<td data-col="created" data-label="<?php esc_attr_e( 'Created', 'ifthenpay-payments-for-forminator' ); ?>"><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $record['created_at'] ) ); ?></td>
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
		?>
		<div class="iftp-fm-details-panel">
			<div class="iftp-fm-details-grid">
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-forminator' ); ?></span>
					<code><?php echo esc_html( (string) $record['ref'] ); ?></code>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span><?php echo esc_html( '' !== $record['request_id'] ? (string) $record['request_id'] : '—' ); ?></span>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span><?php echo esc_html( '' !== $record['transaction_id'] ? (string) $record['transaction_id'] : '—' ); ?></span>
				</div>
				<div class="iftp-fm-detail-item iftp-fm-detail-item--wide">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Payment link', 'ifthenpay-payments-for-forminator' ); ?></span>
					<?php $payment_url = (string) ( $record['payment_url'] ?? '' ); ?>
					<?php if ( '' !== $payment_url ) : ?>
						<a href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $payment_url ); ?></a>
					<?php else : ?>
						<span>&mdash;</span>
					<?php endif; ?>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Form ID', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span><?php echo esc_html( (string) $record['form_id'] ); ?></span>
				</div>
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Last updated', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $record['updated_at'] ) ); ?></span>
				</div>
			</div>
			<?php
			$fields = $this->transactions->entry_data_pairs( $record );

			if ( array() !== $fields ) :
				?>
				<div class="iftp-fm-details-fields">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Submitted fields', 'ifthenpay-payments-for-forminator' ); ?></span>
					<table class="iftp-fm-details-fields-table">
						<tbody>
							<?php foreach ( $fields as $pair ) : ?>
								<tr>
									<th><?php echo esc_html( $pair['label'] ); ?></th>
									<td><?php echo esc_html( $pair['value'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Renders the Form column: the real form's title, or "Ad Hoc" for a
	 * "New payment" entry (form_id 0 — see `TransactionStore::create_manual()`).
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
	 * Renders the Entry column: a link through to the real Forminator
	 * entry once one's been created, or an em dash while there's none yet
	 * (including every ad hoc entry, which never gets one).
	 *
	 * @param array<string, mixed> $record The transaction row to render.
	 */
	private function render_entry_cell( array $record ): void {
		if ( empty( $record['entry_id'] ) ) {
			echo '&mdash;';

			return;
		}
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=forminator-entries&form_id=' . (int) $record['form_id'] ) ); ?>">
			#<?php echo esc_html( (string) (int) $record['entry_id'] ); ?>
		</a>
		<?php
	}

	/**
	 * Renders the Method column as a logo+label pill when it resolves
	 * against the connected methods catalog, falling back to a label-only
	 * pill otherwise. pay_method is only set once a payment confirms
	 * paid (or for an "Ad Hoc" entry), so most rows just show the em dash.
	 *
	 * @param string $pay_method The transaction's pay_method column.
	 */
	private function render_method_cell( string $pay_method ): void {
		if ( '' === $pay_method ) {
			echo '&mdash;';

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

		$logos = $this->method_catalog()[ $pay_method ] ?? null;

		if ( null === $logos ) {
			?>
			<span class="iftp-fm-method-pill iftp-fm-method-pill--plain"><?php echo esc_html( $pay_method ); ?></span>
			<?php

			return;
		}
		?>
		<span class="iftp-fm-method-pill<?php echo '' === $logos['logo'] ? ' iftp-fm-method-pill--plain' : ''; ?>" data-entity="<?php echo esc_attr( $pay_method ); ?>">
			<?php if ( '' !== $logos['logo'] ) : ?>
				<img class="iftp-fm-method-pill__logo" src="<?php echo esc_url( $logos['logo'] ); ?>"<?php echo '' !== $logos['logo_dark'] ? ' data-logo-dark="' . esc_url( $logos['logo_dark'] ) . '"' : ''; ?> alt="" loading="lazy" />
			<?php endif; ?>
			<?php echo esc_html( $pay_method ); ?>
		</span>
		<?php
	}

	/**
	 * Entity code (e.g. `MB`) => light/dark logo URLs, for every connected method.
	 *
	 * @var array<string, array{logo: string, logo_dark: string}>|null
	 */
	private ?array $method_catalog_cache = null;

	/**
	 * Builds the entity => logos catalog, memoized for the request.
	 *
	 * @return array<string, array{logo: string, logo_dark: string}>
	 */
	private function method_catalog(): array {
		if ( null !== $this->method_catalog_cache ) {
			return $this->method_catalog_cache;
		}

		$catalog = array();

		foreach ( $this->settings->get_methods() as $method ) {
			$entity = (string) ( $method['entity'] ?? '' );

			if ( '' === $entity ) {
				continue;
			}

			$catalog[ $entity ] = array(
				'logo'      => (string) ( $method['logo'] ?? '' ),
				'logo_dark' => (string) ( $method['logo_dark'] ?? '' ),
			);
		}

		$this->method_catalog_cache = $catalog;

		return $this->method_catalog_cache;
	}

	/**
	 * I memoize this per request — without it, a page full of the same
	 * handful of forms repeats the same title lookup for every row.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	/**
	 * The given form's title, or "#<id>" when it can't be resolved
	 * (deleted form, or — for an ad hoc entry — form_id 0).
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
	 * Builds the status badge markup for a transaction.
	 *
	 * @param string $status One of TransactionStore::ALL_STATUSES.
	 */
	private function status_badge( string $status ): string {
		return sprintf( '<span class="iftp-fm-badge iftp-fm-badge--%s">%s</span>', esc_attr( $status ), esc_html( $this->status_label( $status ) ) );
	}

	/**
	 * A status's display label — shared by the badge and the CSV export.
	 *
	 * @param string $status One of TransactionStore::ALL_STATUSES.
	 */
	private function status_label( string $status ): string {
		// Cancelled rows show as "Failed" — same badge styling, no separate label.
		$label_status = TransactionStore::STATUS_CANCELLED === $status ? TransactionStore::STATUS_FAILED : $status;

		return $this->status_filters( false )[ $label_status ] ?? $label_status;
	}

	/**
	 * The totals summary's inner `<dl>` — one cell per status with its
	 * count and amount. The `[data-iftp-summary]` wrapper lives in
	 * render(), so AJAX can swap just this content.
	 *
	 * @param array<string, array{count: int, amount: float}> $summary From `TransactionStore::status_summary()`.
	 */
	private function render_summary( array $summary ): string {
		ob_start();
		?>
		<dl class="iftp-fm-summary__list">
			<?php foreach ( array( TransactionStore::STATUS_PAID, TransactionStore::STATUS_PENDING, TransactionStore::STATUS_FAILED, TransactionStore::STATUS_EXPIRED ) as $slug ) : ?>
				<?php
				$count  = (int) ( $summary[ $slug ]['count'] ?? 0 );
				$amount = (float) ( $summary[ $slug ]['amount'] ?? 0 );
				?>
				<div class="iftp-fm-summary__item<?php echo 0 === $count ? ' iftp-fm-summary__item--empty' : ''; ?>">
					<dt class="iftp-fm-summary__label"><?php echo wp_kses_post( $this->status_badge( $slug ) ); ?></dt>
					<dd class="iftp-fm-summary__amount"><?php echo esc_html( number_format( $amount, 2, ',', '.' ) . ' €' ); ?></dd>
					<dd class="iftp-fm-summary__count">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of entries */
								_n( '%s entry', '%s entries', $count, 'ifthenpay-payments-for-forminator' ),
								number_format_i18n( $count )
							)
						);
						?>
					</dd>
				</div>
			<?php endforeach; ?>
		</dl>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The Columns menu's options, slug => label, in default order. Labels
	 * reuse the column headers' own strings.
	 *
	 * @return array<string, string>
	 */
	private function column_labels(): array {
		return array(
			'customer' => __( 'Customer', 'ifthenpay-payments-for-forminator' ),
			'form'     => __( 'Form', 'ifthenpay-payments-for-forminator' ),
			'method'   => __( 'Method', 'ifthenpay-payments-for-forminator' ),
			'amount'   => __( 'Amount', 'ifthenpay-payments-for-forminator' ),
			'status'   => __( 'Status', 'ifthenpay-payments-for-forminator' ),
			'entry'    => __( 'Entry', 'ifthenpay-payments-for-forminator' ),
			'created'  => __( 'Created', 'ifthenpay-payments-for-forminator' ),
		);
	}

	/**
	 * The current admin's hidden columns from user meta, limited to
	 * HIDEABLE_COLUMNS.
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
	 * Keeps known slugs in the given order and appends any missing ones in
	 * default order.
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
	 * The quick date-range chips, each as Y-m-d bounds in the site's
	 * timezone.
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
	 * The "Form" filter dropdown's options: every form with at least one
	 * transaction, id => title, sorted alphabetically.
	 *
	 * @return array<int, string>
	 */
	private function available_forms(): array {
		$forms = array();

		foreach ( $this->transactions->distinct_form_ids() as $form_id ) {
			$forms[ $form_id ] = $this->form_title( $form_id );
		}

		asort( $forms );

		return $forms;
	}

	/**
	 * A sortable column header: a link with a small up/down arrow.
	 * Clicking toggles direction if already sorted by this column,
	 * otherwise starts ascending.
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
	 * Pagination — prev/next buttons and page-number pills, windowed
	 * around the current page.
	 *
	 * @param int                       $paged The current page number.
	 * @param int                       $pages The total number of pages.
	 * @param array<string, mixed>|null $base Filter/sort state to build
	 *     links from instead of $_GET — needed when called from an AJAX
	 *     handler, where $_GET reflects the admin-ajax.php request, not
	 *     the page.
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
	 * A page-jump box in place of a pagination ellipsis — its own GET form,
	 * so Enter navigates without JS. The server's paged clamp backs up the
	 * native min/max check.
	 *
	 * @param int                  $pages The total number of pages.
	 * @param array<string, mixed> $args  The view's URL args (render_pagination()'s $base or the page's own).
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
	 * "X - Y out of Z" footer label. Blank at zero since the table body
	 * already shows its own empty-state message.
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
			__( '%1$d - %2$d out of %3$d', 'ifthenpay-payments-for-forminator' ),
			$first,
			$last,
			$total
		);
	}

	/**
	 * Rebuilds the current URL with the given params overridden — passing
	 * null for a param removes it.
	 *
	 * @param array<string, mixed>      $overrides The params to override.
	 * @param bool                      $reset Whether to discard every existing param instead.
	 * @param array<string, mixed>|null $base Filter/sort state to build
	 *     the URL from instead of $_GET — pass this when called outside a
	 *     normal page GET request (e.g. from an AJAX handler).
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
	 * Hidden inputs that carry a view's state into a plain GET form. Blank
	 * values and the default per_page are skipped, so URLs stay as short as
	 * filtered_url() makes them.
	 *
	 * @param array<string, mixed> $args url_args() output (or rendered_view()'s $base).
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

			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $key ), esc_attr( (string) $value ) );
		}
	}

	/**
	 * One chip per applied panel filter (form, method, amount, created), in
	 * panel order. Each links to the current view minus that filter.
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
			'' !== $view['amount_min'] ? number_format( (float) $view['amount_min'], 2, ',', '.' ) . ' €' : '',
			'' !== $view['amount_max'] ? number_format( (float) $view['amount_max'], 2, ',', '.' ) . ' €' : '',
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
	 * A chip's range text: "A – B", "from A", or the given upper-only
	 * wording ("up to B" / "until B"). '' when neither bound is set.
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
	 * The "Clear filters" link: drops the panel filters, keeps
	 * search/status/sort/per-page.
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
	 * The "Export CSV" link for the given view — same filters and sort,
	 * no pagination, plus the export nonce.
	 *
	 * @param array<string, mixed> $view See `current_filters()`.
	 */
	private function export_url( array $view ): string {
		$args = $this->url_args( $view );

		unset( $args['per_page'], $args['paged'] );

		$args = array(
			'action'   => self::EXPORT_ACTION,
			'_wpnonce' => wp_create_nonce( self::EXPORT_ACTION ),
		) + $args;

		return add_query_arg( $this->query_values( $args ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * Drops blank params and URL-encodes the rest — add_query_arg() doesn't
	 * encode values itself, so a search like "a&b" would split the query.
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
	 * The store's where-args shared by the table, the tab counts, the
	 * summary and the export — everything but status/sort/pagination.
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
	 * The filter/search/status/pagination/sort state from the current
	 * request. Localized into JS too, so an AJAX action can recompute
	 * counts against this exact view instead of a stale or default one.
	 *
	 * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, pay_method: string, amount_min: string, amount_max: string, per_page: int, paged: int, orderby: string, order: string}
	 */
	private function current_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change; filters_from() sanitizes every key it reads.
		return $this->filters_from( $_GET );
	}

	/**
	 * Reads and sanitizes the view state from a raw (still slashed)
	 * request array — $_GET for the page, $_REQUEST for the export.
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
			'per_page'   => $this->resolve_per_page( sanitize_text_field( wp_unslash( $source['per_page'] ?? '' ) ), sanitize_text_field( wp_unslash( $source['per_page_custom'] ?? '' ) ) ),
			'paged'      => max( 1, absint( wp_unslash( $source['paged'] ?? 1 ) ) ),
			'orderby'    => $orderby,
			'order'      => '' !== $orderby ? $this->sanitize_order( sanitize_text_field( wp_unslash( $source['order'] ?? '' ) ) ) : '',
		);
	}

	/**
	 * A filter date as a timestamp in the site's own timezone — strtotime()
	 * would read it as UTC, shifting the filter off from the dates the table
	 * shows (wp_date()). 0 means no bound.
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
	 * Validates a Y-m-d date value, discarding anything malformed instead
	 * of passing it through as-is. Expects `$raw` already run through
	 * `sanitize_text_field( wp_unslash( ... ) )` by the caller — see e.g.
	 * `current_filters()`.
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
	 * Validates orderby against the sortable columns; anything else falls
	 * back to the default reverse-chronological order. Expects `$raw` already run through
	 * `sanitize_text_field( wp_unslash( ... ) )` by the caller.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_orderby( string $raw ): string {
		$value = sanitize_text_field( $raw );

		return in_array( $value, array( 'ref', 'customer_name', 'amount', 'status', 'created_at' ), true ) ? $value : '';
	}

	/**
	 * Accepts only a method that actually appears in the table, else ''.
	 * Expects `$raw` already run through `sanitize_text_field( wp_unslash( ... ) )`.
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
	 * Normalizes an amount bound to a dot-decimal string (e.g. `12.50`),
	 * accepting a comma as the decimal separator too. Anything that isn't
	 * a non-negative number becomes '' (no bound). Expects `$raw` already
	 * run through `sanitize_text_field( wp_unslash( ... ) )`.
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
	 * Swaps an inverted amount range, so "50 – 10" means 10 to 50 instead
	 * of matching nothing.
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
	 * Validates order as 'asc'/'desc'; anything else falls back to
	 * 'desc'. Expects `$raw` already run through
	 * `sanitize_text_field( wp_unslash( ... ) )` by the caller.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_order( string $raw ): string {
		$value = strtolower( sanitize_text_field( $raw ) );

		return 'asc' === $value ? 'asc' : 'desc';
	}

	/**
	 * Clamps a numeric value to [PER_PAGE_MIN, PER_PAGE_MAX] rather than
	 * rejecting it. Only a blank or non-numeric value falls back to the
	 * default. Expects `$raw` already run through
	 * `sanitize_text_field( wp_unslash( ... ) )` by the caller.
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
	 * The select's "custom" choice hands over to the custom box's value,
	 * so everything downstream only ever sees the resolved number.
	 *
	 * @param string $raw    The per_page value, already sanitize_text_field()'d.
	 * @param string $custom The per_page_custom value, already sanitize_text_field()'d.
	 */
	private function resolve_per_page( string $raw, string $custom ): int {
		return $this->sanitize_per_page( 'custom' === $raw ? $custom : $raw );
	}

	/**
	 * The status slug => label map, for the status tabs, the bulk-action
	 * select, and status badge labels.
	 *
	 * @param bool $with_all Whether to include the "All" (`''`) entry —
	 *     true for the status tabs, false for the bulk-action/badge label list.
	 * @return array<string, string>
	 */
	private function status_filters( bool $with_all = true ): array {
		$filters = array();

		if ( $with_all ) {
			$filters[''] = __( 'All', 'ifthenpay-payments-for-forminator' );
		}

		// Cancelled isn't listed here — it's folded into "Failed" everywhere
		// in this admin list; it only shows on its own in the post-payment
		// popup (see ConfirmationSettingsField / Ajax\Controller).
		$labels = array(
			TransactionStore::STATUS_PENDING => __( 'Pending', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_PAID    => __( 'Paid', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_FAILED  => __( 'Failed', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_EXPIRED => __( 'Expired', 'ifthenpay-payments-for-forminator' ),
		);

		return $filters + $labels;
	}
}
