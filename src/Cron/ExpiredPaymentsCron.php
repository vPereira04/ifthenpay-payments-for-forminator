<?php
/**
 * Daily expiry sweep for unpaid payment links.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Cron;

use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expires pending payments older than Expiry Days — the same window sent to ifthenpay as `expiredate`.
 */
class ExpiredPaymentsCron {

	public const HOOK = 'iftp_fm_expire_payments';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the sweep.
	 *
	 * @param SettingsRepository|null $settings     Settings storage.
	 * @param TransactionStore|null   $transactions Transactions storage.
	 */
	public function __construct( ?SettingsRepository $settings = null, ?TransactionStore $transactions = null ) {
		$this->settings     = $settings ?? new SettingsRepository();
		$this->transactions = $transactions ?? new TransactionStore();
	}

	/**
	 * Hooks the sweep and makes sure it's scheduled.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		// Activation schedules it too; this covers a site that was already active.
		// Not earlier than init — reading cron schedules loads other plugins' translations.
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily sweep, unless it already is.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled sweep.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Expires every pending payment past the configured window.
	 */
	public function run(): void {
		$this->transactions->expire_pending_before( time() - $this->settings->get_expiry_days() * DAY_IN_SECONDS );
	}
}
