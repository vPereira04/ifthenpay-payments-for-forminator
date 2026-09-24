<?php
/**
 * Forminator builder field.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Fields;

use Ifthenpay\Forminator\Repository\SettingsRepository;
use Forminator_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ifthenpay field, registered into Forminator's builder next to
 * PayPal/Stripe via `add_filter('forminator_fields', ...)` (`Plugin::boot()`).
 *
 * Unlike PayPal/Stripe, which capture payment inline via a JS SDK before the
 * normal AJAX submit runs, ifthenpay Pay by Link is a redirect-to-hosted-page
 * flow — so this class only renders the builder UI and a frontend marker.
 * The actual payment start (assets/js/frontend.js), pending-transaction
 * storage, and entry creation all happen outside Forminator's own field
 * processing pipeline — see `Ajax\PaymentController` and
 * `Api\Webhook\WebhookController`. `process_to_entry_data()` below exists to
 * satisfy the abstract contract and is only ever exercised on the replayed,
 * already-paid submission the webhook builds — never on a live/unpaid one.
 *
 * `amount_type`/`amount`/`variable`/`checkout_label` below (see defaults())
 * can't be configured from the builder's own field-settings modal — that
 * modal's React component is picked from a hardcoded map with no entry for
 * unregistered field types, so clicking this field on the canvas would open
 * a broken panel. They're configured instead from our own popup,
 * `Admin\FieldSettingsModal`, which intercepts that same click and reads/
 * writes them through the same Forminator_Form_Model this class itself reads
 * from at render time — see that class's docblock for the full explanation.
 */
class IfthenpayField extends Forminator_Field {

	/**
	 * Builder-facing field label.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Field slug.
	 *
	 * @var string
	 */
	public $slug = 'ifthenpay';

	/**
	 * Field type.
	 *
	 * @var string
	 */
	public $type = 'ifthenpay';

	/**
	 * Builder sort position.
	 *
	 * @var int
	 */
	public $position = 25;

	/**
	 * Field options.
	 *
	 * @var array<string, mixed>
	 */
	public $options = array();

	/**
	 * Builder icon class.
	 *
	 * @var string
	 */
	public $icon = 'sui-icon forminator-icon-ifthenpay';

	/**
	 * Unused Forminator_Field contract property — connection status is read from SettingsRepository instead.
	 *
	 * @var bool
	 */
	public $is_connected = false;

	/**
	 * Sets the builder-facing field label.
	 */
	public function __construct() {
		parent::__construct();

		$this->name = esc_html__( 'ifthenpay', 'ifthenpay-payments-for-forminator' );
	}

	/**
	 * Default field settings.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'amount_type'           => 'fixed',
			'amount'                => '0',
			'variable'              => '',
			'checkout_label'        => __( 'Pay now', 'ifthenpay-payments-for-forminator' ),
			'field_label'           => '',
			'description'           => '',
			'description_placement' => 'below',
			'css_class'             => '',
			'hide_icon'             => '',
			'hide_methods'          => '',
		);
	}

	/**
	 * Hidden marker input, mirroring `Forminator_PayPal::markup()`'s shape —
	 * `assets/js/frontend.js` looks for `data-payment-type="ifthenpay"` to
	 * find this field on the form and intercept submission. Wrapped in a
	 * `.forminator-field` container (with the field's own `field_label`/
	 * `description`/`css_class`, when set) so it sits in the same
	 * `.forminator-row > .forminator-col > .forminator-field` structure every
	 * other field renders into — that's what lets Forminator's own
	 * conditional-logic engine (`front.condition.js`) find and toggle it via
	 * `condition_action`/`condition_rule`/`conditions`, the same generic
	 * per-field properties any native field uses, with no extra wiring needed
	 * on our side beyond persisting them (see `FieldSettingsStore`).
	 *
	 * @param array<string, mixed> $field     Field.
	 * @param mixed                $views_obj Forminator_Render_Form object.
	 */
	public function markup( $field, $views_obj ): string {
		$this->field = $field;

		$element_name          = self::get_property( 'element_id', $field );
		$amount_type           = self::get_property( 'amount_type', $field, 'fixed' );
		$amount                = self::get_property( 'amount', $field, '0' );
		$variable              = self::get_property( 'variable', $field, '' );
		$label                 = self::get_property( 'checkout_label', $field, '' );
		$field_label           = self::get_property( 'field_label', $field, '' );
		$description           = self::get_property( 'description', $field, '' );
		$description_placement = self::get_property( 'description_placement', $field, 'below' );
		$css_class             = self::get_property( 'css_class', $field, '' );
		$hide_icon             = self::get_property( 'hide_icon', $field, false, 'bool' );
		$hide_methods          = self::get_property( 'hide_methods', $field, false, 'bool' );
		$visual_html           = $this->methods_visual_markup( $hide_icon, $hide_methods );

		// No esc_attr() here — create_input() escapes every attribute itself,
		// so escaping twice turned "&" into a literal "&amp;" on the button.
		$attr = array(
			'type'              => 'hidden',
			'name'              => $element_name,
			'id'                => 'forminator-' . $element_name . '-field_' . \Forminator_CForm_Front::$uid,
			'class'             => 'forminator-ifthenpay-input',
			'data-is-payment'   => 'true',
			'data-payment-type' => $this->type,
			'data-amount-type'  => $amount_type,
			'data-amount'       => $amount,
			'data-variable'     => $variable,
			'data-label'        => $label,
		);

		$input            = self::create_input( $attr );
		$wrapper_class    = trim( 'forminator-field forminator-ifthenpay-field ' . $css_class );
		$field_label_html = '' !== $field_label ? '<label class="forminator-label">' . esc_html( $field_label ) . '</label>' : '';
		$description_html = '' !== $description ? '<p class="forminator-description">' . esc_html( $description ) . '</p>' : '';

		$label_block = 'above' === $description_placement
			? $description_html . $field_label_html
			: $field_label_html . $description_html;

		$html = '<div class="' . esc_attr( $wrapper_class ) . '">' . $label_block . $visual_html . $input . '</div>';

		return (string) apply_filters( 'forminator_field_ifthenpay_markup', $html, $attr, $field );
	}

