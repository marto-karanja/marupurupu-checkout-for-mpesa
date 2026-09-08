<?php
/**
 * M-Pesa AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        // For logged-in users
        add_action('wp_ajax_mpesa_check_status', array(__CLASS__, 'check_payment_status'));

        // For non-logged-in users (customer checking their order)
        add_action('wp_ajax_nopriv_mpesa_check_status', array(__CLASS__, 'check_payment_status'));

        // Retry payment
        add_action('wp_ajax_mpesa_retry_payment', array(__CLASS__, 'retry_payment'));
        add_action('wp_ajax_nopriv_mpesa_retry_payment', array(__CLASS__, 'retry_payment'));

        // Verify transaction code
        add_action('wp_ajax_mpesa_verify_code', array(__CLASS__, 'verify_transaction_code'));
        add_action('wp_ajax_nopriv_mpesa_verify_code', array(__CLASS__, 'verify_transaction_code'));
    }

    /**
     * Check payment status via AJAX
     */
    public static function check_payment_status() {
        check_ajax_referer('mpesa_check_status', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'mpesa-till-gateway')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'mpesa-till-gateway')));
        }

        // Get transaction
        $transaction = Mpesa_Helpers::get_transaction_by_order_id($order_id);

        if (!$transaction) {
            wp_send_json_error(array('message' => __('No transaction found.', 'mpesa-till-gateway')));
        }

        $response = array(
            'status' => $transaction->status,
            'order_status' => $order->get_status(),
            'transaction_id' => $transaction->transaction_id,
            'result_desc' => $transaction->result_desc,
            'amount' => wc_price($transaction->amount),
            'phone' => $transaction->phone_number,
            'created_at' => $transaction->created_at,
        );

        // Determine if payment is complete
        if ($transaction->status === 'completed' || $order->get_status() === 'processing') {
            $response['complete'] = true;
            $response['message'] = __('Payment confirmed! Your order is being processed.', 'mpesa-till-gateway');
        } elseif ($transaction->status === 'failed') {
            $response['complete'] = false;
            $response['failed'] = true;
            /* translators: %s: the payment failure reason reported by M-Pesa */
            $response['message'] = sprintf(__('Payment failed: %s', 'mpesa-till-gateway'), $transaction->result_desc);
        } else {
            $response['complete'] = false;
            $response['message'] = __('Waiting for payment confirmation...', 'mpesa-till-gateway');
        }

        wp_send_json_success($response);
    }

    /**
     * Retry payment
     */
    public static function retry_payment() {
        check_ajax_referer('mpesa_retry_payment', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'mpesa-till-gateway')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'mpesa-till-gateway')));
        }

        // Validate phone number
        if (!preg_match('/^254[0-9]{9}$/', $phone)) {
            wp_send_json_error(array('message' => __('Please enter a valid phone number (format: 254XXXXXXXXX).', 'mpesa-till-gateway')));
        }

        // Rate limit: this is a nopriv endpoint gated only by order_key
        // (necessarily so -- guest checkout has no logged-in user to check
        // capabilities against), and $phone is caller-supplied rather than
        // read from the order. Without a limit here, anyone holding a
        // valid order_id+order_key for their own real order could enter a
        // *different* phone number and repeatedly trigger unsolicited
        // M-Pesa STK Push prompts against it. Capped per order, not per
        // IP/phone, since order_key is already the actual authorization
        // boundary this endpoint has.
        $rate_limit_key = 'mpesa_retry_rl_' . $order_id;
        $attempts = (int) get_transient($rate_limit_key);
        if ($attempts >= 3) {
            wp_send_json_error(array('message' => __('Too many payment retry attempts for this order. Please wait a few minutes and try again.', 'mpesa-till-gateway')));
        }
        set_transient($rate_limit_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        // Get gateway settings
        $gateway = WC()->payment_gateways()->payment_gateways()['mpesa_till'];

        if (!$gateway) {
            wp_send_json_error(array('message' => __('Payment gateway not available.', 'mpesa-till-gateway')));
        }

        // Initialize M-Pesa API
        $mpesa_api = new Mpesa_API(
            $gateway->consumer_key,
            $gateway->consumer_secret,
            $gateway->shortcode,
            $gateway->till_number,
            $gateway->passkey,
            $gateway->testmode
        );

        // Initiate STK Push
        $response = $mpesa_api->stk_push(
            $phone,
            $order->get_total(),
            $order_id,
            $gateway->callback_url
        );

        if ($response && isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            // Save new transaction attempt
            global $wpdb;
            $table_name = $wpdb->prefix . 'mpesa_till_transactions';

            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'merchant_request_id' => $response['MerchantRequestID'],
                    'checkout_request_id' => $response['CheckoutRequestID'],
                    'phone_number' => $phone,
                    'amount' => $order->get_total(),
                    'status' => 'pending',
                    'request_data' => json_encode($response),
                ),
                array('%d', '%s', '%s', '%s', '%f', '%s', '%s')
            );

            $order->add_order_note(sprintf(
                /* translators: 1: the customer phone number, 2: the M-Pesa merchant request ID */
                __('Payment retry initiated by customer. Phone: %1$s, MerchantRequestID: %2$s', 'mpesa-till-gateway'),
                $phone,
                $response['MerchantRequestID']
            ));

            wp_send_json_success(array(
                'message' => __('Payment request sent! Please check your phone and enter your M-Pesa PIN.', 'mpesa-till-gateway'),
                'merchant_request_id' => $response['MerchantRequestID']
            ));
        } else {
            $error_message = isset($response['errorMessage']) ? $response['errorMessage'] : __('Unable to initiate payment.', 'mpesa-till-gateway');

            /* translators: %s: the error message returned by the M-Pesa API */
            $order->add_order_note(sprintf(__('Payment retry failed: %s', 'mpesa-till-gateway'), $error_message));

            wp_send_json_error(array('message' => $error_message));
        }
    }

    /**
     * Verify transaction code entered by customer
     */
    public static function verify_transaction_code() {
        check_ajax_referer('mpesa_verify_code', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $transaction_code = isset($_POST['transaction_code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['transaction_code']))) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'mpesa-till-gateway')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'mpesa-till-gateway')));
        }

        if (empty($transaction_code)) {
            wp_send_json_error(array('message' => __('Please enter the M-Pesa transaction code.', 'mpesa-till-gateway')));
        }

        // Update transaction with customer-provided code
        global $wpdb;
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if ($existing) {
            // Update transaction with pending verification
            $wpdb->update(
                $table_name,
                array(
                    'transaction_id' => $transaction_code,
                    'result_desc' => 'Customer provided transaction code - pending admin verification',
                    'status' => 'pending',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );

            $order->update_status('on-hold', sprintf(
                /* translators: %s: the M-Pesa transaction code the customer entered */
                __('Customer provided M-Pesa code: %s. Awaiting admin verification.', 'mpesa-till-gateway'),
                $transaction_code
            ));

            $order->add_order_note(sprintf(
                /* translators: %s: the M-Pesa transaction code the customer entered */
                __('Customer submitted M-Pesa transaction code: %s. Please verify this payment manually.', 'mpesa-till-gateway'),
                $transaction_code
            ));

            wp_send_json_success(array(
                'message' => __('Thank you! Your transaction code has been submitted. We will verify and confirm your payment shortly.', 'mpesa-till-gateway')
            ));
        } else {
            wp_send_json_error(array('message' => __('No transaction found for this order.', 'mpesa-till-gateway')));
        }
    }
}

// Initialize AJAX handlers
Mpesa_Ajax::init();
