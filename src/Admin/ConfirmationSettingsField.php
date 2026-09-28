<?php
/**
 * Confirmation Type panel field renderer.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Plugin;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirmation Type panel — what the payer sees right after each outcome.
 *
 * Built at reduced fidelity versus the WPREA gold-standard contract: a plain
 * `wp_kses_post()`-sanitized textarea per status, not the full contenteditable
 * Normal/Raw dual-view editor — disclosed in the blueprint (section 3g) and
 * the build report rather than left unmentioned. Paid is always a popup too,
 * but its 3-way type switch (popup/page/URL) adds a "Continue" link to a
 * page/URL that opens in a new tab, so the submitted data stays on screen.
 * Pending/Failed/Cancelled are a plain popup, since there's nowhere to send
 * the payer for a non-final outcome.
 */
class ConfirmationSettingsField {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the panel.
	 *
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Renders the Confirmation Type panel markup.
	 */
	public function render(): void {
		$paid_type = $this->settings->get_paid_confirmation_type();
		?>
		<div class="sui-box-settings-row">
			<div class="sui-box-settings-col-1">
				<span class="sui-settings-label"><?php esc_html_e( 'Confirmation Type', 'ifthenpay-payments-for-forminator' ); ?></span>
				<span class="sui-description"><?php esc_html_e( 'What the payer sees immediately after each payment outcome.', 'ifthenpay-payments-for-forminator' ); ?></span>
			</div>
			<div class="sui-box-settings-col-2">
				<div class="iftp-fm-confirmation-tabs" role="tablist">
					<button type="button" class="iftp-fm-confirmation-tab iftp-fm-confirmation-tab--active" role="tab" id="iftp-fm-confirmation-tab-paid" aria-controls="iftp-fm-confirmation-panel-paid" aria-selected="true" tabindex="0" data-status="paid"><?php esc_html_e( 'Paid', 'ifthenpay-payments-for-forminator' ); ?></button>
					<button type="button" class="iftp-fm-confirmation-tab" role="tab" id="iftp-fm-confirmation-tab-pending" aria-controls="iftp-fm-confirmation-panel-pending" aria-selected="false" tabindex="-1" data-status="pending"><?php esc_html_e( 'Pending', 'ifthenpay-payments-for-forminator' ); ?></button>
					<button type="button" class="iftp-fm-confirmation-tab" role="tab" id="iftp-fm-confirmation-tab-failed" aria-controls="iftp-fm-confirmation-panel-failed" aria-selected="false" tabindex="-1" data-status="failed"><?php esc_html_e( 'Failed', 'ifthenpay-payments-for-forminator' ); ?></button>
					<button type="button" class="iftp-fm-confirmation-tab" role="tab" id="iftp-fm-confirmation-tab-cancelled" aria-controls="iftp-fm-confirmation-panel-cancelled" aria-selected="false" tabindex="-1" data-status="cancelled"><?php esc_html_e( 'Cancelled', 'ifthenpay-payments-for-forminator' ); ?></button>
				</div>

				<div class="iftp-fm-confirmation-panel" id="iftp-fm-confirmation-panel-paid" role="tabpanel" aria-labelledby="iftp-fm-confirmation-tab-paid" data-status="paid">
					<div class="sui-form-field">
						<label class="sui-label" for="iftp-fm-paid-type"><?php esc_html_e( 'When a payment is confirmed, show', 'ifthenpay-payments-for-forminator' ); ?></label>
						<select class="sui-select" id="iftp-fm-paid-type" name="paid_type">
							<option value="<?php echo esc_attr( SettingsRepository::CONFIRMATION_TYPE_POPUP ); ?>" <?php selected( $paid_type, SettingsRepository::CONFIRMATION_TYPE_POPUP ); ?>><?php esc_html_e( 'Popup message', 'ifthenpay-payments-for-forminator' ); ?></option>
							<option value="<?php echo esc_attr( SettingsRepository::CONFIRMATION_TYPE_PAGE ); ?>" <?php selected( $paid_type, SettingsRepository::CONFIRMATION_TYPE_PAGE ); ?>><?php esc_html_e( 'Popup message + WordPress page (new tab)', 'ifthenpay-payments-for-forminator' ); ?></option>
							<option value="<?php echo esc_attr( SettingsRepository::CONFIRMATION_TYPE_URL ); ?>" <?php selected( $paid_type, SettingsRepository::CONFIRMATION_TYPE_URL ); ?>><?php esc_html_e( 'Popup message + Custom URL (new tab)', 'ifthenpay-payments-for-forminator' ); ?></option>
						</select>
					</div>
					<div class="sui-form-field" id="iftp-fm-paid-page-row" style="<?php echo SettingsRepository::CONFIRMATION_TYPE_PAGE === $paid_type ? '' : 'display:none;'; ?>">
						<label class="sui-label" for="iftp-fm-paid-page-id"><?php esc_html_e( 'Page', 'ifthenpay-payments-for-forminator' ); ?></label>
						<?php
						wp_dropdown_pages(
							array(
								'id'               => 'iftp-fm-paid-page-id',
								'name'             => 'paid_page_id',
								'selected'         => absint( $this->settings->get_paid_confirmation_page_id() ),
								'show_option_none' => esc_html__( '— Select a page —', 'ifthenpay-payments-for-forminator' ),
								'class'            => 'sui-select',
							)
						);
						?>
					</div>
					<div class="sui-form-field" id="iftp-fm-paid-url-row" style="<?php echo SettingsRepository::CONFIRMATION_TYPE_URL === $paid_type ? '' : 'display:none;'; ?>">
						<label class="sui-label" for="iftp-fm-paid-url"><?php esc_html_e( 'URL', 'ifthenpay-payments-for-forminator' ); ?></label>
						<input type="url" class="sui-form-control" id="iftp-fm-paid-url" name="paid_url" value="<?php echo esc_url( $this->settings->get_paid_confirmation_url() ); ?>" placeholder="https://" />
					</div>
					<div class="sui-form-field" id="iftp-fm-paid-message-row">
						<label class="sui-label" for="iftp-fm-paid-title"><?php esc_html_e( 'Paid popup title', 'ifthenpay-payments-for-forminator' ); ?></label>
						<input type="text" class="sui-form-control" id="iftp-fm-paid-title" name="paid_title" value="<?php echo esc_attr( $this->settings->get_confirmation_title( 'paid' ) ); ?>" placeholder="<?php echo esc_attr( Plugin::default_status_title( 'paid' ) ); ?>" />
						<label class="sui-label" for="iftp-fm-paid-message" style="margin-top:10px;"><?php esc_html_e( 'Paid popup message', 'ifthenpay-payments-for-forminator' ); ?></label>
						<textarea class="sui-form-control" id="iftp-fm-paid-message" name="paid_message" rows="2"><?php echo esc_textarea( $this->settings->get_confirmation_message( 'paid' ) ); ?></textarea>
						<label class="sui-checkbox" style="margin-top:6px;">
							<input type="checkbox" id="iftp-fm-show-entry-data" name="show_entry_data" <?php checked( $this->settings->get_show_entry_data() ); ?> />
							<span aria-hidden="true"></span>
							<span class="sui-description"><?php esc_html_e( 'Show submitted field values in the paid popup', 'ifthenpay-payments-for-forminator' ); ?></span>
						</label>
					</div>
				</div>

				<div class="iftp-fm-confirmation-panel" id="iftp-fm-confirmation-panel-pending" role="tabpanel" aria-labelledby="iftp-fm-confirmation-tab-pending" data-status="pending" hidden>
					<div class="sui-form-field">
						<label class="sui-label" for="iftp-fm-pending-title"><?php esc_html_e( 'Pending popup title', 'ifthenpay-payments-for-forminator' ); ?></label>
						<input type="text" class="sui-form-control" id="iftp-fm-pending-title" name="pending_title" value="<?php echo esc_attr( $this->settings->get_confirmation_title( 'pending' ) ); ?>" placeholder="<?php echo esc_attr( Plugin::default_status_title( 'pending' ) ); ?>" />
						<label class="sui-label" for="iftp-fm-pending-message" style="margin-top:10px;"><?php esc_html_e( 'Pending popup message', 'ifthenpay-payments-for-forminator' ); ?></label>
						<textarea class="sui-form-control" id="iftp-fm-pending-message" name="pending_message" rows="2"><?php echo esc_textarea( $this->settings->get_confirmation_message( 'pending' ) ); ?></textarea>
					</div>
				</div>
				<div class="iftp-fm-confirmation-panel" id="iftp-fm-confirmation-panel-failed" role="tabpanel" aria-labelledby="iftp-fm-confirmation-tab-failed" data-status="failed" hidden>
					<div class="sui-form-field">
						<label class="sui-label" for="iftp-fm-failed-title"><?php esc_html_e( 'Failed popup title', 'ifthenpay-payments-for-forminator' ); ?></label>
						<input type="text" class="sui-form-control" id="iftp-fm-failed-title" name="failed_title" value="<?php echo esc_attr( $this->settings->get_confirmation_title( 'failed' ) ); ?>" placeholder="<?php echo esc_attr( Plugin::default_status_title( 'failed' ) ); ?>" />
						<label class="sui-label" for="iftp-fm-failed-message" style="margin-top:10px;"><?php esc_html_e( 'Failed popup message', 'ifthenpay-payments-for-forminator' ); ?></label>
						<textarea class="sui-form-control" id="iftp-fm-failed-message" name="failed_message" rows="2"><?php echo esc_textarea( $this->settings->get_confirmation_message( 'failed' ) ); ?></textarea>
					</div>
				</div>
				<div class="iftp-fm-confirmation-panel" id="iftp-fm-confirmation-panel-cancelled" role="tabpanel" aria-labelledby="iftp-fm-confirmation-tab-cancelled" data-status="cancelled" hidden>
					<div class="sui-form-field">
						<label class="sui-label" for="iftp-fm-cancelled-title"><?php esc_html_e( 'Cancelled popup title', 'ifthenpay-payments-for-forminator' ); ?></label>
						<input type="text" class="sui-form-control" id="iftp-fm-cancelled-title" name="cancelled_title" value="<?php echo esc_attr( $this->settings->get_confirmation_title( 'cancelled' ) ); ?>" placeholder="<?php echo esc_attr( Plugin::default_status_title( 'cancelled' ) ); ?>" />
						<label class="sui-label" for="iftp-fm-cancelled-message" style="margin-top:10px;"><?php esc_html_e( 'Cancelled popup message', 'ifthenpay-payments-for-forminator' ); ?></label>
						<textarea class="sui-form-control" id="iftp-fm-cancelled-message" name="cancelled_message" rows="2"><?php echo esc_textarea( $this->settings->get_confirmation_message( 'cancelled' ) ); ?></textarea>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
