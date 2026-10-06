<?php
/**
 * Payment email template editor, opened from Payment Entries.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Mail\PaymentEmailTemplate;
use Ifthenpay\Forminator\Mail\PaymentRequestEmail;
use Ifthenpay\Forminator\Plugin;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Payment email" popup behind Payment Entries' settings button: subject, HTML body, tags and a live preview.
 */
final class PaymentEmailSettings {

	// Debounce for the live preview, in ms.
	private const PREVIEW_DELAY = 400;

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the editor.
	 *
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Registers the AJAX endpoints.
	 */
	public function register(): void {
		add_action( 'wp_ajax_iftp_fm_save_payment_email', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_iftp_fm_preview_payment_email', array( $this, 'ajax_preview' ) );
	}

	/**
	 * Loads WP's CodeMirror for the body, and returns what entries.js needs.
	 *
	 * The editor is false when the admin turned syntax highlighting off in their profile; the JS keeps the plain textarea then.
	 *
	 * @return array{editor: array<string, mixed>|false, defaults: array{subject: string, body: string}, previewDelay: int}
	 */
	public function enqueue(): array {
		$editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/html',
				'codemirror' => array(
					'lineWrapping' => true,
					// Tab moves focus instead of indenting, so keyboard users aren't stuck in the editor.
					'extraKeys'    => array(
						'Tab'       => false,
						'Shift-Tab' => false,
					),
				),
			)
		);

		// For "Add image", so a logo from the Media Library can go in the email.
		wp_enqueue_media();

