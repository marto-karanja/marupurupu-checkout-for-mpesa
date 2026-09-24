=== Marupurupu Checkout for M-Pesa ===
Contributors: marto46
Tags: woocommerce, mpesa, payment gateway, kenya, safaricom
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept M-Pesa Till payments via STK Push for WooCommerce. Supports both classic and block-based checkout.

== Description ==

Marupurupu Checkout for M-Pesa allows you to accept payments via M-Pesa (Safaricom) using the STK Push (Lipa Na M-Pesa Online) feature. This plugin integrates seamlessly with WooCommerce and supports both classic shortcode-based checkout and modern block-based checkout.

**Independent plugin — no affiliation.** This plugin is developed independently. It is not affiliated with, endorsed by, or sponsored by Safaricom, M-Pesa, WooCommerce or Automattic. M-Pesa, Safaricom and WooCommerce are trademarks of their respective owners, used here only to describe what the plugin works with.

= Features =

* **STK Push Payments** - Customers receive payment prompt directly on their phone
* **Block Checkout Support** - Works with WooCommerce block-based checkout (NEW in v1.1.0)
* **Classic Checkout Support** - Fully compatible with traditional shortcode checkout
* **Automatic Detection** - Automatically works with both checkout types
* **Real-time Payment Status** - Instant payment confirmation via callbacks
* **Transaction Reports** - Comprehensive admin dashboard for transaction tracking
* **Secure Credentials** - AES-256-GCM authenticated encryption for M-Pesa API credentials
* **Test Mode** - Sandbox environment for testing before going live
* **Payment Callbacks** - Automatic order status updates
* **Order Tracking** - Enhanced order received page with payment status
* **Debug Logging** - Detailed logs for troubleshooting
* **Telemetry** - Optional usage tracking (opt-in)

= Requirements =

* WordPress 5.3 or higher
* WooCommerce 3.0 or higher (5.5+ recommended for block checkout)
* PHP 7.4 or higher
* SSL Certificate (required for M-Pesa STK Push)
* M-Pesa Till Number and Daraja API credentials

= Setup =

