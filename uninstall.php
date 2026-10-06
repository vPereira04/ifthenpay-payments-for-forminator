<?php
/**
 * Uninstall routine — removes every option and the custom transactions table.
 *
 * @package Ifthenpay\Forminator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$iftp_fm_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $iftp_fm_autoload ) ) {
	require_once $iftp_fm_autoload;
}

// Everything here lives per site (options, transients, cron, the prefixed table), so on a network I run it on each one.
$iftp_fm_uninstall_site = static function (): void {
	global $wpdb;

	if ( class_exists( \Ifthenpay\Forminator\Repository\SettingsRepository::class ) ) {
		$settings = new \Ifthenpay\Forminator\Repository\SettingsRepository();
		$settings->delete_all();
		$settings->delete_anti_phishing_keys();
		$settings->delete_payment_email();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time DDL cleanup on uninstall; custom plugin table with no WP Core API, caching is meaningless here.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'iftp_fm_transactions' ) );

	delete_option( 'iftp_fm_db_version' );
	delete_transient( 'iftp_fm_callback_resync_lock' );
	delete_transient( 'iftp_fm_backfill_lock' );
	delete_transient( 'iftp_fm_methods_catalog' );
	delete_transient( 'iftp_fm_gateway_rows' );
	wp_clear_scheduled_hook( 'iftp_fm_expire_payments' );
	delete_option( 'iftp_fm_customer_backfill_done' );
	delete_option( 'iftp_fm_customer_backfill_cursor' );
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $iftp_fm_blog_id ) {
		switch_to_blog( (int) $iftp_fm_blog_id );
		$iftp_fm_uninstall_site();
		restore_current_blog();
	}
} else {
	$iftp_fm_uninstall_site();
}

// Per-user Payment Entries column choices, for every user. Usermeta is network-wide, so once is enough.
delete_metadata( 'user', 0, 'iftp_fm_entries_hidden_cols', '', true );
delete_metadata( 'user', 0, 'iftp_fm_entries_col_order', '', true );
delete_metadata( 'user', 0, 'iftp_fm_entries_per_page', '', true );
