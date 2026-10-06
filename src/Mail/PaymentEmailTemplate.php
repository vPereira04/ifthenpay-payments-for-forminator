<?php
/**
 * Payment request email template: default copy, tags and sanitizing.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editable "Send payment email" template.
 *
 * The admin writes HTML with {tags}. I sanitize it on save and again on send, then swap the tags for escaped
 * values in one pass, so nothing a customer typed can turn into markup or into another tag.
 */
final class PaymentEmailTemplate {

	public const TAG_PAYMENT_URL = '{payment_url}';
	public const TAG_FORM_FIELDS = '{form_fields}';

	public const MAX_BODY_LENGTH    = 100000;
	public const MAX_SUBJECT_LENGTH = 255;

	// Dropped with their contents, since kses would leave e.g. a script's code behind as visible text.
	private const STRIPPED_ELEMENTS = 'script|style|head|title|noscript|template|iframe|object|embed|textarea|select|svg|math';

	// Inline CSS kses doesn't allow by default but email layouts rely on.
	private const EXTRA_SAFE_CSS = array( 'word-break', 'overflow-wrap', 'word-wrap', 'mso-line-height-rule' );

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Tag => what it's replaced with, for the editor's tag list.
	 *
	 * @return array<string, string>
	 */
	public static function tags(): array {
		return array(
			'{greeting}'          => __( '"Hi Ana," — or just "Hi," when there\'s no name', 'ifthenpay-payments-for-forminator' ),
			'{customer_name}'     => __( 'Customer name', 'ifthenpay-payments-for-forminator' ),
			'{customer_email}'    => __( 'Customer email', 'ifthenpay-payments-for-forminator' ),
			'{amount}'            => __( 'Amount, e.g. 12,50 €', 'ifthenpay-payments-for-forminator' ),
			'{reference}'         => __( 'Payment reference', 'ifthenpay-payments-for-forminator' ),
			self::TAG_PAYMENT_URL => __( 'Payment link, for a button or link href (required)', 'ifthenpay-payments-for-forminator' ),
			'{form_name}'         => __( 'Form name', 'ifthenpay-payments-for-forminator' ),
			self::TAG_FORM_FIELDS => __( 'Table of what the customer filled in (body only)', 'ifthenpay-payments-for-forminator' ),
			'{site_name}'         => __( 'Site name', 'ifthenpay-payments-for-forminator' ),
			'{site_url}'          => __( 'Site address', 'ifthenpay-payments-for-forminator' ),
		);
	}

	/**
	 * The default subject, in the current locale.
	 */
	public static function default_subject(): string {
		/* translators: %s: site name */
		return sprintf( __( 'Complete your payment to %s', 'ifthenpay-payments-for-forminator' ), '{site_name}' );
	}

