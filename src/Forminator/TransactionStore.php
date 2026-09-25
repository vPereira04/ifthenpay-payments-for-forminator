<?php
/**
 * Custom transactions table storage.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Forminator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ifthenpay's Pay by Link has no backoffice of its own to hold payment
 * records — unlike PayPal/Stripe, there's nowhere else this data lives — so,
 * like the Contact Form 7 and Ninja Forms ifthenpay integrations before this
 * one, I keep a real custom table for it rather than piggyback on Forminator
 * entries. It has to exist *before* the entry does too: the customer is sent
 * to ifthenpay's hosted page before Forminator ever saves anything, so this
 * table is what remembers the submitted field values in the meantime, and
 * `WebhookController` is what turns a paid row into a real Forminator entry.
 */
class TransactionStore {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_EXPIRED   = 'expired';

	public const ALL_STATUSES = array(
		self::STATUS_PENDING,
		self::STATUS_PAID,
		self::STATUS_FAILED,
		self::STATUS_CANCELLED,
		self::STATUS_EXPIRED,
	);

	/**
	 * The Method value every "+ New Payment" row gets — cash taken outside
	 * checkout, no method picker, at Victor's request (mirrors Ninja Forms'
	 * own ad hoc entries).
	 */
	public const AD_HOC_METHOD = 'Ad Hoc';

	/**
	 * Columns `query()`'s `orderby` is allowed to sort by — an allow-list,
	 * since a column name can't go through `$wpdb->prepare()`.
	 */
	private const SORTABLE_COLUMNS = array( 'ref', 'amount', 'status', 'created_at' );

	private const DB_VERSION_OPTION = 'iftp_fm_db_version';
	private const DB_VERSION        = 1;

	private const CACHE_GROUP     = 'iftp_fm';
	private const ENTRY_CACHE_TTL = 600;
	private const LIST_CACHE_TTL  = 30;

	/**
	 * The custom table name, including the site's table prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'iftp_fm_transactions';
	}

	/**
	 * Creates (or upgrades) the transactions table. Called on activation and
	 * as a cheap no-op-once-current safety net on every boot, in case the
	 * plugin was already active before this table existed.
	 */
	public static function maybe_create_table(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
            ref VARCHAR(191) NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            entry_id BIGINT UNSIGNED NULL,
            gateway_key VARCHAR(100) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            pay_method VARCHAR(50) NOT NULL DEFAULT '',
            request_id VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            payment_url VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            field_snapshot LONGTEXT NULL,
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (ref),
            KEY status (status),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Inserts a new pending transaction row.
	 *
	 * @param string                                             $ref            The transaction reference.
	 * @param int                                                $form_id        The Forminator form id.
	 * @param float                                              $amount         The payment amount.
	 * @param string                                             $gateway_key    The Gateway Key used.
	 * @param array<string, array{label: string, value: string}> $field_snapshot Element_id-keyed
	 *                               submitted field values, replayed into a
	 *                               real Forminator entry once the webhook
	 *                               confirms payment.
	 * @param string                                             $payment_url    The ifthenpay hosted payment page URL.
	 * @param bool                                               $is_test        Whether this is a test-mode payment.
	 */
	public function store_pending(
		string $ref,
		int $form_id,
		float $amount,
		string $gateway_key,
		array $field_snapshot,
		string $payment_url = '',
		bool $is_test = false
	): bool {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'ref'            => $ref,
				'form_id'        => $form_id,
				'gateway_key'    => $gateway_key,
				'amount'         => $amount,
				'payment_url'    => $payment_url,
				'status'         => self::STATUS_PENDING,
				'field_snapshot' => wp_json_encode( $field_snapshot ),
				'is_test'        => $is_test ? 1 : 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		$this->bump_last_changed();

		return false !== $result;
	}

	/**
	 * Reads a transaction row by reference.
	 *
	 * @param string $ref The transaction reference.
	 * @return array<string, mixed>|null
	 */
	public function get( string $ref ): ?array {
		global $wpdb;

		$cache_key = 'txn_' . $ref;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', self::table_name(), $ref ),
			ARRAY_A
		);

		$record = is_array( $row ) ? $row : null;

		wp_cache_set( $cache_key, $record, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );

		return $record;
	}

