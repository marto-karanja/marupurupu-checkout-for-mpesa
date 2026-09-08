<?php
/**
 * M-Pesa Callback Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_Callback {

    /**
     * Secret token expected on incoming callback requests (as a ?key=
     * query arg). Generated per-site by WC_Mpesa_Till_Gateway and embedded
     * in the callback URL handed to Safaricom.
     *
     * @var string
     */
    private $expected_secret;

    /**
     * @param string $expected_secret Secret token this site issued for its callback URL.
     */
    public function __construct($expected_secret = '') {
        $this->expected_secret = (string) $expected_secret;
    }

    /**
     * Process M-Pesa callback
     */
    public function process_callback() {
        if (!$this->is_authorized()) {
            $this->log_message('Rejected callback with missing/invalid secret token. Remote IP: ' . $this->get_remote_ip());
            $this->send_response(array('ResultCode' => 1, 'ResultDesc' => 'Unauthorized'));
            return;
        }

        // Get the callback data
        $callback_json = file_get_contents('php://input');
        $callback_data = json_decode($callback_json, true);

        // Log callback data
        $this->log_callback($callback_data);

        if (!$callback_data) {
            $this->send_response(array('ResultCode' => 1, 'ResultDesc' => 'Invalid callback data'));
            return;
        }

        // Extract callback information
        if (isset($callback_data['Body']['stkCallback'])) {
            $stk_callback = $callback_data['Body']['stkCallback'];
            $this->process_stk_callback($stk_callback);
        }

        // Send acknowledgment response
        $this->send_response(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
    }

    /**
     * Verify the request carries the secret token issued for this site.
     * Fails closed: a missing/empty expected secret (e.g. gateway never
     * initialized) is treated as unauthorized, not skipped.
     *
     * @return bool
     */
    private function is_authorized() {
        if (empty($this->expected_secret)) {
            return false;
        }

        $provided = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';

        return hash_equals($this->expected_secret, $provided);
    }

    /**
     * @return string
     */
    private function get_remote_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    }

    /**
     * Process STK Push callback
     */
    private function process_stk_callback($stk_callback) {
        global $wpdb;

        $merchant_request_id = isset($stk_callback['MerchantRequestID']) ? $stk_callback['MerchantRequestID'] : '';
        $checkout_request_id = isset($stk_callback['CheckoutRequestID']) ? $stk_callback['CheckoutRequestID'] : '';
        $result_code = isset($stk_callback['ResultCode']) ? $stk_callback['ResultCode'] : '';
        $result_desc = isset($stk_callback['ResultDesc']) ? $stk_callback['ResultDesc'] : '';

        // Get transaction from database
        $table_name = $wpdb->prefix . 'mpesa_till_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE checkout_request_id = %s",
            $checkout_request_id
        ));

        if (!$transaction) {
            $this->log_message('Transaction not found for CheckoutRequestID: ' . $checkout_request_id);
            return;
        }

        // Idempotency: Safaricom is known to occasionally redeliver the
        // same callback. Without this check, a duplicate would re-run
        // payment_complete() and re-fire woocommerce_payment_complete a
        // second time -- WooCommerce core guards some of its own behavior
        // against that, but nothing guarantees every hook listening on
        // that action does. Once a transaction has reached a terminal
        // state, further callbacks for the same CheckoutRequestID are
        // acknowledged (so Safaricom doesn't keep retrying) but not
        // reprocessed.
        if (in_array($transaction->status, array('completed', 'failed'), true)) {
            $this->log_message('Ignoring duplicate callback for already-' . $transaction->status . ' CheckoutRequestID: ' . $checkout_request_id);
            return;
        }

        $order_id = $transaction->order_id;
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log_message('Order not found: ' . $order_id);
            return;
        }

        // Check if payment was successful
        if ($result_code == '0') {
            // Payment successful
            $callback_metadata = isset($stk_callback['CallbackMetadata']['Item']) ? $stk_callback['CallbackMetadata']['Item'] : array();

            $transaction_id = '';
            $amount = 0;
            $phone = '';
            $transaction_date = '';

            // Extract metadata
            foreach ($callback_metadata as $item) {
                if ($item['Name'] == 'MpesaReceiptNumber') {
                    $transaction_id = $item['Value'];
                }
                if ($item['Name'] == 'Amount') {
                    $amount = $item['Value'];
                }
                if ($item['Name'] == 'PhoneNumber') {
                    $phone = $item['Value'];
                }
                if ($item['Name'] == 'TransactionDate') {
                    $transaction_date = $item['Value'];
                }
            }

            // Verify the confirmed amount actually matches what was
            // requested when the STK push was initiated, rather than
            // trusting the callback's figure blindly. A mismatch (e.g. an
            // integration bug upstream, or any tampering that somehow got
            // past the secret-token check) should never silently mark an
            // order paid in full -- it goes to on-hold for manual review
            // instead. 1 KES tolerance for float/rounding noise.
            $expected_amount = (float) $transaction->amount;
            if (abs((float) $amount - $expected_amount) > 1.0) {
                $wpdb->update(
                    $table_name,
                    array(
                        'transaction_id' => $transaction_id,
                        'result_code' => $result_code,
                        'result_desc' => 'Amount mismatch: expected ' . $expected_amount . ', received ' . $amount,
                        'status' => 'failed',
                        'response_data' => json_encode($stk_callback),
                    ),
                    array('checkout_request_id' => $checkout_request_id),
                    array('%s', '%s', '%s', '%s', '%s'),
                    array('%s')
                );

                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount reported by M-Pesa, 2: the order total, 3: the M-Pesa receipt number */
                    __('M-Pesa reported a paid amount (KES %1$s) that does not match the order total (KES %2$s). NOT marked as paid automatically -- verify manually before fulfilling. Receipt: %3$s', 'mpesa-till-gateway'),
                    number_format((float) $amount, 2),
                    number_format($expected_amount, 2),
                    $transaction_id
                ));

                $this->log_message(sprintf(
                    'Amount mismatch for order %d: expected %s, received %s. Order set to on-hold, NOT marked paid.',
                    $order_id,
                    $expected_amount,
                    $amount
                ));

                return;
            }

            // Update transaction in database
            $wpdb->update(
                $table_name,
                array(
                    'transaction_id' => $transaction_id,
                    'result_code' => $result_code,
                    'result_desc' => $result_desc,
                    'status' => 'completed',
                    'response_data' => json_encode($stk_callback),
                ),
                array('checkout_request_id' => $checkout_request_id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%s')
            );

            // Mark order as processing (payment received, awaiting fulfillment)
            $order->payment_complete($transaction_id);
            $order->update_status('processing', sprintf(
                /* translators: 1: the M-Pesa receipt number, 2: the amount paid, 3: the customer phone number */
                __('M-Pesa payment received and confirmed. Transaction ID: %1$s, Amount: KES %2$s, Phone: %3$s', 'mpesa-till-gateway'),
                $transaction_id,
                number_format($amount, 2),
                $phone
            ));

            $order->add_order_note(sprintf(
                /* translators: 1: the M-Pesa receipt number, 2: the amount paid, 3: the M-Pesa transaction date */
                __('Payment confirmed via M-Pesa callback. Receipt: %1$s, Amount: KES %2$s, Date: %3$s', 'mpesa-till-gateway'),
                $transaction_id,
                number_format($amount, 2),
                $transaction_date
            ));

            // NOTE: $order->payment_complete() above already fires
            // do_action('woocommerce_payment_complete', $order->get_id(), $transaction_id)
            // internally (see WC_Order::payment_complete() in WooCommerce
            // core) -- with a proper int order ID. A second, manual
            // do_action('woocommerce_payment_complete', $order_id) used to
            // sit here, passing $order_id as a raw string (it comes
            // straight from a $wpdb row, which returns every column as a
            // string). That's harmless to most listeners, but WooCommerce
            // core's own wc_maybe_reduce_stock_levels() -> get_stock_reduced()
            // does `is_int($order) ? $order : $order->get_id()`, which
            // fatals on a string ("Call to a member function get_id() on
            // string") -- confirmed live on bonbargains.com 2026-08-28,
            // crashing every successful payment callback partway through,
            // after the order was already marked paid but before stock was
            // reduced or this method's own log line below could run.
            // Removed rather than just cast to int, since it was firing
            // the same hook twice per payment regardless (duplicate stock
            // reduction attempts, duplicate order-complete emails from any
            // other plugin listening on this hook).

            $this->log_message('Payment completed for order: ' . $order_id . ', Transaction ID: ' . $transaction_id);

        } else {
            // Payment failed or cancelled
            $wpdb->update(
                $table_name,
                array(
                    'result_code' => $result_code,
                    'result_desc' => $result_desc,
                    'status' => 'failed',
                    'response_data' => json_encode($stk_callback),
                ),
                array('checkout_request_id' => $checkout_request_id),
                array('%s', '%s', '%s', '%s'),
                array('%s')
            );

            $order->update_status('failed', sprintf(
                /* translators: %s: the failure reason reported by M-Pesa */
                __('M-Pesa payment failed: %s', 'mpesa-till-gateway'),
                $result_desc
            ));

            $this->log_message('Payment failed for order: ' . $order_id . ', Reason: ' . $result_desc);
        }
    }

    /**
     * Send response back to M-Pesa
     */
    private function send_response($response) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Log callback data via WooCommerce's logger.
     *
     * WC stores these under wp-content/uploads/wc-logs/ with a hashed
     * filename and a generated .htaccess/index.php denying direct access --
     * unlike a hand-rolled file under the plugin's own (web-accessible)
     * directory, which is not something this class should recreate.
     * Viewable at WooCommerce > Status > Logs.
     */
    private function log_callback($data) {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->info(
            "M-Pesa callback received:\n" . wp_json_encode($data, JSON_PRETTY_PRINT),
            array('source' => 'mpesa-till-callback')
        );
    }

    /**
     * Log a plain message via WooCommerce's logger.
     */
    private function log_message($message) {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->info($message, array('source' => 'mpesa-till-callback'));
    }
}
