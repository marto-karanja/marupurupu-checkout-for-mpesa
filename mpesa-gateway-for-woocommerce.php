<?php
/**
 * Plugin Name: M-Pesa Gateway for WooCommerce
 * Plugin URI: https://billtoolbox.com
 * Description: Accept M-Pesa Till payments via STK Push for WooCommerce
 * Version: 1.5.0
 * Author: Martin Mburu
 * Author URI: https://billtoolbox.com
 * Text Domain: mpesa-gateway-for-woocommerce
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 10.8
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WC_MPESA_TILL_VERSION', '1.5.0');
define('WC_MPESA_TILL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MPESA_TILL_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'wc_mpesa_till_woocommerce_missing_notice');
    return;
}

function wc_mpesa_till_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>WooCommerce M-Pesa Till Payment Gateway</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Register block-based checkout support.
 * Must be at top level (not inside plugins_loaded) so it hooks in
 * before WooCommerce Blocks fires woocommerce_blocks_loaded at priority 10.
 */
add_action('woocommerce_blocks_loaded', 'wc_mpesa_till_register_blocks_support');

/**
 * Initialize the gateway
 */
add_action('plugins_loaded', 'wc_mpesa_till_init', 11);

function wc_mpesa_till_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Include required files
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-encryption.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-encryption-admin.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-wc-mpesa-till-gateway.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-api.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-callback.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-helpers.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-admin-page.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-ajax.php';
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-order-received.php';

    // Reports (charts/revenue dashboard) is loaded through the feature gate
    // rather than unconditionally — currently always enabled (free for
    // everyone, no change in behavior), but this is the seam a future
    // Pro-tier decision hooks into instead of touching this file. See the
    // doc comment at the top of class-mpesa-feature-gate.php before
    // changing this.
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-feature-gate.php';
    if (Mpesa_Feature_Gate::reports_enabled()) {
        require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-reports.php';
    }

    // Telemetry (opt-in, default off — see class-mpesa-telemetry.php::is_enabled())
    require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-telemetry.php';

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wc_mpesa_till_add_gateway');

    // Must run as its own independent hook, not nested inside
    // wc_mpesa_till_check_encryption() below -- that function is gated by
    // mpesa_till_encryption_checked, which is already permanently true on
    // any site that has been running an earlier version of this plugin,
    // so nesting the reset inside it would mean the reset never fires on
    // exactly the installs it matters most for. This function has its own
    // independent one-time latch, so it's safe to call unconditionally
    // here regardless of the encryption-checked latch's state. Priority 4
    // so it runs before the encryption check at priority 5 -- it must
    // clear old-format data before that pass tries to encrypt it.
    add_action('admin_init', 'wc_mpesa_till_reset_credentials_for_encryption_upgrade', 4);

    // Auto-encrypt credentials on first load (one-time migration)
    add_action('admin_init', 'wc_mpesa_till_check_encryption', 5);

    // Record whether this is a pre-existing install, for a future Pro-tier
    // decision (see class-mpesa-feature-gate.php's doc comment). Same
    // "run once via admin_init" pattern as the encryption check above,
    // for the same reason: register_activation_hook alone would miss any
    // site that updates in place without deactivating first.
    add_action('admin_init', 'wc_mpesa_till_check_legacy_grandfather', 5);
}

function wc_mpesa_till_add_gateway($gateways) {
    $gateways[] = 'WC_Mpesa_Till_Gateway';
    return $gateways;
}

/**
 * Create transaction table on activation
 */
register_activation_hook(__FILE__, 'wc_mpesa_till_activate');

function wc_mpesa_till_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mpesa_till_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        transaction_id varchar(50) DEFAULT NULL,
        merchant_request_id varchar(50) DEFAULT NULL,
        checkout_request_id varchar(50) DEFAULT NULL,
        phone_number varchar(20) NOT NULL,
        amount decimal(10,2) NOT NULL,
        result_code varchar(10) DEFAULT NULL,
        result_desc text DEFAULT NULL,
        status varchar(20) DEFAULT 'pending',
        request_data text DEFAULT NULL,
        response_data text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY checkout_request_id (checkout_request_id),
        KEY transaction_id (transaction_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Auto-migrate existing credentials to encrypted format
    // This runs on plugin activation/update
    wc_mpesa_till_auto_encrypt_credentials();

    // Record pre-existing-install status for a future Pro-tier decision.
    // See wc_mpesa_till_determine_legacy_grandfather() for what this means
    // and why it's captured on both activation and admin_init.
    wc_mpesa_till_determine_legacy_grandfather();
}

