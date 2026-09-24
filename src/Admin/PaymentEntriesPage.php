<?php
/**
 * Payment Entries admin dashboard.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Payment Entries" screen — its own top-level admin menu page, not
 * a full stats/chart/CSV dashboard. Forminator already has a native per-form
 * Entries screen (unlike Contact Form 7/Ninja Forms, which had none), so
 * this only needs to cover what has no other home until a real Forminator
 * entry exists: pending/failed/cancelled ifthenpay activity, plus a link
 * through to the real entry once one's been created, and a way to record a
 * payment taken outside the normal checkout ("+ New Payment").
 *
 * Sort/filter/pagination links are plain GET navigations (see
 * `filtered_url()`), same as the reference this was rebuilt from
 * (`ifthenpay-payments-for-ninja-forms`'s `EntriesPage`) actually behaves —
 * only delete/status-change/create go through AJAX, updating the table in
 * place without a full reload.
 */
class PaymentEntriesPage {

	private const PAGE_SLUG        = 'iftp-fm-transactions';
	private const DEFAULT_PER_PAGE = 10;
	private const PER_PAGE_OPTIONS = array( 10, 20, 30, 50 );
	private const PER_PAGE_MAX     = 250;
	private const NONCE_ACTION     = 'iftp_fm_entries';

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
	 * Registers the menu, assets and AJAX hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_iftp_fm_delete_entries', array( $this, 'ajax_delete_entries' ) );
		add_action( 'wp_ajax_iftp_fm_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_iftp_fm_create_entry', array( $this, 'ajax_create_entry' ) );
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

		$admin_css  = 'assets/css/admin.css';
		$entries_js = 'assets/js/entries.js';

		wp_enqueue_style( 'iftp-fm-admin', IFTP_FM_URL . $admin_css, array(), (string) filemtime( IFTP_FM_PATH . $admin_css ) );
		wp_enqueue_script( 'iftp-fm-entries', IFTP_FM_URL . $entries_js, array(), (string) filemtime( IFTP_FM_PATH . $entries_js ), true );

