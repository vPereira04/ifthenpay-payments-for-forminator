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
 * Every ifthenpay payment attempt. The payer leaves for ifthenpay before Forminator saves anything,
 * so I hold the submitted values here until the webhook turns a paid row into a real entry.
 *
 * @phpstan-type Transaction array{ref: string, form_id: int, entry_id: int, gateway_key: string, amount: float, pay_method: string, request_id: string, transaction_id: string, payment_url: string, customer_name: string, customer_email: string, status: string, field_snapshot: string, created_at: int, updated_at: int}
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
	 * Stamped on "+ New payment" rows, which never go through ifthenpay.
	 */
	public const MANUAL_GATEWAY_KEY = 'manual';
	public const AD_HOC_METHOD      = 'Ad Hoc';

	/**
	 * Allow-list for `query()`'s orderby, since a column name can't go through prepare().
	 */
	public const SORTABLE_COLUMNS = array( 'ref', 'customer_name', 'amount', 'status', 'created_at' );

	// The table's own schema revision, separate from the plugin version. 4 changes no columns; it just
	// rewrites the option as autoloaded, since boot() reads it on every request.
	private const SCHEMA_REVISION = 4;

	private const CUSTOMER_MAX_LENGTH = 191;

	// The list and CSV never show the submitted fields, so I leave the LONGTEXT snapshot out of their reads.
	private const LIST_COLUMNS = 't.ref, t.form_id, t.entry_id, t.gateway_key, t.amount, t.pay_method, t.request_id, t.transaction_id, t.payment_url, t.customer_name, t.customer_email, t.status, t.created_at, t.updated_at';

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
	 * Creates or upgrades the table; a no-op once the stored schema version is current.
	 */
	public static function maybe_create_table(): void {
		if ( version_compare( (string) get_option( 'iftp_fm_db_version', '0' ), (string) self::SCHEMA_REVISION, '>=' ) ) {
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
            customer_name VARCHAR(191) NOT NULL DEFAULT '',
            customer_email VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            field_snapshot LONGTEXT NULL,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (ref),
            KEY status (status),
            KEY form_id (form_id),
            KEY created_at (created_at),
            KEY status_created_at (status,created_at),
            KEY form_id_created_at (form_id,created_at),
            KEY pay_method (pay_method),
            KEY customer_name (customer_name)
        ) {$charset_collate};"
		);

		update_option( 'iftp_fm_db_version', self::SCHEMA_REVISION, true );
	}

	/**
	 * Inserts a new pending transaction row.
	 *
	 * @param string                                             $ref            The transaction reference.
	 * @param int                                                $form_id        The Forminator form id.
	 * @param float                                              $amount         The payment amount.
	 * @param string                                             $gateway_key    The Gateway Key used.
	 * @param array<string, array{label: string, value: string}> $field_snapshot Element_id-keyed submitted values.
	 * @param string                                             $payment_url    The ifthenpay hosted payment page URL.
	 */
	public function store_pending( string $ref, int $form_id, float $amount, string $gateway_key, array $field_snapshot, string $payment_url = '' ): bool {
		return $this->insert(
			array(
				'ref'            => $ref,
				'form_id'        => $form_id,
				'gateway_key'    => $gateway_key,
				'amount'         => $amount,
				'payment_url'    => $payment_url,
				'status'         => self::STATUS_PENDING,
				'field_snapshot' => $field_snapshot,
			)
		);
	}

	/**
	 * Records a payment taken outside checkout ("+ New payment"). Form 0 marks it as ad hoc.
	 *
	 * @param string $customer_name  The customer's name, or ''.
	 * @param string $customer_email The customer's email, or ''.
	 * @param float  $amount         The payment amount.
	 * @param string $status         One of ALL_STATUSES.
	 * @return string|null The generated ref, or null if the insert failed.
	 */
	public function create_manual( string $customer_name, string $customer_email, float $amount, string $status ): ?string {
		$ref      = $this->unique_ref( 'MANUAL_', 8 );
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

		$inserted = $this->insert(
			array(
				'ref'            => $ref,
				'form_id'        => 0,
				'gateway_key'    => self::MANUAL_GATEWAY_KEY,
				'amount'         => $amount,
				'pay_method'     => self::AD_HOC_METHOD,
				'status'         => $status,
				'field_snapshot' => $snapshot,
			)
		);

		return $inserted ? $ref : null;
	}

	/**
	 * A reference no row uses yet.
	 *
	 * @param string $prefix Readable prefix, e.g. `FM_12_`.
	 * @param int    $length Number of random characters after it.
	 */
	public function unique_ref( string $prefix, int $length ): string {
		do {
			$ref = $prefix . strtoupper( wp_generate_password( $length, false, false ) );
		} while ( null !== $this->get( $ref ) );

		return $ref;
	}

	/**
	 * Reads a transaction row by reference.
	 *
	 * @param string $ref The transaction reference.
	 * @return Transaction|null
	 */
	public function get( string $ref ): ?array {
		global $wpdb;

		$cached = wp_cache_get( self::row_cache_key( $ref ), self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return is_array( $cached ) ? self::hydrate( $cached ) : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', self::table_name(), $ref ), ARRAY_A );

		$record = is_array( $row ) ? self::hydrate( $row ) : null;

		wp_cache_set( self::row_cache_key( $ref ), $record, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );

		return $record;
	}

	/**
	 * Marks a transaction paid. One conditional UPDATE, so when the webhook
	 * fires twice at once only one call gets true (and creates the entry).
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

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Marks a transaction failed, unless it's already paid.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_failed( string $ref ): bool {
		return $this->set_status_unless_paid( $ref, self::STATUS_FAILED );
	}

	/**
	 * Marks a transaction cancelled, unless it's already paid.
	 *
	 * @param string $ref The transaction reference.
	 */
	public function mark_cancelled( string $ref ): bool {
		return $this->set_status_unless_paid( $ref, self::STATUS_CANCELLED );
	}

	/**
	 * Expires pending ifthenpay rows created before the cutoff.
	 * A late webhook still wins: mark_paid() only skips paid rows.
	 *
	 * @param int $cutoff Unix timestamp; older pending rows expire.
	 * @return int How many rows changed.
	 */
	public function expire_pending_before( int $cutoff ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a cron sweep, caching it would be pointless.
		$refs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ref FROM %i WHERE status = %s AND gateway_key <> %s AND created_at < %d',
				self::table_name(),
				self::STATUS_PENDING,
				self::MANUAL_GATEWAY_KEY,
				$cutoff
			)
		);

		return $this->set_status_many( array_map( 'strval', $refs ), self::STATUS_EXPIRED, self::STATUS_PENDING );
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
	 * Records a link created later ("Send payment email") plus its Gateway Key, which the webhook validates against.
	 *
	 * @param string $ref         The transaction reference.
	 * @param string $payment_url The ifthenpay hosted payment page URL.
	 * @param string $gateway_key The Gateway Key the link belongs to.
	 */
	public function record_payment_link( string $ref, string $payment_url, string $gateway_key ): bool {
		return $this->update(
			$ref,
			array(
				'payment_url' => $payment_url,
				'gateway_key' => $gateway_key,
			)
		);
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
	 * Admin bulk-action override; unlike mark_*(), it can move a row off "paid".
	 *
	 * @param array<int, string> $refs   The transaction references.
	 * @param string             $status One of ALL_STATUSES.
	 * @return int How many rows actually changed status.
	 */
	public function admin_set_status_many( array $refs, string $status ): int {
		if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
			return 0;
		}

		return $this->set_status_many( $refs, $status );
	}

	/**
	 * Deletes the given rows in one DELETE. Paid rows are payment history, so they stay.
	 *
	 * @param array<int, string> $refs The transaction references.
	 * @return int How many rows were deleted.
	 */
	public function delete_unpaid( array $refs ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( 'DELETE FROM %i WHERE ref IN ({refs}) AND status <> %s', $refs, array( self::table_name() ), array( self::STATUS_PAID ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Loads the given rows into the object cache in one SELECT, so a loop of get() calls doesn't query per ref.
	 *
	 * @param array<int, string> $refs The transaction references.
	 */
	public function prime( array $refs ): void {
		global $wpdb;

		$refs   = self::clean_refs( $refs );
		$cached = wp_cache_get_multiple( array_map( array( self::class, 'row_cache_key' ), $refs ), self::CACHE_GROUP );
		$refs   = array_values( array_filter( $refs, static fn( string $ref ): bool => false === ( $cached[ self::row_cache_key( $ref ) ] ?? false ) ) );

		if ( array() === $refs ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached right below; prepare_for_refs() prepares it.
		$rows  = $wpdb->get_results( self::prepare_for_refs( 'SELECT * FROM %i WHERE ref IN ({refs})', $refs, array( self::table_name() ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$record                  = self::hydrate( $row );
			$found[ $record['ref'] ] = $record;
		}

		// Misses are cached too, same as get() does.
		foreach ( $refs as $ref ) {
			wp_cache_set( self::row_cache_key( $ref ), $found[ $ref ] ?? null, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );
		}
	}

	/**
	 * Full rows (with field_snapshot) whose customer email matches, oldest first, for the privacy exporter.
	 *
	 * @param string $email  The customer email.
	 * @param int    $limit  Rows to fetch.
	 * @param int    $offset Rows to skip.
	 * @return array<int, Transaction>
	 */
	public function find_by_email( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a rare privacy request, caching it would be pointless.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_email = %s ORDER BY created_at ASC, ref ASC LIMIT %d OFFSET %d', self::table_name(), $email, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Strips the customer and submitted values from the given rows. The payment itself (ref, amount, status) stays.
	 *
	 * @param array<int, string> $refs The transaction references.
	 * @return int How many rows changed.
	 */
	public function anonymize( array $refs ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( "UPDATE %i SET customer_name = '', customer_email = '', field_snapshot = NULL, updated_at = %d WHERE ref IN ({refs})", $refs, array( self::table_name(), time() ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Pulls the customer's name/email out of a snapshot for the Customer column.
	 * Forminator keys them `email-N` / `name-N` (or `name-N-first-name` etc.); I skip repeater copies like `email-1-2`.
	 *
	 * @param array<string, mixed> $fields Element_id => {label, value} snapshot.
	 * @return array{name: string, email: string}
	 */
	public function extract_customer( array $fields ): array {
		$name  = self::snapshot_value( $fields['customer_name'] ?? '' );
		$email = self::snapshot_value( $fields['customer_email'] ?? '' );
		$parts = array();

		if ( '' !== $email && false === is_email( $email ) ) {
			$email = '';
		}

		foreach ( $fields as $element_id => $pair ) {
			$element_id = (string) $element_id;
			$value      = self::snapshot_value( $pair );

			if ( '' === $value ) {
				continue;
			}

			if ( '' === $email && 1 === preg_match( '/^email-\d+$/', $element_id ) && false !== is_email( $value ) ) {
				$email = $value;
			} elseif ( '' === $name && 1 === preg_match( '/^name-\d+$/', $element_id ) ) {
				$name = $value;
			} elseif ( 1 === preg_match( '/^(name-\d+)-(first|middle|last)-name$/', $element_id, $match ) ) {
				$parts[ $match[1] ][ $match[2] ] = $value;
			}
		}

		// No single-mode name, so I join the first multi-part name's pieces.
		if ( '' === $name && array() !== $parts ) {
			$first = reset( $parts );
			$name  = implode( ' ', array_filter( array( $first['first'] ?? '', $first['middle'] ?? '', $first['last'] ?? '' ) ) );
		}

		return array(
			'name'  => mb_substr( $name, 0, self::CUSTOMER_MAX_LENGTH ),
			'email' => mb_substr( $email, 0, self::CUSTOMER_MAX_LENGTH ),
		);
	}

	/**
	 * A row's snapshot flattened to label/value pairs, skipping empty values.
	 *
	 * @param array<string, mixed> $record A transaction row, as returned by get().
	 * @return array<int, array{label: string, value: string}>
	 */
	public function entry_data_pairs( array $record ): array {
		$pairs    = array();
		$snapshot = is_string( $record['field_snapshot'] ?? null ) ? $record['field_snapshot'] : '';

		foreach ( self::decode_snapshot( $snapshot ) as $element_id => $pair ) {
			$value = self::snapshot_value( $pair );

			if ( '' === $value ) {
				continue;
			}

			$label = is_array( $pair ) && isset( $pair['label'] ) && is_scalar( $pair['label'] ) ? (string) $pair['label'] : (string) $element_id;

			$pairs[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $pairs;
	}

	/**
	 * A stored snapshot as an array, or empty when it's missing or malformed.
	 *
	 * @param string $json The field_snapshot column.
	 * @return array<string, mixed>
	 */
	public static function decode_snapshot( string $json ): array {
		$snapshot = '' !== $json ? json_decode( $json, true ) : null;

		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * One page of the Payment Entries list, filtered, sorted and paginated in SQL.
	 * Rows come without field_snapshot (see LIST_COLUMNS); get() has it.
	 *
	 * @param array<string, mixed> $args See build_where()/build_order(), plus paged and per_page.
	 * @return array{items: array<int, Transaction>, total: int, pages: int, paged: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$cache_key = 'query_' . $this->last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/**
			 * Only this method writes the key, so the shape is known.
			 *
			 * @var array{items: array<int, Transaction>, total: int, pages: int, paged: int} $cached
			 */
			return $cached;
		}

		$table     = self::table_name();
		$where_sql = $this->build_where( $args );
		$order_sql = $this->build_order( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached below; $where_sql is built from prepare() calls.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where_sql, $table ) );

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $pages, max( 1, (int) ( $args['paged'] ?? 1 ) ) );

		$result = array(
			'items' => $this->fetch_page( $where_sql, $order_sql, $per_page, ( $paged - 1 ) * $per_page ),
			'total' => $total,
			'pages' => $pages,
			'paged' => $paged,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $result;
	}

	/**
	 * Every row matching query()'s filters, unpaginated and without field_snapshot, for the CSV export.
	 * Uncached and read in batches so memory stays flat on a big table.
	 *
	 * @param array<string, mixed> $args  Same filter/sort args as query(); paged/per_page are ignored.
	 * @param int                  $batch Rows per SELECT.
	 * @return \Generator<int, Transaction, void, void>
	 */
	public function iterate( array $args, int $batch = 500 ): \Generator {
		global $wpdb;

		$batch     = max( 1, $batch );
		$where_sql = $this->build_where( $args );
		$order_sql = $this->build_order( $args );
		$offset    = 0;

		do {
			$rows = $this->fetch_page( $where_sql, $order_sql, $batch, $offset );

			// wpdb keeps its own copy of the last result set; I drop it before yielding.
			$wpdb->flush();

			$fetched = count( $rows );

			yield from $rows;

			$offset += $batch;
		} while ( $fetched === $batch );
	}

	/**
	 * The distinct form ids with at least one transaction, for the Form filter.
	 *
	 * @return array<int, int>
	 */
	public function distinct_form_ids(): array {
		global $wpdb;

		$cache_key = 'distinct_form_ids_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT form_id FROM %i WHERE form_id != 0 ORDER BY form_id ASC', self::table_name() ) ) );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $ids;
	}

	/**
	 * The distinct non-empty payment methods, sorted, for the Method filter.
	 *
	 * @return array<int, string>
	 */
	public function distinct_methods(): array {
		global $wpdb;

		$cache_key = 'distinct_methods_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$methods = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pay_method FROM %i WHERE pay_method <> '' ORDER BY pay_method ASC", self::table_name() ) ) );

		wp_cache_set( $cache_key, $methods, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $methods;
	}

	/**
	 * Count and amount per status for the given filters, ignoring their `status`.
	 * Cancelled rolls into Failed, since the admin list has no Cancelled tab.
	 *
	 * @param array<string, mixed> $args Same filter args as build_where().
	 * @return array<string, array{count: int, amount: float}>
	 */
	public function status_summary( array $args = array() ): array {
		global $wpdb;

		unset( $args['status'] );

		$cache_key = 'status_summary_' . $this->last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached below; the WHERE clause is built from prepare() calls.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total, SUM(amount) AS amount FROM %i' . $this->build_where( $args ) . ' GROUP BY status', self::table_name() ), ARRAY_A );

		$summary = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = self::STATUS_CANCELLED === $row['status'] ? self::STATUS_FAILED : (string) $row['status'];
			$prior  = $summary[ $status ] ?? array(
				'count'  => 0,
				'amount' => 0.0,
			);

			$summary[ $status ] = array(
				'count'  => $prior['count'] + (int) $row['total'],
				'amount' => round( $prior['amount'] + (float) $row['amount'], 2 ),
			);
		}

		wp_cache_set( $cache_key, $summary, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $summary;
	}

	/**
	 * Inserts a row, filling in the customer columns and timestamps.
	 *
	 * @param array{ref: string, form_id: int, gateway_key: string, amount: float, status: string, field_snapshot: array<string, mixed>, payment_url?: string, pay_method?: string} $row The row to insert.
	 */
	private function insert( array $row ): bool {
		global $wpdb;

		$now      = time();
		$customer = $this->storable_customer( $this->extract_customer( $row['field_snapshot'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'ref'            => $row['ref'],
				'form_id'        => $row['form_id'],
				'gateway_key'    => $row['gateway_key'],
				'amount'         => $row['amount'],
				'pay_method'     => $row['pay_method'] ?? '',
				'payment_url'    => $row['payment_url'] ?? '',
				'customer_name'  => $customer['name'],
				'customer_email' => $customer['email'],
				'status'         => $row['status'],
				'field_snapshot' => (string) wp_json_encode( $row['field_snapshot'] ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		// unique_ref() cached a miss for this ref; a persistent cache would hide the row from the webhook.
		$this->forget( array( $row['ref'] ) );

		return false !== $result;
	}

	/**
	 * Writes a status unless the row is already paid — in the WHERE clause, so a
	 * webhook landing at the same moment can't be overwritten.
	 *
	 * @param string $ref    The transaction reference.
	 * @param string $status One of ALL_STATUSES.
	 */
	private function set_status_unless_paid( string $ref, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %d WHERE ref = %s AND status <> %s',
				self::table_name(),
				$status,
				time(),
				$ref,
				self::STATUS_PAID
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Sets one status on many rows in one UPDATE.
	 *
	 * @param array<int, string> $refs        The transaction references.
	 * @param string             $status      The status to write.
	 * @param string             $only_status Only touch rows currently in this status, or '' for any.
	 * @return int How many rows changed.
	 */
	private function set_status_many( array $refs, string $status, string $only_status = '' ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		$sql     = '' !== $only_status
			? 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status = %s'
			: 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status <> %s';
		$guarded = '' !== $only_status ? $only_status : $status;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( $sql, $refs, array( self::table_name(), $status, time() ), array( $guarded ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$result = $wpdb->update( self::table_name(), $fields, array( 'ref' => $ref ) );

		$this->forget( array( $ref ) );

		return false !== $result;
	}

	/**
	 * One page of full rows. I pick the refs first and join the rows back, so a
	 * deep OFFSET doesn't sort every row's snapshot along with it.
	 *
	 * @param string $where_sql From build_where().
	 * @param string $order_sql From build_order().
	 * @param int    $limit     Rows to fetch.
	 * @param int    $offset    Rows to skip.
	 * @return array<int, Transaction>
	 */
	private function fetch_page( string $where_sql, string $order_sql, int $limit, int $offset ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, callers cache; $where_sql is built from prepare() calls, $order_sql from SORTABLE_COLUMNS, and LIST_COLUMNS is a constant.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::LIST_COLUMNS . ' FROM %i AS t INNER JOIN (SELECT ref FROM %i' . $where_sql . $order_sql . ' LIMIT %d OFFSET %d) AS page USING (ref)' . $order_sql, $table, $table, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A fully prepared ` WHERE …` clause, or '' for no filter.
	 *
	 * @param array<string, mixed> $args Filter args: status, form_id, pay_method, amount_min/max, refs, date_from/to, search.
	 */
	private function build_where( array $args ): string {
		global $wpdb;

		$conditions = array();
		$status     = is_string( $args['status'] ?? null ) ? $args['status'] : '';

		if ( self::STATUS_FAILED === $status ) {
			// The admin list treats cancelled as a kind of failed.
			$conditions[] = $wpdb->prepare( 'status IN (%s, %s)', self::STATUS_FAILED, self::STATUS_CANCELLED );
		} elseif ( '' !== $status ) {
			$conditions[] = $wpdb->prepare( 'status = %s', $status );
		}

		$form_id = is_numeric( $args['form_id'] ?? null ) ? (int) $args['form_id'] : 0;

		if ( 0 !== $form_id ) {
			$conditions[] = $wpdb->prepare( 'form_id = %d', $form_id );
		}

		$pay_method = is_string( $args['pay_method'] ?? null ) ? $args['pay_method'] : '';

		if ( '' !== $pay_method ) {
			$conditions[] = $wpdb->prepare( 'pay_method = %s', $pay_method );
		}

		if ( is_numeric( $args['amount_min'] ?? null ) ) {
			$conditions[] = $wpdb->prepare( 'amount >= %f', (float) $args['amount_min'] );
		}

		if ( is_numeric( $args['amount_max'] ?? null ) ) {
			$conditions[] = $wpdb->prepare( 'amount <= %f', (float) $args['amount_max'] );
		}

		if ( isset( $args['refs'] ) && is_array( $args['refs'] ) && array() !== $args['refs'] ) {
			$refs = self::clean_refs( $args['refs'] );

			if ( array() === $refs ) {
				// A selection with nothing valid in it must match nothing, not everything.
				$conditions[] = '1 = 0';
			} else {
				$conditions[] = self::prepare_for_refs( 'ref IN ({refs})', $refs );
			}
		}

		$date_from = is_numeric( $args['date_from'] ?? null ) ? (int) $args['date_from'] : 0;

		if ( $date_from > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at >= %d', $date_from );
		}

		$date_to = is_numeric( $args['date_to'] ?? null ) ? (int) $args['date_to'] : 0;

		if ( $date_to > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at <= %d', $date_to );
		}

		$search = is_string( $args['search'] ?? null ) ? $args['search'] : '';

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$match = $wpdb->prepare( 'ref LIKE %s OR pay_method LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s', $like, $like, $like, $like );

			// The Entry column shows "#19115", so I match an entry id typed with or without the "#".
			if ( 1 === preg_match( '/^#?(\d+)$/', trim( $search ), $entry ) ) {
				$match .= $wpdb->prepare( ' OR entry_id = %d', (int) $entry[1] );
			}

			$conditions[] = '(' . $match . ')';
		}

		return array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
	}

	/**
	 * The ` ORDER BY …` clause. Ties break on `ref` so LIMIT/OFFSET pages never repeat or skip a row.
	 *
	 * @param array<string, mixed> $args Sort args: orderby, order.
	 */
	private function build_order( array $args ): string {
		$orderby = $args['orderby'] ?? '';

		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			return ' ORDER BY created_at DESC, ref DESC';
		}

		$order = 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

		return 'ref' === $orderby ? " ORDER BY ref {$order}" : " ORDER BY {$orderby} {$order}, ref {$order}";
	}

	/**
	 * Casts a raw ARRAY_A row to its typed shape once, so callers don't have to.
	 *
	 * @param array<string, mixed> $row A raw table row.
	 * @return Transaction
	 */
	private static function hydrate( array $row ): array {
		$text = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
		$int  = static fn( string $key ): int => is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : 0;

		return array(
			'ref'            => $text( 'ref' ),
			'form_id'        => $int( 'form_id' ),
			'entry_id'       => $int( 'entry_id' ),
			'gateway_key'    => $text( 'gateway_key' ),
			'amount'         => is_numeric( $row['amount'] ?? null ) ? (float) $row['amount'] : 0.0,
			'pay_method'     => $text( 'pay_method' ),
			'request_id'     => $text( 'request_id' ),
			'transaction_id' => $text( 'transaction_id' ),
			'payment_url'    => $text( 'payment_url' ),
			'customer_name'  => $text( 'customer_name' ),
			'customer_email' => $text( 'customer_email' ),
			'status'         => $text( 'status' ),
			'field_snapshot' => $text( 'field_snapshot' ),
			'created_at'     => $int( 'created_at' ),
			'updated_at'     => $int( 'updated_at' ),
		);
	}

	/**
	 * The customer cut down to what its columns can store.
	 * wpdb fails the whole insert on a character the charset can't hold (emoji on utf8mb3), so I strip those.
	 *
	 * @param array{name: string, email: string} $customer As returned by extract_customer().
	 * @return array{name: string, email: string}
	 */
	private function storable_customer( array $customer ): array {
		global $wpdb;

		$table = self::table_name();
		$name  = $wpdb->strip_invalid_text_for_column( $table, 'customer_name', $customer['name'] );
		$email = $wpdb->strip_invalid_text_for_column( $table, 'customer_email', $customer['email'] );

		return array(
			'name'  => is_string( $name ) ? $name : '',
			'email' => is_string( $email ) ? $email : '',
		);
	}

	/**
	 * A snapshot entry's trimmed value — either a {label, value} pair or a bare scalar.
	 *
	 * @param mixed $pair The snapshot entry.
	 */
	private static function snapshot_value( $pair ): string {
		$value = is_array( $pair ) ? ( $pair['value'] ?? '' ) : $pair;

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Prepares $sql with `{refs}` swapped for one %s per ref. The refs sit between $before and $after in the args.
	 *
	 * @param string             $sql    The query, with a `{refs}` token inside its `IN ( … )`.
	 * @param array<int, string> $refs   The refs, already cleaned.
	 * @param array<int, mixed>  $before Args for the placeholders before `{refs}`.
	 * @param array<int, mixed>  $after  Args for the placeholders after it.
	 */
	private static function prepare_for_refs( string $sql, array $refs, array $before = array(), array $after = array() ): string {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $refs ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- callers pass fixed SQL; {refs} becomes one %s per ref.
		return (string) $wpdb->prepare( str_replace( '{refs}', $placeholders, $sql ), array_merge( $before, $refs, $after ) );
	}

	/**
	 * Scalar, non-empty, de-duplicated refs, re-indexed.
	 *
	 * @param array<mixed> $refs Raw refs.
	 * @return array<int, string>
	 */
	private static function clean_refs( array $refs ): array {
		$refs = array_map( 'strval', array_filter( $refs, 'is_scalar' ) );

		return array_values( array_unique( array_filter( $refs, static fn( string $ref ): bool => '' !== $ref ) ) );
	}

	/**
	 * Drops the given rows' cached copies and every cached list.
	 *
	 * @param array<int, string> $refs The transaction references.
	 */
	private function forget( array $refs ): void {
		wp_cache_delete_multiple( array_map( array( self::class, 'row_cache_key' ), $refs ), self::CACHE_GROUP );
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * The cache key for one row.
	 *
	 * @param string $ref The transaction reference.
	 */
	private static function row_cache_key( string $ref ): string {
		return 'txn_' . $ref;
	}

	/**
	 * A token bumped on every write, so list caches never need per-row invalidation.
	 */
	private function last_changed(): string {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );

		if ( ! is_string( $last_changed ) ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}

		return $last_changed;
	}
}
