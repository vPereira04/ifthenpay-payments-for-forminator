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
 * Step 1 of Victor's two-step admin UX: a "Connect to Ifthenpay" row on
 * Forminator's own native Payments tab (`section=payments`).
 *
 * Forminator's Payments tab (`admin/views/settings/tab-payments.php`) is a
 * core file I never touch, and it has no PHP hook inside its `.sui-box-body`
 * — Stripe's and PayPal's rows are hardcoded there, directly above the
 * `.sui-box-footer` "Save Settings" button. `forminator_settings_content`
 * only fires *after* that whole box, as a trailing sibling, so anything
 * rendered here lands in its own box below "Save Settings" — not alongside
 * Stripe/PayPal — unless it's moved.
 *
 * So `render()` outputs the row markup into a hidden holder, and
 * `assets/js/admin.js` relocates it into the native box (inserted before its
 * `.sui-box-footer`) on page load — the only way to sit inside that box
 * without patching Forminator core. The connect modal doesn't need moving;
 * it's `display:none` until opened, so its position in the DOM is irrelevant.
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
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
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

		wp_enqueue_style( 'iftp-fm-admin', IFTP_FM_URL . $admin_css, array(), (string) filemtime( IFTP_FM_PATH . $admin_css ) );
		wp_enqueue_script( 'iftp-fm-admin', IFTP_FM_URL . $admin_js, array( 'jquery', Plugin::METHOD_LOGOS_HANDLE ), (string) filemtime( IFTP_FM_PATH . $admin_js ), true );

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
		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
			return;
		}

		$connected = $this->settings->is_connected();
		?>
		<div id="iftp-fm-connect-source" style="display: none;">
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
						<div class="sui-form-field" style="margin-top: 10px;">
							<a class="sui-button sui-button-blue" href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page'    => 'forminator-settings',
										'section' => 'GatewayConfiguration',
									),
									admin_url( 'admin.php' )
								)
							);
							?>
																		">
								<?php esc_html_e( 'Configure Gateway', 'ifthenpay-payments-for-forminator' ); ?>
							</a>
							<button type="button" class="sui-button sui-button-ghost" id="iftp-fm-disconnect">
								<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
						</div>
					<?php else : ?>
						<span class="sui-description"><?php esc_html_e( 'Connect your ifthenpay Backoffice Key to enable the ifthenpay payment field.', 'ifthenpay-payments-for-forminator' ); ?></span>
						<div class="sui-form-field" style="margin-top: 10px;">
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
	 * Modal markup, matching the real structure SUI's generic `data-modal-open`
	 * opener requires — verified against Forminator core's own reference use of
	 * that same mechanism (`admin/views/settings/hub-connector/popup.php`):
	 * `SUI.Dialog()` does `document.getElementById( <id> )` and then requires
	 * `role="dialog"` on *that exact element* (not an ancestor), so the id has
	 * to sit on the `.sui-modal-content` node, not on the outer `.sui-modal`
	 * wrapper.
	 *
	 * Hidden-by-default/shown-when-active is enforced by our own
	 * `#iftp-fm-connect-modal-wrap` rule in assets/css/admin.css, not
	 * Forminator's `.sui-<version> .sui-modal` CSS — that rule only matches
	 * inside a body class Forminator adds via `admin_body_class`, which this
	 * plugin can't rely on holding across Forminator versions/contexts. Our
	 * rule toggles on the same `sui-active` class `SUI.Dialog` already adds
	 * to this wrapper, so it works regardless of whether the native scope
	 * also applies.
	 *
	 * `data-modal-close` on the Cancel button is picked up by the same generic
	 * SUI wiring, no JS of mine needed for that half.
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
								placeholder="Insert your backoffice key here.."
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
	 * Forminator's tabs switch client-side without reloading, so I can't
	 * tell which one is active from the query string — I just check we're
	 * on the settings page at all and let it enqueue every time.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	private function is_settings_page( string $hook ): bool {
		return 'forminator_page_forminator-settings' === $hook;
	}
}