1. Upload the plugin to `/wp-content/plugins/marupurupu-checkout-for-mpesa/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "M-Pesa Till Payment"
5. Click "Manage" to configure your M-Pesa credentials
6. Add your Business Short Code (Till Number), Consumer Key, Consumer Secret, and Passkey
7. Configure callback URL (auto-generated)
8. Save changes and test in Test Mode first

= Configuration =

**Required Settings:**
* Consumer Key (Production & Test)
* Consumer Secret (Production & Test)
* Business Short Code (Your Till Number)
* Passkey (From Daraja Portal)
* Test Mode toggle

**Optional Settings:**
* Payment instructions for customers
* Debug logging
* Telemetry (usage tracking)

= External services =

This plugin connects to the third-party services below. Nothing is sent to Safaricom until you configure the plugin and a payment is attempted, and nothing is sent to the usage-statistics collector unless you opt in.

**Safaricom Daraja API** (`api.safaricom.co.ke`, or `sandbox.safaricom.co.ke` when Test Mode is on) — the service that actually takes the M-Pesa payment.
* Used for: requesting an access token, sending an STK Push payment prompt to the customer's phone, and checking the status of a payment. Safaricom also posts the payment result back to your site's callback URL.
* Sent, and when: your Consumer Key and Consumer Secret (to obtain a token — whenever one is needed and when you click "Test M-Pesa Connection"); and, when a customer pays, your Business Short Code and Till Number, a request password derived from your Passkey, the order amount, the customer's M-Pesa phone number, an order reference ("Order-" plus the order number) and your site's callback URL.
* Provider: Safaricom PLC. Daraja developer portal: https://developer.safaricom.co.ke/ — Terms and Conditions and Privacy Policy: https://developer.safaricom.co.ke/terms

**Plugin usage-statistics collector** (`telemetry.billtoolbox.com`) — optional and off by default; used only if you tick "Help improve this plugin by sharing anonymous usage data" in the gateway settings. Exactly what is sent, and when, is listed field by field under "Privacy Policy" below. Operated by the plugin author. It has no separate terms document: the complete disclosure of what it receives, stores and for how long is the "Privacy Policy" section below.

**WordPress.org secret-key generator** (`api.wordpress.org/secret-key/1.1/salt/`) — only a link in an admin notice shown when your site's security keys are missing. The plugin sends nothing to it; your browser opens it only if you click the link.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Navigate to Plugins > Add New
3. Search for "Marupurupu Checkout for M-Pesa"
4. Click "Install Now"
5. Activate the plugin

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the downloaded ZIP file
5. Click "Install Now"
6. Activate the plugin

= After Installation =

1. Go to WooCommerce > Settings > Payments
2. Enable "M-Pesa Till Payment"
3. Configure your M-Pesa credentials
4. Test in Test Mode before going live

== Frequently Asked Questions ==

= Does this work with WooCommerce block checkout? =

Yes! Version 1.1.0 adds full support for WooCommerce block-based checkout while maintaining backward compatibility with classic checkout.

= Do I need an SSL certificate? =

Yes, M-Pesa STK Push requires your site to have a valid SSL certificate (HTTPS).

= How do I get M-Pesa API credentials? =

1. Register on Safaricom Daraja Portal: https://developer.safaricom.co.ke/
2. Create a new app
3. Get your Consumer Key and Consumer Secret
4. Request for production credentials after testing

= What is a Till Number? =

A Till Number is your M-Pesa Buy Goods business number. This plugin currently supports Till (Buy Goods) payments only; Paybill numbers are not supported.

= Can I test before going live? =

Yes! Enable "Test Mode" in settings and use the test credentials from Daraja Portal.

= Where can I see transaction reports? =

Go to WooCommerce > M-Pesa Transactions to view all payment transactions.

= What if payment fails? =

The plugin includes comprehensive error logging. Enable debug mode and check wp-content/debug.log for details.

= Does it support multiple currencies? =

Currently supports KES (Kenyan Shillings) only, as required by M-Pesa.

= How do callbacks work? =

The plugin automatically generates a callback URL, including a secret token unique to your site. M-Pesa sends payment confirmations to this URL; the plugin verifies the token before updating order status automatically.

== Screenshots ==

1. Gateway settings page with M-Pesa configuration
2. Checkout page showing M-Pesa payment option (Block checkout)
3. STK Push prompt on customer's phone
4. Transaction reports dashboard
5. Order received page with payment status
6. Payment settings and encryption management

== Changelog ==

= 1.6.3 - 2026-09-24 =
* Fixed: The M-Pesa Transactions and Reports pages logged PHP 8.1 "Passing null to number_format()" deprecations on a store with no transactions yet.
* Fixed: If the plugin was activated while WooCommerce was inactive, its transactions table was never created. The activation hook is now registered before the WooCommerce check, and a missing table is created on the next load once WooCommerce is active. The "requires WooCommerce" notice is now translatable, and network-activated WooCommerce is recognised. The plugin header now declares `Requires Plugins: woocommerce`.
* Fixed: A failed payment attempt on the block-based checkout now shows the gateway's own message instead of a generic error (the gateway returned `fail`, which WooCommerce Blocks does not recognise; it now returns `failure`).
* Changed: If the gateway is enabled but its credentials, shortcode, till number or passkey are not filled in, a payment attempt now stops with a friendly message instead of contacting Safaricom with empty credentials and showing "Failed to get access token".
* Changed: "Test M-Pesa Connection" now also lists any of Business Shortcode, Till Number or Passkey that is still empty, instead of reporting success when only the Consumer Key/Secret were checked.
* Fixed: The Transactions list no longer shows a "View Order" button (and no longer logs a PHP deprecation) for transactions whose order has been deleted. Button/placeholder text inside HTML attributes is now escaped as attribute text, and the plugin-list "Settings" link is translatable.
* Changed: The order-received page's payment-status script is fully translatable, no longer writes debug output to the browser console, and inserts messages as plain text rather than HTML.
* Security: CSV exports (transactions and reports) now prefix any text cell starting with `=`, `+`, `-` or `@` so spreadsheet programs cannot run it as a formula.
* Changed: The usage-statistics opt-in no longer describes (or attempts) an "activation" event, which never fired. Only the opt-in "deactivation" event remains, and the readme now says exactly what it contains.
* Changed: `WC tested up to` raised to 11.1; the amount shown on the order-received page is escaped.

= 1.6.2 - 2026-09-22 =
* Fixed: On admin pages, activating the plugin with opt-in telemetry enabled could trigger a WordPress "translation loading triggered too early" notice. The telemetry check was reading the gateway's settings before WordPress had finished its own startup sequence, which also forced the gateway itself to load earlier than it should have; both now wait until WordPress is ready. No setting, saved credential, or behavior changes as a result.

= 1.6.1 - 2026-09-19 =
* Changed: The plugin's display name is now "Marupurupu Checkout for M-Pesa" (the "and WooCommerce" suffix was removed), following WordPress.org Plugin Review Team feedback that "WooCommerce" is a restricted term in plugin names. The slug, text domain, settings, saved credentials, orders and the Safaricom callback URL are all unchanged. No functional change.
* Removed: Unused internal feature-flag code that was never connected to anything. Every feature remains available to everyone, as before.
* Changed: One more admin-screen check now sanitizes the `section` query argument before comparing it.

= 1.6.0 - 2026-09-19 =
* Changed: **Unique naming prefix.** Every class, function, constant, option, transient, scheduled event, hook, AJAX action, script handle and admin menu slug the plugin registers now uses the prefix `marupurupu_` / `Marupurupu_` / `MARUPURUPU_` instead of the generic `mpesa` (which could collide with other M-Pesa plugins). The custom transactions table is now `{prefix}marupurupu_transactions`.
* Added: **Automatic one-time data migration.** On the first request after updating, data stored under the old names is moved across: the transactions table is renamed (a single atomic `RENAME TABLE`; no rows are copied or lost), saved options and dismissed notices are carried over, and leftover scheduled events are cleared. Your gateway settings, saved credentials and payment history are unchanged, and no action is needed. The migration retries by itself if it cannot finish, and never overwrites newer data.
* Unchanged on purpose: the payment method id (`mpesa_till`), your saved gateway settings, and the Safaricom callback URL (`/wc-api/wc_mpesa_till_callback/`) — orders, settings and Safaricom callbacks depend on them.
* Added: The phone number is now also validated again on the server at the start of payment processing, before any request is made to Safaricom (whether WooCommerce's block checkout runs the classic field validation has varied between WooCommerce versions, so this makes the check independent of it). A trailing newline is no longer accepted as part of a phone number.
* Removed: The one-time credential-reset routine from the 2026-08 encryption upgrade (every existing install has already been through it; it could only ever cause harm on a fresh one).
* Added: A unit-test suite (`composer test`) covering credential encryption, the payment webhook, the STK Push request, the migration and the order-state guard.

= 1.5.6 - 2026-09-19 =
* Fixed: **Block-based checkout could not collect a phone number.** The block checkout component only displayed the payment description; the phone-number field, its validation and the code that submits it with the order had been lost in an earlier source-tree consolidation, so paying with M-Pesa on the WooCommerce Cart & Checkout blocks could not work. Restored. Classic checkout was never affected.
* Added: Declares WooCommerce Cart & Checkout blocks compatibility (`cart_checkout_blocks`), so WooCommerce no longer lists the gateway as incompatible with block checkout.
* Fixed: Readme said Paybill numbers were supported; the plugin supports Till (Buy Goods) only. Corrected, along with the minimum WordPress version (5.3), the Support section (no documentation files ship with the plugin) and the telemetry opt-out description.
* Added: "External services" section in the readme documenting the Safaricom Daraja API and the optional usage-statistics collector — what is sent, when, and links to their terms and privacy policies.
* Removed: `config-sample.php`, which described a constants-based credential mechanism that the plugin never implemented. Credentials are entered on the gateway settings screen and stored encrypted.

= 1.5.5 - 2026-09-18 =
* Changed: **Renamed** to "Marupurupu Checkout for M-Pesa" (slug, folder, main file, and text domain `marupurupu-checkout-for-mpesa`), following WordPress.org Plugin Review Team feedback that the previous name led with a third-party trademark. "Marupurupu" is a Swahili word meaning "allowances". No functional change: internal identifiers, the database table (`wp_mpesa_till_transactions`), the option keys, and the Daraja callback URL (`/wc-api/wc_mpesa_till_callback/`) are all unchanged. Existing installs need a manual reinstall to pick up the new folder name (WordPress cannot rename an installed plugin's folder in an update); stored settings are unaffected.
* Changed: "Not affiliated" disclaimer wording made explicit in the plugin description.
* Changed: `Plugin URI` and the readme's source-code link now point to the renamed public repository, `github.com/marto-karanja/marupurupu-checkout-for-mpesa` (the previous repository name led with a third-party trademark).

= 1.5.4 - 2026-09-18 =
* Fixed: On the gateway settings page, the "Change" button for an already-saved Consumer Key/Secret/Passkey did nothing and there was no field to type a new value into, so saved credentials could not be replaced from the settings screen. Credentials can be changed again.
* Fixed: A stale daily "clean old logs" scheduled task, left behind by older versions for a function that no longer exists, could raise a fatal error on PHP 8 each time WordPress cron ran it. It is now unscheduled.
* Fixed: Bulk "Export to CSV" on the M-Pesa Transactions page sent its download headers after the page had already started rendering, so the file could not be delivered cleanly. It now runs before any output.
* Fixed: The transactions page stylesheet (`admin.css`) was referenced but not shipped, so the page's styles came only from an inline block. It is now a real, enqueued stylesheet.
* Fixed: The customer-facing "retry payment" and "already paid? enter your code" actions could be used on an order that was already paid (overwriting its confirmed M-Pesa receipt and moving it back to on-hold) or on an order placed with a different payment method. Both now only act on unpaid orders placed with this gateway.
* Fixed: The retry-payment form on the thank-you page could re-appear after a payment succeeded within the first 2 minutes.
* Fixed: Blocks-checkout script now uses the plugin version as its cache-busting version, instead of a hardcoded `1.0.0`.
* Security: Safaricom callback data is now sanitized (`sanitize_text_field`) before it is logged, stored, or shown in order notes; nonces are sanitized before verification; the callback secret is sanitized before comparison; the bulk-export `IN (...)` list now goes through `$wpdb->prepare()`.
* Changed: All inline `<script>`/`<style>` blocks and `onclick` handlers moved to properly enqueued files (`wp_enqueue_script`/`wp_enqueue_style`, with report data passed via `wp_localize_script`).
* Changed: Late output escaping added where WooCommerce/WordPress return pre-built HTML (`wc_price`, `paginate_links`, `wpautop`), and `wp_die()` messages now use `esc_html__()`.
* Changed: Bundled Chart.js updated from 3.9.1 to 4.5.1 (see Credits).
* Removed: Unused `mpesa-blocks-improved.js` (never loaded by the plugin).

= 1.5.3 - 2026-09-09 =
* Fixed: `Plugin URI` and `Author URI` in the plugin header were identical (`https://billtoolbox.com`) — flagged during WordPress.org submission review (both must be different, or one omitted). `Plugin URI` now points to the public GitHub source; `Author URI` stays `https://billtoolbox.com`.

