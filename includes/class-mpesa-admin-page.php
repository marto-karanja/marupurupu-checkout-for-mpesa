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
    }

    /**
     * Add menu page
     */
    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('M-Pesa Transactions', 'mpesa-till-gateway'),
            __('M-Pesa Transactions', 'mpesa-till-gateway'),
            'manage_woocommerce',
            'mpesa-transactions',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Enqueue admin styles
     */
    public static function enqueue_styles($hook) {
        if ($hook !== 'woocommerce_page_mpesa-transactions') {
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

        // Handle bulk actions
        self::handle_bulk_actions();

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
            <h1 class="wp-heading-inline"><?php esc_html_e('M-Pesa Transactions', 'mpesa-till-gateway'); ?></h1>
            <hr class="wp-header-end">

            <!-- Statistics Cards -->
            <div class="mpesa-stats">
                <div class="mpesa-stat-card">
                    <h3><?php echo number_format($stats->total); ?></h3>
                    <p><?php esc_html_e('Total Transactions', 'mpesa-till-gateway'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-success">
                    <h3><?php echo number_format($stats->completed); ?></h3>
                    <p><?php esc_html_e('Completed', 'mpesa-till-gateway'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-pending">
                    <h3><?php echo number_format($stats->pending); ?></h3>
                    <p><?php esc_html_e('Pending', 'mpesa-till-gateway'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-failed">
                    <h3><?php echo number_format($stats->failed); ?></h3>
                    <p><?php esc_html_e('Failed', 'mpesa-till-gateway'); ?></p>
                </div>
                <div class="mpesa-stat-card mpesa-stat-amount">
                    <h3><?php echo wc_price($stats->total_amount); ?></h3>
                    <p><?php esc_html_e('Total Revenue', 'mpesa-till-gateway'); ?></p>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" action="">
                <input type="hidden" name="page" value="mpesa-transactions">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="status">
                            <option value=""><?php esc_html_e('All Statuses', 'mpesa-till-gateway'); ?></option>
                            <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php esc_html_e('Completed', 'mpesa-till-gateway'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pending', 'mpesa-till-gateway'); ?></option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php esc_html_e('Failed', 'mpesa-till-gateway'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_html_e('Filter', 'mpesa-till-gateway'); ?>">
                    </div>
                    <div class="alignleft actions">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_html_e('Search by order ID, receipt, or phone...', 'mpesa-till-gateway'); ?>">
                        <input type="submit" class="button" value="<?php esc_html_e('Search', 'mpesa-till-gateway'); ?>">
                        <?php if ($search || $status_filter): ?>
                            <a href="?page=mpesa-transactions" class="button"><?php esc_html_e('Clear', 'mpesa-till-gateway'); ?></a>
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
                            <th><?php esc_html_e('Order', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Receipt', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Phone', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Amount', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Status', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Date', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Actions', 'mpesa-till-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px;">
                                    <?php esc_html_e('No transactions found.', 'mpesa-till-gateway'); ?>
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
                                    <td><?php echo wc_price($transaction->amount); ?></td>
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
                                            <?php esc_html_e('View Order', 'mpesa-till-gateway'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="check-column"><input type="checkbox"></td>
                            <th><?php esc_html_e('Order', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Receipt', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Phone', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Amount', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Status', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Date', 'mpesa-till-gateway'); ?></th>
                            <th><?php esc_html_e('Actions', 'mpesa-till-gateway'); ?></th>
                        </tr>
                    </tfoot>
                </table>

                <!-- Bulk Actions -->
                <div class="tablenav bottom">
                    <div class="alignleft actions">
                        <select name="bulk_action">
                            <option value=""><?php esc_html_e('Bulk Actions', 'mpesa-till-gateway'); ?></option>
                            <option value="export_csv"><?php esc_html_e('Export to CSV', 'mpesa-till-gateway'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_html_e('Apply', 'mpesa-till-gateway'); ?>">
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
                                printf(esc_html(_n('%s item', '%s items', $total_items, 'mpesa-till-gateway')), esc_html(number_format_i18n($total_items)));
                                ?>
                            </span>
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'current' => $paged,
                                'total' => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ));
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <style>
            .mpesa-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .mpesa-stat-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                border-radius: 4px;
                text-align: center;
            }
            .mpesa-stat-card h3 {
                margin: 0 0 10px 0;
                font-size: 32px;
                color: #2271b1;
            }
            .mpesa-stat-card p {
                margin: 0;
                color: #646970;
            }
            .mpesa-stat-success h3 { color: #00a32a; }
            .mpesa-stat-pending h3 { color: #dba617; }
            .mpesa-stat-failed h3 { color: #d63638; }
            .mpesa-stat-amount h3 { color: #2271b1; }

            .mpesa-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .mpesa-status-completed {
                background: #e6f4ea;
                color: #1e8e3e;
            }
            .mpesa-status-pending {
                background: #fef7e0;
                color: #9c6f19;
            }
            .mpesa-status-failed {
                background: #fce8e6;
                color: #d93025;
            }
        </style>
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
        if (!wp_verify_nonce(wp_unslash($_POST['mpesa_bulk_nonce']), 'mpesa_bulk_action')) {
            wp_die(__('Security check failed.', 'mpesa-till-gateway'));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'mpesa-till-gateway'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['bulk_action']));
        $transaction_ids = array_map('intval', $_POST['transaction_ids']);

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

        $ids = implode(',', $transaction_ids);
        $transactions = $wpdb->get_results("SELECT * FROM $table_name WHERE id IN ($ids)");

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

        fclose($output);
        exit;
    }
}

// Initialize admin page
Mpesa_Admin_Page::init();