		return array(
			'editor'       => $editor,
			'defaults'     => $this->defaults(),
			'previewDelay' => self::PREVIEW_DELAY,
		);
	}

	/**
	 * The square settings button that sits next to "New payment".
	 */
	public function render_trigger(): void {
		$label = __( 'Payment email settings', 'ifthenpay-payments-for-forminator' );
		?>
		<button type="button" class="iftp-fm-btn iftp-fm-btn--inverse iftp-fm-btn--icon iftp-fm-email-settings-trigger" data-iftp-email-trigger aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
			<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
		</button>
		<?php
	}

	/**
	 * The editor popup. The fields start on what's saved, or the default when nothing is.
	 */
	public function render_modal(): void {
		$template = $this->current();
		?>
		<div class="iftp-fm-modal" data-iftp-email-modal hidden>
			<div class="iftp-fm-modal-overlay" data-iftp-email-overlay></div>
			<div class="iftp-fm-modal-box iftp-fm-modal-box--wide" role="dialog" aria-modal="true" aria-labelledby="iftp-fm-email-title" aria-describedby="iftp-fm-email-desc">
				<h2 id="iftp-fm-email-title"><?php esc_html_e( 'Payment email', 'ifthenpay-payments-for-forminator' ); ?></h2>
				<p id="iftp-fm-email-desc"><?php esc_html_e( 'What customers get from "Send payment email". Click a tag to drop a payment detail where your cursor is — it\'s filled in for each customer when the email goes out.', 'ifthenpay-payments-for-forminator' ); ?></p>

				<form class="iftp-fm-email-form" data-iftp-email-form novalidate>
					<p class="iftp-fm-form-error" data-iftp-email-error role="alert" hidden></p>

					<div class="iftp-fm-email-editor">
						<div class="iftp-fm-email-editor__compose">
							<div class="iftp-fm-form-field">
								<label for="iftp-fm-email-subject"><?php esc_html_e( 'Subject', 'ifthenpay-payments-for-forminator' ); ?></label>
								<input type="text" id="iftp-fm-email-subject" name="subject" maxlength="<?php echo esc_attr( (string) PaymentEmailTemplate::MAX_SUBJECT_LENGTH ); ?>" value="<?php echo esc_attr( $template['subject'] ); ?>" data-iftp-email-subject />
							</div>

							<div class="iftp-fm-email-tags" role="group" aria-labelledby="iftp-fm-email-tags-label">
								<span id="iftp-fm-email-tags-label" class="iftp-fm-email-tags__label"><?php esc_html_e( 'Insert a tag', 'ifthenpay-payments-for-forminator' ); ?></span>
								<ul class="iftp-fm-email-tags__list">
									<?php foreach ( PaymentEmailTemplate::tags() as $tag => $description ) : ?>
										<li>
											<button type="button" class="iftp-fm-email-tag" data-iftp-email-tag="<?php echo esc_attr( $tag ); ?>" data-iftp-email-tag-help="<?php echo esc_attr( $description ); ?>" title="<?php echo esc_attr( $description ); ?>">
												<?php echo esc_html( $tag ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="iftp-fm-email-tags__help" data-iftp-email-tag-desc aria-live="polite"><?php esc_html_e( 'Hover or focus a tag to see what it becomes.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>

							<div class="iftp-fm-form-field iftp-fm-email-editor__body">
								<div class="iftp-fm-email-editor__body-head">
									<label for="iftp-fm-email-body"><?php esc_html_e( 'Body (HTML)', 'ifthenpay-payments-for-forminator' ); ?></label>
									<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-email-media" data-iftp-email-media>
										<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/></svg>
										<?php esc_html_e( 'Add image', 'ifthenpay-payments-for-forminator' ); ?>
									</button>
								</div>
								<textarea id="iftp-fm-email-body" name="body" rows="18" spellcheck="false" data-iftp-email-body><?php echo esc_textarea( $template['body'] ); ?></textarea>
								<p class="iftp-fm-email-editor__note"><?php esc_html_e( 'Inline styles and tables work best in mail clients. Scripts, forms, embeds and event handlers are removed when you save.', 'ifthenpay-payments-for-forminator' ); ?></p>
							</div>
						</div>

						<div class="iftp-fm-email-editor__preview">
							<div class="iftp-fm-email-preview__head">
								<span class="iftp-fm-email-preview__title"><?php esc_html_e( 'Preview', 'ifthenpay-payments-for-forminator' ); ?></span>
								<span class="iftp-fm-email-preview__hint"><?php esc_html_e( 'With sample data', 'ifthenpay-payments-for-forminator' ); ?></span>
							</div>
							<div class="iftp-fm-email-preview__frame" data-iftp-email-preview-wrap>
								<p class="iftp-fm-email-preview__subject">
									<span class="iftp-fm-email-preview__subject-label"><?php esc_html_e( 'Subject:', 'ifthenpay-payments-for-forminator' ); ?></span>
									<span data-iftp-email-preview-subject></span>
								</p>
								<?php // No allow-scripts: whatever the HTML holds, nothing in the preview can run. ?>
								<iframe class="iftp-fm-email-preview__iframe" sandbox="" title="<?php esc_attr_e( 'Email preview', 'ifthenpay-payments-for-forminator' ); ?>" data-iftp-email-preview></iframe>
							</div>
						</div>
					</div>

					<div class="iftp-fm-modal-actions iftp-fm-modal-actions--split">
						<button type="button" class="iftp-fm-btn iftp-fm-btn--link" data-iftp-email-reset>
							<?php esc_html_e( 'Reset to default', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
						<span class="iftp-fm-modal-actions__end">
							<button type="button" class="iftp-fm-btn iftp-fm-btn--secondary iftp-fm-modal-cancel" data-iftp-email-cancel>
								<?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
							<button type="submit" class="iftp-fm-btn iftp-fm-btn--primary" data-iftp-email-submit>
								<?php esc_html_e( 'Save', 'ifthenpay-payments-for-forminator' ); ?>
							</button>
						</span>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: saves the template, after sanitizing it.
	 *
	 * Sends back what was stored, so the editor shows exactly what will go out.
	 */
	public function ajax_save(): void {
		$this->guard();

		$posted  = $this->posted();
		$subject = PaymentEmailTemplate::sanitize_subject( $posted['subject'] );
		$body    = PaymentEmailTemplate::sanitize_body( $posted['body'] );

		if ( '' === $subject ) {
			wp_send_json_error( array( 'message' => __( 'Enter a subject.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		if ( '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'The email body is empty.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		if ( false === strpos( $body, PaymentEmailTemplate::TAG_PAYMENT_URL ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: the {payment_url} tag */
					'message' => sprintf( __( 'Add the %s tag to the body (e.g. as a button\'s link), or your customer has no way to pay.', 'ifthenpay-payments-for-forminator' ), PaymentEmailTemplate::TAG_PAYMENT_URL ),
				),
				400
			);
		}

		$defaults = $this->defaults();

		// The default is stored as '', so it keeps following the site language.
		$this->settings->set_payment_email(
			$subject === $defaults['subject'] ? '' : $subject,
			$body === $defaults['body'] ? '' : $body
		);

		$lost = PaymentEmailTemplate::lost_markup( $posted['body'], $body );

		wp_send_json_success(
			array(
				'subject' => $subject,
				'body'    => $body,
				'message' => $lost
					? __( 'Payment email saved. Some HTML that isn\'t allowed in emails was removed — the editor now shows what will be sent.', 'ifthenpay-payments-for-forminator' )
					: __( 'Payment email saved.', 'ifthenpay-payments-for-forminator' ),
			)
		);
	}

	/**
	 * AJAX: renders the unsaved template with sample data, through the same path a real send takes.
	 */
	public function ajax_preview(): void {
		$this->guard();

		$posted = $this->posted();
		$email  = PaymentRequestEmail::render(
			array(
				'ref'            => 'IFTP-7Q2K9X',
				'amount'         => 12.5,
				// Inert: the preview frame is sandboxed, so this goes nowhere.
				'payment_url'    => home_url( '/#ifthenpay-payment-link' ),
				'customer_name'  => 'Ana Silva',
				'customer_email' => 'ana.silva@example.com',
				'form_id'        => 0,
			),
			array(
				array(
					'label' => __( 'Name', 'ifthenpay-payments-for-forminator' ),
					'value' => 'Ana Silva',
				),
				array(
					'label' => __( 'Email', 'ifthenpay-payments-for-forminator' ),
					'value' => 'ana.silva@example.com',
				),
				array(
					'label' => __( 'Message', 'ifthenpay-payments-for-forminator' ),
					'value' => __( 'Two tickets for Saturday, please.', 'ifthenpay-payments-for-forminator' ),
				),
			),
			// An emptied field previews as blank rather than falling back to the default.
			'' !== trim( $posted['subject'] ) ? $posted['subject'] : ' ',
			'' !== trim( $posted['body'] ) ? $posted['body'] : ' '
		);

		wp_send_json_success(
			array(
				'subject' => $email['subject'],
				'html'    => '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body marginwidth="0" marginheight="0">' . $email['body'] . '</body></html>',
			)
		);
	}

	/**
	 * The saved template, with the defaults filled in for whatever isn't customized.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function current(): array {
		$defaults = $this->defaults();
		$subject  = $this->settings->get_payment_email_subject();
		$body     = $this->settings->get_payment_email_body();

		return array(
			'subject' => '' !== $subject ? $subject : $defaults['subject'],
			'body'    => '' !== $body ? $body : $defaults['body'],
		);
	}

	/**
	 * The default template in the site's language, the one customers read.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function defaults(): array {
		$switched = switch_to_locale( get_locale() );

		$defaults = array(
			'subject' => PaymentEmailTemplate::default_subject(),
			'body'    => PaymentEmailTemplate::sanitize_body( PaymentEmailTemplate::default_body() ),
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		return $defaults;
	}

	/**
	 * The posted subject and body, unsanitized; callers sanitize for their own use.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; the body is HTML, so PaymentEmailTemplate sanitizes it with its own kses allow-list instead.
		$subject = isset( $_POST['subject'] ) && is_string( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '';
		$body    = isset( $_POST['body'] ) && is_string( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '';
		// phpcs:enable

		if ( strlen( $body ) > PaymentEmailTemplate::MAX_BODY_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'The email body is too long.', 'ifthenpay-payments-for-forminator' ) ), 400 );
		}

		return array(
			'subject' => $subject,
			'body'    => str_replace( "\r\n", "\n", $body ),
		);
	}

	/**
	 * Same nonce, capability and connection check as the rest of Payment Entries.
	 */
	private function guard(): void {
		check_ajax_referer( PaymentEntriesPage::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Plugin::admin_cap() ) || ! $this->settings->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}
	}
}