= 1.5.2 - 2026-09-09 =
* Changed: **Slug, folder, main file, and text domain renamed** from `mpesa-till-gateway` to `mpesa-payment-gateway`, matching the v1.5.1 display-name change and made ahead of first WordPress.org submission (self-service slug changes go away once review starts). No functional change — internal identifiers, the database table (`wp_mpesa_till_transactions`), the `WC_Mpesa_Till_Gateway` class name, and the Daraja callback URL (`/wc-api/wc_mpesa_till_callback/`) are all unchanged, matching the same precedent as the earlier `wc-mpesa-till-payment` → `mpesa-till-gateway` rename. **Existing installs on bonbargains.com/nairobistalls.com need a manual reinstall to pick this up** — WordPress cannot rename an installed plugin's folder via a normal update; deactivate, delete the old `mpesa-till-gateway` folder, install this version fresh, then reactivate. Stored settings are unaffected (kept under a WooCommerce option key, not tied to the folder name).

= 1.5.1 - 2026-09-09 =
* Changed: Display name updated from "M-Pesa Till Gateway for WooCommerce" to "M-Pesa Payment Gateway for WooCommerce" ahead of first WordPress.org submission — cosmetic only. The plugin slug (`mpesa-till-gateway` at the time; renamed again in 1.5.2, see above), text domain, folder name, main file name, database table, and the Daraja callback URL were all unchanged by this specific release; existing installs were unaffected.