	/**
	 * The default body, in the current locale.
	 *
	 * Inline styles and tables only, since that's all most mail clients render. I write the styles the way
	 * kses re-serializes them, so saving the default untouched doesn't look like an edit.
	 */
	public static function default_body(): string {
		$site_link = '<a href="{site_url}" style="color:#0a0a0a;font-weight:600;text-decoration:underline">{site_name}</a>';
		$pay_link  = '<a href="{payment_url}" style="color:#78716c;text-decoration:underline">' . esc_html__( 'Open the payment link', 'ifthenpay-payments-for-forminator' ) . '</a>';
		$url_link  = '<a href="{site_url}" style="color:#78716c;text-decoration:underline">{site_url}</a>';

		/* translators: %s: site name, linked to the site */
		$intro = sprintf( esc_html__( 'You started a payment on %s but didn\'t complete it. You can pick it back up in a few seconds.', 'ifthenpay-payments-for-forminator' ), $site_link );
		/* translators: %s: formatted amount, e.g. "12,50 €" */
		$pay_label = sprintf( esc_html__( 'Pay %s', 'ifthenpay-payments-for-forminator' ), '{amount}' );
		/* translators: %s: "Open the payment link", linked to the payment page */
		$fallback = sprintf( esc_html__( 'Button not working? %s', 'ifthenpay-payments-for-forminator' ), $pay_link );
		/* translators: %s: site URL, linked to the site */
		$sent_from = sprintf( esc_html__( 'Sent from %s', 'ifthenpay-payments-for-forminator' ), $url_link );

		return '<div style="margin:0;padding:40px 12px;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,&apos;Segoe UI&apos;,Helvetica,Arial,sans-serif;color:#0a0a0a">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:520px;margin:0 auto;border-collapse:separate">
		<tr>
			<td style="padding:0 4px 20px">
				<img src="https://ifthenpay.com/docs/en/assets/images/ifthenpay.png" alt="ifthenpay" width="104" style="display:block;max-width:100%;height:auto;border:0;padding-left:40%">
			</td>
		</tr>
		<tr>
			<td style="background-color:#ffffff;border:1px solid #e7e5e4;border-radius:16px;padding:36px 32px">
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border-collapse:separate">
					<tr>
						<td style="padding:6px 12px 6px 10px;border:1px solid #e7e5e4;border-radius:999px;font-size:12px;font-weight:600;color:#57534e;line-height:1">
							<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background-color:#f59e0b;margin-right:7px;vertical-align:middle"></span>' . esc_html__( 'Payment pending', 'ifthenpay-payments-for-forminator' ) . '
						</td>
					</tr>
				</table>
				<h1 style="margin:0 0 12px;font-size:28px;line-height:1.2;font-weight:700;letter-spacing:-0.8px;color:#0a0a0a">' . esc_html__( 'Finish your payment', 'ifthenpay-payments-for-forminator' ) . '</h1>
				<p style="margin:0 0 6px;font-size:15px;line-height:1.65;color:#0a0a0a">{greeting}</p>
				<p style="margin:0 0 30px;font-size:15px;line-height:1.65;color:#57534e">' . $intro . '</p>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse">
					<tr>
						<td style="padding:22px 0 4px;border-top:1px solid #e7e5e4;font-size:13px;color:#78716c">' . esc_html__( 'Total', 'ifthenpay-payments-for-forminator' ) . '</td>
					</tr>
					<tr>
						<td style="padding:0 0 22px;font-size:44px;line-height:1.1;font-weight:700;letter-spacing:-1.8px;color:#0a0a0a">{amount}</td>
					</tr>
					<tr>
						<td style="padding:0">
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse">
								<tr>
									<td style="padding:14px 0;border-top:1px solid #e7e5e4;border-bottom:1px solid #e7e5e4;font-size:13px;color:#78716c">' . esc_html__( 'Reference', 'ifthenpay-payments-for-forminator' ) . '</td>
									<td align="right" style="padding:14px 0;border-top:1px solid #e7e5e4;border-bottom:1px solid #e7e5e4;font-family:&apos;SF Mono&apos;,Consolas,Menlo,monospace;font-size:13px;font-weight:600;color:#0a0a0a">{reference}</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:30px 0 0;border-collapse:separate">
					<tr>
						<td align="center" bgcolor="#0a0a0a" style="background-color:#0a0a0a;border-radius:10px">
							<a href="{payment_url}" style="display:block;padding:16px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px">' . $pay_label . ' &rarr;</a>
						</td>
					</tr>
				</table>
				<p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#78716c;text-align:center">' . esc_html__( 'Secured by ifthenpay', 'ifthenpay-payments-for-forminator' ) . '</p>
				<div style="margin:28px 0 0;font-size:14px;line-height:1.7;color:#57534e">{form_fields}</div>
			</td>
		</tr>
		<tr>
			<td style="padding:24px 8px 0;font-size:12px;line-height:1.7;color:#78716c;text-align:center">
				' . esc_html__( 'Already paid? You can ignore this email.', 'ifthenpay-payments-for-forminator' ) . '<br />
				' . $fallback . '<br />
				' . $sent_from . '
			</td>
		</tr>
	</table>
</div>';
	}

	/**
	 * Cleans admin-written email HTML down to what's safe and makes sense in a mail client.
	 *
	 * @param string $html The raw template.
	 */
	public static function sanitize_body( string $html ): string {
		$html = (string) preg_replace( '#<(' . self::STRIPPED_ELEMENTS . ')\b[^>]*>.*?</\1\s*>#is', '', $html );
		// An unclosed one would otherwise swallow nothing and leave its tag for kses to drop, keeping the code.
		$html = (string) preg_replace( '#<(' . self::STRIPPED_ELEMENTS . ')\b[^>]*>.*$#is', '', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );

		add_filter( 'safe_style_css', array( self::class, 'allow_email_css' ) );
		$clean = wp_kses( $html, self::allowed_html(), self::allowed_protocols() );
		remove_filter( 'safe_style_css', array( self::class, 'allow_email_css' ) );

		return trim( $clean );
	}

	/**
	 * Cleans the subject to a single plain-text line.
	 *
	 * @param string $subject The raw subject.
	 */
	public static function sanitize_subject( string $subject ): string {
		return mb_substr( sanitize_text_field( $subject ), 0, self::MAX_SUBJECT_LENGTH );
	}

	/**
	 * Whether sanitizing actually removed something, ignoring the way kses re-spaces styles and quotes.
	 *
	 * @param string $raw   What the admin wrote.
	 * @param string $clean What sanitize_body() made of it.
	 */
	public static function lost_markup( string $raw, string $clean ): bool {
		$normalize = static function ( string $html ): string {
			return strtolower( (string) preg_replace( '/[\s;"\']+/', '', $html ) );
		};

		return $normalize( $raw ) !== $normalize( $clean );
	}

