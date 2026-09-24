<?php
/**
 * Pay by Link request payload builder.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Pay by Link request payload and the accounts string.
 */
class IfthenpayPayload {

	/**
	 * `account` already comes as the full `ENTITY|ACCOUNT` segment from
	 * ifthenpay — I use it as-is, never rebuild it from `entity`.
	 *
	 * @param array<int, array{entity: string, account: string}> $enabled_methods The site's enabled payment methods.
	 */
	public static function build_accounts_string( array $enabled_methods ): string {
		$pairs = array();

		foreach ( $enabled_methods as $method ) {
			if ( '' === $method['account'] ) {
				continue;
			}

			$pairs[] = $method['account'];
		}

		return implode( ';', $pairs );
	}

	/**
	 * Formats an amount the way the ifthenpay API expects it.
	 *
	 * @param float $amount The amount to format.
	 */
	public static function format_amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Builds the full Pay by Link request payload.
	 *
	 * @param string                                             $id                        The payment reference.
	 * @param float                                              $amount                    The payment amount.
	 * @param string                                             $description               The payer-facing description.
	 * @param array<int, array{entity: string, account: string}> $enabled_methods           The site's enabled payment methods.
	 * @param string                                             $success_url               Redirect URL on success.
	 * @param string                                             $error_url                 Redirect URL on error.
	 * @param string                                             $cancel_url                Redirect URL on cancel.
	 * @param string                                             $lang                      The ifthenpay `lang` code.
	 * @param string                                             $selected_method_position  The catalog Position of the pre-selected method, or '' for none.
	 * @return array<string, mixed>
	 */
	public static function build_payment_payload(
		string $id,
		float $amount,
		string $description,
		array $enabled_methods,
		string $success_url,
		string $error_url,
		string $cancel_url,
		string $lang = 'en',
		string $selected_method_position = ''
	): array {
		$payload = array(
			'id'          => $id,
			'amount'      => self::format_amount( $amount ),
			'description' => $description,
			'accounts'    => self::build_accounts_string( $enabled_methods ),
			'success_url' => $success_url,
			'error_url'   => $error_url,
			'cancel_url'  => $cancel_url,
			'otp'         => 'true',
			'lang'        => $lang,
		);

		if ( '' !== $selected_method_position ) {
			$payload['selected_method'] = $selected_method_position;
		}

		return $payload;
	}

	/**
	 * Maps a WordPress locale (e.g. `pt_PT`, `es_ES`) to an ifthenpay `lang` code.
	 *
	 * @param string $locale The WordPress locale, e.g. get_locale().
	 */
	public static function locale_to_lang( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );

		return in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ? $prefix : 'en';
	}
}