/**
 * One-time, irreversible: clear any stored M-Pesa API credentials when the
 * encryption format changes in a way that isn't backward compatible.
 *
 * class-mpesa-encryption.php was rewritten 2026-08-25 (AES-256-CBC with a
 * shape-based "is this encrypted?" guess -> AES-256-GCM with an explicit
 * format marker) specifically because the old guess had a real
 * false-positive that could silently store credentials unencrypted. That
 * rewrite was a deliberate decision not to support decrypting old-format
 * data (see that file's doc comment for the full reasoning).
 *
 * Without this function, wc_mpesa_till_auto_encrypt_credentials() below
 * would encounter old-format ciphertext, correctly notice it doesn't carry
 * the new format marker, and conclude it must be plaintext that needs
 * encrypting -- encrypting already-encrypted bytes a second time. The
 * result wouldn't error; it would silently produce a value that decrypts
 * one layer down into more ciphertext, which would then get sent to
 * Safaricom's live API as if it were the real credential. That's a much
 * worse failure than an obvious one: on a real production site (this
 * plugin is live at nairobistalls.com), a payment gateway should fail
 * loudly and safely, never silently with corrupted secrets.
 *
 * So instead: run exactly once (guarded by
 * mpesa_till_credentials_reset_2026_08_25), clear the credential fields if
 * any are set, and leave a clear trail -- an error_log entry plus
 * mpesa_till_credentials_cleared_for_encryption_upgrade, which
 * Mpesa_Encryption_Admin::credentials_reset_notice() turns into a visible
 * admin notice asking for re-entry. A merchant re-entering credentials
 * once is a minor, obvious inconvenience; a payment gateway silently using
 * wrong credentials is not.
 */
function wc_mpesa_till_reset_credentials_for_encryption_upgrade() {
    if (get_option('mpesa_till_credentials_reset_2026_08_25')) {
        return;
    }

    // Mark as done immediately, before doing the actual work, so this
    // can't accidentally run twice (e.g. if something below were to
    // trigger another code path that calls back into this function).
    update_option('mpesa_till_credentials_reset_2026_08_25', true);

    $settings = get_option('woocommerce_mpesa_till_settings', array());

    if (empty($settings)) {
        return; // fresh install, nothing to reset
    }

    $fields_to_clear = array('consumer_key', 'consumer_secret', 'test_consumer_key', 'test_consumer_secret', 'passkey');
    $cleared_any = false;

    foreach ($fields_to_clear as $field) {
        if (!empty($settings[$field])) {
            $settings[$field] = '';
            $cleared_any = true;
        }
    }

    if ($cleared_any) {
        update_option('woocommerce_mpesa_till_settings', $settings);
        update_option('mpesa_till_credentials_cleared_for_encryption_upgrade', true);
        error_log('M-Pesa: Cleared stored API credentials during the 2026-08-25 encryption-format upgrade. Re-entry required -- see the admin notice on the M-Pesa settings page.');
    }
}

/**
 * Automatically encrypt existing unencrypted credentials
 * Called on plugin activation to ensure all credentials are encrypted
 */