		wp_localize_script(
			'iftp-fm-entries',
			'iftpFmEntries',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'currentFilters' => $this->current_filters(),
				// Plain status => label map (no "Mark as …" wording) — the
				// bulk-action <select>'s own option text already has that
				// phrasing baked in (see render()); this is only for
				// building the confirm dialog's message.
				'statusLabels'   => $this->status_filters( false ),
				'i18n'           => array(
					'confirmDeleteTitle'   => __( 'Delete selected entries?', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteMessage' => __( 'This only removes their payment-tracking record here — any real Forminator entry already created from it is left untouched.', 'ifthenpay-payments-for-forminator' ),
					'confirmDeleteButton'  => __( 'Delete', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusTitle'   => __( 'Change status?', 'ifthenpay-payments-for-forminator' ),
					/* translators: %s: target status label, e.g. "Paid" */
					'confirmStatusMessage' => __( 'Mark the selected entries as "%s".', 'ifthenpay-payments-for-forminator' ),
					'confirmStatusButton'  => __( 'Change status', 'ifthenpay-payments-for-forminator' ),
					'deleteError'          => __( 'Could not delete the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'updateStatusError'    => __( 'Could not update the selected entries. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'deleteToastSuccess'   => __( 'Selected entries deleted.', 'ifthenpay-payments-for-forminator' ),
					'statusToastSuccess'   => __( 'Selected entries updated.', 'ifthenpay-payments-for-forminator' ),
					/* translators: 1: number of selected entries, 2: total number of entries */
					'selectedOfTotal'      => __( '%1$s selected out of %2$s', 'ifthenpay-payments-for-forminator' ),
					'newEntryCreating'     => __( 'Creating…', 'ifthenpay-payments-for-forminator' ),
					'newEntryToastSuccess' => __( 'Payment recorded.', 'ifthenpay-payments-for-forminator' ),
					'newEntryError'        => __( 'Could not record the payment. Please try again.', 'ifthenpay-payments-for-forminator' ),
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
	 * AJAX: the "+ New Payment" popup's submit — validates, records it via
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$per_page = $this->sanitize_per_page( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'per_page' ] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$paged = max( 1, absint( wp_unslash( $_POST[ $prefix . 'paged' ] ?? 1 ) ) );

		return array(
			's'         => $search,
			'status'    => $status,
			'form_id'   => $form_id,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'orderby'   => $orderby,
			'order'     => $order,
			'per_page'  => $per_page,
			'paged'     => $paged,
		);
	}

	/**
	 * Re-queries the store for the given view state and renders the tbody
	 * + pagination + footer count for it — the shared tail end of every
	 * AJAX handler here.
	 *
	 * @param array<string, mixed> $view See `view_state_from_request()`.
	 * @return array{rowsHtml: string, paginationHtml: string, paged: int, counts: array<string, int>, total: int, totalLabel: string}
	 */
	private function rendered_view( array $view ): array {
		$shared_filters = array(
			'form_id'   => $view['form_id'],
			'date_from' => $this->day_bound( $view['date_from'], '00:00:00' ),
			'date_to'   => $this->day_bound( $view['date_to'], '23:59:59' ),
			'search'    => $view['s'],
		);

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

		$base = array(
			's'         => $view['s'],
			'status'    => $view['status'],
			'form_id'   => $view['form_id'] > 0 ? (string) $view['form_id'] : '',
			'date_from' => $view['date_from'],
			'date_to'   => $view['date_to'],
			'orderby'   => $view['orderby'],
			'order'     => $view['order'],
			'per_page'  => $view['per_page'],
			'paged'     => $result['paged'],
		);

		ob_start();
		$this->render_pagination( $result['paged'], $result['pages'], $base );
		$pagination_html = (string) ob_get_clean();

		return array(
			'rowsHtml'       => $this->render_rows_html( $result['items'] ),
			'paginationHtml' => $pagination_html,
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

		$available_forms = $this->available_forms();

		$shared_filters = array(
			'form_id'   => $form_filter,
			'date_from' => $this->day_bound( $date_from, '00:00:00' ),
			'date_to'   => $this->day_bound( $date_to, '23:59:59' ),
			'search'    => $search,
		);

		$result = $this->transactions->query(
			$shared_filters + array(
				'status'   => $status_filter,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $filters['paged'],
			)
		);

		$slice  = $result['items'];
		$total  = $result['total'];
		$pages  = $result['pages'];
		$paged  = $result['paged'];
		$counts = $this->transactions->status_counts( $shared_filters );
		?>
		<div class="wrap iftp-fm-entries">
			<div class="iftp-fm-page-header">
				<div class="iftp-fm-page-header-text">
					<h1><?php esc_html_e( 'Payment Entries', 'ifthenpay-payments-for-forminator' ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Every ifthenpay payment attempt across your Forminator forms, whether or not it produced a real entry yet.', 'ifthenpay-payments-for-forminator' ); ?>
					</p>
				</div>

				<button type="button" class="button button-primary iftp-fm-new-entry-trigger" data-iftp-new-entry-trigger>
					<?php esc_html_e( '+ New Payment', 'ifthenpay-payments-for-forminator' ); ?>
				</button>
			</div>

			<div class="iftp-fm-entries-card">
				<div class="iftp-fm-entries-card-header">
					<div class="iftp-fm-entries-toolbar-row">
						<ul class="iftp-fm-status-tabs">
							<?php foreach ( $this->status_filters() as $slug => $label ) : ?>
								<li>
									<a
										class="iftp-fm-status-tab<?php echo $slug === $status_filter ? ' current' : ''; ?>"
										href="
										<?php
										echo esc_url(
											$this->filtered_url(
												array(
													'status' => $slug,
													'paged'  => null,
												)
											)
										);
										?>
												"
									><?php echo esc_html( $label ); ?> <span class="count"><?php echo esc_html( (string) ( $counts[ $slug ] ?? 0 ) ); ?></span></a>
								</li>
							<?php endforeach; ?>
						</ul>

						<div class="iftp-fm-bulk-actions" data-iftp-bulk-actions hidden>
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
								<option value="delete"><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-forminator' ); ?></option>
							</select>
							<button type="button" class="button" data-iftp-bulk-apply><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-forminator' ); ?></button>
						</div>

						<form method="get" class="iftp-fm-entries-filters">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
							<?php if ( '' !== $status_filter ) : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>" />
							<?php endif; ?>

							<label class="screen-reader-text" for="iftp-fm-form-filter"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-forminator' ); ?></label>
							<select id="iftp-fm-form-filter" name="form_id">
								<option value=""><?php esc_html_e( 'All forms', 'ifthenpay-payments-for-forminator' ); ?></option>
								<?php foreach ( $available_forms as $id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $form_filter, $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<label class="screen-reader-text" for="iftp-fm-date-from"><?php esc_html_e( 'From date', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="date" id="iftp-fm-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />

							<label class="screen-reader-text" for="iftp-fm-date-to"><?php esc_html_e( 'To date', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="date" id="iftp-fm-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

							<label class="screen-reader-text" for="iftp-fm-search"><?php esc_html_e( 'Search', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="search" id="iftp-fm-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search reference or method…', 'ifthenpay-payments-for-forminator' ); ?>" />

							<label class="screen-reader-text" for="iftp-fm-per-page"><?php esc_html_e( 'Entries per page', 'ifthenpay-payments-for-forminator' ); ?></label>
							<select id="iftp-fm-per-page" name="per_page">
								<?php foreach ( self::PER_PAGE_OPTIONS as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of entries per page */
												__( '%d per page', 'ifthenpay-payments-for-forminator' ),
												$option
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>

							<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'ifthenpay-payments-for-forminator' ); ?></button>
							<a href="<?php echo esc_url( $this->filtered_url( array(), true ) ); ?>" class="iftp-fm-reset-filters"><?php esc_html_e( 'Reset filters', 'ifthenpay-payments-for-forminator' ); ?></a>
						</form>
					</div>
				</div>

				<div class="iftp-fm-entries-table-wrap">
					<table class="iftp-fm-entries-table">
						<thead>
							<tr>
								<th class="iftp-fm-col-check">
									<input type="checkbox" data-iftp-select-all aria-label="<?php esc_attr_e( 'Select all', 'ifthenpay-payments-for-forminator' ); ?>" />
								</th>
								<th data-col="ref"><?php echo wp_kses_post( $this->sort_link( 'ref', __( 'Reference', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
								<th data-col="form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-forminator' ); ?></th>
								<th data-col="method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-forminator' ); ?></th>
								<th class="iftp-fm-col-amount" data-col="amount"><?php echo wp_kses_post( $this->sort_link( 'amount', __( 'Amount', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
								<th data-col="status"><?php echo wp_kses_post( $this->sort_link( 'status', __( 'Status', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
								<th data-col="entry"><?php esc_html_e( 'Entry', 'ifthenpay-payments-for-forminator' ); ?></th>
								<th data-col="created"><?php echo wp_kses_post( $this->sort_link( 'created_at', __( 'Created', 'ifthenpay-payments-for-forminator' ), $orderby, $order ) ); ?></th>
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
					<div class="iftp-fm-footer-status">
						<span class="iftp-fm-entries-count" data-iftp-total-count data-iftp-total="<?php echo esc_attr( (string) $total ); ?>">
							<?php echo esc_html( $this->entries_range_label( $paged, $per_page, $total ) ); ?>
						</span>
						<span class="iftp-fm-entries-selection" data-iftp-selection-summary hidden>
							<span data-iftp-selection-text></span>
							<button type="button" class="button-link iftp-fm-selection-cancel" data-iftp-selection-cancel><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?></button>
						</span>
					</div>
					<div data-iftp-pagination><?php $this->render_pagination( $paged, $pages ); ?></div>
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
	 * The "+ New Payment" popup — for recording a payment taken outside
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
							<label for="iftp-fm-ne-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input type="number" id="iftp-fm-ne-amount" name="amount" step="0.01" min="0.01" inputmode="decimal" required />
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
						<button type="button" class="button iftp-fm-modal-cancel" data-iftp-new-entry-cancel>
							<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
						<button type="submit" class="button button-primary" data-iftp-new-entry-submit>
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
					<button type="button" class="button iftp-fm-modal-cancel" data-iftp-modal-cancel>
						<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
					<button type="button" class="button button-primary" data-iftp-modal-confirm><?php esc_html_e( 'Confirm', 'ifthenpay-payments-for-forminator' ); ?></button>
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
	 * Sticky "scroll to top" button — shown once scrolled down (entirely
	 * handled by entries.js).
	 */
	private function render_scroll_to_top_button(): void {
		?>
		<button type="button" class="iftp-fm-scroll-btn" data-iftp-scroll-btn aria-label="<?php esc_attr_e( 'Scroll to top', 'ifthenpay-payments-for-forminator' ); ?>">
			<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
		</button>
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
				<td colspan="8" class="iftp-fm-entries-empty">
					<div class="iftp-fm-empty-state">
						<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'No ifthenpay payments match this view.', 'ifthenpay-payments-for-forminator' ); ?>
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
		$row_id = 'iftp-fm-details-' . sanitize_html_class( (string) $record['ref'] );
		?>
		<tr class="iftp-fm-entry-row" data-iftp-ref="<?php echo esc_attr( (string) $record['ref'] ); ?>">
			<td class="iftp-fm-col-check">
				<input type="checkbox" class="iftp-fm-row-check" data-iftp-row-check value="<?php echo esc_attr( (string) $record['ref'] ); ?>" aria-label="<?php esc_attr_e( 'Select entry', 'ifthenpay-payments-for-forminator' ); ?>" />
			</td>
			<td data-col="ref">
				<strong><?php echo esc_html( (string) $record['ref'] ); ?></strong>
				<div class="iftp-fm-row-actions">
					<button type="button" class="button-link iftp-fm-row-action iftp-fm-details-toggle" data-details-target="<?php echo esc_attr( $row_id ); ?>">
						<?php esc_html_e( 'View', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
					<span class="iftp-fm-row-actions-sep" aria-hidden="true">|</span>
					<button type="button" class="button-link iftp-fm-row-action iftp-fm-row-action--delete" data-iftp-row-delete="<?php echo esc_attr( (string) $record['ref'] ); ?>">
						<?php esc_html_e( 'Delete', 'ifthenpay-payments-for-forminator' ); ?>
					</button>
				</div>
			</td>
			<td data-col="form"><?php $this->render_form_cell( (int) $record['form_id'] ); ?></td>
			<td data-col="method"><?php $this->render_method_cell( (string) $record['pay_method'] ); ?></td>
			<td class="iftp-fm-col-amount" data-col="amount"><?php echo esc_html( number_format( (float) $record['amount'], 2, ',', '.' ) . ' €' ); ?></td>
			<td data-col="status"><?php echo wp_kses_post( $this->status_badge( (string) $record['status'] ) ); ?></td>
			<td data-col="entry"><?php $this->render_entry_cell( $record ); ?></td>
			<td data-col="created"><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $record['created_at'] ) ); ?></td>
		</tr>
		<tr id="<?php echo esc_attr( $row_id ); ?>" class="iftp-fm-entry-details" hidden>
			<td colspan="8">
				<?php $this->render_details( $record ); ?>
			</td>
		</tr>
		<?php
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
				<div class="iftp-fm-detail-item">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Payment Link', 'ifthenpay-payments-for-forminator' ); ?></span>
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
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Last Updated', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $record['updated_at'] ) ); ?></span>
				</div>
			</div>
			<?php
			$fields = $this->transactions->entry_data_pairs( $record );

			if ( array() !== $fields ) :
				?>
				<div class="iftp-fm-details-fields">
					<span class="iftp-fm-detail-label"><?php esc_html_e( 'Submitted Fields', 'ifthenpay-payments-for-forminator' ); ?></span>
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
	 * "+ New Payment" entry (form_id 0 — see `TransactionStore::create_manual()`).
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
	 * against the connected methods catalog, falling back to the bare
	 * string otherwise. pay_method is only set once a payment confirms
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
			<span class="iftp-fm-method-pill">
				<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
				<?php echo esc_html( $pay_method ); ?>
			</span>
			<?php

			return;
		}

		$logo = $this->method_catalog()[ $pay_method ] ?? null;

		if ( null === $logo ) {
			echo esc_html( $pay_method );

			return;
		}
		?>
		<span class="iftp-fm-method-pill" data-entity="<?php echo esc_attr( $pay_method ); ?>">
			<?php if ( '' !== $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" />
			<?php endif; ?>
			<?php echo esc_html( $pay_method ); ?>
		</span>
		<?php
	}

	/**
	 * Entity code (e.g. `MB`) => logo URL, for every connected method.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $method_catalog_cache = null;

	/**
	 * Builds the entity => logo catalog, memoized for the request.
	 *
	 * @return array<string, string>
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

			$catalog[ $entity ] = (string) ( $method['logo'] ?? '' );
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
		$label = $this->status_filters( false )[ $status ] ?? $status;

		return sprintf( '<span class="iftp-fm-badge iftp-fm-badge--%s">%s</span>', esc_attr( $status ), esc_html( $label ) );
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
			$classes .= ' is-sorted iftp-fm-sortable--' . $current_order;
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

		$window_start = max( 1, $paged - 2 );
		$window_end   = min( $paged + 2, $pages );
		?>
		<nav class="iftp-fm-pagination" aria-label="<?php esc_attr_e( 'Entries pagination', 'ifthenpay-payments-for-forminator' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a class="iftp-fm-page-btn" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged - 1 ), false, $base ) ); ?>">&laquo;</a>
			<?php endif; ?>

			<?php if ( $window_start > 1 ) : ?>
				<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => 1 ), false, $base ) ); ?>">1</a>
				<?php if ( $window_start > 2 ) : ?>
					<span class="iftp-fm-page-ellipsis">&hellip;</span>
				<?php endif; ?>
			<?php endif; ?>

			<?php for ( $page = $window_start; $page <= $window_end; $page++ ) : ?>
				<?php if ( $page === $paged ) : ?>
					<span class="iftp-fm-page-number is-current" aria-current="page"><?php echo esc_html( (string) $page ); ?></span>
				<?php else : ?>
					<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $page ), false, $base ) ); ?>"><?php echo esc_html( (string) $page ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $window_end < $pages ) : ?>
				<?php if ( $window_end < $pages - 1 ) : ?>
					<span class="iftp-fm-page-ellipsis">&hellip;</span>
				<?php endif; ?>
				<a class="iftp-fm-page-number" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $pages ), false, $base ) ); ?>"><?php echo esc_html( (string) $pages ); ?></a>
			<?php endif; ?>

			<?php if ( $paged < $pages ) : ?>
				<a class="iftp-fm-page-btn" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged + 1 ), false, $base ) ); ?>">&raquo;</a>
			<?php endif; ?>
		</nav>
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
	 * @param bool                      $reset Whether to discard every existing param instead (the "Reset filters" link).
	 * @param array<string, mixed>|null $base Filter/sort state to build
	 *     the URL from instead of $_GET — pass this when called outside a
	 *     normal page GET request (e.g. from an AJAX handler).
	 */
	private function filtered_url( array $overrides, bool $reset = false, ?array $base = null ): string {
		if ( $reset ) {
			return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		}

		if ( null !== $base ) {
			$args = array( 'page' => self::PAGE_SLUG ) + $base;
		} else {
			$current = $this->current_filters();
			$args    = array(
				'page'      => self::PAGE_SLUG,
				's'         => $current['s'],
				'status'    => $current['status'],
				'form_id'   => $current['form_id'] > 0 ? (string) $current['form_id'] : '',
				'date_from' => $current['date_from'],
				'date_to'   => $current['date_to'],
				'orderby'   => $current['orderby'],
				'order'     => $current['order'],
				'per_page'  => $current['per_page'],
				'paged'     => $current['paged'],
			);
		}

		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = $value;
			}
		}

		$args = array_filter(
			$args,
			static function ( $value ): bool {
				return '' !== $value && null !== $value;
			}
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The filter/search/status/pagination/sort state from the current
	 * request. Localized into JS too, so an AJAX action can recompute
	 * counts against this exact view instead of a stale or default one.
	 *
	 * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, per_page: int, paged: int, orderby: string, order: string}
	 */
	private function current_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
		$orderby = $this->sanitize_orderby( sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) ) );

		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			's'         => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'status'    => sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'form_id'   => absint( wp_unslash( $_GET['form_id'] ?? 0 ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'date_from' => $this->sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'date_to'   => $this->sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'per_page'  => $this->sanitize_per_page( sanitize_text_field( wp_unslash( $_GET['per_page'] ?? '' ) ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'paged'     => max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ),
			'orderby'   => $orderby,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter/search/pagination/sort from the current admin screen's own query string, not a state change.
			'order'     => '' !== $orderby ? $this->sanitize_order( sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) ) ) : '',
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

		return in_array( $value, array( 'ref', 'amount', 'status', 'created_at' ), true ) ? $value : '';
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
	 * Accepts one of PER_PAGE_OPTIONS, clamped to [1, PER_PAGE_MAX] rather
	 * than rejected outright. Only a blank or non-numeric value falls
	 * back to the default. Expects `$raw` already run through
	 * `sanitize_text_field( wp_unslash( ... ) )` by the caller.
	 *
	 * @param string $raw The raw request value, already sanitize_text_field()'d.
	 */
	private function sanitize_per_page( string $raw ): int {
		$raw = sanitize_text_field( $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return self::DEFAULT_PER_PAGE;
		}

		return max( 1, min( self::PER_PAGE_MAX, (int) $raw ) );
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

		$labels = array(
			TransactionStore::STATUS_PENDING   => __( 'Pending', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_PAID      => __( 'Paid', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_FAILED    => __( 'Failed', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_CANCELLED => __( 'Cancelled', 'ifthenpay-payments-for-forminator' ),
			TransactionStore::STATUS_EXPIRED   => __( 'Expired', 'ifthenpay-payments-for-forminator' ),
		);

		return $filters + $labels;
	}
}
