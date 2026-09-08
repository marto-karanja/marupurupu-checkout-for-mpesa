<?php
/**
 * M-Pesa Helper Functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_Helpers {

    /**
     * Format phone number to M-Pesa format (254XXXXXXXXX)
     */
    public static function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Handle different formats
        if (substr($phone, 0, 1) == '0') {
            // Convert 0712345678 to 254712345678
            $phone = '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 1) == '+') {
            // Convert +254712345678 to 254712345678
            $phone = substr($phone, 1);
        } elseif (substr($phone, 0, 3) != '254') {
            // Add 254 if not present
            $phone = '254' . $phone;
        }

        return $phone;
    }

    /**
     * Validate M-Pesa phone number
     */
    public static function validate_phone_number($phone) {
        $phone = self::format_phone_number($phone);
        return preg_match('/^254[0-9]{9}$/', $phone);
    }

    /**
     * Get transaction by order ID
     */
    public static function get_transaction_by_order_id($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));
    }

    /**
     * Get transaction by checkout request ID
     */
    public static function get_transaction_by_checkout_id($checkout_request_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE checkout_request_id = %s",
            $checkout_request_id
        ));
    }

    /**
     * Get transaction by M-Pesa transaction ID
     */
    public static function get_transaction_by_mpesa_id($transaction_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE transaction_id = %s",
            $transaction_id
        ));
    }

    /**
     * Get all transactions for an order
     */
    public static function get_order_transactions($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC",
            $order_id
        ));
    }

    /**
     * Get transaction statistics
     */
    public static function get_transaction_stats($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount
            FROM $table_name
            WHERE created_at >= %s",
            $date_from
        ));

        return $stats;
    }

    /**
     * Format amount for display
     */
    public static function format_amount($amount) {
        return 'KES ' . number_format($amount, 2);
    }

    /**
     * Get M-Pesa result code description
     */
    public static function get_result_description($result_code) {
        $descriptions = array(
            '0' => 'Success',
            '1' => 'Insufficient Funds',
            '1032' => 'Request cancelled by user',
            '1037' => 'Timeout - User did not enter PIN',
            '1001' => 'Unable to lock subscriber',
            '2001' => 'Invalid initiator information',
            '1019' => 'Transaction expired',
            '1' => 'The balance is insufficient for the transaction',
            '17' => 'System internal error',
            '20' => 'Invalid SMS',
            '26' => 'System error',
        );

        return isset($descriptions[$result_code]) ? $descriptions[$result_code] : 'Unknown error';
    }

    /**
     * Check if transaction is successful
     */
    public static function is_transaction_successful($result_code) {
        return $result_code === '0' || $result_code === 0;
    }

    /**
     * Sanitize callback data for logging
     */
    public static function sanitize_log_data($data) {
        if (is_array($data)) {
            // Remove sensitive information
            $sensitive_keys = array('Password', 'ConsumerSecret', 'AccessToken');
            foreach ($sensitive_keys as $key) {
                if (isset($data[$key])) {
                    $data[$key] = '***REDACTED***';
                }
            }
        }
        return $data;
    }

    /**
     * Check if M-Pesa is available (basic validation)
     */
    public static function is_mpesa_available() {
        $gateway = WC()->payment_gateways()->payment_gateways()['mpesa_till'] ?? null;

        if (!$gateway) {
            return false;
        }

        // Check if enabled
        if ($gateway->enabled !== 'yes') {
            return false;
        }

        // Check if required credentials are set
        if (empty($gateway->consumer_key) || empty($gateway->consumer_secret) || empty($gateway->till_number)) {
            return false;
        }

        return true;
    }

    /**
     * Get pending transactions (older than 2 minutes)
     */
    public static function get_pending_transactions($minutes = 2) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        $date_threshold = gmdate('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE status = 'pending'
            AND created_at < %s
            ORDER BY created_at ASC",
            $date_threshold
        ));
    }

    /**
     * Get admin URL for transaction details
     */
    public static function get_admin_transaction_url($order_id) {
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    /**
     * Add meta box to order page showing M-Pesa transaction details
     */
    public static function add_mpesa_meta_box() {
        // Support both HPOS and traditional post-based orders
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'mpesa_transaction_details',
            __('M-Pesa Transaction Details', 'mpesa-gateway-for-woocommerce'),
            array(__CLASS__, 'render_mpesa_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render M-Pesa meta box content
     */
    public static function render_mpesa_meta_box($post_or_order_object) {
        // Support both HPOS and traditional post-based orders
        $order = ($post_or_order_object instanceof WP_Post)
            ? wc_get_order($post_or_order_object->ID)
            : $post_or_order_object;

        if (!$order) {
            echo '<p>' . esc_html__('Unable to retrieve order.', 'mpesa-gateway-for-woocommerce') . '</p>';
            return;
        }

        $order_id = $order->get_id();
        $transaction = self::get_transaction_by_order_id($order_id);

        if (!$transaction) {
            echo '<p>' . esc_html__('No M-Pesa transaction found for this order.', 'mpesa-gateway-for-woocommerce') . '</p>';
            return;
        }

        echo '<div class="mpesa-transaction-details">';
        echo '<p><strong>' . esc_html__('Status:', 'mpesa-gateway-for-woocommerce') . '</strong> <span class="mpesa-status-' . esc_attr($transaction->status) . '">' . esc_html(ucfirst($transaction->status)) . '</span></p>';

        if ($transaction->transaction_id) {
            echo '<p><strong>' . esc_html__('M-Pesa Receipt:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html($transaction->transaction_id) . '</p>';
        }

        echo '<p><strong>' . esc_html__('Phone Number:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html($transaction->phone_number) . '</p>';
        echo '<p><strong>' . esc_html__('Amount:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html(self::format_amount($transaction->amount)) . '</p>';

        if ($transaction->result_desc) {
            echo '<p><strong>' . esc_html__('Result:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html($transaction->result_desc) . '</p>';
        }

        echo '<p><strong>' . esc_html__('Created:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html($transaction->created_at) . '</p>';

        if ($transaction->updated_at != $transaction->created_at) {
            echo '<p><strong>' . esc_html__('Updated:', 'mpesa-gateway-for-woocommerce') . '</strong> ' . esc_html($transaction->updated_at) . '</p>';
        }

        // Manual confirmation section for pending/failed transactions
        if (in_array($transaction->status, array('pending', 'failed')) && $order->get_status() !== 'processing') {
            echo '<hr>';
            echo '<h4>' . esc_html__('Manual Payment Confirmation', 'mpesa-gateway-for-woocommerce') . '</h4>';
            echo '<p class="description">' . esc_html__('If payment was successful but not automatically confirmed, enter the M-Pesa receipt number below:', 'mpesa-gateway-for-woocommerce') . '</p>';
            echo '<form method="post" action="">';
            echo '<input type="hidden" name="mpesa_manual_confirm_nonce" value="' . esc_attr(wp_create_nonce('mpesa_manual_confirm_' . $order_id)) . '">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
            echo '<p><input type="text" name="mpesa_receipt_number" placeholder="' . esc_attr__('M-Pesa Receipt Number (e.g. QA12BC3DEF)', 'mpesa-gateway-for-woocommerce') . '" style="width: 100%;" required></p>';
            echo '<p><button type="submit" name="mpesa_manual_confirm" class="button button-primary">' . esc_html__('Confirm Payment', 'mpesa-gateway-for-woocommerce') . '</button></p>';
            echo '</form>';
        }

        echo '</div>';
    }

    /**
     * Handle manual payment confirmation
     */
    public static function handle_manual_confirmation() {
        if (!isset($_POST['mpesa_manual_confirm'], $_POST['order_id'], $_POST['mpesa_receipt_number'], $_POST['mpesa_manual_confirm_nonce'])) {
            return;
        }

        $order_id = intval($_POST['order_id']);
        $receipt_number = sanitize_text_field(wp_unslash($_POST['mpesa_receipt_number']));

        // Verify nonce
        if (!wp_verify_nonce(wp_unslash($_POST['mpesa_manual_confirm_nonce']), 'mpesa_manual_confirm_' . $order_id)) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('Security check failed.', 'mpesa-gateway-for-woocommerce') . '</p></div>';
            });
            return;
        }

        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('You do not have permission to perform this action.', 'mpesa-gateway-for-woocommerce') . '</p></div>';
            });
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        // Update transaction
        $wpdb->update(
            $table_name,
            array(
                'transaction_id' => $receipt_number,
                'result_code' => '0',
                'result_desc' => 'Manually confirmed by admin',
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('order_id' => $order_id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        // Update order
        $order->payment_complete($receipt_number);
        $order->update_status('processing', sprintf(
            /* translators: 1: the admin's display name, 2: the M-Pesa receipt number */
            __('Payment manually confirmed by %1$s. M-Pesa Receipt: %2$s', 'mpesa-gateway-for-woocommerce'),
            wp_get_current_user()->display_name,
            $receipt_number
        ));

        $order->add_order_note(sprintf(
            /* translators: 1: the M-Pesa receipt number, 2: the admin's display name */
            __('Payment manually verified and confirmed. Receipt Number: %1$s, Confirmed by: %2$s', 'mpesa-gateway-for-woocommerce'),
            $receipt_number,
            wp_get_current_user()->display_name
        ));

        add_action('admin_notices', function() use ($receipt_number) {
            /* translators: %s: the M-Pesa receipt number */
            echo '<div class="updated"><p>' . esc_html(sprintf(__('Payment confirmed successfully. Receipt: %s', 'mpesa-gateway-for-woocommerce'), $receipt_number)) . '</p></div>';
        });
    }
}

// Add meta box to order page
add_action('add_meta_boxes', array('Mpesa_Helpers', 'add_mpesa_meta_box'));

// Handle manual payment confirmation
add_action('admin_init', array('Mpesa_Helpers', 'handle_manual_confirmation'));

// Schedule log cleanup (runs daily)
if (!wp_next_scheduled('mpesa_clean_old_logs')) {
    wp_schedule_event(time(), 'daily', 'mpesa_clean_old_logs');
}

add_action('mpesa_clean_old_logs', array('Mpesa_Helpers', 'clean_old_logs'));