function wc_mpesa_till_auto_encrypt_credentials() {
    // Only run if encryption class is available
    if (!class_exists('Mpesa_Encryption')) {
        require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-encryption.php';
    }

    // Must run before the encrypt-scan below -- see its own doc comment
    // for why blindly encrypting whatever's currently stored would be
    // dangerous on a site with old-format credentials.
    wc_mpesa_till_reset_credentials_for_encryption_upgrade();

    // Get gateway settings
    $settings = get_option('woocommerce_mpesa_till_settings', array());

    if (empty($settings)) {
        return; // No settings saved yet
    }

    $fields_to_encrypt = array(
        'consumer_key',
        'consumer_secret',
        'test_consumer_key',
        'test_consumer_secret',
        'passkey'
    );

    $migrated = false;

    foreach ($fields_to_encrypt as $field) {
        // Skip if field is empty
        if (empty($settings[$field])) {
            continue;
        }

        $value = $settings[$field];

        // Only encrypt if not already encrypted
        if (!Mpesa_Encryption::is_encrypted($value)) {
            $settings[$field] = Mpesa_Encryption::encrypt($value);
            $migrated = true;
        }
    }

    // Save updated settings if any were migrated
    if ($migrated) {
        update_option('woocommerce_mpesa_till_settings', $settings);
        error_log('M-Pesa: Auto-encrypted credentials on plugin activation');
    }
}

/**
 * Check and encrypt credentials on admin load (one-time migration)
 * This ensures encryption happens even if user doesn't deactivate/reactivate
 */
function wc_mpesa_till_check_encryption() {
    // Only run once (check if migration already done)
    if (get_option('mpesa_till_encryption_checked')) {
        return;
    }

    // Mark as checked immediately to prevent multiple runs
    update_option('mpesa_till_encryption_checked', true);

    // Run auto-encryption
    wc_mpesa_till_auto_encrypt_credentials();
}

/**
 * Check whether this site's pre-existing-install status has been recorded
 * yet (one-time, same pattern as wc_mpesa_till_check_encryption() above).
 * This is the admin_init half of grandfather detection — it exists
 * specifically because register_activation_hook() only fires on a fresh
 * activate, and does NOT re-fire when an already-active plugin is simply
 * updated to a new version in place. Most real users update rather than
 * deactivate-then-reactivate, so relying on the activation hook alone
 * would miss the exact sites this is meant to protect.
 */
function wc_mpesa_till_check_legacy_grandfather() {
    // Only run once (check if already determined)
    if (get_option('mpesa_till_legacy_grandfather_checked')) {
        return;
    }

    // Mark as checked immediately to prevent multiple runs
    update_option('mpesa_till_legacy_grandfather_checked', true);

    wc_mpesa_till_determine_legacy_grandfather();
}

/**
 * Determine and permanently record whether this site was already a
 * configured M-Pesa Till install before any Pro-tier gating existed in the
 * code. See class-mpesa-feature-gate.php's file-level doc comment for the
 * full "why" — short version: nothing consults this flag yet (every
 * feature is free for everyone today), but capturing it now means a future
 * version that DOES start gating a feature can grandfather existing users
 * automatically, without ever needing to guess "was this site already here
 * before the cutover?" after the fact.
 *
 * Detection signal: the `woocommerce_mpesa_till_settings` option already
 * existing (even with blank credential fields) means WooCommerce's settings
 * API has already saved a form submission for this gateway at least once,
 * which only happens if a human visited the settings screen before this
 * code ever ran — i.e., a real pre-existing install, not a fresh one
 * starting today. A brand-new install has no such option yet, so it's
 * correctly left un-grandfathered.
 *
 * Deliberately a one-way latch: once set to true, later runs never
 * re-evaluate or unset it, so this can't accidentally un-grandfather a
 * site just because, say, someone reset their settings.
 */
function wc_mpesa_till_determine_legacy_grandfather() {
    // Already grandfathered from a previous run — never re-evaluate.
    if (get_option('mpesa_till_legacy_full_access') === true) {
        return;
    }

    $existing_settings = get_option('woocommerce_mpesa_till_settings', false);

    if ($existing_settings !== false) {
        update_option('mpesa_till_legacy_full_access', true);
    }
}

/**
 * Register block-based checkout support
 * Enables M-Pesa to work with WooCommerce block-based checkout
 */
function wc_mpesa_till_register_blocks_support() {
    // Check if WooCommerce Blocks is available
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        // Load blocks support class
        require_once WC_MPESA_TILL_PLUGIN_DIR . 'includes/class-mpesa-blocks-support.php';

        // Register payment method with blocks
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Mpesa_Till_Blocks_Support());
            }
        );
    }
}

/**
 * Add settings link on plugin page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_mpesa_till_action_links');

function wc_mpesa_till_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mpesa_till') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
