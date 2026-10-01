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
 * The ifthenpay field in Forminator's builder.
 *
 * Pay by Link redirects to a hosted page, so this only renders a frontend marker; payment
 * start and entry creation happen outside Forminator's field pipeline (PaymentController/webhook).
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
	 * Renders the hidden marker input frontend.js uses to intercept submission.
	 *
	 * I wrap it in a normal .forminator-field so Forminator's conditional logic can toggle it.
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

		// No esc_attr() here: create_input() escapes attributes itself, and double-escaping shows "&amp;".
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
	 * Renders the ifthenpay icon and method logos box.
	 *
	 * I use Forminator's .forminator-input class so it picks up the form style's box look.
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
				// frontend.js swaps to this when the field sits on a dark background.
				$logo_dark     = (string) ( $method['logo_dark'] ?? '' );
				$methods_html .= sprintf(
					'<img class="iftp-fm-field-method-icon" src="%s" alt="%s"%s loading="lazy" />',
					esc_url( $method['logo'] ),
					esc_attr( $method['alias'] ?? '' ),
					'' !== $logo_dark ? ' data-logo-dark="' . esc_url( $logo_dark ) . '"' : ''
				);
			}
		}

		$divider_html = ( $show_icon && $show_methods ) ? '<span class="iftp-fm-field-divider" aria-hidden="true"></span>' : '';
		$methods_span = $show_methods ? '<span class="iftp-fm-field-methods">' . $methods_html . '</span>' : '';

		return '<div class="forminator-input iftp-fm-field-visual">' . $icon_html . $divider_html . $methods_span . '</div>';
	}

	/**
	 * Refuses Forminator's own submit while this field is visible.
	 *
	 * Paid entries only ever come from the webhook, and frontend.js always takes
	 * over the submit when the field shows, so a normal submit reaching here is
	 * someone skipping the payment. Forminator doesn't call this for hidden fields.
	 *
	 * @param array<string, mixed>        $field Field.
	 * @param array<string, mixed>|string $data  Data.
	 */
	public function validate( $field, $data ): void {
		$id = (string) self::get_property( 'element_id', $field );

		$this->validation_message[ $id ] = __( 'Please complete the payment to submit this form.', 'ifthenpay-payments-for-forminator' );
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
	 * Entry data for the field.
	 *
	 * Only runs on the replayed submission after the webhook confirmed payment, so it's always PAID.
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
