<?php
/**
 * Gateway Configuration settings screen.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Repository\SettingsRepository;
use Ifthenpay\Forminator\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 2 of Victor's two-step admin UX: a dedicated `section=GatewayConfiguration`
 * screen, only reachable once the Backoffice Key is connected (`ConnectSettings`).
 *
 * Gateway Key, methods table (with the star default-method toggle), payer-
 * facing description, expiry days, and Confirmation Type all live here and
 * save together over one AJAX call (`Ajax\Controller::save_settings`).
 */
class GatewaySettingsPage {

	private const SECTION_SLUG = 'GatewayConfiguration';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Methods table renderer.
	 *
	 * @var GatewaySettingsField
	 */
	private GatewaySettingsField $methods_field;

	/**
	 * Gateway catalog sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Sets up the page.
	 *
	 * @param SettingsRepository|null   $settings      Settings storage; a fresh instance is used when omitted.
	 * @param GatewaySettingsField|null $methods_field Methods table renderer; a fresh instance is used when omitted.
	 * @param GatewaySync|null          $sync          Gateway catalog sync; a fresh instance is used when omitted.
	 */
	public function __construct(
		?SettingsRepository $settings = null,
		?GatewaySettingsField $methods_field = null,
		?GatewaySync $sync = null
	) {
		$this->settings      = $settings ?? new SettingsRepository();
		$this->methods_field = $methods_field ?? new GatewaySettingsField();
		$this->sync          = $sync ?? new GatewaySync( $this->settings );
	}

	/**
	 * Registers the section and render hooks.
	 */
	public function register(): void {
		add_filter( 'forminator_settings_sections', array( $this, 'add_section' ) );
		add_action( 'forminator_settings_content', array( $this, 'render' ) );
	}

	/**
	 * Adds our own tab to Forminator's settings sidenav, once connected.
	 *
	 * @param array<string, string> $sections Section slug => label map.
	 * @return array<string, string>
	 */
	public function add_section( array $sections ): array {
		// Locked behind a real connection — there's nothing to configure on
		// this screen until step 1 (ConnectSettings) is done, and showing an
		// empty/broken tab would be worse than not listing it at all.
		if ( ! $this->settings->is_connected() ) {
			return $sections;
		}

		$sections[ self::SECTION_SLUG ] = __( 'Ifthenpay Gateway', 'ifthenpay-payments-for-forminator' );

		return $sections;
	}

	/**
	 * Renders the Gateway Configuration screen.
	 */
	public function render(): void {
		if ( ! current_user_can( forminator_get_admin_cap() ) || ! $this->settings->is_connected() ) {
			return;
		}

		if ( $this->sync->needs_backfill() ) {
			$this->sync->sync();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle from the current admin screen's own query string, not a state change.
		$section = sanitize_text_field( wp_unslash( $_GET['section'] ?? 'dashboard' ) );
		?>
		<div class="sui-box" data-nav="<?php echo esc_attr( self::SECTION_SLUG ); ?>" style="<?php echo esc_attr( self::SECTION_SLUG !== $section ? 'display: none;' : '' ); ?>">
			<div class="sui-box-header">
				<h2 class="sui-box-title"><?php esc_html_e( 'Ifthenpay Gateway', 'ifthenpay-payments-for-forminator' ); ?></h2>
			</div>

			<form id="iftp-fm-settings-form">
				<div class="sui-box-body">
					<div class="sui-box-settings-row">
						<div class="sui-box-settings-col-1">
							<span class="sui-settings-label"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-forminator' ); ?></span>
							<span class="sui-description"><?php esc_html_e( 'The ifthenpay Gateway Key this form field will use.', 'ifthenpay-payments-for-forminator' ); ?></span>
						</div>
						<div class="sui-box-settings-col-2">
							<select class="sui-select" id="iftp-fm-gateway-key" name="gateway_key">
								<?php foreach ( $this->settings->get_gateway_keys() as $key ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $this->settings->get_gateway_key() ); ?>>
										<?php echo esc_html( $key ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="sui-box-settings-row">
						<div class="sui-box-settings-col-1">
							<span class="sui-settings-label"><?php esc_html_e( 'Payment Methods', 'ifthenpay-payments-for-forminator' ); ?></span>
							<span class="sui-description"><?php esc_html_e( 'Choose which methods to offer and which one is pre-selected on the hosted payment page.', 'ifthenpay-payments-for-forminator' ); ?></span>
						</div>
						<div class="sui-box-settings-col-2">
							<div id="iftp-fm-methods-table-wrapper">
								<?php echo $this->methods_field->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped fragment ?>
							</div>
						</div>
					</div>

					<div class="sui-box-settings-row">
						<div class="sui-box-settings-col-1">
							<span class="sui-settings-label"><?php esc_html_e( 'Hosted Payment Page', 'ifthenpay-payments-for-forminator' ); ?></span>
							<span class="sui-description"><?php esc_html_e( 'Shown to the payer on the ifthenpay payment page, and used to expire unpaid links.', 'ifthenpay-payments-for-forminator' ); ?></span>
						</div>
						<div class="sui-box-settings-col-2">
							<div class="sui-form-field">
								<label for="iftp-fm-description" class="sui-label"><?php esc_html_e( 'Description', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="text" class="sui-form-control" id="iftp-fm-description" name="description" value="<?php echo esc_attr( $this->settings->get_description() ); ?>" />
							</div>
							<div class="sui-form-field">
								<label for="iftp-fm-expiry-days" class="sui-label"><?php esc_html_e( 'Expiry Days', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="number" min="1" class="sui-form-control" id="iftp-fm-expiry-days" name="expiry_days" value="<?php echo esc_attr( (string) $this->settings->get_expiry_days() ); ?>" />
							</div>
						</div>
					</div>

					<?php ( new ConfirmationSettingsField( $this->settings ) )->render(); ?>
				</div>

				<div class="sui-box-footer">
					<div class="sui-actions-right">
						<button type="submit" class="sui-button sui-button-blue" id="iftp-fm-save-settings">
							<span class="sui-loading-text"><?php esc_html_e( 'Save Settings', 'ifthenpay-payments-for-forminator' ); ?></span>
							<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
						</button>
						<span class="iftp-fm-save-status" aria-live="polite"></span>
					</div>
				</div>
			</form>
		</div>
		<?php
	}
}