= 1.5.0 - 2026-08-31 =
* **Renamed** from `wc-mpesa-till-payment` to `mpesa-till-gateway` (slug, folder, main file, text domain) — see the 1.5.2 and 1.5.5 entries above for the further renames, most recently to `marupurupu-checkout-for-mpesa`. WordPress.org restricts the term "wc" in plugin slugs — the previous name could never have been submitted. No functional change; internal identifiers, the database table, and the M-Pesa/Daraja callback URL are all unchanged, so existing installs keep working exactly as before once updated.
* Changed: Outbound Safaricom API calls (OAuth token, STK Push, status query) now use WordPress's own HTTP API (`wp_remote_get()`/`wp_remote_post()`) instead of calling cURL directly — same behavior (timeouts, SSL verification), but works correctly on hosts that restrict direct cURL usage and follows WordPress.org coding standards.
* Fixed: 8 "Creation of dynamic property is deprecated" warnings on every settings-page load (PHP 8.2) — the gateway's credential fields are now properly declared class properties.
* Fixed: numerous smaller correctness/compliance items found via a full run of the official WordPress.org Plugin Check tool — missing output escaping, missing translator comments on translatable strings with placeholders, `date()` calls affected by server timezone changed to `gmdate()`, superglobal reads missing `wp_unslash()`, `wp_redirect()` changed to `wp_safe_redirect()` for two admin actions added in 1.4.x, and a stale "Tested up to" header.
* Removed: two long-dead, unused logging methods that hand-rolled log files inside the plugin's own (web-accessible) directory — this exact pattern was already fixed elsewhere in 2026-08-01 by switching to WooCommerce's own logger; these had no remaining callers.