	/**
	 * Fills the body's tags.
	 *
	 * @param string                $template The sanitized body.
	 * @param array<string, string> $values   Tag => plain value; I escape each for HTML here.
	 * @param string                $fields   The {form_fields} block, already-safe HTML.
	 */
	public static function render_body( string $template, array $values, string $fields ): string {
		$replacements = array();

		foreach ( $values as $tag => $value ) {
			$replacements[ $tag ] = in_array( $tag, array( self::TAG_PAYMENT_URL, '{site_url}' ), true ) ? esc_url( $value ) : esc_html( $value );
		}

		$replacements[ self::TAG_FORM_FIELDS ] = $fields;

		// strtr() is a single pass, so a value that contains "{payment_url}" stays literal text.
		return strtr( self::sanitize_body( $template ), $replacements );
	}

	/**
	 * Fills the subject's tags.
	 *
	 * @param string                $template The sanitized subject.
	 * @param array<string, string> $values   Tag => plain value.
	 */
	public static function render_subject( string $template, array $values ): string {
		$values[ self::TAG_FORM_FIELDS ] = '';

		// One line, so nothing a customer typed can break into the mail headers.
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( strtr( self::sanitize_subject( $template ), $values ) ) ) );
	}

	/**
	 * The {form_fields} block: a heading and a two-column table, or nothing without fields.
	 *
	 * @param array<int, array{label: string, value: string}> $fields The submitted form fields.
	 */
	public static function fields_block( array $fields ): string {
		if ( array() === $fields ) {
			return '';
		}

		$rows = '';

		foreach ( array_values( $fields ) as $i => $pair ) {
			$border = $i > 0 ? 'border-top:1px solid #eaedf0;' : '';
			$rows  .= '<tr>'
				. '<td style="padding:10px 16px;width:40%;vertical-align:top;font-size:14px;color:#646970;' . $border . '">' . esc_html( $pair['label'] ) . '</td>'
				. '<td style="padding:10px 16px;vertical-align:top;font-size:14px;color:#1d2327;word-break:break-word;' . $border . '">' . esc_html( $pair['value'] ) . '</td>'
				. '</tr>';
		}

		return '<p style="margin:0 0 8px;font-size:12px;font-weight:bold;letter-spacing:0.04em;text-transform:uppercase;color:#646970;">' . esc_html__( 'What you filled in', 'ifthenpay-payments-for-forminator' ) . '</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border:1px solid #e0e3e7;border-radius:6px;border-collapse:separate;">' . $rows . '</table>';
	}

	/**
	 * Adds the email-layout CSS properties to kses's list, only while I sanitize.
	 *
	 * @param mixed $properties The allowed CSS properties.
	 * @return array<int, string>
	 */
	public static function allow_email_css( $properties ): array {
		return array_merge( is_array( $properties ) ? $properties : array(), self::EXTRA_SAFE_CSS );
	}

	/**
	 * Mail-client markup only: no scripts, forms, embeds or event handlers.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_html(): array {
		$common = array(
			'style' => true,
			'class' => true,
			'id'    => true,
			'title' => true,
			'dir'   => true,
			'lang'  => true,
			'align' => true,
			'role'  => true,
		);
		$cell   = $common + array(
			'width'   => true,
			'height'  => true,
			'colspan' => true,
			'rowspan' => true,
			'bgcolor' => true,
			'valign'  => true,
			'nowrap'  => true,
			'scope'   => true,
		);

		$tags = array_fill_keys(
			array( 'abbr', 'b', 'big', 'blockquote', 'br', 'caption', 'center', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'li', 'p', 'pre', 's', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'tbody', 'tfoot', 'thead', 'u' ),
			$common
		);

		$tags['a']     = $common + array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
			'name'   => true,
		);
		$tags['img']   = $common + array(
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
			'border' => true,
		);
		$tags['hr']    = $common + array(
			'width' => true,
			'size'  => true,
			'color' => true,
		);
		$tags['font']  = $common + array(
			'color' => true,
			'face'  => true,
			'size'  => true,
		);
		$tags['ol']    = $common + array(
			'start' => true,
			'type'  => true,
		);
		$tags['ul']    = $common + array( 'type' => true );
		$tags['table'] = $common + array(
			'width'       => true,
			'height'      => true,
			'border'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'bgcolor'     => true,
		);
		$tags['tr']    = $common + array(
			'bgcolor' => true,
			'valign'  => true,
			'height'  => true,
		);
		$tags['td']    = $cell;
		$tags['th']    = $cell;

		return $tags;
	}

	/**
	 * Link/image protocols an email may use.
	 *
	 * @return array<int, string>
	 */
	private static function allowed_protocols(): array {
		return array( 'http', 'https', 'mailto', 'tel' );
	}
}
