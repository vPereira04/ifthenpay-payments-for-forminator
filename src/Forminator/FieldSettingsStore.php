<?php
/**
 * Per-field ifthenpay settings storage.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Forminator;

use Forminator_Form_Field_Model;
use Forminator_Form_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `amount_type`/`amount`/`variable`/`checkout_label`
 * properties `Fields\IfthenpayField::markup()` renders, straight through
 * Forminator's own `Forminator_Form_Model`/`Forminator_Form_Field_Model` API
 * — the same persistence path the builder's own "Save" button uses
 * internally. Used by `Ajax\Controller`'s builder-popup endpoints; see
 * `Admin\FieldSettingsModal`'s docblock for why a popup is the only option.
 */
class FieldSettingsStore {

	/**
	 * Field types that never carry a submitted value, so they're pointless
	 * as a "variable" amount source and are left out of that dropdown.
	 */
	private const NON_VALUE_FIELD_TYPES = array( 'html', 'page-break', 'section', 'submit', 'group', 'ifthenpay' );

	/**
	 * Operators `front.condition.js` (Forminator core, non-date fields)
	 * understands — anything else is dropped rather than saved, since a
	 * client-side operator string with no matching `case` there silently
	 * evaluates to `false` forever.
	 */
	private const CONDITION_OPERATORS = array( 'is', 'is_not', 'is_great', 'is_less', 'contains', 'does_not_contain', 'starts', 'ends' );

	/**
	 * The current settings for one ifthenpay field, plus the other fields on
	 * its form available as a variable-amount source — or null when the
	 * form/field doesn't exist (e.g. the form was never saved after the
	 * field was dragged onto the canvas).
	 *
	 * @param int    $form_id    The form the field belongs to.
	 * @param string $field_slug The field's slug (its `element_id`).
	 * @return array{amount_type: string, amount: string, variable: string, checkout_label: string, field_label: string, description: string, description_placement: string, css_class: string, hide_icon: string, hide_methods: string, condition_action: string, condition_rule: string, conditions: array<int, array{element_id: string, rule: string, value: string}>, other_fields: array<int, array{slug: string, label: string}>}|null
	 */
	public function get( int $form_id, string $field_slug ): ?array {
		$model = $this->load_model( $form_id );

		if ( null === $model ) {
			return null;
		}

		$field = $this->find_field( $model, $field_slug );

		if ( null === $field ) {
			return null;
		}

		return array(
			'amount_type'           => $this->field_property( $field, 'amount_type', 'fixed' ),
			'amount'                => $this->field_property( $field, 'amount', '0' ),
			'variable'              => $this->field_property( $field, 'variable', '' ),
			'checkout_label'        => $this->field_property( $field, 'checkout_label', __( 'Pay now', 'ifthenpay-payments-for-forminator' ) ),
			'field_label'           => $this->field_property( $field, 'field_label', '' ),
			'description'           => $this->field_property( $field, 'description', '' ),
			'description_placement' => $this->valid_description_placement( (string) $field->__get( 'description_placement' ) ),
			'css_class'             => $this->field_property( $field, 'css_class', '' ),
			'hide_icon'             => $this->field_property( $field, 'hide_icon', '' ),
			'hide_methods'          => $this->field_property( $field, 'hide_methods', '' ),
			'condition_action'      => $this->field_property( $field, 'condition_action', 'show' ),
			'condition_rule'        => $this->field_property( $field, 'condition_rule', 'all' ),
			'conditions'            => $this->field_conditions( $field ),
			'other_fields'          => $this->other_fields( $model ),
		);
	}