= 1.4.2 - 2026-08-28 =
* Fixed: **Critical** — every successful M-Pesa payment triggered a PHP fatal error partway through the callback handler (order was correctly marked paid, but processing stopped there — stock was never reduced, and any later code/hooks in that request never ran). Caused by a redundant, duplicate `do_action('woocommerce_payment_complete', ...)` call that passed a raw database string instead of an integer order ID, which crashes WooCommerce core's own stock-reduction code (`get_stock_reduced()`) on this WooCommerce version. `WC_Order::payment_complete()`, called immediately before it, already fires this same hook correctly — the duplicate call is removed rather than just type-fixed, since it was firing every other plugin's payment-complete listeners twice per order. Found live on a production install 2026-08-28; confirmed via WooCommerce core source that the fix doesn't lose any behavior.

= 1.4.1 - 2026-08-28 =
* Changed: Saved Consumer Key/Secret/Passkey fields now show a masked fingerprint (bullets plus the last 4 real characters, same convention as a masked card number) with a "Change" button, instead of rendering completely blank. A blank field with only a placeholder was mistaken for "nothing saved" by a real merchant, causing them to keep re-entering credentials on every save. The real value is still never placed in the page as plaintext — only 4 of 32-64 characters are ever shown, purely to confirm at a glance that a specific value is genuinely stored.

= 1.4.0 - 2026-08-28 =
* Added: "Test M-Pesa Connection" button on the settings page — checks the currently saved Consumer Key/Secret against Safaricom right now and reports plainly whether they work, instead of only finding out via a failed checkout. Added after a real support case where a merchant couldn't tell "saved and correct" from "saved but wrong" since credential fields never redisplay their value
* Fixed: A failed OAuth token request (wrong/invalid Consumer Key or Secret) now logs Safaricom's HTTP status and response to the PHP error log — previously this specific failure mode logged nothing at all, making "Failed to get access token" undiagnosable from the server side