	/**
	 * The field's on-page presence: this field has no real inputs of its own
	 * (see the class docblock), so without this it would render as an empty
	 * label with nothing for the payer to look at. Built on Forminator's own
	 * `.forminator-input` box class — the same one native fields wrap a
	 * prefix/suffix in (see `Forminator_Calculation::markup()`) — so it picks
	 * up whatever border/background/radius the active form style already
	 * uses, rather than a hand-rolled box that'd drift from it.
	 *
	 * `$hide_icon`/`$hide_methods` independently omit their own span. When
	 * neither would leave anything to show, the box is left out entirely —
	 * the field still works, this only feeds `markup()`'s `$visual_html`.
	 *
	 * @param bool $hide_icon    Whether to omit the ifthenpay glyph.
	 * @param bool $hide_methods Whether to omit the payment method icons.
	 */
	private function methods_visual_markup( bool $hide_icon, bool $hide_methods ): string {
		$settings = new SettingsRepository();
		$methods  = array_filter(
			$settings->get_methods(),
			static function ( array $method ): bool {
				return ! empty( $method['enabled'] ) && '' !== $method['account'] && '' !== ( $method['logo'] ?? '' );
			}
		);

		$show_icon    = ! $hide_icon;
		$show_methods = ! $hide_methods && array() !== $methods;

		if ( ! $show_icon && ! $show_methods ) {
			return '';
		}

		$icon_html = $show_icon ? '<span class="iftp-fm-field-icon" role="img" aria-label="ifthenpay"></span>' : '';

		$methods_html = '';
		if ( $show_methods ) {
			foreach ( $methods as $method ) {
				$methods_html .= sprintf(
					'<img class="iftp-fm-field-method-icon" src="%s" alt="%s" loading="lazy" />',
					esc_url( $method['logo'] ),
					esc_attr( $method['alias'] ?? '' )
				);
			}
		}

		$divider_html = ( $show_icon && $show_methods ) ? '<span class="iftp-fm-field-divider" aria-hidden="true"></span>' : '';
		$methods_span = $show_methods ? '<span class="iftp-fm-field-methods">' . $methods_html . '</span>' : '';

		return '<div class="forminator-input iftp-fm-field-visual">' . $icon_html . $divider_html . $methods_span . '</div>';
	}

	/**
	 * No inline validation — see validate()'s body comment.
	 *
	 * @param array<string, mixed>        $field Field.
	 * @param array<string, mixed>|string $data  Data.
	 */
	public function validate( $field, $data ): void {
		// No inline validation — the amount/method choice is validated
		// server-side in `Ajax\PaymentController::create_payment()`, the
		// only place a real charge attempt can originate from.
	}

	/**
	 * Sanitizes the submitted field value.
	 *
	 * @param array<string, mixed>        $field Field.
	 * @param array<string, mixed>|string $data  The data to be sanitized.
	 * @return array<string, mixed>|string
	 */
	public function sanitize( $field, $data ) {
		return forminator_sanitize_field( $data );
	}

	/**
	 * Whether the field can be shown: ifthenpay must be connected, have a
	 * gateway key, and at least one enabled method.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public function is_available( $field ): bool {
		$settings = new SettingsRepository();

		return $settings->is_connected()
			&& '' !== $settings->get_gateway_key()
			&& array() !== $settings->get_enabled_methods();
	}

	/**
	 * Only ever runs against the replayed, webhook-confirmed submission —
	 * see the class docblock. `Ajax\PaymentController`/`WebhookController`
	 * already know the real amount/status by the time this would run.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return array<string, string>
	 */
	public function process_to_entry_data( $field ): array {
		return array(
			'mode'   => 'live',
			'status' => 'PAID',
		);
	}

	/**
	 * Fields this field's amount depends on, for Forminator's conditional logic.
	 *
	 * @param array<string, mixed> $field_settings Field settings.
	 * @return array<int, mixed>
	 */
	public function get_amount_dependent_fields( $field_settings ): array {
		$depend_field = array();
		$amount_type  = self::get_property( 'amount_type', $field_settings, 'fixed' );
		$variable     = self::get_property( 'variable', $field_settings, '' );

		if ( 'variable' === $amount_type && '' !== $variable ) {
			$depend_field[] = $variable;
		}

		return $depend_field;
	}
}
