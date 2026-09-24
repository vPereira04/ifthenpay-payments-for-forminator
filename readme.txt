=== ifthenpay Payments for Forminator ===
Contributors: ifthenpay
Tags: payments, forminator, multibanco, mb way, ifthenpay
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ifthenpay payments (card, Apple Pay, Google Pay, Cofidis, Pix, Multibanco, MB WAY) in Forminator via Pay by Link.

== Description ==

Adds an "ifthenpay" payment field to the Forminator form builder, alongside PayPal and Stripe. Payment is collected via ifthenpay's hosted Pay by Link page; payment status is confirmed by an authenticated webhook.

Configuration is a two-step flow under Forminator's own Settings screen:

1. **Connect** (`Settings → Payments`) — enter your ifthenpay Backoffice Key.
2. **Gateway Configuration** (`Settings → Ifthenpay Gateway`, unlocked once connected) — choose the Gateway Key, enable payment methods, pick a default method, and set the payer-facing description and expiry days.

== External Services ==

This plugin connects to the ifthenpay API (https://api.ifthenpay.com) to process payments for Forminator form submissions.

- **ifthenpay API**
  - **What it is and what it is used for**: ifthenpay is a third-party Portuguese payment service provider. The plugin uses its API to validate your Backoffice Key, fetch your Gateway Key(s) and provisioned payment methods, create a Pay by Link payment session when a payer submits a form with the ifthenpay field, check a payment's live status, and register the webhook callback URL for your Gateway Key.
  - **What data is sent and when**:
    - During setup (`GET /gateway/get`, `GET /gateway/methods/available`, `POST /endpoint/callback/activation/`): your Backoffice Key and Gateway Key, and the webhook callback URL.
    - During payment creation (`POST /gateway/pinpay/{gateway_key}`): the payment amount, a generated reference, a payer-facing description, and the success/error/cancel return URLs used to bring the payer back to your site. No submitted form field values are sent to ifthenpay.
    - During status checks (`GET /gateway/transaction/status/get`): the transaction id.
    - During callbacks (received from ifthenpay): the transaction reference, anti-phishing key, paid amount, payment method, and request id.
  - **Terms of Service**: [Terms and Conditions](https://ifthenpay.com/termos-e-condicoes/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All requests are made server-side over HTTPS. No card or banking details are stored by this plugin.

== Changelog ==

= 1.0.0 =
* Initial release: ifthenpay Gateway (Pay by Link) field for Forminator, two-step settings UX, custom transactions table, webhook-confirmed payment status.
