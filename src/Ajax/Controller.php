<?php
/**
 * Admin settings AJAX router.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Ajax;

use Ifthenpay\Forminator\Admin\GatewaySettingsField;
use Ifthenpay\Forminator\Api\IfthenpayClient;
use Ifthenpay\Forminator\Api\Webhook\WebhookController;
use Ifthenpay\Forminator\Forminator\FieldSettingsStore;
use Ifthenpay\Forminator\Mail\IfthenpayEmailHelper;
use Ifthenpay\Forminator\Repository\SettingsRepository;
use Ifthenpay\Forminator\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Router for the settings screen's admin AJAX calls — connecting/
 * disconnecting the Backoffice Key, switching the Gateway Key, saving the
 * Gateway Configuration screen, requesting method activation, and loading/
 * saving one field's settings from the builder-canvas popup (see
 * `Admin\FieldSettingsModal`).
 */
class Controller {

	private const NONCE_ACTION = 'iftp_fm_admin';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Gateway catalog sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Methods table renderer.
	 *
	 * @var GatewaySettingsField
	 */
	private GatewaySettingsField $methods_field;

	/**
	 * Per-field ifthenpay settings storage.
	 *
	 * @var FieldSettingsStore
	 */
	private FieldSettingsStore $field_settings;

	/**
	 * Sets up the router.
	 *
	 * @param SettingsRepository|null   $settings       Settings storage; a fresh instance is used when omitted.
	 * @param IfthenpayClient|null      $client         Ifthenpay API client; a fresh instance is used when omitted.
	 * @param GatewaySettingsField|null $methods_field  Methods table renderer; a fresh instance is used when omitted.
	 * @param GatewaySync|null          $sync           Gateway catalog sync; a fresh instance is used when omitted.
	 * @param FieldSettingsStore|null   $field_settings Per-field settings storage; a fresh instance is used when omitted.
	 */
	public function __construct(
		?SettingsRepository $settings = null,
		?IfthenpayClient $client = null,
		?GatewaySettingsField $methods_field = null,
		?GatewaySync $sync = null,
		?FieldSettingsStore $field_settings = null
	) {
		$this->settings       = $settings ?? new SettingsRepository();
		$this->client         = $client ?? new IfthenpayClient();
		$this->methods_field  = $methods_field ?? new GatewaySettingsField();
		$this->sync           = $sync ?? new GatewaySync( $this->settings, $this->client );
		$this->field_settings = $field_settings ?? new FieldSettingsStore();
	}

	/**
	 * Registers every AJAX action this router handles.
	 */
	public function register(): void {
		add_action( 'wp_ajax_iftp_fm_connect_backoffice', array( $this, 'connect_backoffice' ) );
		add_action( 'wp_ajax_iftp_fm_disconnect_backoffice', array( $this, 'disconnect_backoffice' ) );
		add_action( 'wp_ajax_iftp_fm_select_gateway_key', array( $this, 'select_gateway_key' ) );
		add_action( 'wp_ajax_iftp_fm_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_iftp_fm_request_activation', array( $this, 'request_activation' ) );
		add_action( 'wp_ajax_iftp_fm_get_field_settings', array( $this, 'get_field_settings' ) );
		add_action( 'wp_ajax_iftp_fm_save_field_settings', array( $this, 'save_field_settings' ) );
	}

