<?php
/**
 * M-Pesa Telemetry - Anonymous Usage Tracking
 *
 * Collects anonymous usage data to improve the plugin (opt-in only)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Telemetry {

    /**
     * Telemetry endpoint URL
     */
    const ENDPOINT = 'https://telemetry.billtoolbox.com/wp-json/mpesa-telemetry/v1/collect';

    /**
     * Shared-secret header value. Noise filter only (rejects scanners/accidental
     * hits) — NOT real access control, since this source ships publicly on
     * WordPress.org and the string is readable by anyone who downloads the
     * plugin. Real defenses live server-side: rate limiting, strict input
     * validation, and output escaping in the collector/dashboard.
     */
    const SHARED_SECRET = '035ad1ac3e5721140e608bffbc1250c23ce9960f338273e0';

    /**
     * Initialize telemetry
     */
    public static function init() {
        // Only initialize if user opted in
        if (!self::is_enabled()) {
            return;
        }

        // Schedule events
        add_action('marupurupu_weekly_heartbeat', array(__CLASS__, 'send_heartbeat'));
        add_action('marupurupu_daily_stats', array(__CLASS__, 'send_daily_stats'));

        // Hook into plugin events
        add_action('marupurupu_payment_completed', array(__CLASS__, 'track_payment_success'));
        add_action('marupurupu_payment_failed', array(__CLASS__, 'track_payment_failure'));
        add_action('marupurupu_feature_used', array(__CLASS__, 'track_feature_usage'), 10, 2);

        // Track errors
        add_action('marupurupu_error_occurred', array(__CLASS__, 'track_error'), 10, 3);

        // Schedule recurring events if not already scheduled
        if (!wp_next_scheduled('marupurupu_weekly_heartbeat')) {
            wp_schedule_event(time(), 'weekly', 'marupurupu_weekly_heartbeat');
        }

        if (!wp_next_scheduled('marupurupu_daily_stats')) {
            wp_schedule_event(strtotime('tomorrow 3am'), 'daily', 'marupurupu_daily_stats');
        }
    }

    /**
     * Check if telemetry is enabled
     */
    public static function is_enabled() {
        $gateway = self::get_gateway();

        if (!$gateway) {
            return false;
        }

        return $gateway->get_option('telemetry_enabled', 'no') === 'yes';
    }

    /**
     * Get anonymized site identifier
     */
    private static function get_site_id() {
        // Use hash of site URL as anonymous identifier
        return hash('sha256', get_site_url());
    }

    /**
     * Send telemetry data
     */
    private static function send($data) {
        if (!self::is_enabled()) {
            return false;
        }

        // Add common fields
        $data['site_id'] = self::get_site_id();
        $data['plugin_version'] = MARUPURUPU_VERSION;
        $data['timestamp'] = current_time('mysql');

        // Send non-blocking request
        $response = wp_remote_post(self::ENDPOINT, array(
            'body' => wp_json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'WC-Mpesa-Till/' . MARUPURUPU_VERSION,
                'X-Mpesa-Telemetry-Key' => self::SHARED_SECRET
            ),
            'timeout' => 5,
            'blocking' => false, // Don't wait for response
            'sslverify' => true
        ));

        return !is_wp_error($response);
    }

    /**
     * Send weekly heartbeat
     */
    public static function send_heartbeat() {
        global $wpdb;

        $data = array(
            'event' => 'heartbeat',
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => WC()->version,
            'php_version' => PHP_VERSION,
            'mysql_version' => $wpdb->db_version(),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'openssl_available' => function_exists('openssl_encrypt'),
            'curl_available' => function_exists('curl_version'),
            'hpos_enabled' => class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController'),
            'site_language' => get_locale(),
            'timezone' => wp_timezone_string(),
            'multisite' => is_multisite(),
            'encryption_enabled' => self::is_encryption_enabled(),
        );

        self::send($data);
    }

    /**
     * Send daily statistics
     */
    public static function send_daily_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'marupurupu_transactions';

        // Get yesterday's stats
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_amount,
                MIN(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as min_amount,
                MAX(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as max_amount
            FROM $table_name
            WHERE DATE(created_at) = %s",
            $yesterday
        ));

        // Don't send if no transactions
        if (!$stats || $stats->total == 0) {
            return;
        }

        // Get result code distribution for failures
        $failure_codes = $wpdb->get_results($wpdb->prepare(
            "SELECT result_code, COUNT(*) as count
             FROM $table_name
             WHERE DATE(created_at) = %s AND status = 'failed'
             GROUP BY result_code
             ORDER BY count DESC
             LIMIT 10",
            $yesterday
        ));

        $failure_distribution = array();
        foreach ($failure_codes as $code) {
            $failure_distribution[$code->result_code] = (int)$code->count;
        }

        $data = array(
            'event' => 'daily_stats',
            'date' => $yesterday,
            'total_transactions' => (int)$stats->total,
            'successful' => (int)$stats->completed,
            'failed' => (int)$stats->failed,
            'pending' => (int)$stats->pending,
            'success_rate' => $stats->total > 0 ? round(($stats->completed / $stats->total) * 100, 2) : 0,
            'avg_amount' => round((float)$stats->avg_amount, 2),
            'min_amount' => round((float)$stats->min_amount, 2),
            'max_amount' => round((float)$stats->max_amount, 2),
            'failure_codes' => $failure_distribution
        );

        self::send($data);

        // Clear old feature usage data
        delete_option('marupurupu_feature_usage');
    }

    /**
     * Track payment success
     */
    public static function track_payment_success() {
        // Increment local counter
        $count = get_option('marupurupu_success_count', 0);
        update_option('marupurupu_success_count', $count + 1, false);
    }

    /**
     * Track payment failure
     */
    public static function track_payment_failure() {
        // Increment local counter
        $count = get_option('marupurupu_failure_count', 0);
        update_option('marupurupu_failure_count', $count + 1, false);
    }

    /**
     * Track feature usage
     *
     * @param string $feature_name Feature identifier
     * @param array $metadata Optional metadata (anonymized)
     */
    public static function track_feature_usage($feature_name, $metadata = array()) {
        $usage = get_option('marupurupu_feature_usage', array());

        if (!isset($usage[$feature_name])) {
            $usage[$feature_name] = array(
                'count' => 0,
                'first_used' => current_time('mysql'),
                'last_used' => current_time('mysql')
            );
        }

        $usage[$feature_name]['count']++;
        $usage[$feature_name]['last_used'] = current_time('mysql');

        update_option('marupurupu_feature_usage', $usage, false);

        // Send weekly aggregates (not per-use to reduce traffic)
        if (gmdate('w') == 0) { // Sunday
            self::send_feature_usage();
        }
    }

    /**
     * Send feature usage data
     */
    private static function send_feature_usage() {
        $usage = get_option('marupurupu_feature_usage', array());

        if (empty($usage)) {
            return;
        }

        $data = array(
            'event' => 'feature_usage',
            'features' => $usage
        );

        self::send($data);
    }

    /**
     * Track error occurrence
     *
     * @param string $error_type Error category (e.g., 'api_error', 'callback_error')
     * @param string $error_code Error code (e.g., '1037', 'timeout')
     * @param string $context Optional context (anonymized, no sensitive data)
     */
    public static function track_error($error_type, $error_code, $context = '') {
        $data = array(
            'event' => 'error',
            'error_type' => sanitize_text_field($error_type),
            'error_code' => sanitize_text_field($error_code),
            'context' => sanitize_text_field($context),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => WC()->version
        );

        // Send immediately (errors are critical)
        self::send($data);
    }

    /**
     * Track performance metric
     *
     * @param string $metric_name Metric identifier
     * @param float $duration_ms Duration in milliseconds
     */
    public static function track_performance($metric_name, $duration_ms) {
        $metrics = get_transient('marupurupu_performance_metrics');

        if (!$metrics) {
            $metrics = array();
        }

        if (!isset($metrics[$metric_name])) {
            $metrics[$metric_name] = array(
                'count' => 0,
                'total_ms' => 0,
                'min_ms' => $duration_ms,
                'max_ms' => $duration_ms
            );
        }

        $metrics[$metric_name]['count']++;
        $metrics[$metric_name]['total_ms'] += $duration_ms;
        $metrics[$metric_name]['min_ms'] = min($metrics[$metric_name]['min_ms'], $duration_ms);
        $metrics[$metric_name]['max_ms'] = max($metrics[$metric_name]['max_ms'], $duration_ms);

        set_transient('marupurupu_performance_metrics', $metrics, WEEK_IN_SECONDS);

        // Send weekly
        if (gmdate('w') == 0) {
            self::send_performance_metrics();
        }
    }

    /**
     * Send performance metrics
     */
    private static function send_performance_metrics() {
        $metrics = get_transient('marupurupu_performance_metrics');

        if (empty($metrics)) {
            return;
        }

        // Calculate averages
        foreach ($metrics as $name => &$metric) {
            $metric['avg_ms'] = $metric['count'] > 0
                ? round($metric['total_ms'] / $metric['count'], 2)
                : 0;
        }

        $data = array(
            'event' => 'performance',
            'metrics' => $metrics
        );

        self::send($data);

        // Clear metrics after sending
        delete_transient('marupurupu_performance_metrics');
    }

    /**
     * Track plugin activation
     */
    public static function track_activation() {
        $data = array(
            'event' => 'activation',
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => WC()->version,
            'php_version' => PHP_VERSION,
            'site_language' => get_locale(),
            'multisite' => is_multisite()
        );

        // Routed through send(), which re-checks is_enabled() — activation
        // fires before the merchant has necessarily seen/saved the opt-in
        // checkbox, so this must never bypass that gate (WordPress.org
        // Guideline 7 requires explicit consent before any external request).
        self::send($data);
    }

    /**
     * Track plugin deactivation
     */
    public static function track_deactivation() {
        $data = array(
            'event' => 'deactivation'
        );

        self::send($data);

        // Unschedule events
        wp_clear_scheduled_hook('marupurupu_weekly_heartbeat');
        wp_clear_scheduled_hook('marupurupu_daily_stats');
    }

    /**
     * Check if encryption is enabled
     */
    private static function is_encryption_enabled() {
        $gateway = self::get_gateway();

        if (!$gateway) {
            return false;
        }

        // Check if any credential is encrypted
        $fields = array('consumer_key', 'consumer_secret', 'passkey');

        foreach ($fields as $field) {
            $value = $gateway->get_option($field);
            if (!empty($value) && Marupurupu_Encryption::is_encrypted($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get gateway instance
     */
    private static function get_gateway() {
        if (!function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['mpesa_till']) ? $gateways['mpesa_till'] : null;
    }
}

// Initialize telemetry
add_action('plugins_loaded', array('Marupurupu_Telemetry', 'init'), 20);

// Track activation/deactivation
register_activation_hook(MARUPURUPU_PLUGIN_DIR . 'marupurupu-checkout-for-mpesa.php', array('Marupurupu_Telemetry', 'track_activation'));
register_deactivation_hook(MARUPURUPU_PLUGIN_DIR . 'marupurupu-checkout-for-mpesa.php', array('Marupurupu_Telemetry', 'track_deactivation'));
