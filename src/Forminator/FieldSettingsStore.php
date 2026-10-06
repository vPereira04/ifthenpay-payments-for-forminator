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
 * Reads and writes the ifthenpay field's settings through Forminator's form model, like the builder's Save.
 */
class FieldSettingsStore {

	private const NON_VALUE_FIELD_TYPES = array( 'html', 'page-break', 'section', 'submit', 'group', 'ifthenpay' );

	/**
	 * The operators front.condition.js understands. I drop anything else on save, since it would never match.
	 */
	private const CONDITION_OPERATORS = array( 'is', 'is_not', 'is_great', 'is_less', 'contains', 'does_not_contain', 'starts', 'ends' );

	/**
	 * Form models already loaded this request, by form id.
	 *
	 * @var array<int, Forminator_Form_Model|null>
	 */
	private static array $models = array();

	/**
	 * One ifthenpay field's settings plus the form's other fields, or null if the form/field isn't saved yet.
	 *
	 * @param int    $form_id    The form the field belongs to.
	 * @param string $field_slug The field's slug (its `element_id`).
	 * @return array{amount_type: string, amount: string, variable: string, checkout_label: string, field_label: string, description: string, description_placement: string, css_class: string, hide_icon: string, hide_methods: string, condition_action: string, condition_rule: string, conditions: array<int, array{element_id: string, rule: string, value: string}>, other_fields: array<int, array{slug: string, label: string}>}|null
	 */
	public function get( int $form_id, string $field_slug ): ?array {
		$model = self::load_model( $form_id );

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
	 * @param int                  $form_id    The form the field belongs to.
	 * @param string               $field_slug The field's slug (its `element_id`).
	 * @param array<string, mixed> $settings   Raw popup values.
	 */
	public function save( int $form_id, string $field_slug, array $settings ): bool {
		$model = self::load_model( $form_id );

		if ( null === $model ) {
			return false;
		}

		$field = $this->find_field( $model, $field_slug );

		if ( null === $field ) {
			return false;
		}

		$text        = static fn( string $key, string $fallback = '' ): string => is_scalar( $settings[ $key ] ?? null ) ? (string) $settings[ $key ] : $fallback;
		$amount_type = $text( 'amount_type', 'fixed' );
		$label       = $text( 'checkout_label' );
		$conditions  = $this->valid_conditions( is_array( $settings['conditions'] ?? null ) ? $settings['conditions'] : array() );
		$action      = $text( 'condition_action', 'show' );
		$rule        = $text( 'condition_rule', 'all' );

		$field->__set( 'amount_type', in_array( $amount_type, array( 'fixed', 'variable' ), true ) ? $amount_type : 'fixed' );
		$field->__set( 'amount', number_format( (float) $text( 'amount', '0' ), 2, '.', '' ) );
		$field->__set( 'variable', $text( 'variable' ) );
		$field->__set( 'checkout_label', '' !== $label ? $label : __( 'Pay now', 'ifthenpay-payments-for-forminator' ) );
		$field->__set( 'field_label', $text( 'field_label' ) );
		$field->__set( 'description', $text( 'description' ) );
		$field->__set( 'description_placement', $this->valid_description_placement( $text( 'description_placement' ) ) );
		$field->__set( 'css_class', $text( 'css_class' ) );
		$field->__set( 'hide_icon', ! empty( $settings['hide_icon'] ) ? '1' : '' );
		$field->__set( 'hide_methods', ! empty( $settings['hide_methods'] ) ? '1' : '' );
		$field->__set( 'condition_action', array() !== $conditions && in_array( $action, array( 'show', 'hide' ), true ) ? $action : 'show' );
		$field->__set( 'condition_rule', array() !== $conditions && in_array( $rule, array( 'all', 'any' ), true ) ? $rule : 'all' );
		$field->__set( 'conditions', $conditions );

		// Has to be this same model instance — a fresh load would save the old values.
		$model->save();

		return true;
	}

	/**
	 * The amount config a payment is charged from, read server-side so the browser can't set a fixed price.
	 * An empty slug means the first ifthenpay field.
	 *
	 * @param int    $form_id    The form the payment belongs to.
	 * @param string $field_slug The ifthenpay field's slug (its `element_id`), or ''.
	 * @return array{amount_type: string, amount: float, variable: string}|null
	 */
	public function payment_config( int $form_id, string $field_slug ): ?array {
		$model = self::load_model( $form_id );

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
		$model = self::load_model( $form_id );

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
	 * The element_ids of every field on a form that can carry a submitted value.
	 *
	 * @param int $form_id The form to look in.
	 * @return string[]
	 */
	public function value_field_slugs( int $form_id ): array {
		$model = self::load_model( $form_id );

		return null === $model ? array() : array_column( $this->other_fields( $model ), 'slug' );
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
	 * 'above' or 'below', falling back to 'below'.
	 *
	 * @param string $value Raw placement value.
	 */
	private function valid_description_placement( string $value ): string {
		return in_array( $value, array( 'above', 'below' ), true ) ? $value : 'below';
	}

	/**
	 * Drops conditions with no element_id or an unknown operator.
	 *
	 * @param array<mixed> $conditions Raw conditions, from the request or the stored field.
	 * @return array<int, array{element_id: string, rule: string, value: string}>
	 */
	private function valid_conditions( array $conditions ): array {
		$valid = array();

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

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
	 * The field's stored conditions.
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
	 * The form's value-carrying fields as `{slug, label}`, for the "Amount field" dropdown.
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
	 * Loads a form model once per request, or null when it doesn't exist.
	 * A payment request reads the same form for its fields, amount and variable price, so I share one copy.
	 *
	 * @param int $form_id The form to load.
	 */
	public static function load_model( int $form_id ): ?Forminator_Form_Model {
		if ( $form_id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $form_id, self::$models ) ) {
			$model = Forminator_Form_Model::model()->load( $form_id );

			self::$models[ $form_id ] = $model instanceof Forminator_Form_Model ? $model : null;
		}

		return self::$models[ $form_id ];
	}

	/**
	 * Finds an ifthenpay field by slug, so the popup can't edit any other field.
	 * I match by hand: a `::load()` model indexes fields numerically, so `get_field( $slug )` misses.
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
