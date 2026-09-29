<?php
/**
 * Plugin settings repository.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's global ifthenpay configuration.
 *
 * Forminator's payment fields (PayPal/Stripe) are configured per-site too, not
 * per-form, so I follow that same shape here — one global connection + one
 * global methods/description/expiry configuration, referenced by every form
 * that adds the ifthenpay field.
 */
class SettingsRepository {

	private const OPTION_BACKOFFICE_KEY = 'iftp_fm_backoffice_key';
	private const OPTION_GATEWAY_KEY    = 'iftp_fm_gateway_key';
	private const OPTION_GATEWAY_KEYS   = 'iftp_fm_gateway_keys';
	private const OPTION_APK_KEYS       = 'iftp_fm_anti_phishing_keys';
	private const OPTION_CALLBACK_HASH  = 'iftp_fm_callback_hash';
	private const OPTION_METHODS        = 'iftp_fm_methods';
	private const OPTION_DEFAULT_METHOD = 'iftp_fm_default_method';
	private const OPTION_DESCRIPTION    = 'iftp_fm_description';
	private const OPTION_EXPIRY_DAYS    = 'iftp_fm_expiry_days';

	private const OPTION_CONFIRMATION_PAID_TYPE         = 'iftp_fm_confirmation_paid_type';
	private const OPTION_CONFIRMATION_PAID_PAGE_ID      = 'iftp_fm_confirmation_paid_page_id';
	private const OPTION_CONFIRMATION_PAID_URL          = 'iftp_fm_confirmation_paid_url';
	private const OPTION_CONFIRMATION_SHOW_ENTRY_DATA   = 'iftp_fm_confirmation_show_entry_data';
	private const OPTION_CONFIRMATION_PAID_MESSAGE      = 'iftp_fm_confirmation_paid_message';
	private const OPTION_CONFIRMATION_PENDING_MESSAGE   = 'iftp_fm_confirmation_pending_message';
	private const OPTION_CONFIRMATION_FAILED_MESSAGE    = 'iftp_fm_confirmation_failed_message';
	private const OPTION_CONFIRMATION_CANCELLED_MESSAGE = 'iftp_fm_confirmation_cancelled_message';
	private const OPTION_CONFIRMATION_PAID_TITLE        = 'iftp_fm_confirmation_paid_title';
	private const OPTION_CONFIRMATION_PENDING_TITLE     = 'iftp_fm_confirmation_pending_title';
	private const OPTION_CONFIRMATION_FAILED_TITLE      = 'iftp_fm_confirmation_failed_title';
	private const OPTION_CONFIRMATION_CANCELLED_TITLE   = 'iftp_fm_confirmation_cancelled_title';

	public const CONFIRMATION_TYPE_POPUP = 'popup';
	public const CONFIRMATION_TYPE_PAGE  = 'page';
	public const CONFIRMATION_TYPE_URL   = 'url';

	/**
	 * The stored Backoffice Key, or '' when not connected.
	 */
	public function get_backoffice_key(): string {
		return (string) get_option( self::OPTION_BACKOFFICE_KEY, '' );
	}

	/**
	 * Stores the Backoffice Key.
	 *
	 * @param string $key The Backoffice Key.
	 */
	public function set_backoffice_key( string $key ): void {
		update_option( self::OPTION_BACKOFFICE_KEY, $key, false );
	}

	/**
	 * Whether a Backoffice Key is stored.
	 */
	public function is_connected(): bool {
		return '' !== $this->get_backoffice_key();
	}

	/**
	 * The currently selected Gateway Key.
	 */
	public function get_gateway_key(): string {
		return (string) get_option( self::OPTION_GATEWAY_KEY, '' );
	}

