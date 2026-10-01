<?php
/**
 * Server-side price for variable-amount payments.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Forminator;

use Forminator_CForm_Front_Action;
use Forminator_Field;
use Forminator_Form_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out what a variable-amount payment costs from the submitted form data,
 * so the browser never gets to name its own price.
 */
class VariableAmount {

	/**
	 * The amount the submission is worth, or null when it can't be trusted.
	 *
	 * Calculations and hidden-field defaults come from Forminator's own server-side
	 * pass, a select/radio value must be one of the field's options, and a number or
	 * currency field is what the payer typed, which is the point of those fields.
	 *
	 * @param int                  $form_id  The form being paid for.
	 * @param string               $variable Slug of the field the amount follows.
	 * @param array<string, mixed> $data     Submitted values keyed by input name.
	 */
	public function resolve( int $form_id, string $variable, array $data ): ?float {
		$form = class_exists( Forminator_CForm_Front_Action::class ) ? FieldSettingsStore::load_model( $form_id ) : null;

		if ( ! $form instanceof Forminator_Form_Model ) {
			return null;
		}

		$settings = self::field_settings( $form, $variable );

		if ( null === $settings ) {
			return null;
		}

		// The same visibility + calculation pass Forminator's PayPal field prices from.
		Forminator_CForm_Front_Action::$module_id       = $form_id;
		Forminator_CForm_Front_Action::$module_object   = $form;
		Forminator_CForm_Front_Action::$module_settings = $form->get_form_settings();
		Forminator_CForm_Front_Action::$prepared_data   = $data;
		Forminator_CForm_Front_Action::check_fields_visibility();

		$type  = (string) ( $settings['type'] ?? '' );
		$value = Forminator_CForm_Front_Action::$prepared_data[ $variable ] ?? null;

		// A hidden calculation is skipped by that pass, so its value would still be the posted one.
		if ( 'calculation' === $type && in_array( $variable, Forminator_CForm_Front_Action::$hidden_fields, true ) ) {
			return null;
		}

		// A multi-select charges its first choice, same as the frontend reads it.
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! self::is_option( $settings, $value ) ) {
			return null;
		}

		if ( in_array( $type, array( 'number', 'currency' ), true ) ) {
			$value = (string) Forminator_Field::forminator_replace_number( $settings, $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * A field's formatted settings by slug.
	 *
	 * @param Forminator_Form_Model $form The form.
	 * @param string                $slug The field slug.
	 * @return array<string, mixed>|null
	 */
	private static function field_settings( Forminator_Form_Model $form, string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $form->get_fields() as $field ) {
			if ( $field->slug === $slug ) {
				return $field->to_formatted_array();
			}
		}

		return null;
	}

	/**
	 * Whether a value is one of a select/radio field's configured options.
	 *
	 * @param array<string, mixed> $settings The field's formatted settings.
	 * @param string               $value    The submitted value.
	 */
	private static function is_option( array $settings, string $value ): bool {
		$options = is_array( $settings['options'] ?? null ) ? $settings['options'] : array();

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			// Forminator falls back to the label when an option has no value.
			$option_value = $option['value'] ?? $option['label'] ?? '';

			if ( is_scalar( $option_value ) && (string) $option_value === $value ) {
				return true;
			}
		}

		return false;
	}
}
