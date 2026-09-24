<?php
/**
 * Builder-canvas popup for the ifthenpay field's settings.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forminator's form builder is a compiled React app (build/form-scripts.js).
 * The component that renders a field's settings modal (every tab: Labels,
 * Settings, Advanced, ...) is picked from a hardcoded, build-time object
 * literal keyed by field type slug (`email`, `paypal`, `stripe`, ...) — the
 * same component backs every tab, not just "Settings" — with no entry, and no
 * generic/fallback entry, for third-party field types registered via the
 * `forminator_fields` filter. Clicking our field on the canvas would hand
 * that lookup `undefined`, which every tab route then tries to render as a
 * React component, so the modal fails rather than showing anything useful.
 * There's no PHP-side extension point either — `Forminator_Field`'s abstract
 * base class exposes nothing for the builder canvas, only for frontend markup
 * and processing. We must never patch Forminator core to add one.
 *
 * So instead of Forminator's native modal, this renders our *own* popup
 * (styled with Forminator's own SUI classes for a native look) and
 * `assets/js/field-settings.js` intercepts a click on our field's canvas
 * block — `.fui-builder-field-block[data-field="ifthenpay"] .fui-field--info`,
 * confirmed against the built bundle — before Forminator's own (broken)
 * click handler opens its modal, the same capture-phase interception
 * `assets/js/frontend.js` already uses to get ahead of Forminator's own
 * submit handler. The popup reads/writes the same `amount_type`/`amount`/
 * `variable`/`checkout_label` field properties through `Ajax\Controller`'s
 * `iftp_fm_get_field_settings`/`iftp_fm_save_field_settings` actions
 * (`Forminator\FieldSettingsStore`) — the same persistence path the
 * builder's own "Save" button uses internally, so nothing about how the
 * field renders on the frontend changes.
 */
class FieldSettingsModal {

	private const NONCE_ACTION = 'iftp_fm_admin';
	private const BUILDER_HOOK = 'forminator_page_forminator-cform-wizard';

	/**
	 * Whether the current admin request is on the builder screen, with
	 * capability to configure it — set by `enqueue_assets()`, read by
	 * `render_modal()`, so both stay in lockstep without re-deriving it twice.
	 *
	 * @var bool
	 */
	private bool $should_render = false;

