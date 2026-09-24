<?php
/**
 * Plugin Name: ifthenpay | Payments for Forminator
 * Plugin URI: https://ifthenpay.com
 * Description: Accept ifthenpay payments (card, Apple Pay, Google Pay, Cofidis, Pix, Multibanco, MB WAY) in Forminator via Pay by Link, with webhook-confirmed payment status.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ifthenpay-payments-for-forminator
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_FM_VERSION', '1.0.0' );
define( 'IFTP_FM_FILE', __FILE__ );
define( 'IFTP_FM_PATH', plugin_dir_path( __FILE__ ) );
define( 'IFTP_FM_URL', plugin_dir_url( __FILE__ ) );

$iftp_fm_autoload = IFTP_FM_PATH . 'vendor/autoload.php';

if ( file_exists( $iftp_fm_autoload ) ) {
	require_once $iftp_fm_autoload;
}

/*
 * Forminator itself has no "plugins_loaded"-time gate to wait on (unlike Ninja
 * Forms, it doesn't defer its own bootstrap to a later hook) — I still wait
 * for `plugins_loaded` before touching any Forminator class, since load order
 * between plugins isn't guaranteed any earlier than that.
 */
add_action( 'plugins_loaded', 'iftp_fm_bootstrap' );

/**
 * Boots the plugin once Forminator (and our own autoloader) are confirmed available.
 */
function iftp_fm_bootstrap(): void {
	if ( ! class_exists( 'Forminator' ) ) {
		add_action( 'admin_notices', 'iftp_fm_missing_forminator_notice' );

		return;
	}

	if ( ! class_exists( \Ifthenpay\Forminator\Plugin::class ) ) {
		add_action( 'admin_notices', 'iftp_fm_missing_autoloader_notice' );

		return;
	}

	\Ifthenpay\Forminator\Plugin::instance()->boot();
}

/**
 * Warns the admin that Forminator itself isn't active.
 */
function iftp_fm_missing_forminator_notice(): void {
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'ifthenpay Payments for Forminator requires Forminator to be installed and active.', 'ifthenpay-payments-for-forminator' )
	);
}

/**
 * Warns the admin that Composer dependencies were never installed.
 */
function iftp_fm_missing_autoloader_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'ifthenpay Payments for Forminator could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-forminator' )
	);
}

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \Ifthenpay\Forminator\Forminator\TransactionStore::class ) ) {
			\Ifthenpay\Forminator\Forminator\TransactionStore::maybe_create_table();
		}

		// So the /iftp_fm_callback/{ref}/ webhook endpoint resolves right
		// away instead of 404ing until the next unrelated rewrite flush.
		if ( class_exists( \Ifthenpay\Forminator\Api\Webhook\WebhookController::class ) ) {
			\Ifthenpay\Forminator\Api\Webhook\WebhookController::flush();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);