	/**
	 * Persists one field's settings. Returns false when the form/field can't be found.
	 *
	 * @param int                                                                $form_id               The form the field belongs to.
	 * @param string                                                             $field_slug            The field's slug (its `element_id`).
	 * @param string                                                             $amount_type           One of 'fixed'/'variable'.
	 * @param string                                                             $amount                The fixed amount.
	 * @param string                                                             $variable              The referenced field's slug, when amount_type is 'variable'.
	 * @param string                                                             $checkout_label        The checkout button label.
	 * @param string                                                             $field_label           The field's admin-facing label, shown above the checkout button.
	 * @param string                                                             $description           The field's admin-facing description, shown below the label.
	 * @param string                                                             $description_placement One of 'above'/'below' — where description renders relative to field_label.
	 * @param string                                                             $css_class             Extra class name(s) for the field's wrapper.
	 * @param bool                                                               $hide_icon             Whether to omit the ifthenpay glyph from the field's visual box.
	 * @param bool                                                               $hide_methods          Whether to omit the payment method icons from the field's visual box.
	 * @param string                                                             $condition_action      One of 'show'/'hide' — what happens when the conditions below match.
	 * @param string                                                             $condition_rule        One of 'all'/'any' — whether every condition or just one must match.
	 * @param array<int, array{element_id: string, rule: string, value: string}> $conditions The conditional-logic rules, same shape Forminator's own fields use.
	 */
	public function save(
		int $form_id,
		string $field_slug,
		string $amount_type,
		string $amount,
		string $variable,
		string $checkout_label,
		string $field_label,
		string $description,
		string $description_placement,
		string $css_class,
		bool $hide_icon,
		bool $hide_methods,
		string $condition_action,
		string $condition_rule,
		array $conditions
	): bool {
		$model = $this->load_model( $form_id );

		if ( null === $model ) {
			return false;
		}

		$field = $this->find_field( $model, $field_slug );

		if ( null === $field ) {
			return false;
		}

		$field->__set( 'amount_type', in_array( $amount_type, array( 'fixed', 'variable' ), true ) ? $amount_type : 'fixed' );
		$field->__set( 'amount', number_format( (float) $amount, 2, '.', '' ) );
		$field->__set( 'variable', $variable );
		$field->__set( 'checkout_label', '' !== $checkout_label ? $checkout_label : __( 'Pay now', 'ifthenpay-payments-for-forminator' ) );
		$field->__set( 'field_label', $field_label );
		$field->__set( 'description', $description );
		$field->__set( 'description_placement', $this->valid_description_placement( $description_placement ) );
		$field->__set( 'css_class', $css_class );
		$field->__set( 'hide_icon', $hide_icon ? '1' : '' );
		$field->__set( 'hide_methods', $hide_methods ? '1' : '' );

		$valid_conditions = $this->valid_conditions( $conditions );

		$field->__set( 'condition_action', array() !== $valid_conditions && in_array( $condition_action, array( 'show', 'hide' ), true ) ? $condition_action : 'show' );
		$field->__set( 'condition_rule', array() !== $valid_conditions && in_array( $condition_rule, array( 'all', 'any' ), true ) ? $condition_rule : 'all' );
		$field->__set( 'conditions', $valid_conditions );

		// Same model instance the mutated $field object belongs to —
		// re-loading a fresh one here would save the pre-mutation data instead.
		$model->save();

		return true;
	}

	/**
	 * The amount config the payment is charged from, read server-side so the
	 * browser can't pick its own price — or null when the form has no such
	 * ifthenpay field. An empty slug falls back to the form's first ifthenpay field.
	 *
	 * @param int    $form_id    The form the payment belongs to.
	 * @param string $field_slug The ifthenpay field's slug (its `element_id`), or ''.
	 * @return array{amount_type: string, amount: float, variable: string}|null
	 */
	public function payment_config( int $form_id, string $field_slug ): ?array {
		$model = $this->load_model( $form_id );

		if ( null === $model ) {
			return null;
		}

		$field = '' !== $field_slug ? $this->find_field( $model, $field_slug ) : $this->first_ifthenpay_field( $model );

		if ( null === $field ) {
			return null;
		}

		return array(
			'amount_type' => 'variable' === $this->field_property( $field, 'amount_type', 'fixed' ) ? 'variable' : 'fixed',
			'amount'      => (float) $this->field_property( $field, 'amount', '0' ),
			'variable'    => $this->field_property( $field, 'variable', '' ),
		);
	}

	/**
	 * The element_ids of every ifthenpay field on a form.
	 *
	 * @param int $form_id The form to look in.
	 * @return string[]
	 */
	public function ifthenpay_field_slugs( int $form_id ): array {
		$model = $this->load_model( $form_id );

		if ( null === $model ) {
			return array();
		}

		$slugs = array();

		foreach ( $model->get_fields() as $field ) {
			if ( 'ifthenpay' === (string) $field->__get( 'type' ) ) {
				$slugs[] = $field->slug;
			}
		}

		return $slugs;
	}

