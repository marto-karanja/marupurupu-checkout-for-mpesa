# Marupurupu Checkout for M-Pesa

Accept M-Pesa Till payments via STK Push (Safaricom Daraja API) in WooCommerce. Supports both classic shortcode-based checkout and modern block-based checkout.

> **Independent plugin — no affiliation.** This plugin is not affiliated with, endorsed by, or sponsored by Safaricom, M-Pesa, WooCommerce or Automattic. Those names are trademarks of their respective owners and are used only to describe what the plugin works with. "Marupurupu" is a Swahili word meaning "allowances".

- **Stable version:** 1.6.0
- **Requires:** WordPress 5.3+, WooCommerce 3.0+ (5.5+ for block checkout), PHP 7.4+
- **License:** [GPLv2 or later](LICENSE)

## Features

- **STK Push payments** — customers get a payment prompt directly on their phone
- **Block & classic checkout support** — works with both WooCommerce checkout types automatically
- **Real-time payment status** — instant confirmation via the Safaricom callback, with live polling on the checkout/thank-you page
- **Transaction reports** — admin dashboard under WooCommerce > M-Pesa Transactions, with CSV export
- **Secure credentials** — AES-256-GCM authenticated encryption for stored M-Pesa API credentials
- **Test mode** — sandbox environment for testing before going live
- **Telemetry** — anonymous usage tracking, opt-in and off by default

## Requirements

- WordPress 5.3 or higher
- WooCommerce 3.0 or higher (5.5+ recommended for block checkout)
- PHP 7.4 or higher
- SSL certificate (required by Safaricom for STK Push)
- An M-Pesa Till Number and Daraja API credentials

## Installation

1. Download the latest release, or clone this repo, into `wp-content/plugins/marupurupu-checkout-for-mpesa/`
2. Activate the plugin from the WordPress **Plugins** menu
3. Go to **WooCommerce > Settings > Payments** and enable "M-Pesa Till Payment"
4. Click **Manage** and enter your Business Short Code (Till Number), Consumer Key, Consumer Secret, and Passkey from the [Safaricom Daraja Portal](https://developer.safaricom.co.ke/)
5. The callback URL is auto-generated (with a per-site secret token) — no manual setup needed
6. Save, then test a payment in **Test Mode** before going live

See `readme.txt` for the full WordPress.org-formatted documentation, FAQ, and changelog.

## Architecture

| File | Responsibility |
|---|---|
| `marupurupu-checkout-for-mpesa.php` | Plugin entry point — registers the gateway and WooCommerce Blocks support |
| `includes/class-wc-mpesa-till-gateway.php` | `WC_Payment_Gateway` subclass — checkout fields, `process_payment()`, settings form |
| `includes/class-mpesa-api.php` | Daraja API client — OAuth token, STK Push, status query |
| `includes/class-mpesa-callback.php` | Handles the Safaricom → site payment webhook |
| `includes/class-mpesa-encryption.php` | AES-256-GCM encryption for stored credentials |
| `includes/class-mpesa-ajax.php` | Customer-facing AJAX — poll status, retry push, manual code entry |
| `includes/class-mpesa-admin-page.php` | Transaction list table, filters, CSV export |
| `includes/class-mpesa-blocks-support.php` | WooCommerce Blocks checkout integration |

Payment flow: checkout → `process_payment()` triggers an STK Push → order goes `on-hold` → customer confirms on their phone → Safaricom posts the result to the callback handler → order flips to `processing` (paid) or `failed`.

## Security

- AES-256-GCM authenticated encryption for stored credentials
- Callback webhook requires a per-site secret token, verified with `hash_equals` (fails closed)
- Callback payment amount is verified against the order total before marking as paid
- Rate limiting on the customer-facing payment-retry endpoint
- Nonce verification, input sanitization, and output escaping throughout

## License

GPLv2 or later — see [LICENSE](LICENSE).

## Credits

Developed by [Martin Mburu](https://billtoolbox.com). Built on the Safaricom Daraja API and the WooCommerce Payment Gateway / Blocks APIs.
