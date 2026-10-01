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

if ( class_exists( \Ifthenpay\Forminator\Repository\SettingsRepository::class ) ) {
	$iftp_fm_settings = new \Ifthenpay\Forminator\Repository\SettingsRepository();
	$iftp_fm_settings->delete_all();
	$iftp_fm_settings->delete_anti_phishing_keys();
	$iftp_fm_settings->delete_payment_email();
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time DDL cleanup on uninstall; custom plugin table with no WP Core API, caching is meaningless here.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'iftp_fm_transactions' ) );

delete_option( 'iftp_fm_db_version' );
delete_transient( 'iftp_fm_callback_resync_lock' );
delete_transient( 'iftp_fm_backfill_lock' );
wp_clear_scheduled_hook( 'iftp_fm_expire_payments' );
delete_option( 'iftp_fm_customer_backfill_done' );
delete_option( 'iftp_fm_customer_backfill_cursor' );

// Per-user Payment Entries column choices, for every user.
delete_metadata( 'user', 0, 'iftp_fm_entries_hidden_cols', '', true );
delete_metadata( 'user', 0, 'iftp_fm_entries_col_order', '', true );
delete_metadata( 'user', 0, 'iftp_fm_entries_per_page', '', true );
