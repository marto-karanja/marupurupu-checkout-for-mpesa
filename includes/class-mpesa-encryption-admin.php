<?php
/**
 * M-Pesa Encryption Admin Utilities
 *
 * Provides admin interface for encryption management and migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Encryption_Admin {

    /**
     * Option names for one-time-dismissible notices, keyed by the short
     * name used in the dismiss AJAX request.
     *
     * @var array
     */
    private static $dismissible_notices = array(
        'encryption' => 'marupurupu_encryption_notice_dismissed',
        'weak_key'   => 'marupurupu_weak_key_notice_dismissed',
    );

    /**
     * Initialize admin utilities
     */
    public static function init() {
        // Add admin notice if credentials need migration
        add_action('admin_notices', array(__CLASS__, 'migration_notice'));

        // Warn if the encryption key falls back to a publicly-derivable value
        add_action('admin_notices', array(__CLASS__, 'weak_key_notice'));

        // Warn about active encryption/decryption failures
        add_action('admin_notices', array(__CLASS__, 'encryption_problem_notice'));

        // One-time notice explaining the 2026-08-25 encryption format
        // upgrade, if it cleared this site's stored credentials.
        add_action('admin_notices', array(__CLASS__, 'credentials_reset_notice'));

        // Add encryption status to plugin settings page
        add_action('woocommerce_settings_checkout', array(__CLASS__, 'add_encryption_status'));

        // Add a "Test Connection" box so a merchant can confirm the
        // currently-saved credentials actually work against Safaricom,
        // instead of only finding out via a failed checkout. Rendered after
        // add_encryption_status() above (both hook the same action, added
        // in this order).
        add_action('woocommerce_settings_checkout', array(__CLASS__, 'add_connection_test_box'));

        // Handle migration action
        add_action('admin_post_marupurupu_migrate_credentials', array(__CLASS__, 'handle_migration'));

        // Add encryption test action
        add_action('admin_post_marupurupu_test_encryption', array(__CLASS__, 'handle_encryption_test'));

        // Add M-Pesa connection test action
        add_action('admin_post_marupurupu_test_connection', array(__CLASS__, 'handle_test_connection'));

        // Persist notice dismissals (shared by migration_notice and weak_key_notice)
        add_action('wp_ajax_marupurupu_dismiss_notice', array(__CLASS__, 'ajax_dismiss_notice'));
    }

    /**
     * AJAX handler: persist that an admin dismissed a one-time notice.
     */
    public static function ajax_dismiss_notice() {
        check_ajax_referer('marupurupu_dismiss_notice', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('', '', array('response' => 403));
        }

        $notice = isset($_POST['notice']) ? sanitize_key(wp_unslash($_POST['notice'])) : '';

        if (isset(self::$dismissible_notices[$notice])) {
            update_option(self::$dismissible_notices[$notice], true);
        }

        wp_die();
    }

    /**
     * Check if credentials need migration
     *
     * @return bool True if migration needed
     */
    public static function needs_migration() {
        $gateway = self::get_gateway();

        if (!$gateway) {
            return false;
        }

        // Check if any credential is unencrypted
        $fields_to_check = array('consumer_key', 'consumer_secret', 'test_consumer_key', 'test_consumer_secret', 'passkey');

        foreach ($fields_to_check as $field) {
            $value = $gateway->get_option($field);

            if (!empty($value) && !Marupurupu_Encryption::is_encrypted($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display admin notice if migration is needed
     */
    public static function migration_notice() {
        // Only show on relevant admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings', 'plugins'))) {
            return;
        }

        // Check if migration is needed
        if (!self::needs_migration()) {
            return;
        }

        // Don't show if user dismissed
        if (get_option('marupurupu_encryption_notice_dismissed', false)) {
            return;
        }

        ?>
        <div class="notice notice-warning is-dismissible" id="mpesa-encryption-notice">
            <p>
                <strong><?php esc_html_e('M-Pesa Till Payment Gateway:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                <?php esc_html_e('Your API credentials are currently stored unencrypted. We recommend migrating to encrypted storage for enhanced security.', 'marupurupu-checkout-for-mpesa'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=marupurupu_migrate_credentials'), 'marupurupu_migrate')); ?>" class="button button-primary">
                    <?php esc_html_e('Migrate to Encrypted Storage', 'marupurupu-checkout-for-mpesa'); ?>
                </a>
                <button type="button" class="button mpesa-dismiss-notice" data-notice="encryption" data-target="mpesa-encryption-notice">
                    <?php esc_html_e('Dismiss', 'marupurupu-checkout-for-mpesa'); ?>
                </button>
            </p>
        </div>
        <?php
        self::enqueue_notice_script();
    }

    /**
     * Enqueue the script that persists a notice's "Dismiss" click (shared by
     * migration_notice() and weak_key_notice()). Safe to call more than
     * once per page -- WordPress only registers a handle once.
     */
    private static function enqueue_notice_script() {
        wp_enqueue_script('marupurupu-admin-notices', MARUPURUPU_PLUGIN_URL . 'assets/js/mpesa-admin-notices.js', array('jquery'), MARUPURUPU_VERSION, true);
        wp_localize_script('marupurupu-admin-notices', 'marupurupuAdminNotices', array(
            'nonce' => wp_create_nonce('marupurupu_dismiss_notice'),
        ));
    }

    /**
     * Display admin notice if the encryption key falls back to a value
     * derivable from the site's public URL (AUTH_KEY/SECURE_AUTH_KEY unset
     * or left at the WordPress placeholder in wp-config.php).
     */
    public static function weak_key_notice() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings', 'plugins'))) {
            return;
        }

        if (!Marupurupu_Encryption::is_using_weak_key()) {
            return;
        }

        if (get_option('marupurupu_weak_key_notice_dismissed', false)) {
            return;
        }

        ?>
        <div class="notice notice-error is-dismissible" id="mpesa-weak-key-notice">
            <p>
                <strong><?php esc_html_e('M-Pesa Till Payment Gateway:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                <?php esc_html_e('WordPress security keys (AUTH_KEY / SECURE_AUTH_KEY) are not configured in wp-config.php. Your stored M-Pesa credentials are currently encrypted with a key derived from your site URL, which is public and guessable. Please configure real security keys as soon as possible.', 'marupurupu-checkout-for-mpesa'); ?>
            </p>
            <p>
                <a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e('Generate WordPress security keys', 'marupurupu-checkout-for-mpesa'); ?>
                </a>
                <button type="button" class="button mpesa-dismiss-notice" data-notice="weak_key" data-target="mpesa-weak-key-notice">
                    <?php esc_html_e('Dismiss', 'marupurupu-checkout-for-mpesa'); ?>
                </button>
            </p>
        </div>
        <?php
        self::enqueue_notice_script();
    }

    /**
     * Display admin notices for active encryption/decryption failures.
     * Not dismissible -- Marupurupu_Encryption clears the underlying option as
     * soon as an encrypt/decrypt call succeeds again, so these disappear on
     * their own once the real problem is fixed rather than being silenced
     * while still broken.
     */
    public static function encryption_problem_notice() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings', 'plugins'))) {
            return;
        }

        if (get_option('marupurupu_encryption_degraded')) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('M-Pesa Till Payment Gateway:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php esc_html_e('A credential was just saved WITHOUT encryption because encryption failed (OpenSSL unavailable or an internal error -- see your PHP error log). Fix the underlying issue, then re-save your M-Pesa settings to re-encrypt it.', 'marupurupu-checkout-for-mpesa'); ?>
                </p>
            </div>
            <?php
        }

        if (get_option('marupurupu_decryption_key_mismatch')) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('M-Pesa Till Payment Gateway:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php esc_html_e('Stored M-Pesa credentials could not be decrypted -- the encryption key (WordPress AUTH_KEY/SECURE_AUTH_KEY) may have changed since they were saved. Re-enter and save your credentials on the M-Pesa settings page.', 'marupurupu-checkout-for-mpesa'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Notice explaining why credentials were cleared during the 2026-08-25
     * encryption format upgrade (AES-256-CBC -> AES-256-GCM, with an
     * explicit format marker replacing the old shape-based guess -- see
     * class-mpesa-encryption.php's file-level doc comment for the full
     * story). Not auto-dismissed: it clears itself only once credentials
     * have actually been re-entered and saved (mirrors how
     * encryption_problem_notice() self-clears on the next successful
     * encrypt/decrypt), so a merchant can't accidentally dismiss it away
     * and forget mid-task that checkout is unconfigured until they do.
     */
    public static function credentials_reset_notice() {
        if (!get_option('marupurupu_credentials_cleared_notice')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings', 'plugins'))) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('M-Pesa Till Payment Gateway:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                <?php esc_html_e('Your stored M-Pesa API credentials were cleared as part of a security upgrade to how they\'re encrypted. This was a deliberate, one-time reset -- not an error -- because safely converting the old encrypted values wasn\'t possible without risking silently corrupted credentials being used against a live payment API. Please re-enter and save your Consumer Key, Consumer Secret, and Passkey on the M-Pesa settings page before accepting payments again.', 'marupurupu-checkout-for-mpesa'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=mpesa_till')); ?>" class="button button-primary">
                    <?php esc_html_e('Go to M-Pesa Settings', 'marupurupu-checkout-for-mpesa'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Add encryption status to settings page
     */
    public static function add_encryption_status() {
        $screen = get_current_screen();

        // Only show on checkout settings page
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') {
            return;
        }

        // Only show when viewing payment gateway settings
        if (!isset($_GET['section']) || sanitize_text_field(wp_unslash($_GET['section'])) !== 'mpesa_till') {
            return;
        }

        $gateway = self::get_gateway();
        if (!$gateway) {
            return;
        }

        // Check encryption status
        $all_encrypted = !self::needs_migration();
        $encryption_working = Marupurupu_Encryption::test_encryption();
        $openssl_available = function_exists('openssl_encrypt');

        ?>
        <div class="mpesa-encryption-status" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid <?php echo $all_encrypted ? '#46b450' : '#ffb900'; ?>;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Encryption Status', 'marupurupu-checkout-for-mpesa'); ?></h3>

            <table class="widefat" style="max-width: 600px;">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('OpenSSL Extension', 'marupurupu-checkout-for-mpesa'); ?></strong></td>
                        <td>
                            <?php if ($openssl_available): ?>
                                <span style="color: #46b450;">✓ <?php esc_html_e('Available', 'marupurupu-checkout-for-mpesa'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php esc_html_e('Not Available', 'marupurupu-checkout-for-mpesa'); ?></span>
                                <p style="margin: 5px 0 0 0; color: #dc3232;">
                                    <?php esc_html_e('OpenSSL PHP extension is required for encryption. Please enable it in your PHP configuration.', 'marupurupu-checkout-for-mpesa'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Encryption Test', 'marupurupu-checkout-for-mpesa'); ?></strong></td>
                        <td>
                            <?php if ($encryption_working): ?>
                                <span style="color: #46b450;">✓ <?php esc_html_e('Working', 'marupurupu-checkout-for-mpesa'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php esc_html_e('Failed', 'marupurupu-checkout-for-mpesa'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Credentials Status', 'marupurupu-checkout-for-mpesa'); ?></strong></td>
                        <td>
                            <?php if ($all_encrypted): ?>
                                <span style="color: #46b450;">✓ <?php esc_html_e('All credentials encrypted', 'marupurupu-checkout-for-mpesa'); ?></span>
                            <?php else: ?>
                                <span style="color: #ffb900;">⚠ <?php esc_html_e('Some credentials unencrypted', 'marupurupu-checkout-for-mpesa'); ?></span>
                                <p style="margin: 5px 0 0 0;">
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=marupurupu_migrate_credentials'), 'marupurupu_migrate')); ?>" class="button button-small">
                                        <?php esc_html_e('Migrate Now', 'marupurupu-checkout-for-mpesa'); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Encryption Method', 'marupurupu-checkout-for-mpesa'); ?></strong></td>
                        <td>AES-256-GCM</td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Encryption Key Source', 'marupurupu-checkout-for-mpesa'); ?></strong></td>
                        <td>
                            <?php
                            if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here') {
                                echo '<span style="color: #46b450;">✓ ' . esc_html__('WordPress AUTH_KEY', 'marupurupu-checkout-for-mpesa') . '</span>';
                            } elseif (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== 'put your unique phrase here') {
                                echo '<span style="color: #ffb900;">⚠ ' . esc_html__('WordPress SECURE_AUTH_KEY (fallback)', 'marupurupu-checkout-for-mpesa') . '</span>';
                            } else {
                                echo '<span style="color: #dc3232;">✗ ' . esc_html__('Site URL (weak fallback)', 'marupurupu-checkout-for-mpesa') . '</span>';
                                echo '<p style="margin: 5px 0 0 0; color: #dc3232;">';
                                esc_html_e('Please configure WordPress security keys in wp-config.php for stronger encryption.', 'marupurupu-checkout-for-mpesa');
                                echo ' <a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank">' . esc_html__('Generate keys', 'marupurupu-checkout-for-mpesa') . '</a>';
                                echo '</p>';
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin: 15px 0 0 0; font-size: 12px; color: #666;">
                <?php esc_html_e('Credentials are encrypted using AES-256-GCM (authenticated encryption) when saved to the database. They are automatically decrypted when loaded for use.', 'marupurupu-checkout-for-mpesa'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render a "Test Connection" box on the M-Pesa settings page.
     *
     * Attempts a real OAuth token request to Safaricom using whatever
     * credentials are currently *saved* (not whatever is typed into the
     * form but not yet submitted -- there's no way to test an unsaved
     * value without either exposing it in a GET request or standing up a
     * separate AJAX endpoint that re-implements the same encrypt/validate
     * logic as process_admin_options(); testing the saved value keeps this
     * simple and matches how the "Test Encryption" button above already
     * works). Exists specifically because a blank-looking credential field
     * with a value already saved gives no way to tell "saved and correct"
     * from "saved but wrong" until a real checkout fails -- see the
     * 2026-08-28 bonbargains.com incident notes in PROGRESS.md.
     */
    public static function add_connection_test_box() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') {
            return;
        }

        if (!isset($_GET['section']) || sanitize_text_field(wp_unslash($_GET['section'])) !== 'mpesa_till') {
            return;
        }

        $gateway = self::get_gateway();
        if (!$gateway) {
            return;
        }

        $mode_label = $gateway->testmode
            ? __('Test/Sandbox', 'marupurupu-checkout-for-mpesa')
            : __('Live/Production', 'marupurupu-checkout-for-mpesa');

        ?>
        <div class="mpesa-connection-test" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #666;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Test M-Pesa Connection', 'marupurupu-checkout-for-mpesa'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: "Test/Sandbox" or "Live/Production" */
                    wp_kses_post(__('Checks the currently saved Consumer Key/Secret (%s mode) against Safaricom right now, and tells you plainly whether they work -- rather than waiting for a customer\'s checkout to fail.', 'marupurupu-checkout-for-mpesa')),
                    '<strong>' . esc_html($mode_label) . '</strong>'
                );
                ?>
            </p>
            <p>
                <?php if (empty($gateway->consumer_key) || empty($gateway->consumer_secret)): ?>
                    <em><?php esc_html_e('No Consumer Key/Secret saved for the current mode yet -- save your settings first.', 'marupurupu-checkout-for-mpesa'); ?></em>
                <?php else: ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=marupurupu_test_connection'), 'marupurupu_test_connection')); ?>" class="button button-primary">
                        <?php esc_html_e('Test Connection Now', 'marupurupu-checkout-for-mpesa'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the "Test Connection" action: attempt a real OAuth token
     * request with the currently saved credentials and redirect back with
     * a plain-language result. Never passes the credentials themselves
     * through the URL or the resulting notice -- only Marupurupu_API::test_connection()'s
     * already-scrubbed message.
     */
    public static function handle_test_connection() {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'marupurupu_test_connection')) {
            wp_die(esc_html__('Security check failed', 'marupurupu-checkout-for-mpesa'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action', 'marupurupu-checkout-for-mpesa'));
        }

        $gateway = self::get_gateway();
        $result = array(
            'success' => false,
            'message' => __('M-Pesa gateway not found.', 'marupurupu-checkout-for-mpesa'),
        );

        if ($gateway) {
            $api = new Marupurupu_API(
                $gateway->consumer_key,
                $gateway->consumer_secret,
                $gateway->shortcode,
                $gateway->till_number,
                $gateway->passkey,
                $gateway->testmode
            );

            $result = $api->test_connection();
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'mpesa_till',
                'marupurupu_connection_test' => $result['success'] ? 'success' : 'failed',
                'marupurupu_connection_message' => rawurlencode($result['message']),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handle credential migration
     */
    public static function handle_migration() {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'marupurupu_migrate')) {
            wp_die(esc_html__('Security check failed', 'marupurupu-checkout-for-mpesa'));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action', 'marupurupu-checkout-for-mpesa'));
        }

        // Perform migration
        $result = Marupurupu_Encryption::migrate_credentials();

        // Redirect back with message
        $redirect_url = add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'mpesa_till',
                'marupurupu_migration' => $result['success'] ? 'success' : 'failed',
                'marupurupu_migrated_count' => $result['migrated']
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handle encryption test
     */
    public static function handle_encryption_test() {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'marupurupu_test_encryption')) {
            wp_die(esc_html__('Security check failed', 'marupurupu-checkout-for-mpesa'));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action', 'marupurupu-checkout-for-mpesa'));
        }

        // Run test
        $success = Marupurupu_Encryption::test_encryption();

        // Redirect back with message
        $redirect_url = add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'mpesa_till',
                'marupurupu_encryption_test' => $success ? 'passed' : 'failed'
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Get M-Pesa gateway instance
     *
     * @return Marupurupu_Gateway|null
     */
    private static function get_gateway() {
        if (!function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['mpesa_till']) ? $gateways['mpesa_till'] : null;
    }

    /**
     * Display migration success/failure messages
     */
    public static function display_migration_messages() {
        if (!isset($_GET['marupurupu_migration'])) {
            return;
        }

        $migration_result = sanitize_text_field(wp_unslash($_GET['marupurupu_migration']));

        if ($migration_result === 'success') {
            $count = isset($_GET['marupurupu_migrated_count']) ? intval($_GET['marupurupu_migrated_count']) : 0;
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('M-Pesa Encryption:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php
                    /* translators: %d: number of credentials migrated */
                    printf(esc_html__('Successfully migrated %d credential(s) to encrypted storage.', 'marupurupu-checkout-for-mpesa'), absint($count));
                    ?>
                </p>
            </div>
            <?php
        } elseif ($migration_result === 'failed') {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e('M-Pesa Encryption:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php esc_html_e('Migration failed. Please check error logs.', 'marupurupu-checkout-for-mpesa'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Display encryption test messages
     */
    public static function display_test_messages() {
        if (!isset($_GET['marupurupu_encryption_test'])) {
            return;
        }

        $test_result = sanitize_text_field(wp_unslash($_GET['marupurupu_encryption_test']));

        if ($test_result === 'passed') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('M-Pesa Encryption:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php esc_html_e('Encryption test passed successfully.', 'marupurupu-checkout-for-mpesa'); ?>
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e('M-Pesa Encryption:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                    <?php esc_html_e('Encryption test failed. Please check that OpenSSL is enabled.', 'marupurupu-checkout-for-mpesa'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Display M-Pesa connection test result. Message text comes from
     * Marupurupu_API::test_connection(), which never includes credentials or
     * Safaricom's raw response body -- safe to esc_html() and print as-is.
     */
    public static function display_connection_test_messages() {
        if (!isset($_GET['marupurupu_connection_test'])) {
            return;
        }

        $passed = sanitize_text_field(wp_unslash($_GET['marupurupu_connection_test'])) === 'success';
        // Note: no rawurldecode() here -- add_query_arg() doesn't re-encode
        // values passed to it (only pre-existing query args get
        // urlencode_deep()'d), so the rawurlencode() applied when building
        // this URL in handle_test_connection() is the only encoding layer;
        // PHP's own query-string parsing already undoes it once into $_GET.
        $message = isset($_GET['marupurupu_connection_message'])
            ? sanitize_text_field(wp_unslash($_GET['marupurupu_connection_message']))
            : '';

        $notice_class = $passed ? 'notice-success' : 'notice-error';
        $icon = $passed ? '✓' : '✗';
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
            <p>
                <strong><?php esc_html_e('M-Pesa Connection Test:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                <?php echo esc_html($icon . ' ' . $message); ?>
            </p>
        </div>
        <?php
    }
}

// Initialize admin notices
add_action('admin_notices', array('Marupurupu_Encryption_Admin', 'display_migration_messages'));
add_action('admin_notices', array('Marupurupu_Encryption_Admin', 'display_test_messages'));
add_action('admin_notices', array('Marupurupu_Encryption_Admin', 'display_connection_test_messages'));

// Initialize admin utilities
Marupurupu_Encryption_Admin::init();
