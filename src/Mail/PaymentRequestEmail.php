<?php
/**
 * Payment request email sender.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resends a customer their pending payment link, from Payment Entries' "Send payment email".
 */
final class PaymentRequestEmail {

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Builds and sends the email, in the site's language rather than the admin's.
	 *
	 * @param array{ref: string, amount: float, payment_url: string, customer_name: string, customer_email: string} $payment The pending payment.
	 * @param array<int, array{label: string, value: string}>                                                       $fields  The submitted form fields.
	 */
	public static function send( array $payment, array $fields ): bool {
		$switched = switch_to_locale( get_locale() );

		// blogname is stored HTML-escaped; I want the plain text and escape it on output.
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );
		$amount    = number_format( $payment['amount'], 2, ',', '.' ) . ' €';

		/* translators: %s: site name */
		$subject = sprintf( __( 'Complete your payment to %s', 'ifthenpay-payments-for-forminator' ), $site_name );
		$body    = self::body( $payment, $fields, $site_name, $site_url, $amount );

		if ( $switched ) {
			restore_previous_locale();
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = (string) get_option( 'admin_email' );

		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return wp_mail( $payment['customer_email'], $subject, $body, $headers );
	}

	/**
	 * Renders the email HTML.
	 *
	 * I use inline styles and tables only, since that's all most mail clients render.
	 *
	 * @param array{ref: string, amount: float, payment_url: string, customer_name: string, customer_email: string} $payment   The pending payment.
	 * @param array<int, array{label: string, value: string}>                                                       $fields    The submitted form fields.
	 * @param string                                                                                                $site_name The shop's name.
	 * @param string                                                                                                $site_url  The shop's home URL.
	 * @param string                                                                                                $amount    The formatted amount.
	 */
	private static function body( array $payment, array $fields, string $site_name, string $site_url, string $amount ): string {
		$site_link = '<a href="' . esc_url( $site_url ) . '" style="color:#0073aa;">' . esc_html( $site_name ) . '</a>';

		ob_start();
		?>
		<div style="margin:0; padding:24px 12px; background-color:#f6f8fa; font-family:Arial, Helvetica, sans-serif; color:#1d2327;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; margin:0 auto; background-color:#ffffff; border:1px solid #e0e3e7; border-radius:8px; border-collapse:separate;">
				<tr>
					<td style="padding:32px 32px 8px;">
						<p style="margin:0 0 16px; font-size:16px; line-height:1.5;">
							<?php
							echo esc_html(
								'' !== $payment['customer_name']
									/* translators: %s: customer name */
									? sprintf( __( 'Hi %s,', 'ifthenpay-payments-for-forminator' ), $payment['customer_name'] )
									: __( 'Hi,', 'ifthenpay-payments-for-forminator' )
							);
							?>
						</p>
						<p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#3c434a;">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: site name, linked to the site */
									__( 'You started a payment on %s that hasn\'t been completed yet. You can finish it securely with ifthenpay using the button below.', 'ifthenpay-payments-for-forminator' ),
									$site_link
								),
								array(
									'a' => array(
										'href'  => array(),
										'style' => array(),
									),
								)
							);
							?>
						</p>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 24px; background-color:#f6f8fa; border-radius:6px; border-collapse:separate;">
							<tr>
								<td style="padding:16px 20px; font-size:13px; color:#646970;"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-forminator' ); ?></td>
								<td style="padding:16px 20px; font-size:18px; font-weight:bold; text-align:right; color:#1d2327;"><?php echo esc_html( $amount ); ?></td>
							</tr>
							<tr>
								<td style="padding:0 20px 16px; font-size:13px; color:#646970;"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-forminator' ); ?></td>
								<td style="padding:0 20px 16px; font-size:13px; text-align:right; font-family:Consolas, Menlo, monospace; color:#1d2327;"><?php echo esc_html( $payment['ref'] ); ?></td>
							</tr>
						</table>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 28px;">
							<tr>
								<td style="border-radius:6px; background-color:#0073aa;">
									<a href="<?php echo esc_url( $payment['payment_url'] ); ?>" style="display:inline-block; padding:14px 32px; font-size:16px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
										<?php
										/* translators: %s: formatted amount, e.g. "12,50 €" */
										echo esc_html( sprintf( __( 'Pay %s', 'ifthenpay-payments-for-forminator' ), $amount ) );
										?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<?php if ( array() !== $fields ) : ?>
					<tr>
						<td style="padding:0 32px 24px;">
							<p style="margin:0 0 8px; font-size:12px; font-weight:bold; letter-spacing:0.04em; text-transform:uppercase; color:#646970;"><?php esc_html_e( 'What you filled in', 'ifthenpay-payments-for-forminator' ); ?></p>
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border:1px solid #e0e3e7; border-radius:6px; border-collapse:separate;">
								<?php foreach ( $fields as $i => $pair ) : ?>
									<tr>
										<td style="padding:10px 16px; width:40%; vertical-align:top; font-size:14px; color:#646970;<?php echo $i > 0 ? ' border-top:1px solid #eaedf0;' : ''; ?>"><?php echo esc_html( $pair['label'] ); ?></td>
										<td style="padding:10px 16px; vertical-align:top; font-size:14px; color:#1d2327; word-break:break-word;<?php echo $i > 0 ? ' border-top:1px solid #eaedf0;' : ''; ?>"><?php echo esc_html( $pair['value'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</table>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<td style="padding:20px 32px 28px; border-top:1px solid #eaedf0; font-size:13px; line-height:1.6; color:#646970; text-align:center;">
						<?php esc_html_e( 'If you\'ve already paid, you can ignore this email.', 'ifthenpay-payments-for-forminator' ); ?><br />
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: site URL, linked to the site */
								__( 'This message was sent from %s', 'ifthenpay-payments-for-forminator' ),
								'<a href="' . esc_url( $site_url ) . '" style="color:#0073aa;">' . esc_html( $site_url ) . '</a>'
							),
							array(
								'a' => array(
									'href'  => array(),
									'style' => array(),
								),
							)
						);
						?>
					</td>
				</tr>
			</table>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