= 1.3.0 - 2026-08-25 =
* Security: Rewrote credential encryption from AES-256-CBC to AES-256-GCM (authenticated encryption) with an explicit format marker, fixing a real bug where a plaintext credential that happened to look like valid base64 could be silently stored unencrypted, then misreported as an "encryption key changed" error when the plugin later tried to decrypt it
* Security: **Stored M-Pesa credentials are cleared once during this update** and must be re-entered — the new encryption format is not compatible with the old one, and safely converting old encrypted values wasn't possible without risking corrupted credentials being silently used against the live payment API. See the admin notice on the M-Pesa settings page after updating
* Security: Settings screen no longer redisplays your decrypted Consumer Key/Secret/Passkey in the page HTML on every visit — fields now stay blank (matching how Stripe/PayPal gateway plugins handle secrets) and only change what's stored if you actually type a new value
* Security: The M-Pesa callback handler now verifies the confirmed payment amount against the original order total before marking an order as paid, and ignores duplicate callback deliveries for an already-processed transaction (Safaricom is known to occasionally redeliver the same callback)
* Security: Outbound Daraja API calls now set an explicit connection/request timeout, and explicitly require SSL certificate verification instead of relying on PHP's default
* Security: The customer-facing payment-retry endpoint is now rate-limited (max 3 attempts per order per 10 minutes) — it previously allowed unlimited unsolicited STK Push prompts to any phone number, not just the order's own
* Changed: Encryption key derivation now uses HKDF (combining WordPress's AUTH_KEY and AUTH_SALT) instead of a bare hash of AUTH_KEY alone

= 1.2.0 - 2026-08-14 =
* Added: Anonymous usage telemetry is now fully functional (opt-in, off by default) — restored the settings checkbox, fixed a bug where activation could report in before the opt-in check ran, and pointed it at a real, dedicated collector backend
* Security: Telemetry activation reporting is now gated by the same opt-in check as every other telemetry event, with no exceptions
* Changed: Privacy Policy section now documents the exact field list sent for every telemetry event type

= 1.1.1 - 2026-08-02 =
* Security: Removed disabled SSL certificate verification on all outbound Daraja API calls
* Security: M-Pesa callback URL now includes a per-site secret token; requests without it are rejected
* Security: Callback logs now use WooCommerce's protected logger instead of an unprotected custom log file
* Fixed: Encryption admin notices now proactively warn about a weak encryption key or degraded encryption instead of only logging silently
* Changed: Renamed to "M-Pesa Till Gateway for WooCommerce"
* Changed: Bundled Chart.js locally instead of loading it from a CDN
* Removed: Dead `class-mpesa-blocks-support-v2.php` (superseded by the active blocks support class)

= 1.1.0 - 2026-01-13 =
* Added: WooCommerce Blocks support for modern checkout
* Added: React-based payment method registration
* Added: Automatic checkout type detection
* Improved: Documentation and troubleshooting guides
* Fixed: Payment method not showing on block checkout
* Maintained: 100% backward compatibility with classic checkout

= 1.0.0 - 2025-12-24 =
* Initial release
* STK Push payment integration
* Payment callbacks and confirmations
* AES-256-CBC credential encryption
* Transaction reports and tracking
* Test mode support
* Admin dashboard
* Telemetry tracking (opt-in)
* Debug logging

== Upgrade Notice ==

= 1.3.0 =
IMPORTANT: This security release clears your stored M-Pesa credentials (Consumer Key/Secret, Passkey). You MUST re-enter and save them on the M-Pesa settings page after updating, or checkout will not work. See the full changelog for details.

= 1.2.0 =
Telemetry is now a real, working opt-in feature (was previously scaffolded but inert). Off by default — no behavior change unless you explicitly enable it under Payments > M-Pesa Till > Anonymous Usage Data.

= 1.1.1 =
Security hardening release: re-enables SSL certificate verification on M-Pesa API calls and adds callback authentication. Update is strongly recommended for all sites.

= 1.1.0 =
This version adds support for WooCommerce block-based checkout. No breaking changes. Simply update and M-Pesa will work on both classic and block checkouts.

= 1.0.0 =
Initial release. Please test in sandbox mode before using in production.

== Technical Details ==

= Compatibility =

* WordPress: 5.3+
* WooCommerce: 3.0+ (5.5+ for block checkout)
* PHP: 7.4, 8.0, 8.1, 8.2
* WooCommerce Blocks: 11.0+

= Security =

* AES-256-GCM authenticated encryption for stored credentials (detects tampering/wrong-key decryption cryptographically, not by guessing)
* Nonce verification for all forms
* Input sanitization and output escaping
* SQL injection prevention
* XSS protection
* Callback authentication via a per-site secret token
* Callback payment amount verified against the original order before marking paid
* Rate limiting on the customer-facing payment-retry endpoint

= Performance =

* Minimal database queries
* Efficient caching
* Optimized asset loading
* No frontend JavaScript unless on checkout page

== Privacy Policy ==

This plugin:
* Stores M-Pesa transaction data in your WordPress database
* Sends payment requests to Safaricom M-Pesa API
* Optionally tracks anonymous usage data (opt-in telemetry) — see below
* Does not share customer data with third parties (except Safaricom for payment processing)
* Encrypts sensitive credentials at rest

= Telemetry (opt-in) =

Anonymous usage telemetry is **off by default**. It only activates if you
check "Help improve this plugin by sharing anonymous usage data" under
WooCommerce > Settings > Payments > M-Pesa Till > Anonymous Usage Data, and
stops sending anything as soon as you uncheck it (any leftover scheduled
tasks then do nothing). If you had opted in, deactivating the plugin sends one final `deactivation` event (described below).

**Site identifier**: every event includes a `site_id` — a SHA-256 hash of
your site's URL. This is a stable, unique-per-install pseudonymous
identifier, not full anonymity: because it's stable, events from your site
can be correlated with each other over time (e.g. to see version-upgrade
history), even though your actual site URL, domain, or any other
identifying detail is never transmitted.

**What's sent, by event type** (the `feature_usage`, `error` and `performance` events are supported by the code but the plugin does not currently trigger them; they are listed so the disclosure stays complete if that changes):

* `heartbeat` (weekly) — WordPress, WooCommerce, PHP, and MySQL version
  numbers; server software string; PHP memory limit and max execution
  time; whether OpenSSL and cURL are available; whether High-Performance
  Order Storage (HPOS) is enabled; site language and timezone; whether the
  site is a WordPress Multisite install; whether stored M-Pesa credentials
  are encrypted (a boolean only — never the credentials themselves).
* `daily_stats` (daily) — transaction counts by status (completed/pending/
  failed), success rate, **minimum/maximum/average transaction amounts**,
  and a distribution of M-Pesa result/failure codes. No order IDs, phone
  numbers, or customer identities are included — only aggregate numbers.
* `feature_usage` (weekly aggregate) — which plugin features were used and
  how many times, with no reference to which orders/customers triggered
  them.
* `error` — an error category and code (e.g. `api_error` / `1037`), plus a
  short sanitized context string. The context field only ever contains the
  fixed category labels already used internally by the plugin's error
  tracking — never raw request bodies, stack traces, or user input.
* `performance` (weekly aggregate) — timing metrics (count/min/max/average
  duration in milliseconds) for named internal operations, with no
  reference to which orders they came from.
* `deactivation` — sent once when the plugin is deactivated (and only if you
  had opted in): just the event name, the anonymous site identifier, the
  plugin version and a timestamp. No event is sent on activation.

**Never included, in any event, ever**: phone numbers, order details,
customer names or addresses, or M-Pesa credentials (Consumer Key/Secret,
Passkey, callback secret) in any form.

Telemetry, when enabled, is sent to a dedicated collector endpoint operated
by the plugin author (`telemetry.billtoolbox.com`) — a separate WordPress
install used only for this purpose, isolated from any other site.

**Also recorded by the collector**: like any web server, it receives the IP
address of the server that sends each event, and stores it with the event. It
is used only for rate limiting and abuse investigation and is not shown on the
collector's dashboard. Events (including that IP address) are currently kept
until they are deleted manually — there is no automatic expiry. To have the
events for your site removed, ask in this plugin's support forum on
WordPress.org and include your site's identifier (`site_id`: the SHA-256 hash
of your site URL described above).