	/**
	 * Registers the asset and markup hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_modal' ) );
	}

	/**
	 * Enqueues the popup's script/style on Forminator's own form-builder screen.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		$this->should_render = self::BUILDER_HOOK === $hook && current_user_can( forminator_get_admin_cap() );

		if ( ! $this->should_render ) {
			return;
		}

		$admin_css = 'assets/css/admin.css';
		$field_js  = 'assets/js/field-settings.js';

		wp_enqueue_style( 'iftp-fm-admin', IFTP_FM_URL . $admin_css, array(), (string) filemtime( IFTP_FM_PATH . $admin_css ) );
		wp_enqueue_script( 'iftp-fm-field-settings', IFTP_FM_URL . $field_js, array(), (string) filemtime( IFTP_FM_PATH . $field_js ), true );

		wp_localize_script(
			'iftp-fm-field-settings',
			'iftpFmFieldSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only form id from the current builder screen's own query string, not a state change.
				'formId'  => absint( wp_unslash( $_GET['id'] ?? 0 ) ),
				'i18n'    => array(
					'title'              => __( 'Ifthenpay Payment Settings', 'ifthenpay-payments-for-forminator' ),
					'selectField'        => __( '— Select a field —', 'ifthenpay-payments-for-forminator' ),
					'apply'              => __( 'Apply', 'ifthenpay-payments-for-forminator' ),
					'applying'           => __( 'Applying…', 'ifthenpay-payments-for-forminator' ),
					'error'              => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-forminator' ),
					'unsaved'            => __( 'Save the form once first, then configure this field.', 'ifthenpay-payments-for-forminator' ),
					'saved'              => __( 'Saved. Reloading…', 'ifthenpay-payments-for-forminator' ),
					'defaultLabel'       => __( 'Pay now', 'ifthenpay-payments-for-forminator' ),
					'removeCondition'    => __( 'Remove condition', 'ifthenpay-payments-for-forminator' ),
					'conditionOperators' => array(
						'is'               => __( 'is', 'ifthenpay-payments-for-forminator' ),
						'is_not'           => __( 'is not', 'ifthenpay-payments-for-forminator' ),
						'is_great'         => __( 'is greater than', 'ifthenpay-payments-for-forminator' ),
						'is_less'          => __( 'is less than', 'ifthenpay-payments-for-forminator' ),
						'contains'         => __( 'contains', 'ifthenpay-payments-for-forminator' ),
						'does_not_contain' => __( 'does not contain', 'ifthenpay-payments-for-forminator' ),
						'starts'           => __( 'starts with', 'ifthenpay-payments-for-forminator' ),
						'ends'             => __( 'ends with', 'ifthenpay-payments-for-forminator' ),
					),
				),
			)
		);
	}

	/**
	 * Renders the (initially hidden) popup markup once, into wp-admin's
	 * footer — three tabs, mirroring native Forminator field-settings
	 * modals: "Settings" (the field's own amount/checkout-label config),
	 * "Advanced" (label/description/description-placement/CSS class), and
	 * "Visibility" (conditional logic) — native fields keep conditional
	 * logic on its own tab too, rather than folding it into Advanced.
	 */
	public function render_modal(): void {
		if ( ! $this->should_render ) {
			return;
		}
		?>
		<div class="iftp-fm-field-modal-wrap" id="iftp-fm-field-modal-wrap">
			<div role="dialog" class="sui-modal-content" aria-modal="true" aria-labelledby="iftp-fm-field-modal-title">
				<div class="sui-box">
					<div class="sui-box-header sui-flatten">
						<h3 id="iftp-fm-field-modal-title" class="sui-box-title">
							<i class="sui-icon forminator-icon-ifthenpay" aria-hidden="true"></i>
							<?php esc_html_e( 'Customize Ifthenpay Field', 'ifthenpay-payments-for-forminator' ); ?>
							<span
								id="iftp-fm-field-modal-tag"
								class="sui-tag sui-tooltip sui-tooltip-constrained sui-tooltip-bottom"
								style="margin-top: -2px; --tooltip-width: 170px;"
								data-tooltip="<?php echo esc_attr__( "Use this merge tag to dynamically include this field's value in email notification, success messages, and other text elements.", 'ifthenpay-payments-for-forminator' ); ?>"
							></span>
						</h3>
						<div class="sui-actions-right">
							<button type="button" class="iftp-fm-field-modal-close" id="iftp-fm-field-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-forminator' ); ?>">&times;</button>
						</div>
					</div>

					<div class="iftp-fm-field-tabs" role="tablist">
						<button type="button" class="iftp-fm-field-tab iftp-fm-field-tab--active" role="tab" aria-selected="true" id="iftp-fm-field-tab-settings" aria-controls="iftp-fm-field-panel-settings" tabindex="0" data-tab="settings"><?php esc_html_e( 'Settings', 'ifthenpay-payments-for-forminator' ); ?></button>
						<button type="button" class="iftp-fm-field-tab" role="tab" aria-selected="false" id="iftp-fm-field-tab-advanced" aria-controls="iftp-fm-field-panel-advanced" tabindex="-1" data-tab="advanced"><?php esc_html_e( 'Advanced', 'ifthenpay-payments-for-forminator' ); ?></button>
						<button type="button" class="iftp-fm-field-tab" role="tab" aria-selected="false" id="iftp-fm-field-tab-style" aria-controls="iftp-fm-field-panel-style" tabindex="-1" data-tab="style"><?php esc_html_e( 'Style', 'ifthenpay-payments-for-forminator' ); ?></button>
						<button type="button" class="iftp-fm-field-tab" role="tab" aria-selected="false" id="iftp-fm-field-tab-visibility" aria-controls="iftp-fm-field-panel-visibility" tabindex="-1" data-tab="visibility"><?php esc_html_e( 'Visibility', 'ifthenpay-payments-for-forminator' ); ?></button>
					</div>

					<div class="sui-box-body">
						<p id="iftp-fm-field-modal-message" role="alert"></p>

						<div class="iftp-fm-field-tab-panel" id="iftp-fm-field-panel-settings" role="tabpanel" aria-labelledby="iftp-fm-field-tab-settings" data-tab-panel="settings">
							<div class="sui-box-settings-row">
								<span class="sui-settings-label"><?php esc_html_e( 'Amount type', 'ifthenpay-payments-for-forminator' ); ?></span>
								<div class="sui-side-tabs iftp-fm-toggle-tabs">
									<div class="sui-tabs-menu">
										<label class="sui-tab-item active" for="iftp-fm-field-amount-type-fixed">
											<input type="radio" name="iftp_fm_field_amount_type" id="iftp-fm-field-amount-type-fixed" value="fixed" checked />
											<?php esc_html_e( 'Fixed amount', 'ifthenpay-payments-for-forminator' ); ?>
										</label>
										<label class="sui-tab-item" for="iftp-fm-field-amount-type-variable">
											<input type="radio" name="iftp_fm_field_amount_type" id="iftp-fm-field-amount-type-variable" value="variable" />
											<?php esc_html_e( 'From another field on this form', 'ifthenpay-payments-for-forminator' ); ?>
										</label>
									</div>
								</div>
							</div>

							<div class="sui-box-settings-row" id="iftp-fm-field-amount-row">
								<label for="iftp-fm-field-amount" class="sui-settings-label"><?php esc_html_e( 'Fixed amount', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="number" step="0.01" min="0" id="iftp-fm-field-amount" class="sui-form-control" />
							</div>

							<div class="sui-box-settings-row iftp-fm-hidden" id="iftp-fm-field-variable-row">
								<label for="iftp-fm-field-variable" class="sui-settings-label"><?php esc_html_e( 'Amount field', 'ifthenpay-payments-for-forminator' ); ?></label>
								<select id="iftp-fm-field-variable" class="sui-form-control"></select>
								<p class="description"><?php esc_html_e( 'That field\'s submitted value becomes the payment amount.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>

							<div class="sui-box-settings-row">
								<label for="iftp-fm-field-label" class="sui-settings-label"><?php esc_html_e( 'Checkout button label', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="text" id="iftp-fm-field-label" class="sui-form-control" />
							</div>

						</div>

						<div class="iftp-fm-field-tab-panel" id="iftp-fm-field-panel-advanced" role="tabpanel" aria-labelledby="iftp-fm-field-tab-advanced" data-tab-panel="advanced" hidden>
							<div class="sui-box-settings-row">
								<label for="iftp-fm-field-label-text" class="sui-settings-label"><?php esc_html_e( 'Field label', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="text" id="iftp-fm-field-label-text" class="sui-form-control" />
								<p class="description"><?php esc_html_e( 'Optional — shown above the checkout button.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>

							<div class="sui-box-settings-row">
								<label for="iftp-fm-field-description" class="sui-settings-label"><?php esc_html_e( 'Description', 'ifthenpay-payments-for-forminator' ); ?></label>
								<textarea id="iftp-fm-field-description" class="sui-form-control" rows="2"></textarea>
							</div>

							<div class="sui-box-settings-row">
								<label for="iftp-fm-field-description-placement" class="sui-settings-label"><?php esc_html_e( 'Description placement', 'ifthenpay-payments-for-forminator' ); ?></label>
								<select id="iftp-fm-field-description-placement" class="iftp-fm-select">
									<option value="below"><?php esc_html_e( 'Below the label', 'ifthenpay-payments-for-forminator' ); ?></option>
									<option value="above"><?php esc_html_e( 'Above the label', 'ifthenpay-payments-for-forminator' ); ?></option>
								</select>
							</div>

						</div>

						<div class="iftp-fm-field-tab-panel" id="iftp-fm-field-panel-style" role="tabpanel" aria-labelledby="iftp-fm-field-tab-style" data-tab-panel="style" hidden>
							<div class="sui-box-settings-row">
								<label for="iftp-fm-field-css-class" class="sui-settings-label"><?php esc_html_e( 'Additional CSS Classes', 'ifthenpay-payments-for-forminator' ); ?></label>
								<p class="description"><?php esc_html_e( "Add custom CSS classes to style this field's container — great for adjusting spacing, colors, or layout.", 'ifthenpay-payments-for-forminator' ); ?></p>
								<input type="text" id="iftp-fm-field-css-class" class="sui-form-control" placeholder="<?php esc_attr_e( 'E.g. form-field', 'ifthenpay-payments-for-forminator' ); ?>" />
								<p class="description"><?php esc_html_e( 'Add one or more class names, separated by spaces. These apply to the field container.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>

							<div class="sui-box-settings-row">
								<span class="sui-settings-label"><?php esc_html_e( 'Display', 'ifthenpay-payments-for-forminator' ); ?></span>
								<div class="iftp-fm-toggle-list">
									<label class="sui-toggle">
										<input type="checkbox" id="iftp-fm-field-hide-icon" />
										<span class="sui-toggle-slider" aria-hidden="true"></span>
										<span class="sui-toggle-label"><?php esc_html_e( 'Hide the ifthenpay icon', 'ifthenpay-payments-for-forminator' ); ?></span>
									</label>
									<label class="sui-toggle">
										<input type="checkbox" id="iftp-fm-field-hide-methods" />
										<span class="sui-toggle-slider" aria-hidden="true"></span>
										<span class="sui-toggle-label"><?php esc_html_e( 'Hide the payment methods icons', 'ifthenpay-payments-for-forminator' ); ?></span>
									</label>
								</div>
								<p class="description"><?php esc_html_e( 'The field keeps working even if both are hidden.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>
						</div>

						<div class="iftp-fm-field-tab-panel" id="iftp-fm-field-panel-visibility" role="tabpanel" aria-labelledby="iftp-fm-field-tab-visibility" data-tab-panel="visibility" hidden>
							<div class="sui-box-settings-row">
								<label>
									<input type="checkbox" id="iftp-fm-field-conditional-enabled" />
									<?php esc_html_e( 'Enable conditional logic', 'ifthenpay-payments-for-forminator' ); ?>
								</label>

								<div id="iftp-fm-field-conditional-body" class="iftp-fm-hidden">
									<div class="iftp-fm-conditional-summary">
										<div class="sui-side-tabs iftp-fm-toggle-tabs">
											<div class="sui-tabs-menu">
												<label class="sui-tab-item active" for="iftp-fm-field-condition-action-show">
													<input type="radio" name="iftp_fm_field_condition_action" id="iftp-fm-field-condition-action-show" value="show" checked />
													<?php esc_html_e( 'Show', 'ifthenpay-payments-for-forminator' ); ?>
												</label>
												<label class="sui-tab-item" for="iftp-fm-field-condition-action-hide">
													<input type="radio" name="iftp_fm_field_condition_action" id="iftp-fm-field-condition-action-hide" value="hide" />
													<?php esc_html_e( 'Hide', 'ifthenpay-payments-for-forminator' ); ?>
												</label>
											</div>
										</div>
										<span><?php esc_html_e( 'this field if', 'ifthenpay-payments-for-forminator' ); ?></span>
										<div class="sui-side-tabs iftp-fm-toggle-tabs">
											<div class="sui-tabs-menu">
												<label class="sui-tab-item active" for="iftp-fm-field-condition-rule-all">
													<input type="radio" name="iftp_fm_field_condition_rule" id="iftp-fm-field-condition-rule-all" value="all" checked />
													<?php esc_html_e( 'All', 'ifthenpay-payments-for-forminator' ); ?>
												</label>
												<label class="sui-tab-item" for="iftp-fm-field-condition-rule-any">
													<input type="radio" name="iftp_fm_field_condition_rule" id="iftp-fm-field-condition-rule-any" value="any" />
													<?php esc_html_e( 'Any', 'ifthenpay-payments-for-forminator' ); ?>
												</label>
											</div>
										</div>
										<span><?php esc_html_e( 'of the following match:', 'ifthenpay-payments-for-forminator' ); ?></span>
									</div>

									<div id="iftp-fm-field-conditions-list"></div>

									<button type="button" class="sui-button sui-button-ghost" id="iftp-fm-field-condition-add">
										<?php esc_html_e( '+ Add condition', 'ifthenpay-payments-for-forminator' ); ?>
									</button>
								</div>
							</div>
						</div>
					</div>
					<div class="sui-box-footer sui-content-separated">
						<button type="button" class="sui-button sui-button-ghost forminator-discard-field-settings modal-close-button" id="iftp-fm-field-modal-cancel">
							<i class="iftp-fm-icon-close" aria-hidden="true"></i><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
						<button type="button" class="sui-button forminator-save-field-settings" id="iftp-fm-field-modal-save">
							<span class="sui-loading-text"><i class="iftp-fm-icon-check" aria-hidden="true"></i><span id="iftp-fm-field-modal-save-label"><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-forminator' ); ?></span></span>
							<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
