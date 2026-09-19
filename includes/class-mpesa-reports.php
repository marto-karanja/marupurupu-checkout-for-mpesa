<?php
/**
 * M-Pesa Payment Reports
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_Reports {

    /**
     * Initialize reports page
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    /**
     * Add menu page
     */
    public static function add_menu_page() {
        add_menu_page(
            __('M-Pesa Payments', 'marupurupu-checkout-for-mpesa'),
            __('M-Pesa Payments', 'marupurupu-checkout-for-mpesa'),
            'manage_woocommerce',
            'mpesa-payments',
            array(__CLASS__, 'render_reports_page'),
            'dashicons-money-alt',
            56
        );

        // Add submenu pages
        add_submenu_page(
            'mpesa-payments',
            __('Reports', 'marupurupu-checkout-for-mpesa'),
            __('Reports', 'marupurupu-checkout-for-mpesa'),
            'manage_woocommerce',
            'mpesa-payments',
            array(__CLASS__, 'render_reports_page')
        );

        add_submenu_page(
            'mpesa-payments',
            __('Transactions', 'marupurupu-checkout-for-mpesa'),
            __('Transactions', 'marupurupu-checkout-for-mpesa'),
            'manage_woocommerce',
            'mpesa-transactions',
            array('Mpesa_Admin_Page', 'render_page')
        );

        add_submenu_page(
            'mpesa-payments',
            __('Settings', 'marupurupu-checkout-for-mpesa'),
            __('Settings', 'marupurupu-checkout-for-mpesa'),
            'manage_woocommerce',
            'admin.php?page=wc-settings&tab=checkout&section=mpesa_till'
        );
    }

    /**
     * Enqueue scripts
     */
    public static function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_mpesa-payments') {
            return;
        }

        wp_enqueue_style('mpesa-reports', WC_MPESA_TILL_PLUGIN_URL . 'assets/css/mpesa-reports.css', array(), WC_MPESA_TILL_VERSION);

        // Chart.js bundled locally (not loaded from a CDN) so the plugin
        // never depends on an external host being reachable/trustworthy.
        // Source: https://github.com/chartjs/Chart.js (MIT), see readme.txt.
        wp_enqueue_script('chart-js', WC_MPESA_TILL_PLUGIN_URL . 'assets/js/chart.umd.js', array(), '4.5.1', true);
        wp_enqueue_script('mpesa-reports', WC_MPESA_TILL_PLUGIN_URL . 'assets/js/mpesa-reports.js', array('jquery', 'chart-js'), WC_MPESA_TILL_VERSION, true);

        // Chart data is computed here (not while rendering the page) so it
        // can be handed to the script through wp_localize_script(), which
        // JSON-encodes it safely, instead of echoing JSON into a <script> tag.
        list($date_from, $date_to) = self::get_date_range();

        $daily_data = self::get_daily_data($date_from, $date_to);
        $status_breakdown = self::get_status_breakdown($date_from, $date_to);

        wp_localize_script('mpesa-reports', 'mpesaReportsData', array(
            'daily' => array(
                'labels' => array_column($daily_data, 'date'),
                'revenue' => array_column($daily_data, 'revenue'),
                'count' => array_column($daily_data, 'count'),
            ),
            'status' => array(
                $status_breakdown ? (int) $status_breakdown->completed : 0,
                $status_breakdown ? (int) $status_breakdown->pending : 0,
                $status_breakdown ? (int) $status_breakdown->failed : 0,
            ),
            'i18n' => array(
                'revenue' => __('Revenue', 'marupurupu-checkout-for-mpesa'),
                'revenueKes' => __('Revenue (KES)', 'marupurupu-checkout-for-mpesa'),
                'transactions' => __('Transactions', 'marupurupu-checkout-for-mpesa'),
                'completed' => __('Completed', 'marupurupu-checkout-for-mpesa'),
                'pending' => __('Pending', 'marupurupu-checkout-for-mpesa'),
                'failed' => __('Failed', 'marupurupu-checkout-for-mpesa'),
            ),
        ));
    }

    /**
     * Read the report's date range from the query string (read-only filter,
     * no state change), falling back to "this month so far" if either value
     * is missing or not a YYYY-MM-DD date.
     *
     * @return array [date_from, date_to]
     */
    private static function get_date_range() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filter, no state change.
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $date_from = gmdate('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_to = gmdate('Y-m-d');
        }

        return array($date_from, $date_to);
    }

    /**
     * Render reports page
     */
    public static function render_reports_page() {
        list($date_from, $date_to) = self::get_date_range();

        // Get overall statistics
        $stats = self::get_statistics($date_from, $date_to);

        // Get top customers
        $top_customers = self::get_top_customers($date_from, $date_to, 10);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e('M-Pesa Payment Reports', 'marupurupu-checkout-for-mpesa'); ?>
            </h1>
            <hr class="wp-header-end">

            <!-- Date Range Filter -->
            <div class="mpesa-reports-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="mpesa-payments">

                    <label for="date_from"><?php esc_html_e('From:', 'marupurupu-checkout-for-mpesa'); ?></label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>">

                    <label for="date_to"><?php esc_html_e('To:', 'marupurupu-checkout-for-mpesa'); ?></label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>">

                    <input type="submit" class="button button-primary" value="<?php esc_html_e('Filter', 'marupurupu-checkout-for-mpesa'); ?>">

                    <a href="?page=mpesa-payments" class="button"><?php esc_html_e('Reset', 'marupurupu-checkout-for-mpesa'); ?></a>

                    <a href="<?php echo esc_url(self::get_export_url($date_from, $date_to)); ?>" class="button" style="float: right;">
                        <?php esc_html_e('Export Report (CSV)', 'marupurupu-checkout-for-mpesa'); ?>
                    </a>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="mpesa-reports-summary">
                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #0f834d;">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo wp_kses_post(wc_price($stats->total_revenue)); ?></h3>
                        <p><?php esc_html_e('Total Revenue', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #2271b1;">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->completed); ?></h3>
                        <p><?php esc_html_e('Successful Payments', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #dba617;">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->pending); ?></h3>
                        <p><?php esc_html_e('Pending Payments', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #d63638;">
                        <span class="dashicons dashicons-dismiss"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->failed); ?></h3>
                        <p><?php esc_html_e('Failed Payments', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #50575e;">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->total, 0); ?></h3>
                        <p><?php esc_html_e('Total Transactions', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #0f834d;">
                        <span class="dashicons dashicons-chart-line"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo wp_kses_post(wc_price($stats->avg_transaction)); ?></h3>
                        <p><?php esc_html_e('Average Transaction', 'marupurupu-checkout-for-mpesa'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="mpesa-reports-charts">
                <div class="chart-container" style="width: 48%; display: inline-block; vertical-align: top;">
                    <h2><?php esc_html_e('Daily Revenue', 'marupurupu-checkout-for-mpesa'); ?></h2>
                    <canvas id="revenueChart"></canvas>
                </div>

                <div class="chart-container" style="width: 48%; display: inline-block; vertical-align: top; margin-left: 3%;">
                    <h2><?php esc_html_e('Payment Status Distribution', 'marupurupu-checkout-for-mpesa'); ?></h2>
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Top Customers Table -->
            <div class="mpesa-reports-table">
                <h2><?php esc_html_e('Top Customers', 'marupurupu-checkout-for-mpesa'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Rank', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Phone Number', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Total Transactions', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Total Amount', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Success Rate', 'marupurupu-checkout-for-mpesa'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_customers)): ?>
                            <?php $rank = 1; foreach ($top_customers as $customer): ?>
                                <tr>
                                    <td><?php echo absint($rank++); ?></td>
                                    <td><?php echo esc_html($customer->phone_number); ?></td>
                                    <td><?php echo number_format($customer->total_transactions); ?></td>
                                    <td><?php echo wp_kses_post(wc_price($customer->total_amount)); ?></td>
                                    <td>
                                        <?php
                                        $success_rate = ($customer->completed / $customer->total_transactions) * 100;
                                        echo number_format($success_rate, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">
                                    <?php esc_html_e('No data available for selected period.', 'marupurupu-checkout-for-mpesa'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Get statistics for date range
     */
    private static function get_statistics($date_from, $date_to) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_transaction
            FROM $table_name
            WHERE DATE(created_at) BETWEEN %s AND %s",
            $date_from,
            $date_to
        ));
    }

    /**
     * Get daily data for charts
     */
    private static function get_daily_data($date_from, $date_to) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue
            FROM $table_name
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC",
            $date_from,
            $date_to
        ));

        return array_map(function($row) {
            return array(
                'date' => gmdate('M d', strtotime($row->date)),
                'count' => (int)$row->count,
                'revenue' => (float)$row->revenue
            );
        }, $results);
    }

    /**
     * Get top customers
     */
    private static function get_top_customers($date_from, $date_to, $limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                phone_number,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount
            FROM $table_name
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY phone_number
            ORDER BY total_amount DESC
            LIMIT %d",
            $date_from,
            $date_to,
            $limit
        ));
    }

    /**
     * Get status breakdown
     */
    private static function get_status_breakdown($date_from, $date_to) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM $table_name
            WHERE DATE(created_at) BETWEEN %s AND %s",
            $date_from,
            $date_to
        ));
    }

    /**
     * Get export URL
     */
    private static function get_export_url($date_from, $date_to) {
        return admin_url('admin-post.php?action=mpesa_export_report&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&nonce=' . wp_create_nonce('mpesa_export_report'));
    }
}

// Initialize
Mpesa_Reports::init();

// Handle export
add_action('admin_post_mpesa_export_report', function() {
    check_admin_referer('mpesa_export_report', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'marupurupu-checkout-for-mpesa'));
    }

    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : gmdate('Y-m-01');
    $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : gmdate('Y-m-d');

    global $wpdb;
    $table_name = $wpdb->prefix . 'mpesa_till_transactions';

    $transactions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE DATE(created_at) BETWEEN %s AND %s ORDER BY created_at DESC",
        $date_from,
        $date_to
    ));

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mpesa-report-' . $date_from . '-to-' . $date_to . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers
    fputcsv($output, array(
        'Date',
        'Order ID',
        'Transaction ID',
        'Phone Number',
        'Amount',
        'Status',
        'Result Description'
    ));

    // Data
    foreach ($transactions as $transaction) {
        fputcsv($output, array(
            $transaction->created_at,
            $transaction->order_id,
            $transaction->transaction_id,
            $transaction->phone_number,
            $transaction->amount,
            $transaction->status,
            $transaction->result_desc
        ));
    }

    // php://output is a stream wrapper, not a filesystem handle; WP_Filesystem has no equivalent.
    fclose($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    exit;
});