	/**
	 * Stores the currently selected Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function set_gateway_key( string $gateway_key ): void {
		update_option( self::OPTION_GATEWAY_KEY, $gateway_key, false );
	}

	/**
	 * Every Gateway Key row available under the connected Backoffice Key.
	 *
	 * @return array<int, string>
	 */
	public function get_gateway_keys(): array {
		$keys = get_option( self::OPTION_GATEWAY_KEYS, array() );

		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Stores the full list of available Gateway Keys.
	 *
	 * @param array<int, string> $gateway_keys The Gateway Keys to store.
	 */
	public function set_gateway_keys( array $gateway_keys ): void {
		update_option( self::OPTION_GATEWAY_KEYS, array_values( array_unique( $gateway_keys ) ), false );
	}

	/**
	 * The anti-phishing key registered with ifthenpay for a Gateway Key, or '' when none yet.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function get_anti_phishing_key( string $gateway_key ): string {
		$keys = get_option( self::OPTION_APK_KEYS, array() );

		return is_array( $keys ) ? (string) ( $keys[ $gateway_key ] ?? '' ) : '';
	}

	/**
	 * Stores the anti-phishing key registered for a Gateway Key.
	 *
	 * @param string $gateway_key       The Gateway Key.
	 * @param string $anti_phishing_key The random key sent as `apKey` on callback activation.
	 */
	public function set_anti_phishing_key( string $gateway_key, string $anti_phishing_key ): void {
		$keys = get_option( self::OPTION_APK_KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();

		$keys[ $gateway_key ] = $anti_phishing_key;

		update_option( self::OPTION_APK_KEYS, $keys, false );
	}

	/**
	 * Deletes every stored anti-phishing key — uninstall only, see delete_all().
	 */
	public function delete_anti_phishing_keys(): void {
		delete_option( self::OPTION_APK_KEYS );
	}

	/**
	 * Hash of the Gateway Key + callback URL ifthenpay last accepted, or '' when none yet.
	 */
	public function get_callback_hash(): string {
		return (string) get_option( self::OPTION_CALLBACK_HASH, '' );
	}

	/**
	 * Stores the hash of the callback ifthenpay just accepted.
	 *
	 * @param string $hash See `WebhookRegistrar::hash()`.
	 */
	public function set_callback_hash( string $hash ): void {
		update_option( self::OPTION_CALLBACK_HASH, $hash, false );
	}

	/**
	 * The full payment methods catalog for the current Gateway Key.
	 *
	 * @return array<int, array{entity: string, alias: string, logo?: string, logo_dark?: string, enabled: bool, account: string, position: int, visible?: bool}>
	 */
	public function get_methods(): array {
		$methods = get_option( self::OPTION_METHODS, array() );

		return is_array( $methods ) ? $methods : array();
	}

	/**
	 * Stores the payment methods catalog.
	 *
	 * @param array<int, array{entity: string, alias: string, enabled: bool, account: string, position: int}> $methods The methods to store.
	 */
	public function set_methods( array $methods ): void {
		update_option( self::OPTION_METHODS, $methods, false );
	}

	/**
	 * The subset of get_methods() that are enabled and provisioned.
	 *
	 * @return array<int, array{entity: string, account: string}>
	 */
	public function get_enabled_methods(): array {
		$enabled = array();

		foreach ( $this->get_methods() as $method ) {
			if ( ! empty( $method['enabled'] ) && '' !== ( $method['account'] ?? '' ) ) {
				$enabled[] = array(
					'entity'  => (string) $method['entity'],
					'account' => (string) $method['account'],
				);
			}
		}

		return $enabled;
	}

	/**
	 * The configured default method entity, or '' when none is set.
	 */
	public function get_default_method(): string {
		return (string) get_option( self::OPTION_DEFAULT_METHOD, '' );
	}

	/**
	 * The catalog `Position` value for the configured default method, as
	 * expected by the PBL payload's `selected_method` field. Empty when no
	 * default is set or it's no longer an enabled method.
	 */
	public function get_default_method_position(): string {
		$default = $this->get_default_method();

		if ( '' === $default ) {
			return '';
		}

		foreach ( $this->get_methods() as $method ) {
			if ( $method['entity'] === $default && ! empty( $method['enabled'] ) ) {
				return (string) $method['position'];
			}
		}

		return '';
	}

	/**
	 * Stores the default method entity.
	 *
	 * @param string $entity The method entity, or '' to clear.
	 */
	public function set_default_method( string $entity ): void {
		update_option( self::OPTION_DEFAULT_METHOD, $entity );
	}

	/**
	 * The payer-facing description, defaulting to the site name.
	 */
	public function get_description(): string {
		$default = get_bloginfo( 'name' );

		return (string) get_option( self::OPTION_DESCRIPTION, $default );
	}

	/**
	 * Stores the payer-facing description.
	 *
	 * @param string $description The description text.
	 */
	public function set_description( string $description ): void {
		update_option( self::OPTION_DESCRIPTION, $description );
	}

	/**
	 * The number of days an unpaid payment link stays valid.
	 */
	public function get_expiry_days(): int {
		return (int) get_option( self::OPTION_EXPIRY_DAYS, 3 );
	}

	/**
	 * Stores the expiry days, floored at 1.
	 *
	 * @param int $days The number of days.
	 */
	public function set_expiry_days( int $days ): void {
		update_option( self::OPTION_EXPIRY_DAYS, max( 1, $days ) );
	}

	/**
	 * The configured "paid" confirmation type (popup/page/url).
	 */
	public function get_paid_confirmation_type(): string {
		$type = (string) get_option( self::OPTION_CONFIRMATION_PAID_TYPE, self::CONFIRMATION_TYPE_POPUP );

		$allowed = array( self::CONFIRMATION_TYPE_POPUP, self::CONFIRMATION_TYPE_PAGE, self::CONFIRMATION_TYPE_URL );

		return in_array( $type, $allowed, true ) ? $type : self::CONFIRMATION_TYPE_POPUP;
	}

	/**
	 * Stores the "paid" confirmation type.
	 *
	 * @param string $type One of the CONFIRMATION_TYPE_* constants.
	 */
	public function set_paid_confirmation_type( string $type ): void {
		update_option( self::OPTION_CONFIRMATION_PAID_TYPE, $type );
	}

	/**
	 * The "paid" confirmation page id, when the type is "page".
	 */
	public function get_paid_confirmation_page_id(): int {
		return (int) get_option( self::OPTION_CONFIRMATION_PAID_PAGE_ID, 0 );
	}

	/**
	 * Stores the "paid" confirmation page id.
	 *
	 * @param int $page_id The page id.
	 */
	public function set_paid_confirmation_page_id( int $page_id ): void {
		update_option( self::OPTION_CONFIRMATION_PAID_PAGE_ID, $page_id );
	}

	/**
	 * The "paid" confirmation URL, when the type is "url".
	 */
	public function get_paid_confirmation_url(): string {
		return (string) get_option( self::OPTION_CONFIRMATION_PAID_URL, '' );
	}

	/**
	 * Stores the "paid" confirmation URL.
	 *
	 * @param string $url The confirmation URL.
	 */
	public function set_paid_confirmation_url( string $url ): void {
		update_option( self::OPTION_CONFIRMATION_PAID_URL, $url );
	}

	/**
	 * Whether the paid popup should show the submitted field values.
	 */
	public function get_show_entry_data(): bool {
		return (bool) get_option( self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA, false );
	}

	/**
	 * Stores whether the paid popup should show the submitted field values.
	 *
	 * @param bool $show Whether to show the field values.
	 */
	public function set_show_entry_data( bool $show ): void {
		update_option( self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA, $show );
	}

	/**
	 * The page/URL the paid popup links to (opened in a new tab) — empty
	 * when the confirmation type is a plain popup.
	 */
	public function get_paid_page_url(): string {
		switch ( $this->get_paid_confirmation_type() ) {
			case self::CONFIRMATION_TYPE_PAGE:
				$page_id = $this->get_paid_confirmation_page_id();

				return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
			case self::CONFIRMATION_TYPE_URL:
				return $this->get_paid_confirmation_url();
			default:
				return '';
		}
	}

	/**
	 * The configured popup message for a status, or '' when not set.
	 *
	 * @param string $status One of 'paid', 'pending', 'failed', 'cancelled'.
	 */
	public function get_confirmation_message( string $status ): string {
		$option = self::confirmation_message_option( $status );

		return null !== $option ? (string) get_option( $option, '' ) : '';
	}

	/**
	 * Stores the popup message for a status.
	 *
	 * @param string $status  One of 'paid', 'pending', 'failed', 'cancelled'.
	 * @param string $message The message to store.
	 */
	public function set_confirmation_message( string $status, string $message ): void {
		$option = self::confirmation_message_option( $status );

		if ( null === $option ) {
			return;
		}

		update_option( $option, $message );
	}

	/**
	 * The option key for a status's confirmation message, or null when unknown.
	 *
	 * @param string $status One of 'paid', 'pending', 'failed', 'cancelled'.
	 */
	private static function confirmation_message_option( string $status ): ?string {
		switch ( $status ) {
			case 'paid':
				return self::OPTION_CONFIRMATION_PAID_MESSAGE;
			case 'pending':
				return self::OPTION_CONFIRMATION_PENDING_MESSAGE;
			case 'failed':
				return self::OPTION_CONFIRMATION_FAILED_MESSAGE;
			case 'cancelled':
				return self::OPTION_CONFIRMATION_CANCELLED_MESSAGE;
			default:
				return null;
		}
	}

	/**
	 * The configured popup title for a status, or '' when not set (falls back to the built-in title).
	 *
	 * @param string $status One of 'paid', 'pending', 'failed', 'cancelled'.
	 */
	public function get_confirmation_title( string $status ): string {
		$option = self::confirmation_title_option( $status );

		return null !== $option ? (string) get_option( $option, '' ) : '';
	}

	/**
	 * Stores the popup title for a status.
	 *
	 * @param string $status One of 'paid', 'pending', 'failed', 'cancelled'.
	 * @param string $title  The title to store.
	 */
	public function set_confirmation_title( string $status, string $title ): void {
		$option = self::confirmation_title_option( $status );

		if ( null === $option ) {
			return;
		}

		update_option( $option, $title );
	}

	/**
	 * The option key for a status's confirmation title, or null when unknown.
	 *
	 * @param string $status One of 'paid', 'pending', 'failed', 'cancelled'.
	 */
	private static function confirmation_title_option( string $status ): ?string {
		switch ( $status ) {
			case 'paid':
				return self::OPTION_CONFIRMATION_PAID_TITLE;
			case 'pending':
				return self::OPTION_CONFIRMATION_PENDING_TITLE;
			case 'failed':
				return self::OPTION_CONFIRMATION_FAILED_TITLE;
			case 'cancelled':
				return self::OPTION_CONFIRMATION_CANCELLED_TITLE;
			default:
				return null;
		}
	}

	/**
	 * Deletes every option this repository owns — used on disconnect and uninstall.
	 *
	 * I keep the anti-phishing keys here: ifthenpay still calls back for
	 * payments started before a disconnect, and those need the key to validate.
	 */
	public function delete_all(): void {
		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_GATEWAY_KEY );
		delete_option( self::OPTION_GATEWAY_KEYS );
		delete_option( self::OPTION_CALLBACK_HASH );
		delete_option( self::OPTION_METHODS );
		delete_option( self::OPTION_DEFAULT_METHOD );
		delete_option( self::OPTION_DESCRIPTION );
		delete_option( self::OPTION_EXPIRY_DAYS );
		delete_option( self::OPTION_CONFIRMATION_PAID_TYPE );
		delete_option( self::OPTION_CONFIRMATION_PAID_PAGE_ID );
		delete_option( self::OPTION_CONFIRMATION_PAID_URL );
		delete_option( self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA );
		delete_option( self::OPTION_CONFIRMATION_PAID_MESSAGE );
		delete_option( self::OPTION_CONFIRMATION_PENDING_MESSAGE );
		delete_option( self::OPTION_CONFIRMATION_FAILED_MESSAGE );
		delete_option( self::OPTION_CONFIRMATION_CANCELLED_MESSAGE );
		delete_option( self::OPTION_CONFIRMATION_PAID_TITLE );
		delete_option( self::OPTION_CONFIRMATION_PENDING_TITLE );
		delete_option( self::OPTION_CONFIRMATION_FAILED_TITLE );
		delete_option( self::OPTION_CONFIRMATION_CANCELLED_TITLE );
	}
}
