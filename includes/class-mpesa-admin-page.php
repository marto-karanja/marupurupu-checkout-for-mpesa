<?php
/**
 * M-Pesa Admin Orders Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_Admin_Page {

    /**
     * Initialize admin page
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));

        // Must run on admin_init, before any admin page output has started:
        // the CSV export sends its own headers, which is impossible once
        // render_page() is already mid-output ("headers already sent").
        add_action('admin_init', array(__CLASS__, 'handle_bulk_actions'));
    }

    /**
     * Add menu page
     */
    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('M-Pesa Transactions', 'marupurupu-checkout-for-mpesa'),
            __('M-Pesa Transactions', 'marupurupu-checkout-for-mpesa'),
            'manage_woocommerce',
            'mpesa-transactions',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Enqueue admin styles
     */
    public static function enqueue_styles($hook) {
        // This page is registered under both the WooCommerce menu and the
        // M-Pesa Payments menu (see Mpesa_Reports::add_menu_page()), so its
        // hook suffix differs depending on which menu it was opened from.
        if (false === strpos($hook, '_page_mpesa-transactions')) {
            return;
        }

        wp_enqueue_style('mpesa-admin', WC_MPESA_TILL_PLUGIN_URL . 'assets/css/admin.css', array(), WC_MPESA_TILL_VERSION);
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        // (Bulk actions are handled earlier, on admin_init -- see init().)

        // Get filter parameters
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $per_page = 20;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        // Build query
        $where = array('1=1');
        if ($status_filter) {
            $where[] = $wpdb->prepare('status = %s', $status_filter);
        }
        if ($search) {
            $where[] = $wpdb->prepare('(transaction_id LIKE %s OR phone_number LIKE %s OR order_id = %d)',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                intval($search)
            );
        }

        $where_clause = implode(' AND ', $where);

        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where_clause");

        // Get transactions
        $transactions = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
        );

        // Get statistics
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount
            FROM $table_name"
        );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('M-Pesa Transactions', 'marupurupu-checkout-for-mpesa'); ?></h1>
            <hr class="wp-header-end">

            <!-- Statistics Cards -->
            <div class="mpesa-stats">
                <div class="mpesa-stat-card">
                    <h3><?php echo number_format($stats->total); ?></h3>
                    <p><?php esc_html_e('Total Transactions', 'marupurupu-checkout-for-mpesa'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-success">
                    <h3><?php echo number_format($stats->completed); ?></h3>
                    <p><?php esc_html_e('Completed', 'marupurupu-checkout-for-mpesa'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-pending">
                    <h3><?php echo number_format($stats->pending); ?></h3>
                    <p><?php esc_html_e('Pending', 'marupurupu-checkout-for-mpesa'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-failed">
                    <h3><?php echo number_format($stats->failed); ?></h3>
                    <p><?php esc_html_e('Failed', 'marupurupu-checkout-for-mpesa'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-amount">
                    <h3><?php echo wp_kses_post(wc_price($stats->total_amount)); ?></h3>
                    <p><?php esc_html_e('Total Revenue', 'marupurupu-checkout-for-mpesa'); ?></p>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" action="">
                <input type="hidden" name="page" value="mpesa-transactions">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="status">
                            <option value=""><?php esc_html_e('All Statuses', 'marupurupu-checkout-for-mpesa'); ?></option>
                            <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'marupurupu-checkout-for-mpesa'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'marupurupu-checkout-for-mpesa'); ?></option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php esc_html_e('Failed', 'marupurupu-checkout-for-mpesa'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_html_e('Filter', 'marupurupu-checkout-for-mpesa'); ?>">
                    </div>
                    <div class="alignleft actions">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_html_e('Search by order ID, receipt, or phone...', 'marupurupu-checkout-for-mpesa'); ?>">
                        <input type="submit" class="button" value="<?php esc_html_e('Search', 'marupurupu-checkout-for-mpesa'); ?>">
                        <?php if ($search || $status_filter): ?>
                            <a href="?page=mpesa-transactions" class="button"><?php esc_html_e('Clear', 'marupurupu-checkout-for-mpesa'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Transactions Table -->
            <form method="post">
                <?php wp_nonce_field('mpesa_bulk_action', 'mpesa_bulk_nonce'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                            <th><?php esc_html_e('Order', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Receipt', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Phone', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Amount', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Status', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Date', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Actions', 'marupurupu-checkout-for-mpesa'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px;">
                                    <?php esc_html_e('No transactions found.', 'marupurupu-checkout-for-mpesa'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php $order = wc_get_order($transaction->order_id); ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="transaction_ids[]" value="<?php echo esc_attr($transaction->id); ?>">
                                    </th>
                                    <td>
                                        <?php if ($order): ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($transaction->order_id)); ?>">
                                                #<?php echo absint($transaction->order_id); ?>
                                            </a>
                                        <?php else: ?>
                                            #<?php echo absint($transaction->order_id); ?> <span class="description">(deleted)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($transaction->transaction_id): ?>
                                            <code><?php echo esc_html($transaction->transaction_id); ?></code>
                                        <?php else: ?>
                                            <span class="description">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($transaction->phone_number); ?></td>
                                    <td><?php echo wp_kses_post(wc_price($transaction->amount)); ?></td>
                                    <td>
                                        <span class="mpesa-status-badge mpesa-status-<?php echo esc_attr($transaction->status); ?>">
                                            <?php echo esc_html(ucfirst($transaction->status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($transaction->order_id)); ?>" class="button button-small">
                                            <?php esc_html_e('View Order', 'marupurupu-checkout-for-mpesa'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="check-column"><input type="checkbox"></td>
                            <th><?php esc_html_e('Order', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Receipt', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Phone', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Amount', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Status', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Date', 'marupurupu-checkout-for-mpesa'); ?></th>
                            <th><?php esc_html_e('Actions', 'marupurupu-checkout-for-mpesa'); ?></th>
                        </tr>
                    </tfoot>
                </table>

                <!-- Bulk Actions -->
                <div class="tablenav bottom">
                    <div class="alignleft actions">
                        <select name="bulk_action">
                            <option value=""><?php esc_html_e('Bulk Actions', 'marupurupu-checkout-for-mpesa'); ?></option>
                            <option value="export_csv"><?php esc_html_e('Export to CSV', 'marupurupu-checkout-for-mpesa'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_html_e('Apply', 'marupurupu-checkout-for-mpesa'); ?>">
                    </div>

                    <!-- Pagination -->
                    <?php
                    $total_pages = ceil($total_items / $per_page);
                    if ($total_pages > 1):
                    ?>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                /* translators: %s: number of transaction items */
                                printf(esc_html(_n('%s item', '%s items', $total_items, 'marupurupu-checkout-for-mpesa')), esc_html(number_format_i18n($total_items)));
                                ?>
                            </span>
                            <?php
                            echo wp_kses_post(paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'current' => $paged,
                                'total' => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            )));
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle bulk actions
     */
    public static function handle_bulk_actions() {
        if (!isset($_POST['bulk_action'], $_POST['transaction_ids'], $_POST['mpesa_bulk_nonce'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mpesa_bulk_nonce'])), 'mpesa_bulk_action')) {
            wp_die(esc_html__('Security check failed.', 'marupurupu-checkout-for-mpesa'));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'marupurupu-checkout-for-mpesa'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['bulk_action']));
        $transaction_ids = array_map('absint', (array) wp_unslash($_POST['transaction_ids']));

        if ($action === 'export_csv') {
            self::export_to_csv($transaction_ids);
        }
    }

    /**
     * Export transactions to CSV
     */
    public static function export_to_csv($transaction_ids = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        if (empty($transaction_ids)) {
            return;
        }

        // One %d placeholder per ID, so the list goes through prepare()
        // instead of being interpolated into the query.
        $placeholders = implode(',', array_fill(0, count($transaction_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table_name is the plugin's own table; $placeholders is only '%d' tokens.
        $transactions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE id IN ($placeholders)", $transaction_ids));

        if (empty($transactions)) {
            return;
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mpesa-transactions-' . gmdate('Y-m-d') . '.csv');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add headers
        fputcsv($output, array(
            'Order ID',
            'Transaction ID',
            'Merchant Request ID',
            'Checkout Request ID',
            'Phone Number',
            'Amount',
            'Result Code',
            'Result Description',
            'Status',
            'Created At',
            'Updated At'
        ));

        // Add data
        foreach ($transactions as $transaction) {
            fputcsv($output, array(
                $transaction->order_id,
                $transaction->transaction_id,
                $transaction->merchant_request_id,
                $transaction->checkout_request_id,
                $transaction->phone_number,
                $transaction->amount,
                $transaction->result_code,
                $transaction->result_desc,
                $transaction->status,
                $transaction->created_at,
                $transaction->updated_at
            ));
        }

        // php://output is a stream wrapper, not a filesystem handle; WP_Filesystem has no equivalent.
        fclose($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }
}

// Initialize admin page
Mpesa_Admin_Page::init();
