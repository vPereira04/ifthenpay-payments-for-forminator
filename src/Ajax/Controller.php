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
use Ifthenpay\Forminator\Api\Webhook\WebhookRegistrar;
use Ifthenpay\Forminator\Forminator\FieldSettingsStore;
use Ifthenpay\Forminator\Mail\IfthenpayEmailHelper;
use Ifthenpay\Forminator\Plugin;
use Ifthenpay\Forminator\Repository\SettingsRepository;
use Ifthenpay\Forminator\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX for the settings screens and the builder's field popup.
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
	 * Webhook callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $webhooks;

	/**
	 * Sets up the router.
	 *
	 * @param SettingsRepository|null   $settings       Settings storage.
	 * @param IfthenpayClient|null      $client         Ifthenpay API client.
	 * @param GatewaySettingsField|null $methods_field  Methods table renderer.
	 * @param GatewaySync|null          $sync           Gateway catalog sync.
	 * @param FieldSettingsStore|null   $field_settings Per-field settings storage.
	 * @param WebhookRegistrar|null     $webhooks       Webhook callback registration.
	 */
	public function __construct(
		?SettingsRepository $settings = null,
		?IfthenpayClient $client = null,
		?GatewaySettingsField $methods_field = null,
		?GatewaySync $sync = null,
		?FieldSettingsStore $field_settings = null,
		?WebhookRegistrar $webhooks = null
	) {
		$this->settings       = $settings ?? new SettingsRepository();
		$this->client         = $client ?? new IfthenpayClient();
		$this->methods_field  = $methods_field ?? new GatewaySettingsField();
		$this->sync           = $sync ?? new GatewaySync( $this->settings, $this->client );
		$this->field_settings = $field_settings ?? new FieldSettingsStore();
		$this->webhooks       = $webhooks ?? new WebhookRegistrar( $this->settings, $this->client );
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

		$backoffice_key = $this->post_text( 'backoffice_key' );

		if ( ! preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Backoffice Key format.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		if ( ! $this->sync->connect( $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'No Forminator gateway found for this Backoffice Key.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->webhooks->register_webhook( $this->settings->get_gateway_key() );

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
		delete_transient( IfthenpayClient::GATEWAY_ROWS_TRANSIENT );

		wp_send_json_success();
	}

	/**
	 * Switches the active Gateway Key and re-renders the methods table for it.
	 */
	public function select_gateway_key(): void {
		$this->guard();

		$gateway_key = $this->post_text( 'gateway_key' );

		if ( ! $this->sync->switch_gateway_key( $gateway_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Gateway Key.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		$this->webhooks->register_webhook( $gateway_key );

		wp_send_json_success( array( 'table_html' => $this->methods_field->render() ) );
	}

	/**
	 * Saves the whole Gateway Configuration screen in one call.
	 */
	public function save_settings(): void {
		$this->guard();

		$gateway_key = $this->post_text( 'gateway_key' );

		if ( '' !== $gateway_key && $gateway_key !== $this->settings->get_gateway_key() ) {
			$this->sync->switch_gateway_key( $gateway_key );
		}

		$enabled_entities = $this->post_list( 'enabled_methods' );
		$default_method   = $this->post_text( 'default_method' );
		$methods          = $this->settings->get_methods();

		foreach ( $methods as &$method ) {
			$method['enabled'] = in_array( $method['entity'], $enabled_entities, true ) && '' !== $method['account'];
		}
		unset( $method );

		if ( ! in_array( $default_method, array_column( array_filter( $methods, static fn ( $m ) => $m['enabled'] ), 'entity' ), true ) ) {
			$default_method = '';
		}

		$this->settings->set_methods( $methods );
		$this->settings->set_default_method( $default_method );
		$this->settings->set_description( $this->post_text( 'description' ) );
		$this->settings->set_expiry_days( absint( $this->post_text( 'expiry_days', '3' ) ) );

		$this->save_confirmation_fields();

		$this->webhooks->register_webhook( $this->settings->get_gateway_key() );

		wp_send_json_success( array( 'table_html' => $this->methods_field->render() ) );
	}

	/**
	 * Saves the Confirmation Type panel. The popup shows these as plain text, so I store them that way.
	 */
	private function save_confirmation_fields(): void {
		$this->settings->set_paid_confirmation_type( $this->post_text( 'paid_type' ) );
		$this->settings->set_paid_confirmation_page_id( absint( $this->post_text( 'paid_page_id' ) ) );
		$this->settings->set_paid_confirmation_url( esc_url_raw( $this->post_text( 'paid_url' ) ) );
		$this->settings->set_show_entry_data( '' !== $this->post_text( 'show_entry_data' ) );

		foreach ( SettingsRepository::CONFIRMATION_STATUSES as $status ) {
			$this->settings->set_confirmation_title( $status, $this->post_text( $status . '_title' ) );
			$this->settings->set_confirmation_message( $status, $this->post_textarea( $status . '_message' ) );
		}
	}

	/**
	 * Emails ifthenpay support to request activation of a not-yet-provisioned method.
	 */
	public function request_activation(): void {
		$this->guard();

		$entity      = strtoupper( $this->post_text( 'entity' ) );
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
				'plugin_version'     => IFTP_FM_VERSION,
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Could not send the activation request. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		set_transient( $cooldown_key, true, DAY_IN_SECONDS );

		wp_send_json_success();
	}

	/**
	 * Loads one ifthenpay field's settings for the builder popup, plus the form's
	 * other fields as variable-amount candidates.
	 */
	public function get_field_settings(): void {
		$this->guard();

		$settings = $this->field_settings->get( absint( $this->post_text( 'form_id' ) ), $this->post_text( 'field_slug' ) );

		if ( null === $settings ) {
			wp_send_json_error( array( 'message' => __( 'Save the form once first, then configure this field.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		wp_send_json_success( $settings );
	}

	/**
	 * Saves one ifthenpay field's settings from the builder popup.
	 */
	public function save_field_settings(): void {
		$this->guard();

		$saved = $this->field_settings->save(
			absint( $this->post_text( 'form_id' ) ),
			$this->post_text( 'field_slug' ),
			array(
				'amount_type'           => $this->post_text( 'amount_type', 'fixed' ),
				'amount'                => $this->post_text( 'amount', '0' ),
				'variable'              => $this->post_text( 'variable' ),
				'checkout_label'        => $this->post_text( 'checkout_label' ),
				'field_label'           => $this->post_text( 'field_label' ),
				'description'           => $this->post_textarea( 'description' ),
				'description_placement' => $this->post_text( 'description_placement', 'below' ),
				'css_class'             => $this->post_text( 'css_class' ),
				'hide_icon'             => '1' === $this->post_text( 'hide_icon' ),
				'hide_methods'          => '1' === $this->post_text( 'hide_methods' ),
				'condition_action'      => $this->post_text( 'condition_action', 'show' ),
				'condition_rule'        => $this->post_text( 'condition_rule', 'all' ),
				'conditions'            => $this->post_conditions(),
			)
		);

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the field settings. Please try again.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * The popup's `conditions[<i>][element_id|rule|value]` rows, shape-cleaned only.
	 * FieldSettingsStore validates the operators and drops incomplete rows.
	 *
	 * @return array<int, array{element_id: string, rule: string, value: string}>
	 */
	private function post_conditions(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; each value is sanitized below.
		$raw        = isset( $_POST['conditions'] ) && is_array( $_POST['conditions'] ) ? wp_unslash( $_POST['conditions'] ) : array();
		$conditions = array();

		foreach ( $raw as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$conditions[] = array(
				'element_id' => sanitize_text_field( is_string( $condition['element_id'] ?? null ) ? $condition['element_id'] : '' ),
				'rule'       => sanitize_text_field( is_string( $condition['rule'] ?? null ) ? $condition['rule'] : '' ),
				'value'      => sanitize_text_field( is_string( $condition['value'] ?? null ) ? $condition['value'] : '' ),
			);
		}

		return $conditions;
	}

	/**
	 * A single-line POST value.
	 *
	 * @param string $key      The POST key.
	 * @param string $fallback Returned when the key is missing or not a string.
	 */
	private function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs guard() first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * A multi-line POST value, line breaks kept.
	 *
	 * @param string $key The POST key.
	 */
	private function post_textarea( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs guard() first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * A POST array of single-line strings.
	 *
	 * @param string $key The POST key.
	 * @return array<int, string>
	 */
	private function post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs guard() first.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs guard() first.
		$values = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );

		// sanitize_text_field() turns nested arrays into '', so this drops them too.
		return array_values( array_filter( $values, static fn( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Verifies the nonce and the Forminator admin capability.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}
	}
}