	/**
	 * Connects a Backoffice Key: validates its format, then its Forminator gateway.
	 */
	public function connect_backoffice(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$backoffice_key = sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Backoffice Key format.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		if ( ! $this->sync->connect( $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'No Forminator gateway found for this Backoffice Key.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->register_webhook( $this->settings->get_gateway_key() );

		wp_send_json_success(
			array(
				'redirect' => add_query_arg(
					array(
						'page'    => 'forminator-settings',
						'section' => 'GatewayConfiguration',
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	/**
	 * Disconnects the Backoffice Key, wiping every stored ifthenpay setting.
	 */
	public function disconnect_backoffice(): void {
		$this->guard();

		$this->settings->delete_all();

		wp_send_json_success();
	}

	/**
	 * Switches the active Gateway Key and re-renders the methods table for it.
	 */
	public function select_gateway_key(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$gateway_key = sanitize_text_field( wp_unslash( $_POST['gateway_key'] ?? '' ) );

		if ( ! $this->sync->switch_gateway_key( $gateway_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Gateway Key.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->register_webhook( $gateway_key );

		wp_send_json_success( array( 'table_html' => $this->methods_field->render() ) );
	}

	/**
	 * Saves the whole Gateway Configuration screen in one call.
	 */
	public function save_settings(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$gateway_key = sanitize_text_field( wp_unslash( $_POST['gateway_key'] ?? '' ) );

		if ( '' !== $gateway_key && $gateway_key !== $this->settings->get_gateway_key() ) {
			$this->sync->switch_gateway_key( $gateway_key );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$enabled_entities = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['enabled_methods'] ?? array() ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$default_method = sanitize_text_field( wp_unslash( $_POST['default_method'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$description = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$expiry_days = absint( wp_unslash( $_POST['expiry_days'] ?? 3 ) );

		$methods = $this->settings->get_methods();

		foreach ( $methods as &$method ) {
			$method['enabled'] = in_array( $method['entity'], $enabled_entities, true ) && '' !== $method['account'];
		}
		unset( $method );

		if ( ! in_array( $default_method, array_column( array_filter( $methods, static fn ( $m ) => $m['enabled'] ), 'entity' ), true ) ) {
			$default_method = '';
		}

		$this->settings->set_methods( $methods );
		$this->settings->set_default_method( $default_method );
		$this->settings->set_description( $description );
		$this->settings->set_expiry_days( $expiry_days );

		$this->save_confirmation_fields();

		$this->register_webhook( $this->settings->get_gateway_key() );

		wp_send_json_success( array( 'table_html' => $this->methods_field->render() ) );
	}

	/**
	 * Messages are sanitized with wp_kses_post() rather than
	 * sanitize_textarea_field() — the saved value is rendered as HTML in the
	 * frontend popup. Titles are plain text (sanitize_text_field()) since
	 * they're rendered as a heading, not HTML.
	 */
	private function save_confirmation_fields(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
		$paid_type = sanitize_text_field( wp_unslash( $_POST['paid_type'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
		$paid_page_id = absint( wp_unslash( $_POST['paid_page_id'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
		$paid_url = esc_url_raw( wp_unslash( $_POST['paid_url'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
		$show_entry_data = ! empty( $_POST['show_entry_data'] );

		$this->settings->set_paid_confirmation_type( $paid_type );
		$this->settings->set_paid_confirmation_page_id( $paid_page_id );
		$this->settings->set_paid_confirmation_url( $paid_url );
		$this->settings->set_show_entry_data( $show_entry_data );

		foreach ( array( 'paid', 'pending', 'failed', 'cancelled' ) as $status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
			$title = sanitize_text_field( wp_unslash( $_POST[ $status . '_title' ] ?? '' ) );
			$this->settings->set_confirmation_title( $status, $title );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by the caller (save_settings()) via guard()'s check_ajax_referer() call.
			$message = wp_kses_post( wp_unslash( $_POST[ $status . '_message' ] ?? '' ) );
			$this->settings->set_confirmation_message( $status, $message );
		}
	}

	/**
	 * Emails ifthenpay support to request activation of a not-yet-provisioned method.
	 */
	public function request_activation(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$entity      = strtoupper( sanitize_text_field( wp_unslash( $_POST['entity'] ?? '' ) ) );
		$gateway_key = $this->settings->get_gateway_key();

		if ( '' === $entity || '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing method or Gateway Key.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$cooldown_key = 'iftp_fm_activation_' . md5( $gateway_key . '|' . $entity );

		if ( get_transient( $cooldown_key ) ) {
			wp_send_json_error( array( 'message' => __( 'An activation request for this method was already sent recently.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'        => $gateway_key,
				'entity'             => $entity,
				'backoffice_key'     => $this->settings->get_backoffice_key(),
				'customer_email'     => wp_get_current_user()->user_email,
				'site_url'           => home_url( '/' ),
				'site_name'          => get_bloginfo( 'name' ),
				'wp_version'         => get_bloginfo( 'version' ),
				'forminator_version' => defined( 'FORMINATOR_VERSION' ) ? FORMINATOR_VERSION : '',
				'plugin_version'     => defined( 'IFTP_FM_VERSION' ) ? IFTP_FM_VERSION : '',
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Could not send the activation request. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		set_transient( $cooldown_key, true, DAY_IN_SECONDS );

		wp_send_json_success();
	}

	/**
	 * Loads one ifthenpay field's settings for the builder-canvas popup —
	 * its own amount/label config, plus the other fields on its form as
	 * variable-amount candidates.
	 */
	public function get_field_settings(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$form_id = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$field_slug = sanitize_text_field( wp_unslash( $_POST['field_slug'] ?? '' ) );

		$settings = $this->field_settings->get( $form_id, $field_slug );

		if ( null === $settings ) {
			wp_send_json_error( array( 'message' => __( 'Save the form once first, then configure this field.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		wp_send_json_success( $settings );
	}

	/**
	 * Saves one ifthenpay field's settings from the builder-canvas popup.
	 */
	public function save_field_settings(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$form_id = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$field_slug = sanitize_text_field( wp_unslash( $_POST['field_slug'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$amount_type = sanitize_text_field( wp_unslash( $_POST['amount_type'] ?? 'fixed' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$amount = sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$variable = sanitize_text_field( wp_unslash( $_POST['variable'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$checkout_label = sanitize_text_field( wp_unslash( $_POST['checkout_label'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$field_label = sanitize_text_field( wp_unslash( $_POST['field_label'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$description_placement = sanitize_text_field( wp_unslash( $_POST['description_placement'] ?? 'below' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$css_class = sanitize_text_field( wp_unslash( $_POST['css_class'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$hide_icon = '1' === sanitize_text_field( wp_unslash( $_POST['hide_icon'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$hide_methods = '1' === sanitize_text_field( wp_unslash( $_POST['hide_methods'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$condition_action = sanitize_text_field( wp_unslash( $_POST['condition_action'] ?? 'show' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above via guard()'s check_ajax_referer() call.
		$condition_rule = sanitize_text_field( wp_unslash( $_POST['condition_rule'] ?? 'all' ) );
		$conditions     = $this->parse_conditions();

		$saved = $this->field_settings->save( $form_id, $field_slug, $amount_type, $amount, $variable, $checkout_label, $field_label, $description, $description_placement, $css_class, $hide_icon, $hide_methods, $condition_action, $condition_rule, $conditions );

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the field settings. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * Reads the conditional-logic rows the popup posts as `conditions[<i>][element_id|rule|value]`
	 * — PHP parses that bracket notation into a nested array on its own, the
	 * same convention `assets/js/frontend.js` already uses for `fields[...]`.
	 * `FieldSettingsStore::save()` does the real validation (operator
	 * vocabulary, dropping incomplete rows); this only untaints the raw shape.
	 *
	 * @return array<int, array{element_id: string, rule: string, value: string}>
	 */
	private function parse_conditions(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already checked above via guard()'s check_ajax_referer() call; each row's values are sanitized individually in the loop below.
		$raw        = wp_unslash( (array) ( $_POST['conditions'] ?? array() ) );
		$conditions = array();

		foreach ( $raw as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$conditions[] = array(
				'element_id' => sanitize_text_field( $condition['element_id'] ?? '' ),
				'rule'       => sanitize_text_field( $condition['rule'] ?? '' ),
				'value'      => sanitize_text_field( $condition['value'] ?? '' ),
			);
		}

		return $conditions;
	}

	/**
	 * Verifies the request nonce and the settings-page capability. Every public
	 * handler above calls this first.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}
	}

	/**
	 * Re-registers the ifthenpay webhook callback for the given Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key to register the webhook for.
	 */
	private function register_webhook( string $gateway_key ): void {
		if ( '' === $gateway_key ) {
			return;
		}

		$this->client->activate_callback( $gateway_key, WebhookController::url() );
	}
}
