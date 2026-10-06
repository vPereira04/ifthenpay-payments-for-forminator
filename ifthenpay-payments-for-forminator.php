<?php
/**
 * Plugin Name: ifthenpay | Payments for Forminator
 * Plugin URI: https://ifthenpay.com
 * Description: Accept ifthenpay payments (card, Apple Pay, Google Pay, Cofidis, Pix, Multibanco, MB WAY) in Forminator via Pay by Link, with webhook-confirmed payment status.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: forminator
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPLv2 or later
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

// Boots once Forminator and our autoloader are both available. A closure, so I don't add a global function.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'Forminator' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for Forminator requires Forminator to be installed and active.', 'ifthenpay-payments-for-forminator' ), array( 'type' => 'warning' ) );
				},
				10,
				0
			);

			return;
		}

		if ( ! class_exists( \Ifthenpay\Forminator\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for Forminator could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-forminator' ), array( 'type' => 'error' ) );
				},
				10,
				0
			);

			return;
		}

		\Ifthenpay\Forminator\Plugin::instance()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		// WP 6.5+ already blocks this through "Requires Plugins"; 6.4 ignores that header, so I refuse here too.
		if ( ! class_exists( 'Forminator' ) ) {
			wp_die(
				esc_html__( 'ifthenpay | Payments for Forminator requires Forminator. Install and activate Forminator first, then activate this plugin.', 'ifthenpay-payments-for-forminator' ),
				esc_html__( 'Plugin dependency missing', 'ifthenpay-payments-for-forminator' ),
				array( 'back_link' => true )
			);
		}

		if ( ! class_exists( \Ifthenpay\Forminator\Plugin::class ) ) {
			return;
		}

		\Ifthenpay\Forminator\Forminator\TransactionStore::maybe_create_table();
		\Ifthenpay\Forminator\Cron\ExpiredPaymentsCron::schedule();

		// So the webhook endpoint resolves right away instead of 404ing until the next flush.
		\Ifthenpay\Forminator\Api\Webhook\WebhookController::flush();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \Ifthenpay\Forminator\Plugin::class ) ) {
			\Ifthenpay\Forminator\Cron\ExpiredPaymentsCron::unschedule();
		}

		flush_rewrite_rules();
	}
);
