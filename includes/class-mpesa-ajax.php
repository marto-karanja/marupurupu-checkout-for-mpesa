<?php
/**
 * M-Pesa AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        // For logged-in users
        add_action('wp_ajax_marupurupu_check_status', array(__CLASS__, 'check_payment_status'));

        // For non-logged-in users (customer checking their order)
        add_action('wp_ajax_nopriv_marupurupu_check_status', array(__CLASS__, 'check_payment_status'));

        // Retry payment
        add_action('wp_ajax_marupurupu_retry_payment', array(__CLASS__, 'retry_payment'));
        add_action('wp_ajax_nopriv_marupurupu_retry_payment', array(__CLASS__, 'retry_payment'));

        // Verify transaction code
        add_action('wp_ajax_marupurupu_verify_code', array(__CLASS__, 'verify_transaction_code'));
        add_action('wp_ajax_nopriv_marupurupu_verify_code', array(__CLASS__, 'verify_transaction_code'));
    }

    /**
     * Whether the customer-facing retry/verify endpoints may act on this
     * order. Those endpoints are reachable by anyone holding the order key
     * (necessarily so -- guest checkout has no logged-in user to check), so
     * they must never touch an order that's already paid (a stray or hostile
     * call could otherwise overwrite a confirmed M-Pesa receipt and knock a
     * `processing` order back to `on-hold`), or one placed through a
     * different payment gateway.
     *
     * @param WC_Order $order
     * @return bool
     */
    private static function order_accepts_payment_action($order) {
        return $order->get_payment_method() === 'mpesa_till'
            && in_array($order->get_status(), array('pending', 'on-hold', 'failed'), true);
    }

    /**
     * Check payment status via AJAX
     */
    public static function check_payment_status() {
        check_ajax_referer('marupurupu_check_status', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'marupurupu-checkout-for-mpesa')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'marupurupu-checkout-for-mpesa')));
        }

        // Get transaction
        $transaction = Marupurupu_Helpers::get_transaction_by_order_id($order_id);

        if (!$transaction) {
            wp_send_json_error(array('message' => __('No transaction found.', 'marupurupu-checkout-for-mpesa')));
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
            $response['message'] = __('Payment confirmed! Your order is being processed.', 'marupurupu-checkout-for-mpesa');
        } elseif ($transaction->status === 'failed') {
            $response['complete'] = false;
            $response['failed'] = true;
            /* translators: %s: the payment failure reason reported by M-Pesa */
            $response['message'] = sprintf(__('Payment failed: %s', 'marupurupu-checkout-for-mpesa'), $transaction->result_desc);
        } else {
            $response['complete'] = false;
            $response['message'] = __('Waiting for payment confirmation...', 'marupurupu-checkout-for-mpesa');
        }

        wp_send_json_success($response);
    }

    /**
     * Retry payment
     */
    public static function retry_payment() {
        check_ajax_referer('marupurupu_retry_payment', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'marupurupu-checkout-for-mpesa')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'marupurupu-checkout-for-mpesa')));
        }

        if (!self::order_accepts_payment_action($order)) {
            wp_send_json_error(array('message' => __('This order can no longer be paid from this page.', 'marupurupu-checkout-for-mpesa')));
        }

        // Validate phone number
        if (!Marupurupu_Gateway::is_valid_phone_number($phone)) {
            wp_send_json_error(array('message' => __('Please enter a valid phone number (format: 254XXXXXXXXX).', 'marupurupu-checkout-for-mpesa')));
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
        $rate_limit_key = 'marupurupu_retry_rl_' . $order_id;
        $attempts = (int) get_transient($rate_limit_key);
        if ($attempts >= 3) {
            wp_send_json_error(array('message' => __('Too many payment retry attempts for this order. Please wait a few minutes and try again.', 'marupurupu-checkout-for-mpesa')));
        }
        set_transient($rate_limit_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        // Get gateway settings
        $gateway = WC()->payment_gateways()->payment_gateways()['mpesa_till'] ?? null;

        if (!$gateway) {
            wp_send_json_error(array('message' => __('Payment gateway not available.', 'marupurupu-checkout-for-mpesa')));
        }

        // Initialize M-Pesa API
        $marupurupu_api = new Marupurupu_API(
            $gateway->consumer_key,
            $gateway->consumer_secret,
            $gateway->shortcode,
            $gateway->till_number,
            $gateway->passkey,
            $gateway->testmode
        );

        // Initiate STK Push
        $response = $marupurupu_api->stk_push(
            $phone,
            $order->get_total(),
            $order_id,
            $gateway->callback_url
        );

        if ($response && isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            // Save new transaction attempt
            global $wpdb;
            $table_name = $wpdb->prefix . 'marupurupu_transactions';

            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'merchant_request_id' => $response['MerchantRequestID'],
                    'checkout_request_id' => $response['CheckoutRequestID'],
                    'phone_number' => $phone,
                    'amount' => $order->get_total(),
                    'status' => 'pending',
                    'request_data' => wp_json_encode($response),
                ),
                array('%d', '%s', '%s', '%s', '%f', '%s', '%s')
            );

            $order->add_order_note(sprintf(
                /* translators: 1: the customer phone number, 2: the M-Pesa merchant request ID */
                __('Payment retry initiated by customer. Phone: %1$s, MerchantRequestID: %2$s', 'marupurupu-checkout-for-mpesa'),
                $phone,
                $response['MerchantRequestID']
            ));

            wp_send_json_success(array(
                'message' => __('Payment request sent! Please check your phone and enter your M-Pesa PIN.', 'marupurupu-checkout-for-mpesa'),
                'merchant_request_id' => $response['MerchantRequestID']
            ));
        } else {
            $error_message = isset($response['errorMessage']) ? $response['errorMessage'] : __('Unable to initiate payment.', 'marupurupu-checkout-for-mpesa');

            /* translators: %s: the error message returned by the M-Pesa API */
            $order->add_order_note(sprintf(__('Payment retry failed: %s', 'marupurupu-checkout-for-mpesa'), $error_message));

            wp_send_json_error(array('message' => $error_message));
        }
    }

    /**
     * Verify transaction code entered by customer
     */
    public static function verify_transaction_code() {
        check_ajax_referer('marupurupu_verify_code', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $transaction_code = isset($_POST['transaction_code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['transaction_code']))) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'marupurupu-checkout-for-mpesa')));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Invalid order.', 'marupurupu-checkout-for-mpesa')));
        }

        if (!self::order_accepts_payment_action($order)) {
            wp_send_json_error(array('message' => __('This order can no longer be changed from this page.', 'marupurupu-checkout-for-mpesa')));
        }

        if (empty($transaction_code)) {
            wp_send_json_error(array('message' => __('Please enter the M-Pesa transaction code.', 'marupurupu-checkout-for-mpesa')));
        }

        // Update transaction with customer-provided code
        global $wpdb;
        $table_name = $wpdb->prefix . 'marupurupu_transactions';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if ($existing && $existing->status === 'completed') {
            // Never overwrite a payment M-Pesa itself already confirmed.
            wp_send_json_error(array('message' => __('This payment has already been confirmed.', 'marupurupu-checkout-for-mpesa')));
        }

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
                __('Customer provided M-Pesa code: %s. Awaiting admin verification.', 'marupurupu-checkout-for-mpesa'),
                $transaction_code
            ));

            $order->add_order_note(sprintf(
                /* translators: %s: the M-Pesa transaction code the customer entered */
                __('Customer submitted M-Pesa transaction code: %s. Please verify this payment manually.', 'marupurupu-checkout-for-mpesa'),
                $transaction_code
            ));

            wp_send_json_success(array(
                'message' => __('Thank you! Your transaction code has been submitted. We will verify and confirm your payment shortly.', 'marupurupu-checkout-for-mpesa')
            ));
        } else {
            wp_send_json_error(array('message' => __('No transaction found for this order.', 'marupurupu-checkout-for-mpesa')));
        }
    }
}

// Initialize AJAX handlers
Marupurupu_Ajax::init();
