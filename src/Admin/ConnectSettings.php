<?php
/**
 * Connect-to-Ifthenpay settings box.
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
 * A "Connect to Ifthenpay" row on Forminator's native Payments tab.
 *
 * The tab has no hook inside its box (Stripe/PayPal are hardcoded), so I render the row
 * into a hidden holder and admin.js moves it in, above the "Save Settings" footer.
 */
class ConnectSettings {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the box.
	 *
	 * @param SettingsRepository|null $settings Settings storage.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Registers the render and asset hooks.
	 */
	public function register(): void {
		add_action( 'forminator_settings_content', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the admin script/style on Forminator's own settings screen.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_settings_page( $hook ) ) {
			return;
		}

		$admin_css = 'assets/css/admin.css';
		$admin_js  = 'assets/js/admin.js';

		wp_enqueue_style( 'iftp-fm-admin', IFTP_FM_URL . $admin_css, array(), Plugin::asset_version( $admin_css ) );
		wp_enqueue_script( 'iftp-fm-admin', IFTP_FM_URL . $admin_js, array( 'jquery', Plugin::METHOD_LOGOS_HANDLE ), Plugin::asset_version( $admin_js ), true );

		wp_localize_script(
			'iftp-fm-admin',
			'iftpFmAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'iftp_fm_admin' ),
				'settingsUrl' => add_query_arg(
					array(
						'page'    => 'forminator-settings',
						'section' => 'GatewayConfiguration',
					),
					admin_url( 'admin.php' )
				),
				'i18n'        => array(
					'connect'           => __( 'Connect', 'ifthenpay-payments-for-forminator' ),
					'connecting'        => __( 'Connecting…', 'ifthenpay-payments-for-forminator' ),
					'invalidKey'        => __( 'Wrong Backoffice Format..', 'ifthenpay-payments-for-forminator' ),
					'disconnectConfirm' => __( 'Disconnect your ifthenpay Backoffice Key? This will disable the ifthenpay field on every form.', 'ifthenpay-payments-for-forminator' ),
					'requesting'        => __( 'Requesting…', 'ifthenpay-payments-for-forminator' ),
					'requested'         => __( 'Requested', 'ifthenpay-payments-for-forminator' ),
					'saving'            => __( 'Saving…', 'ifthenpay-payments-for-forminator' ),
					'saved'             => __( 'Saved', 'ifthenpay-payments-for-forminator' ),
					'error'             => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-forminator' ),
				),
			)
		);
	}

	/**
	 * Renders the connect box.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		$connected     = $this->settings->is_connected();
		$configure_url = add_query_arg(
			array(
				'page'    => 'forminator-settings',
				'section' => 'GatewayConfiguration',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div id="iftp-fm-connect-source" hidden>
			<div id="sui-box-ifthenpay" class="sui-box-settings-row">
				<div class="sui-box-settings-col-1">
					<span class="sui-settings-label"><?php esc_html_e( 'ifthenpay', 'ifthenpay-payments-for-forminator' ); ?></span>
					<span class="sui-description"><?php esc_html_e( 'Accept Multibanco, MB WAY, card, Apple Pay, Google Pay, Cofidis and Pix payments in your forms via ifthenpay Pay by Link.', 'ifthenpay-payments-for-forminator' ); ?></span>
				</div>
				<div class="sui-box-settings-col-2">
					<span class="sui-settings-label"><?php esc_html_e( 'Authorization', 'ifthenpay-payments-for-forminator' ); ?></span>
					<?php if ( $connected ) : ?>
						<span class="sui-description">
							<?php esc_html_e( 'Connected.', 'ifthenpay-payments-for-forminator' ); ?>
						</span>
						<div class="sui-form-field iftp-fm-connect-actions">
							<a class="sui-button sui-button-blue" href="<?php echo esc_url( $configure_url ); ?>">
								<?php esc_html_e( 'Configure Gateway', 'ifthenpay-payments-for-forminator' ); ?>
							</a>
							<button type="button" class="sui-button sui-button-ghost" id="iftp-fm-disconnect">
								<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
						</div>
					<?php else : ?>
						<span class="sui-description"><?php esc_html_e( 'Connect your ifthenpay Backoffice Key to enable the ifthenpay payment field.', 'ifthenpay-payments-for-forminator' ); ?></span>
						<div class="sui-form-field iftp-fm-connect-actions">
							<button
								type="button"
								class="sui-button"
								data-modal-open="iftp-fm-connect-modal"
								id="iftp-fm-connect-open"
							>
								<?php esc_html_e( 'Connect to Ifthenpay', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
						</div>
					<?php endif; ?>
					<p id="iftp-fm-connect-message" role="alert"></p>
				</div>
			</div>
		</div>

		<?php
		if ( ! $connected ) :
			$this->render_connect_modal();
endif;
	}

	/**
	 * Renders the connect modal.
	 *
	 * SUI.Dialog() needs role="dialog" on the element with the id, so the id sits on
	 * .sui-modal-content; show/hide comes from my own CSS rule on `sui-active`.
	 */
	private function render_connect_modal(): void {
		?>
		<div class="sui-modal sui-modal-md" id="iftp-fm-connect-modal-wrap">
			<div role="dialog" id="iftp-fm-connect-modal" class="sui-modal-content" aria-modal="true" aria-labelledby="iftp-fm-connect-modal-title">
				<div class="sui-box">
					<div class="sui-box-header sui-flatten sui-content-center">
						<h3 id="iftp-fm-connect-modal-title" class="sui-box-title"><?php esc_html_e( 'Connect to Ifthenpay', 'ifthenpay-payments-for-forminator' ); ?></h3>
					</div>
					<div class="sui-box-body">
						<div class="sui-box-settings-row">
							<label for="iftp-fm-backoffice-key" class="sui-settings-label"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-forminator' ); ?></label>
							<input
								type="text"
								id="iftp-fm-backoffice-key"
								class="sui-form-control"
								placeholder="<?php esc_attr_e( 'Insert your backoffice key here..', 'ifthenpay-payments-for-forminator' ); ?>"
								autocomplete="off"
								spellcheck="false"
								aria-describedby="iftp-fm-backoffice-key-help iftp-fm-connect-modal-message"
							/>
							<span id="iftp-fm-backoffice-key-help" class="sui-description"><?php esc_html_e( 'The 16-digit key from your ifthenpay Backoffice account.', 'ifthenpay-payments-for-forminator' ); ?></span>
							<p id="iftp-fm-connect-modal-message" class="iftp-fm-connect-modal-message" role="alert"></p>
						</div>
					</div>
					<div class="sui-box-footer">
						<div class="sui-actions-right">
							<button type="button" class="sui-button sui-button-ghost" data-modal-close>
								<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
							<button type="button" class="sui-button sui-button-blue" id="iftp-fm-connect-submit">
								<span class="sui-loading-text"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-forminator' ); ?></span>
								<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the current admin request is on our own settings screen.
	 *
	 * Tabs switch client-side, so I can't tell which one is active; I enqueue on the whole page.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	private function is_settings_page( string $hook ): bool {
		return 'forminator_page_forminator-settings' === $hook;
	}
}