	/**
	 * The form's first ifthenpay field, or null.
	 *
	 * @param Forminator_Form_Model $model The loaded form model.
	 */
	private function first_ifthenpay_field( Forminator_Form_Model $model ): ?Forminator_Form_Field_Model {
		foreach ( $model->get_fields() as $field ) {
			if ( 'ifthenpay' === (string) $field->__get( 'type' ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Falls back to 'below' for anything other than the two placements
	 * `IfthenpayField::markup()` understands.
	 *
	 * @param string $value Raw placement value.
	 */
	private function valid_description_placement( string $value ): string {
		return in_array( $value, array( 'above', 'below' ), true ) ? $value : 'below';
	}

	/**
	 * Drops any condition missing an element_id/operator, or carrying an
	 * operator `front.condition.js` doesn't implement — see
	 * CONDITION_OPERATORS.
	 *
	 * @param array<int, array{element_id: string, rule: string, value: string}> $conditions Raw conditions from the request.
	 * @return array<int, array{element_id: string, rule: string, value: string}>
	 */
	private function valid_conditions( array $conditions ): array {
		$valid = array();

		foreach ( $conditions as $condition ) {
			$element_id = (string) ( $condition['element_id'] ?? '' );
			$rule       = (string) ( $condition['rule'] ?? '' );

			if ( '' === $element_id || ! in_array( $rule, self::CONDITION_OPERATORS, true ) ) {
				continue;
			}

			$valid[] = array(
				'element_id' => $element_id,
				'rule'       => $rule,
				'value'      => (string) ( $condition['value'] ?? '' ),
			);
		}

		return $valid;
	}

	/**
	 * Reads the field's raw `conditions` property, in the same
	 * `{element_id, rule, value}` shape `valid_conditions()` writes.
	 *
	 * @param Forminator_Form_Field_Model $field The field to read from.
	 * @return array<int, array{element_id: string, rule: string, value: string}>
	 */
	private function field_conditions( Forminator_Form_Field_Model $field ): array {
		$raw = $field->__get( 'conditions' );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $this->valid_conditions( $raw );
	}

	/**
	 * The other fields on a form, excluding payment-irrelevant types, as a
	 * `{slug, label}` list for the "Amount field" dropdown.
	 *
	 * @param Forminator_Form_Model $model The form to read fields from.
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function other_fields( Forminator_Form_Model $model ): array {
		$fields = array();

		foreach ( $model->get_fields() as $field ) {
			$type = (string) $field->__get( 'type' );

			if ( in_array( $type, self::NON_VALUE_FIELD_TYPES, true ) ) {
				continue;
			}

			$fields[] = array(
				'slug'  => (string) $field->slug,
				'label' => $this->field_property( $field, 'field_label', (string) $field->slug ),
			);
		}

		return $fields;
	}

	/**
	 * Loads a form model, or null when it doesn't exist (e.g. the form was
	 * never saved after the field was dragged onto the canvas).
	 *
	 * @param int $form_id The form to load.
	 */
	private function load_model( int $form_id ): ?Forminator_Form_Model {
		if ( $form_id <= 0 ) {
			return null;
		}

		$model = Forminator_Form_Model::model()->load( $form_id );

		return $model instanceof Forminator_Form_Model ? $model : null;
	}

	/**
	 * Finds one of a loaded model's fields by slug.
	 *
	 * I don't trust `Forminator_Base_Form_Model::get_field()`'s own
	 * slug-keyed lookup here — a model loaded via `::load()` appends fields
	 * with a numeric index, not by slug (see `add_field()`'s
	 * `$this->fields[] = $field`), so `get_field($slug)` silently misses.
	 * Matching by slug myself instead. Only ifthenpay fields match, so the
	 * popup endpoints can't be pointed at some other field on the form.
	 *
	 * @param Forminator_Form_Model $model      The loaded form model.
	 * @param string                $field_slug The field slug to find.
	 */
	private function find_field( Forminator_Form_Model $model, string $field_slug ): ?Forminator_Form_Field_Model {
		if ( '' === $field_slug ) {
			return null;
		}

		foreach ( $model->get_fields() as $field ) {
			if ( $field->slug === $field_slug && 'ifthenpay' === (string) $field->__get( 'type' ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Reads a raw field property, falling back to a default when it's unset or empty.
	 *
	 * @param Forminator_Form_Field_Model $field    The field to read from.
	 * @param string                      $property The property name.
	 * @param string                      $fallback Returned when the property is missing or ''.
	 */
	private function field_property( Forminator_Form_Field_Model $field, string $property, string $fallback ): string {
		$value = (string) ( $field->__get( $property ) ?? '' );

		return '' !== $value ? $value : $fallback;
	}
}
