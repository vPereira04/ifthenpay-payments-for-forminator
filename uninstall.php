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
	( new \Ifthenpay\Forminator\Repository\SettingsRepository() )->delete_all();
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time DDL cleanup on uninstall; custom plugin table with no WP Core API, caching is meaningless here.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'iftp_fm_transactions' );

delete_option( 'iftp_fm_db_version' );
