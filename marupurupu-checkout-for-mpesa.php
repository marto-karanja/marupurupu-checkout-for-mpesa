<?php
/**
 * Plugin Name: Marupurupu Checkout for M-Pesa and WooCommerce
 * Plugin URI: https://github.com/marto-karanja/marupurupu-checkout-for-mpesa
 * Description: Accept M-Pesa Till payments via STK Push for WooCommerce
 * Version: 1.6.0
 * Author: Martin Mburu
 * Author URI: https://billtoolbox.com
 * Text Domain: marupurupu-checkout-for-mpesa
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

/*
 * Naming: everything this plugin defines in PHP's or WordPress's global
 * namespace is prefixed marupurupu_ / Marupurupu_ / MARUPURUPU_. The few
 * identifiers below deliberately keep the older "mpesa_till" name because
 * they are stored in the database or handed to third parties, so renaming them
 * would orphan existing data:
 *   - gateway id "mpesa_till": saved on every order as its payment method, and
 *     it names the settings option (woocommerce_mpesa_till_settings)
 *   - the Safaricom callback endpoint /wc-api/wc_mpesa_till_callback/ (and its
 *     woocommerce_api_wc_mpesa_till_callback hook)
 *   - the credential-encryption format marker and key-derivation label in
 *     class-mpesa-encryption.php (changing them would make stored credentials
 *     undecryptable)
 */

// Define plugin constants
define('MARUPURUPU_VERSION', '1.6.0');
define('MARUPURUPU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARUPURUPU_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'marupurupu_woocommerce_missing_notice');
    return;
}

function marupurupu_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Marupurupu Checkout for M-Pesa and WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Declare WooCommerce feature compatibility: HPOS (custom order tables) and
 * the Cart & Checkout blocks. Without the blocks declaration WooCommerce lists
 * this gateway as "incompatible" on its Features screen even though
 * Marupurupu_Blocks_Support registers it for the block checkout.
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Register block-based checkout support.
 * Must be at top level (not inside plugins_loaded) so it hooks in
 * before WooCommerce Blocks fires woocommerce_blocks_loaded at priority 10.
 */
add_action('woocommerce_blocks_loaded', 'marupurupu_register_blocks_support');

/**
 * Initialize the gateway
 */
add_action('plugins_loaded', 'marupurupu_init', 11);

function marupurupu_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // One-time move of data stored under this plugin's earlier "mpesa_*" names
    // (options, the transactions table, scheduled events) to the marupurupu_*
    // names. Must run before anything below reads that data.
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-marupurupu-migration.php';
    Marupurupu_Migration::run();

    // Include required files
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-encryption.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-encryption-admin.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-wc-mpesa-till-gateway.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-api.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-callback.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-helpers.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-admin-page.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-ajax.php';
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-order-received.php';

    // Reports (charts/revenue dashboard) is loaded through the feature gate
    // rather than unconditionally — currently always enabled (free for
    // everyone, no change in behavior), but this is the seam a future
    // Pro-tier decision hooks into instead of touching this file. See the
    // doc comment at the top of class-mpesa-feature-gate.php before
    // changing this.
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-feature-gate.php';
    if (Marupurupu_Feature_Gate::reports_enabled()) {
        require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-reports.php';
    }

    // Telemetry (opt-in, default off — see class-mpesa-telemetry.php::is_enabled())
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-telemetry.php';

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'marupurupu_add_gateway');

    // Auto-encrypt credentials on first load (one-time migration)
    add_action('admin_init', 'marupurupu_check_encryption', 5);

    // Record whether this is a pre-existing install, for a future Pro-tier
    // decision (see class-mpesa-feature-gate.php's doc comment). Same
    // "run once via admin_init" pattern as the encryption check above,
    // for the same reason: register_activation_hook alone would miss any
    // site that updates in place without deactivating first.
    add_action('admin_init', 'marupurupu_check_legacy_grandfather', 5);
}

function marupurupu_add_gateway($gateways) {
    $gateways[] = 'Marupurupu_Gateway';
    return $gateways;
}

/**
 * Create transaction table on activation
 */
register_activation_hook(__FILE__, 'marupurupu_activate');

function marupurupu_activate() {
    global $wpdb;

    // Move any data stored under the earlier "mpesa_*" names first, so dbDelta
    // below doesn't create a second, empty transactions table next to the old one.
    require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-marupurupu-migration.php';
    Marupurupu_Migration::run();

    $table_name = $wpdb->prefix . 'marupurupu_transactions';
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
    marupurupu_auto_encrypt_credentials();

    // Record pre-existing-install status for a future Pro-tier decision.
    // See marupurupu_determine_legacy_grandfather() for what this means
    // and why it's captured on both activation and admin_init.
    marupurupu_determine_legacy_grandfather();
}

/**
 * Automatically encrypt existing unencrypted credentials
 * Called on plugin activation to ensure all credentials are encrypted
 */
function marupurupu_auto_encrypt_credentials() {
    // Only run if encryption class is available
    if (!class_exists('Marupurupu_Encryption')) {
        require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-encryption.php';
    }

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
        if (!Marupurupu_Encryption::is_encrypted($value)) {
            $settings[$field] = Marupurupu_Encryption::encrypt($value);
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
function marupurupu_check_encryption() {
    // Only run once (check if migration already done)
    if (get_option('marupurupu_encryption_checked')) {
        return;
    }

    // Mark as checked immediately to prevent multiple runs
    update_option('marupurupu_encryption_checked', true);

    // Run auto-encryption
    marupurupu_auto_encrypt_credentials();
}

/**
 * Check whether this site's pre-existing-install status has been recorded
 * yet (one-time, same pattern as marupurupu_check_encryption() above).
 * This is the admin_init half of grandfather detection — it exists
 * specifically because register_activation_hook() only fires on a fresh
 * activate, and does NOT re-fire when an already-active plugin is simply
 * updated to a new version in place. Most real users update rather than
 * deactivate-then-reactivate, so relying on the activation hook alone
 * would miss the exact sites this is meant to protect.
 */
function marupurupu_check_legacy_grandfather() {
    // Only run once (check if already determined)
    if (get_option('marupurupu_legacy_grandfather_checked')) {
        return;
    }

    // Mark as checked immediately to prevent multiple runs
    update_option('marupurupu_legacy_grandfather_checked', true);

    marupurupu_determine_legacy_grandfather();
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
function marupurupu_determine_legacy_grandfather() {
    // Already grandfathered from a previous run — never re-evaluate.
    if (get_option('marupurupu_legacy_full_access') === true) {
        return;
    }

    $existing_settings = get_option('woocommerce_mpesa_till_settings', false);

    if ($existing_settings !== false) {
        update_option('marupurupu_legacy_full_access', true);
    }
}

/**
 * Register block-based checkout support
 * Enables M-Pesa to work with WooCommerce block-based checkout
 */
function marupurupu_register_blocks_support() {
    // Check if WooCommerce Blocks is available
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        // Load blocks support class
        require_once MARUPURUPU_PLUGIN_DIR . 'includes/class-mpesa-blocks-support.php';

        // Register payment method with blocks
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new Marupurupu_Blocks_Support());
            }
        );
    }
}

/**
 * Add settings link on plugin page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'marupurupu_action_links');

function marupurupu_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mpesa_till') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
