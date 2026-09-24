<?php
/**
 * M-Pesa Order Received Page Enhancement
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Order_Received {

    /**
     * Initialize
     */
    public static function init() {
        add_action('woocommerce_thankyou_mpesa_till', array(__CLASS__, 'render_payment_status'), 10, 1);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    /**
     * Enqueue scripts on order received page
     */
    public static function enqueue_scripts() {
        if (!is_order_received_page()) {
            return;
        }

        global $wp;
        $order_id = absint($wp->query_vars['order-received']);

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== 'mpesa_till') {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'marupurupu-order-status',
            MARUPURUPU_PLUGIN_URL . 'assets/css/mpesa-order-status.css',
            array(),
            MARUPURUPU_VERSION
        );

        // Enqueue JS
        wp_enqueue_script(
            'marupurupu-order-status',
            MARUPURUPU_PLUGIN_URL . 'assets/js/mpesa-order-status.js',
            array('jquery'),
            MARUPURUPU_VERSION,
            true
        );

        // Localize script
        wp_localize_script('marupurupu-order-status', 'marupurupu_order_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'order_id' => $order_id,
            'order_key' => $order->get_order_key(),
            'check_status_nonce' => wp_create_nonce('marupurupu_check_status'),
            'retry_payment_nonce' => wp_create_nonce('marupurupu_retry_payment'),
            'verify_code_nonce' => wp_create_nonce('marupurupu_verify_code'),
            'i18n' => array(
                'success_next' => __('Your order is being processed. You will receive a confirmation email shortly.', 'marupurupu-checkout-for-mpesa'),
                /* translators: %d: number of seconds until the page reloads */
                'refreshing' => __('Refreshing page in %d seconds...', 'marupurupu-checkout-for-mpesa'),
                'what_next' => __('What to do next:', 'marupurupu-checkout-for-mpesa'),
                'failed_step_1' => __('If you didn\'t complete the payment, click "Send Payment Request" below to retry', 'marupurupu-checkout-for-mpesa'),
                'failed_step_2' => __('If you already paid, enter your M-Pesa transaction code to verify', 'marupurupu-checkout-for-mpesa'),
                'failed_step_3' => __('Contact support if you need assistance', 'marupurupu-checkout-for-mpesa'),
                'timeout_title' => __('Payment confirmation timeout', 'marupurupu-checkout-for-mpesa'),
                'timeout_intro' => __('We haven\'t received confirmation yet. This could mean:', 'marupurupu-checkout-for-mpesa'),
                'timeout_cause_1' => __('The payment is still processing (please wait a few more minutes)', 'marupurupu-checkout-for-mpesa'),
                'timeout_cause_2' => __('You didn\'t complete the payment on your phone', 'marupurupu-checkout-for-mpesa'),
                'timeout_cause_3' => __('There was a network delay', 'marupurupu-checkout-for-mpesa'),
                'timeout_step_1' => __('Check your phone for M-Pesa confirmation SMS', 'marupurupu-checkout-for-mpesa'),
                'timeout_step_2' => __('If you received an SMS, enter the transaction code below to verify', 'marupurupu-checkout-for-mpesa'),
                'timeout_step_3' => __('If you didn\'t pay yet, click "Send Payment Request" to retry', 'marupurupu-checkout-for-mpesa'),
                'timeout_step_4' => __('Refresh this page in a few minutes to check status', 'marupurupu-checkout-for-mpesa'),
                'invalid_phone' => __('Please enter a valid phone number (format: 254XXXXXXXXX)', 'marupurupu-checkout-for-mpesa'),
                'check_phone' => __('Check your phone now.', 'marupurupu-checkout-for-mpesa'),
                'enter_code' => __('Please enter the M-Pesa transaction code', 'marupurupu-checkout-for-mpesa'),
                'connection_error' => __('Connection error. Please try again.', 'marupurupu-checkout-for-mpesa'),
                'sending' => __('Sending...', 'marupurupu-checkout-for-mpesa'),
                'send_request' => __('Send Payment Request', 'marupurupu-checkout-for-mpesa'),
                'verifying' => __('Verifying...', 'marupurupu-checkout-for-mpesa'),
                'verify_payment' => __('Verify Payment', 'marupurupu-checkout-for-mpesa'),
            ),
        ));
    }

    /**
     * Render payment status on thank you page
     */
    public static function render_payment_status($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $transaction = Marupurupu_Helpers::get_transaction_by_order_id($order_id);

        if (!$transaction) {
            return;
        }

        $order_status = $order->get_status();
        $transaction_status = $transaction->status;

        ?>
        <div id="mpesa-messages"></div>

        <?php if ($order_status === 'processing' || $transaction_status === 'completed'): ?>
            <!-- Payment Successful -->
            <div class="mpesa-order-status" id="mpesa-status-success">
                <svg class="mpesa-success-checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                    <circle class="circle" cx="26" cy="26" r="25" fill="none"/>
                    <path class="check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                </svg>

                <div class="mpesa-status-title" style="color: #0f834d;">
                    <?php esc_html_e('Payment Confirmed!', 'marupurupu-checkout-for-mpesa'); ?>
                </div>

                <div class="mpesa-status-message">
                    <?php esc_html_e('Your M-Pesa payment has been received and confirmed. Your order is now being processed.', 'marupurupu-checkout-for-mpesa'); ?>
                </div>

                <?php if ($transaction->transaction_id): ?>
                    <div class="mpesa-status-details">
                        <p>
                            <strong><?php esc_html_e('M-Pesa Receipt:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                            <span><?php echo esc_html($transaction->transaction_id); ?></span>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Amount Paid:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                            <span><?php echo wp_kses_post(wc_price($transaction->amount)); ?></span>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Phone Number:', 'marupurupu-checkout-for-mpesa'); ?></strong>
                            <span><?php echo esc_html($transaction->phone_number); ?></span>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($transaction_status === 'failed'): ?>
            <!-- Payment Failed -->
            <div class="mpesa-order-status" id="mpesa-status-failed">
                <svg class="mpesa-error-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                    <circle class="circle" cx="26" cy="26" r="25" fill="none"/>
                    <path class="cross" fill="none" d="M16 16 36 36 M36 16 16 36"/>
                </svg>

                <div class="mpesa-status-title" style="color: #e2401c;">
                    <?php esc_html_e('Payment Failed', 'marupurupu-checkout-for-mpesa'); ?>
                </div>

                <div class="mpesa-status-message">
                    <?php
                    if ($transaction->result_desc) {
                        echo esc_html($transaction->result_desc);
                    } else {
                        esc_html_e('The M-Pesa payment was not completed.', 'marupurupu-checkout-for-mpesa');
                    }
                    ?>
                </div>

                <?php self::render_retry_section($order, $transaction); ?>
            </div>

        <?php else: ?>
            <!-- Payment Pending -->
            <div class="mpesa-order-status" id="mpesa-status-pending">
                <div class="mpesa-status-icon pending">⏱️</div>

                <div class="mpesa-status-title">
                    <?php esc_html_e('Waiting for Payment Confirmation', 'marupurupu-checkout-for-mpesa'); ?>
                </div>

                <div class="mpesa-status-message">
                    <span class="mpesa-status-indicator pending"></span>
                    <span id="mpesa-status-text"><?php esc_html_e('Please check your phone and enter your M-Pesa PIN to complete the payment.', 'marupurupu-checkout-for-mpesa'); ?></span>
                </div>

                <div class="mpesa-timer">
                    <?php esc_html_e('Time elapsed:', 'marupurupu-checkout-for-mpesa'); ?> <span id="mpesa-time-elapsed">0:00</span>
                </div>

                <div class="mpesa-instructions">
                    <h4><?php esc_html_e('What to do next:', 'marupurupu-checkout-for-mpesa'); ?></h4>
                    <ol>
                        <li><?php esc_html_e('Check your phone for an M-Pesa payment prompt', 'marupurupu-checkout-for-mpesa'); ?></li>
                        <li><?php esc_html_e('Enter your M-Pesa PIN to confirm payment', 'marupurupu-checkout-for-mpesa'); ?></li>
                        <li><?php esc_html_e('Wait for confirmation (this page will update automatically)', 'marupurupu-checkout-for-mpesa'); ?></li>
                    </ol>
                </div>
            </div>

            <!-- Show retry and verify sections after initial wait -->
            <div id="mpesa-retry-section" style="display: none;">
                <?php self::render_retry_section($order, $transaction); ?>
            </div>
        <?php endif; ?>

        <?php
        // Always show verify section for pending/failed
        if (in_array($transaction_status, array('pending', 'failed')) && $order_status !== 'processing') {
            self::render_verify_section($order, $transaction);
        }
    }

    /**
     * Render retry payment section
     */
    private static function render_retry_section($order, $transaction) {
        ?>
        <div class="mpesa-retry-section">
            <h3><?php esc_html_e('Retry Payment', 'marupurupu-checkout-for-mpesa'); ?></h3>
            <p><?php esc_html_e('Didn\'t receive the payment prompt? Click below to send a new request.', 'marupurupu-checkout-for-mpesa'); ?></p>

            <div class="mpesa-form-group">
                <label for="mpesa-retry-phone">
                    <?php esc_html_e('Phone Number', 'marupurupu-checkout-for-mpesa'); ?>
                </label>
                <input
                    type="tel"
                    id="mpesa-retry-phone"
                    value="<?php echo esc_attr($transaction->phone_number); ?>"
                    placeholder="254XXXXXXXXX"
                    maxlength="12"
                >
                <div class="description">
                    <?php esc_html_e('Enter the phone number to receive the M-Pesa payment prompt', 'marupurupu-checkout-for-mpesa'); ?>
                </div>
            </div>

            <button type="button" id="mpesa-retry-payment" class="mpesa-button">
                <?php esc_html_e('Send Payment Request', 'marupurupu-checkout-for-mpesa'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render verify transaction code section
     */
    private static function render_verify_section($order, $transaction) {
        ?>
        <div class="mpesa-verify-section">
            <h3><?php esc_html_e('Already Paid?', 'marupurupu-checkout-for-mpesa'); ?></h3>
            <p class="description">
                <?php esc_html_e('If you have already completed the payment, enter your M-Pesa transaction code below to verify.', 'marupurupu-checkout-for-mpesa'); ?>
            </p>

            <div class="mpesa-sms-example">
                <?php esc_html_e('Your M-Pesa confirmation SMS looks like this:', 'marupurupu-checkout-for-mpesa'); ?><br><br>
                <strong>QA12BC3DEF</strong> <?php esc_html_e('Confirmed', 'marupurupu-checkout-for-mpesa'); ?><br>
                <?php esc_html_e('You have paid KES', 'marupurupu-checkout-for-mpesa'); ?> <?php echo esc_html(number_format_i18n((float) $order->get_total(), 2)); ?><br>
                <?php esc_html_e('on', 'marupurupu-checkout-for-mpesa'); ?> <?php echo esc_html(gmdate('d/m/Y \a\t h:i A')); ?>
            </div>

            <div class="mpesa-form-group">
                <label for="mpesa-transaction-code">
                    <?php esc_html_e('M-Pesa Transaction Code', 'marupurupu-checkout-for-mpesa'); ?>
                </label>
                <input
                    type="text"
                    id="mpesa-transaction-code"
                    placeholder="<?php esc_attr_e('e.g. QA12BC3DEF', 'marupurupu-checkout-for-mpesa'); ?>"
                    maxlength="20"
                    style="text-transform: uppercase;"
                >
                <div class="description">
                    <?php esc_html_e('Enter the transaction code from your M-Pesa confirmation SMS', 'marupurupu-checkout-for-mpesa'); ?>
                </div>
            </div>

            <button type="button" id="mpesa-verify-code-btn" class="mpesa-button mpesa-button-secondary">
                <?php esc_html_e('Verify Payment', 'marupurupu-checkout-for-mpesa'); ?>
            </button>
        </div>
        <?php
    }
}

// Initialize
Marupurupu_Order_Received::init();