== Credits ==

Developed by: Martin Mburu
Based on: Safaricom Daraja API
Uses: WooCommerce Payment Gateway API
Blocks Integration: WooCommerce Blocks API

= Third-party libraries =

* [Chart.js](https://www.chartjs.org/) 4.5.1 (MIT License) — draws the charts on the Reports page. Bundled locally as `assets/js/chart.umd.js` (the library's own official minified build, unmodified). Human-readable source: https://github.com/chartjs/Chart.js (the `v4.5.1` tag) or https://www.npmjs.com/package/chart.js/v/4.5.1.

== Additional Information ==

* Source code: https://github.com/marto-karanja/marupurupu-checkout-for-mpesa
* API Reference: https://developer.safaricom.co.ke/docs
* WooCommerce Blocks: https://woocommerce.com/checkout-blocks/

This plugin is not officially affiliated with, endorsed by, or sponsored by Safaricom or M-Pesa. It integrates with Safaricom's publicly documented Daraja API.

== Support ==

Need help?
1. Read the FAQ above.
2. Turn on WordPress debug logging (WP_DEBUG_LOG) and check WooCommerce > Status > Logs (source "mpesa-till-callback") and wp-content/debug.log.
3. Ask in this plugin's support forum on WordPress.org, with your WordPress, WooCommerce and PHP versions and the relevant log lines. Never post your Consumer Key, Consumer Secret or Passkey.
