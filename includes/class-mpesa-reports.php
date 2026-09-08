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
            __('M-Pesa Payments', 'mpesa-till-gateway'),
            __('M-Pesa Payments', 'mpesa-till-gateway'),
            'manage_woocommerce',
            'mpesa-payments',
            array(__CLASS__, 'render_reports_page'),
            'dashicons-money-alt',
            56
        );

        // Add submenu pages
        add_submenu_page(
            'mpesa-payments',
            __('Reports', 'mpesa-till-gateway'),
            __('Reports', 'mpesa-till-gateway'),
            'manage_woocommerce',
            'mpesa-payments',
            array(__CLASS__, 'render_reports_page')
        );

        add_submenu_page(
            'mpesa-payments',
            __('Transactions', 'mpesa-till-gateway'),
            __('Transactions', 'mpesa-till-gateway'),
            'manage_woocommerce',
            'mpesa-transactions',
            array('Mpesa_Admin_Page', 'render_page')
        );

        add_submenu_page(
            'mpesa-payments',
            __('Settings', 'mpesa-till-gateway'),
            __('Settings', 'mpesa-till-gateway'),
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

        // Chart.js bundled locally (not loaded from a CDN) so the plugin
        // never depends on an external host being reachable/trustworthy.
        wp_enqueue_script('chart-js', WC_MPESA_TILL_PLUGIN_URL . 'assets/js/chart.min.js', array(), '3.9.1', true);
    }

    /**
     * Render reports page
     */
    public static function render_reports_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        // Get date range from query params
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : gmdate('Y-m-01');
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : gmdate('Y-m-d');

        // Get overall statistics
        $stats = self::get_statistics($date_from, $date_to);

        // Get daily data for charts
        $daily_data = self::get_daily_data($date_from, $date_to);

        // Get top customers
        $top_customers = self::get_top_customers($date_from, $date_to, 10);

        // Get payment method breakdown
        $status_breakdown = self::get_status_breakdown($date_from, $date_to);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e('M-Pesa Payment Reports', 'mpesa-till-gateway'); ?>
            </h1>
            <hr class="wp-header-end">

            <!-- Date Range Filter -->
            <div class="mpesa-reports-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="mpesa-payments">

                    <label for="date_from"><?php esc_html_e('From:', 'mpesa-till-gateway'); ?></label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>">

                    <label for="date_to"><?php esc_html_e('To:', 'mpesa-till-gateway'); ?></label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>">

                    <input type="submit" class="button button-primary" value="<?php esc_html_e('Filter', 'mpesa-till-gateway'); ?>">

                    <a href="?page=mpesa-payments" class="button"><?php esc_html_e('Reset', 'mpesa-till-gateway'); ?></a>

                    <a href="<?php echo esc_url(self::get_export_url($date_from, $date_to)); ?>" class="button" style="float: right;">
                        <?php esc_html_e('Export Report (CSV)', 'mpesa-till-gateway'); ?>
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
                        <h3><?php echo wc_price($stats->total_revenue); ?></h3>
                        <p><?php esc_html_e('Total Revenue', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #2271b1;">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->completed); ?></h3>
                        <p><?php esc_html_e('Successful Payments', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #dba617;">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->pending); ?></h3>
                        <p><?php esc_html_e('Pending Payments', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #d63638;">
                        <span class="dashicons dashicons-dismiss"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->failed); ?></h3>
                        <p><?php esc_html_e('Failed Payments', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #50575e;">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo number_format($stats->total, 0); ?></h3>
                        <p><?php esc_html_e('Total Transactions', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>

                <div class="mpesa-report-card">
                    <div class="card-icon" style="background: #0f834d;">
                        <span class="dashicons dashicons-chart-line"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php echo wc_price($stats->avg_transaction); ?></h3>
                        <p><?php esc_html_e('Average Transaction', 'mpesa-till-gateway'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="mpesa-reports-charts">
                <div class="chart-container" style="width: 48%; display: inline-block; vertical-align: top;">
                    <h2><?php esc_html_e('Daily Revenue', 'mpesa-till-gateway'); ?></h2>
                    <canvas id="revenueChart"></canvas>
                </div>

                <div class="chart-container" style="width: 48%; display: inline-block; vertical-align: top; margin-left: 3%;">
                    <h2><?php esc_html_e('Payment Status Distribution', 'mpesa-till-gateway'); ?></h2>
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Top Customers Table -->
            <div class="mpesa-reports-table">
                <h2><?php esc_html_e('Top Customers', 'mpesa-till-gateway'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Rank', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Phone Number', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Total Transactions', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Total Amount', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Success Rate', 'mpesa-till-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_customers)): ?>
                            <?php $rank = 1; foreach ($top_customers as $customer): ?>
                                <tr>
                                    <td><?php echo absint($rank++); ?></td>
                                    <td><?php echo esc_html($customer->phone_number); ?></td>
                                    <td><?php echo number_format($customer->total_transactions); ?></td>
                                    <td><?php echo wc_price($customer->total_amount); ?></td>
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
                                    <?php esc_html_e('No data available for selected period.', 'mpesa-till-gateway'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Chart.js Data -->
        <script>
        jQuery(document).ready(function($) {
            // Daily Revenue Chart
            var revenueCtx = document.getElementById('revenueChart').getContext('2d');
            var revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($daily_data, 'date')); ?>,
                    datasets: [{
                        label: '<?php esc_html_e('Revenue', 'mpesa-till-gateway'); ?>',
                        data: <?php echo json_encode(array_column($daily_data, 'revenue')); ?>,
                        borderColor: '#0f834d',
                        backgroundColor: 'rgba(15, 131, 77, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: '<?php esc_html_e('Transactions', 'mpesa-till-gateway'); ?>',
                        data: <?php echo json_encode(array_column($daily_data, 'count')); ?>,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: '<?php esc_html_e('Revenue (KES)', 'mpesa-till-gateway'); ?>'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: '<?php esc_html_e('Transactions', 'mpesa-till-gateway'); ?>'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });

            // Status Distribution Chart
            var statusCtx = document.getElementById('statusChart').getContext('2d');
            var statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['<?php esc_html_e('Completed', 'mpesa-till-gateway'); ?>', '<?php esc_html_e('Pending', 'mpesa-till-gateway'); ?>', '<?php esc_html_e('Failed', 'mpesa-till-gateway'); ?>'],
                    datasets: [{
                        data: [
                            <?php echo (int) $status_breakdown->completed; ?>,
                            <?php echo (int) $status_breakdown->pending; ?>,
                            <?php echo (int) $status_breakdown->failed; ?>
                        ],
                        backgroundColor: ['#0f834d', '#dba617', '#d63638'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });
        </script>

        <style>
            .mpesa-reports-filters {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .mpesa-reports-filters form {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .mpesa-reports-filters label {
                font-weight: 600;
            }
            .mpesa-reports-filters input[type="date"] {
                padding: 5px;
            }

            .mpesa-reports-summary {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .mpesa-report-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            .mpesa-report-card .card-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .mpesa-report-card .card-icon .dashicons {
                color: #fff;
                font-size: 30px;
                width: 30px;
                height: 30px;
            }
            .mpesa-report-card .card-content h3 {
                margin: 0 0 5px 0;
                font-size: 28px;
                color: #1d2327;
            }
            .mpesa-report-card .card-content p {
                margin: 0;
                color: #646970;
                font-size: 14px;
            }

            .mpesa-reports-charts {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .chart-container {
                padding: 10px;
            }
            .chart-container h2 {
                margin-top: 0;
            }

            .mpesa-reports-table {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .mpesa-reports-table h2 {
                margin-top: 0;
            }
        </style>
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
        wp_die(__('You do not have permission to perform this action.', 'mpesa-till-gateway'));
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

    fclose($output);
    exit;
});
