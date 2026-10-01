<?php
/**
 * Payment request email sender.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Mail;

use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resends a customer their pending payment link, from Payment Entries' "Send payment email".
 *
 * @phpstan-type EmailPayment array{ref: string, amount: float, payment_url: string, customer_name: string, customer_email: string, form_id: int}
 */
final class PaymentRequestEmail {

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Builds the email from the saved template and sends it.
	 *
	 * @param array<string, mixed>                            $payment  The pending payment.
	 * @param array<int, array{label: string, value: string}> $fields   The submitted form fields.
	 * @param SettingsRepository                              $settings Where the template lives.
	 * @phpstan-param EmailPayment $payment
	 */
	public static function send( array $payment, array $fields, SettingsRepository $settings ): bool {
		$email = self::render( $payment, $fields, $settings->get_payment_email_subject(), $settings->get_payment_email_body() );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = (string) get_option( 'admin_email' );

		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return wp_mail( $payment['customer_email'], $email['subject'], $email['body'], $headers );
	}

	/**
	 * Fills a template for one payment, in the site's language rather than the admin's.
	 *
	 * Also what the editor's preview runs, so it shows exactly what would be sent.
	 *
	 * @param array<string, mixed>                            $payment          The payment.
	 * @param array<int, array{label: string, value: string}> $fields           The submitted form fields.
	 * @param string                                          $subject_template The subject, or '' for the default.
	 * @param string                                          $body_template    The body, or '' for the default.
	 * @phpstan-param EmailPayment $payment
	 * @return array{subject: string, body: string}
	 */
	public static function render( array $payment, array $fields, string $subject_template, string $body_template ): array {
		$switched = switch_to_locale( get_locale() );

		$values = self::tag_values( $payment );
		$email  = array(
			'subject' => PaymentEmailTemplate::render_subject( '' !== $subject_template ? $subject_template : PaymentEmailTemplate::default_subject(), $values ),
			'body'    => PaymentEmailTemplate::render_body(
				'' !== $body_template ? $body_template : PaymentEmailTemplate::default_body(),
				$values,
				PaymentEmailTemplate::fields_block( $fields )
			),
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		return $email;
	}

	/**
	 * Tag => plain value; the template escapes each one for where it goes.
	 *
	 * @param array<string, mixed> $payment The payment.
	 * @phpstan-param EmailPayment $payment
	 * @return array<string, string>
	 */
	private static function tag_values( array $payment ): array {
		$name      = $payment['customer_name'];
		$form_name = $payment['form_id'] > 0 ? get_post_field( 'post_title', $payment['form_id'], 'raw' ) : '';

		return array(
			'{greeting}'                          => '' !== $name
				/* translators: %s: customer name */
				? sprintf( __( 'Hi %s,', 'ifthenpay-payments-for-forminator' ), $name )
				: __( 'Hi,', 'ifthenpay-payments-for-forminator' ),
			'{customer_name}'                     => $name,
			'{customer_email}'                    => $payment['customer_email'],
			'{amount}'                            => number_format( $payment['amount'], 2, ',', '.' ) . ' €',
			'{reference}'                         => $payment['ref'],
			PaymentEmailTemplate::TAG_PAYMENT_URL => $payment['payment_url'],
			'{form_name}'                         => is_string( $form_name ) ? $form_name : '',
			// blogname is stored HTML-escaped; I want the plain text and escape it on output.
			'{site_name}'                         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'                          => home_url( '/' ),
		);
	}
}