	/**
	 * Marks a transaction paid, returning true only for the call that
	 * actually flipped it. It's one conditional UPDATE, so if the webhook
	 * fires twice at once only one of them wins (and creates the entry).
	 *
	 * @param string $ref        The transaction reference.
	 * @param string $pay_method The payment method used.
	 * @param string $request_id The ifthenpay request id.
	 */
	public function mark_paid( string $ref, string $pay_method, string $request_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, pay_method = %s, request_id = %s, updated_at = %d WHERE ref = %s AND status <> %s',
				self::table_name(),
				self::STATUS_PAID,
				$pay_method,
				$request_id,
				time(),
				$ref,
				self::STATUS_PAID
			)
		);

		wp_cache_delete( 'txn_' . $ref, self::CACHE_GROUP );
		$this->bump_last_changed();

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Marks a transaction failed, unless it's already paid.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_failed( string $ref ): bool {
		return $this->update_if_not_paid( $ref, self::STATUS_FAILED );
	}

	/**
	 * Marks a transaction cancelled, unless it's already paid.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_cancelled( string $ref ): bool {
		return $this->update_if_not_paid( $ref, self::STATUS_CANCELLED );
	}

	/**
	 * Marks a transaction expired, unless it's already paid.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_expired( string $ref ): bool {
		return $this->update_if_not_paid( $ref, self::STATUS_EXPIRED );
	}

	/**
	 * Records the real Forminator entry id created for a paid transaction.
	 *
	 * @param string $ref      The transaction reference.
	 * @param int    $entry_id The Forminator entry id.
	 */
	public function record_entry_id( string $ref, int $entry_id ): bool {
		return $this->update( $ref, array( 'entry_id' => $entry_id ) );
	}

	/**
	 * Records the ifthenpay transaction id.
	 *
	 * @param string $ref            The transaction reference.
	 * @param string $transaction_id The ifthenpay transaction id.
	 */
	public function record_transaction_id( string $ref, string $transaction_id ): bool {
		return $this->update( $ref, array( 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Admin override from the transactions list bulk-action bar — always
	 * writes the requested status, unlike the mark_*() helpers above.
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of ALL_STATUSES.
	 */
	public function admin_set_status( string $ref, string $status ): bool {
		if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
			return false;
		}

		return $this->update( $ref, array( 'status' => $status ) );
	}

	/**
	 * Writes a status unless the record is missing or already paid (terminal).
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of ALL_STATUSES.
	 */
	private function update_if_not_paid( string $ref, string $status ): bool {
		$record = $this->get( $ref );

		if ( null === $record || self::STATUS_PAID === $record['status'] ) {
			return null !== $record;
		}

		return $this->update( $ref, array( 'status' => $status ) );
	}

	/**
	 * Updates a transaction row and invalidates its cache entry.
	 *
	 * @param string               $ref    The transaction reference.
	 * @param array<string, mixed> $fields The columns to update.
	 */
	private function update( string $ref, array $fields ): bool {
		global $wpdb;

		$fields['updated_at'] = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->update( self::table_name(), $fields, array( 'ref' => $ref ) );

		wp_cache_delete( 'txn_' . $ref, self::CACHE_GROUP );
		$this->bump_last_changed();

		return false !== $result;
	}

	/**
	 * Deletes a transaction row and invalidates its cache entry.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function delete( string $ref ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$wpdb->delete( self::table_name(), array( 'ref' => $ref ), array( '%s' ) );

		wp_cache_delete( 'txn_' . $ref, self::CACHE_GROUP );
		$this->bump_last_changed();
	}

	/**
	 * From the Payment Entries screen's "+ New Payment" popup — records a
	 * payment taken outside the normal checkout (over the phone, in
	 * person). Unlike Ninja Forms' own `create_manual()`, this never needs
	 * a real Forminator entry behind it: `form_id` 0 marks it as ad hoc
	 * (see `PaymentEntriesPage::render_form_cell()`), and the customer's
	 * name/email ride in `field_snapshot` the same way a real submission's
	 * fields do, so `entry_data_pairs()` shows them in the details panel
	 * without any extra plumbing.
	 *
	 * @param string $customer_name  The customer's name, or ''.
	 * @param string $customer_email The customer's email, or ''.
	 * @param float  $amount         The payment amount.
	 * @param string $status         One of ALL_STATUSES.
	 * @return string|null the generated ref, or null if the insert failed.
	 */
	public function create_manual( string $customer_name, string $customer_email, float $amount, string $status ): ?string {
		global $wpdb;

		$ref      = $this->generate_manual_ref();
		$snapshot = array();

		if ( '' !== $customer_name ) {
			$snapshot['customer_name'] = array(
				'label' => __( 'Customer name', 'ifthenpay-payments-for-forminator' ),
				'value' => $customer_name,
			);
		}

		if ( '' !== $customer_email ) {
			$snapshot['customer_email'] = array(
				'label' => __( 'Customer email', 'ifthenpay-payments-for-forminator' ),
				'value' => $customer_email,
			);
		}

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'ref'            => $ref,
				'form_id'        => 0,
				'gateway_key'    => 'manual',
				'amount'         => $amount,
				'pay_method'     => self::AD_HOC_METHOD,
				'payment_url'    => '',
				'status'         => $status,
				'field_snapshot' => wp_json_encode( $snapshot ),
				'is_test'        => 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		$this->bump_last_changed();

		return false !== $result ? $ref : null;
	}

	/**
	 * `MANUAL_` keeps these visually distinct from a real ifthenpay-issued
	 * reference at a glance in the Reference column.
	 */
	private function generate_manual_ref(): string {
		do {
			$ref = 'MANUAL_' . strtoupper( wp_generate_password( 8, false, false ) );
		} while ( null !== $this->get( $ref ) );

		return $ref;
	}

	/**
	 * Flattens a stored field snapshot into label/value pairs for the confirmation popup.
	 *
	 * @param array<string, mixed> $record A transaction row, as returned by get().
	 * @return array{label: string, value: string}[]
	 */
	public function entry_data_pairs( array $record ): array {
		$snapshot = json_decode( (string) ( $record['field_snapshot'] ?? '' ), true );

		if ( ! is_array( $snapshot ) ) {
			return array();
		}

		$pairs = array();

		foreach ( $snapshot as $element_id => $pair ) {
			$label = is_array( $pair ) && isset( $pair['label'] ) ? $pair['label'] : $element_id;
			$value = is_array( $pair ) ? ( $pair['value'] ?? '' ) : $pair;

			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$pairs[] = array(
				'label' => (string) $label,
				'value' => (string) $value,
			);
		}

		return $pairs;
	}

	/**
	 * Backs the Payment Entries admin dashboard — filter/sort/paginate in
	 * SQL. Direct column sorting is plenty at this table's expected scale
	 * (a cross-form payment-attempt log, not Ninja Forms' primary
	 * submissions store) — no secondary index table needed, unlike
	 * `SubmissionStore::query_index()`.
	 *
	 * @param array<string, mixed> $args Filter/sort/pagination args: status,
	 *                                    form_id, date_from, date_to, search,
	 *                                    orderby, order, paged, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, paged: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$cache_key = 'query_' . $this->get_last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$table     = self::table_name();
		$where_sql = $this->build_where( $args );
		$order_sql = $this->build_order( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table; $where_sql is assembled from $wpdb->prepare() calls in build_where(), no user data interpolated directly.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where_sql, $table ) );

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $pages, max( 1, (int) ( $args['paged'] ?? 1 ) ) );
		$offset   = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table; $where_sql is assembled from $wpdb->prepare() calls in build_where(), $order_sql is validated against SORTABLE_COLUMNS in build_order(), no user data interpolated directly.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $where_sql . $order_sql . ' LIMIT %d OFFSET %d', $table, $per_page, $offset ), ARRAY_A );

		$result = array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => $pages,
			'paged' => $paged,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $result;
	}

	/**
	 * The distinct form IDs with at least one transaction, for the "Form"
	 * filter dropdown — ascending, so the admin's own forms sort roughly
	 * oldest-first same as they were created.
	 *
	 * @return array<int, int>
	 */
	public function distinct_form_ids(): array {
		global $wpdb;

		$cache_key = 'distinct_form_ids_' . $this->get_last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT form_id FROM %i WHERE form_id != 0 ORDER BY form_id ASC', self::table_name() ) );
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $ids;
	}

	/**
	 * Builds a fully-prepared ` WHERE …` clause (or '' for no filter) from list args.
	 *
	 * @param array<string, mixed> $args Filter args: status, form_id, date_from, date_to, search.
	 */
	private function build_where( array $args ): string {
		global $wpdb;

		$conditions = array();

		$status = (string) ( $args['status'] ?? '' );

		if ( '' !== $status ) {
			// "Failed" folds in cancelled rows — the admin list treats cancelled
			// as a subset of failed and only surfaces it standalone in the
			// post-payment popup, not here.
			if ( self::STATUS_FAILED === $status ) {
				$conditions[] = $wpdb->prepare( 'status IN (%s, %s)', self::STATUS_FAILED, self::STATUS_CANCELLED );
			} else {
				$conditions[] = $wpdb->prepare( 'status = %s', $status );
			}
		}

		$form_id = (int) ( $args['form_id'] ?? 0 );

		if ( 0 !== $form_id ) {
			$conditions[] = $wpdb->prepare( 'form_id = %d', $form_id );
		}

		$date_from = (int) ( $args['date_from'] ?? 0 );

		if ( $date_from > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at >= %d', $date_from );
		}

		$date_to = (int) ( $args['date_to'] ?? 0 );

		if ( $date_to > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at <= %d', $date_to );
		}

		$search = (string) ( $args['search'] ?? '' );

		if ( '' !== $search ) {
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions[] = $wpdb->prepare( '(ref LIKE %s OR pay_method LIKE %s)', $like, $like );
		}

		return array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
	}

	/**
	 * Builds the ` ORDER BY …` clause. `orderby` is checked against
	 * SORTABLE_COLUMNS rather than passed through `$wpdb->prepare()` — a
	 * column identifier can't be a placeholder, so an allow-list is what
	 * keeps this safe instead.
	 *
	 * @param array<string, mixed> $args Sort args: orderby, order.
	 */
	private function build_order( array $args ): string {
		$orderby = (string) ( $args['orderby'] ?? '' );

		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			return ' ORDER BY created_at DESC';
		}

		$order = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';

		return " ORDER BY {$orderby} {$order}";
	}

	/**
	 * The count of transactions per status, keyed by status — against the
	 * given form/date/search filters (its own `status` key, if any, is
	 * ignored) so the status tabs stay accurate for whatever else the
	 * admin currently has filtered, same idea as `query()`.
	 *
	 * @param array<string, mixed> $args Filter args: form_id, date_from, date_to, search.
	 * @return array<string, int>
	 */
	public function status_counts( array $args = array() ): array {
		global $wpdb;

		unset( $args['status'] );

		$cache_key = 'status_counts_' . $this->get_last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$where_sql = $this->build_where( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table; $where_sql is assembled from $wpdb->prepare() calls in build_where(), no user data interpolated directly.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i' . $where_sql . ' GROUP BY status', self::table_name() ) );

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
		}

		// Cancelled has no tab of its own — its count rolls into "Failed".
		if ( isset( $counts[ self::STATUS_CANCELLED ] ) ) {
			$counts[ self::STATUS_FAILED ] = ( $counts[ self::STATUS_FAILED ] ?? 0 ) + $counts[ self::STATUS_CANCELLED ];
			unset( $counts[ self::STATUS_CANCELLED ] );
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $counts;
	}

	/**
	 * A cache-busting token bumped on every write, used to key list/stat
	 * caches so they never need per-row invalidation.
	 */
	private function get_last_changed(): string {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );

		if ( false === $last_changed ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}

		return (string) $last_changed;
	}

	/**
	 * Bumps the cache-busting token, invalidating every list/stat cache entry.
	 */
	private function bump_last_changed(): void {
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}
}
