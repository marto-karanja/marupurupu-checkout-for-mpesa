<?php
/**
 * WooCommerce M-Pesa Till Payment Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Gateway extends WC_Payment_Gateway {

    /**
     * Single source of truth for which settings fields hold encrypted
     * credentials. Used by process_admin_options() (deciding what to
     * encrypt/preserve on save) and generate_text_html()/generate_password_html()
     * (deciding what to never redisplay). Previously this list was
     * duplicated inconsistently across those methods -- keeping one copy
     * means a field can't be handled correctly in one place and forgotten
     * in another.
     */
    const ENCRYPTED_FIELDS = array('consumer_key', 'consumer_secret', 'test_consumer_key', 'test_consumer_secret', 'passkey');

    /**
     * Gateway-specific settings, populated in the constructor from
     * get_option()/get_decrypted_option(). Declared explicitly (rather than
     * left as dynamic properties) since PHP 8.2 deprecates dynamic property
     * creation, and since several of these are also read from outside this
     * class (Marupurupu_Ajax, Marupurupu_Helpers, Marupurupu_Encryption_Admin) -- hence
     * public, not private.
     */
    public $testmode;
    public $consumer_key;
    public $consumer_secret;
    public $shortcode;
    public $till_number;
    public $passkey;
    public $callback_secret;
    public $callback_url;

    public function __construct() {
        $this->id = 'mpesa_till';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('M-Pesa Till Payment', 'marupurupu-checkout-for-mpesa');
        $this->method_description = __('Accept M-Pesa payments via STK Push for Till Numbers', 'marupurupu-checkout-for-mpesa');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings (decrypt sensitive credentials)
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->consumer_key = $this->testmode ? $this->get_decrypted_option('test_consumer_key') : $this->get_decrypted_option('consumer_key');
        $this->consumer_secret = $this->testmode ? $this->get_decrypted_option('test_consumer_secret') : $this->get_decrypted_option('consumer_secret');
        $this->shortcode = $this->get_option('shortcode');
        $this->till_number = $this->get_option('till_number');
        $this->passkey = $this->get_decrypted_option('passkey');

        // The callback URL embeds a per-site secret token (auto-generated
        // and persisted on first use) so the webhook can reject requests
        // that don't know it. See handle_callback() / Marupurupu_Callback.
        $this->callback_secret = $this->get_callback_secret();
        $this->callback_url = $this->build_callback_url();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_mpesa_till_callback', array($this, 'handle_callback'));
    }

    /**
     * Get (or generate and persist) the secret token used to authenticate
     * incoming M-Pesa callbacks.
     *
     * @return string
     */
    private function get_callback_secret() {
        $secret = $this->get_option('callback_secret');

        if (empty($secret)) {
            $secret = wp_generate_password(32, false, false);
            $this->update_option('callback_secret', $secret);
        }

        return $secret;
    }

    /**
     * Build the callback URL Safaricom should POST to, including the
     * secret token as a query arg.
     *
     * @return string
     */
    private function build_callback_url() {
        return add_query_arg('key', $this->callback_secret, home_url('/wc-api/wc_mpesa_till_callback/'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'marupurupu-checkout-for-mpesa'),
                'type' => 'checkbox',
                'label' => __('Enable M-Pesa Till Payment', 'marupurupu-checkout-for-mpesa'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Payment method title that customers see during checkout.', 'marupurupu-checkout-for-mpesa'),
                'default' => __('M-Pesa', 'marupurupu-checkout-for-mpesa'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'marupurupu-checkout-for-mpesa'),
                'type' => 'textarea',
                'description' => __('Payment method description that customers see during checkout.', 'marupurupu-checkout-for-mpesa'),
                'default' => __('Pay securely using M-Pesa.', 'marupurupu-checkout-for-mpesa'),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test Mode', 'marupurupu-checkout-for-mpesa'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'marupurupu-checkout-for-mpesa'),
                'default' => 'yes',
                'description' => __('Use sandbox API credentials for testing.', 'marupurupu-checkout-for-mpesa'),
            ),
            'shortcode' => array(
                'title' => __('Business Shortcode', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Your M-Pesa Business Shortcode (used for authentication). For sandbox, use 174379.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'till_number' => array(
                'title' => __('Till Number', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Your M-Pesa Till Number (Store Number). This can be the same as Shortcode for Till accounts.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'consumer_key' => array(
                'title' => __('Consumer Key (Live)', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Your M-Pesa API Consumer Key for production.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'consumer_secret' => array(
                'title' => __('Consumer Secret (Live)', 'marupurupu-checkout-for-mpesa'),
                'type' => 'password',
                'description' => __('Your M-Pesa API Consumer Secret for production.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_consumer_key' => array(
                'title' => __('Consumer Key (Test)', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Your M-Pesa API Consumer Key for sandbox.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_consumer_secret' => array(
                'title' => __('Consumer Secret (Test)', 'marupurupu-checkout-for-mpesa'),
                'type' => 'password',
                'description' => __('Your M-Pesa API Consumer Secret for sandbox.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'passkey' => array(
                'title' => __('Passkey', 'marupurupu-checkout-for-mpesa'),
                'type' => 'password',
                'description' => __('Your M-Pesa API Passkey.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'desc_tip' => true,
            ),
            'callback_url' => array(
                'title' => __('Callback URL', 'marupurupu-checkout-for-mpesa'),
                'type' => 'text',
                'description' => __('Use this exact URL for the M-Pesa callback. It includes a secret token unique to this site; requests without a matching token are rejected. Do not edit or truncate it.', 'marupurupu-checkout-for-mpesa'),
                'default' => '',
                'custom_attributes' => array('readonly' => 'readonly'),
            ),
            'telemetry_enabled' => array(
                'title' => __('Anonymous Usage Data', 'marupurupu-checkout-for-mpesa'),
                'type' => 'checkbox',
                'label' => __('Help improve this plugin by sharing anonymous usage data', 'marupurupu-checkout-for-mpesa'),
                'description' => __('We collect anonymous usage statistics to improve the plugin (WordPress/WooCommerce/PHP versions, transaction counts and amount aggregates, error rates, feature usage). No phone numbers, order details, customer names, or M-Pesa credentials are ever included. Opt-in, off by default. See this plugin\'s Privacy Policy section (readme.txt) for the full field-by-field disclosure.', 'marupurupu-checkout-for-mpesa'),
                'default' => 'no',
            ),
        );
    }

    /**
     * Payment fields on checkout page
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
        ?>
        <fieldset id="wc-<?php echo esc_attr($this->id); ?>-form" class="wc-payment-form">
            <p class="form-row form-row-wide">
                <label for="mpesa_phone_number">
                    <?php esc_html_e('M-Pesa Phone Number', 'marupurupu-checkout-for-mpesa'); ?> <span class="required">*</span>
                </label>
                <input id="mpesa_phone_number" name="mpesa_phone_number" type="tel"
                       placeholder="254XXXXXXXXX"
                       pattern="254[0-9]{9}"
                       maxlength="12"
                       required />
                <small><?php esc_html_e('Enter phone number in format: 254XXXXXXXXX', 'marupurupu-checkout-for-mpesa'); ?></small>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Whether $phone is a valid M-Pesa number in the format Daraja expects:
     * country code 254 followed by nine digits, no spaces, no leading "+" or 0.
     *
     * @param mixed $phone
     * @return bool
     */
    public static function is_valid_phone_number($phone) {
        // \z (not $): a plain $ also matches just before a trailing newline.
        return is_string($phone) && 1 === preg_match('/^254[0-9]{9}\z/', $phone);
    }

    /**
     * Validate payment fields
     */
    public function validate_fields() {
        if (empty($_POST['mpesa_phone_number'])) {
            wc_add_notice(__('M-Pesa phone number is required.', 'marupurupu-checkout-for-mpesa'), 'error');
            return false;
        }

        $phone = sanitize_text_field(wp_unslash($_POST['mpesa_phone_number']));

        if (!self::is_valid_phone_number($phone)) {
            wc_add_notice(__('Please enter a valid M-Pesa phone number (format: 254XXXXXXXXX).', 'marupurupu-checkout-for-mpesa'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        // validate_fields() (called by WooCommerce's checkout flow before
        // this method) already guarantees this field is present and
        // non-empty -- isset() here is a defensive no-op for that
        // guarantee, not a new validation path.
        $phone = isset($_POST['mpesa_phone_number']) ? sanitize_text_field(wp_unslash($_POST['mpesa_phone_number'])) : '';

        // Validate again here, as defence in depth: whether WooCommerce's block
        // checkout (Store API) runs validate_fields() has varied between
        // WooCommerce versions, and a bad number must never reach Safaricom.
        if (!self::is_valid_phone_number($phone)) {
            wc_add_notice(__('Please enter a valid M-Pesa phone number (format: 254XXXXXXXXX).', 'marupurupu-checkout-for-mpesa'), 'error');

            // 'failure' is the value WooCommerce (classic and Blocks) recognises;
            // Blocks ignores anything else and shows a generic error instead of
            // the notice added above.
            return array(
                'result' => 'failure',
                'redirect' => ''
            );
        }

        // Initialize M-Pesa API
        $marupurupu_api = new Marupurupu_API(
            $this->consumer_key,
            $this->consumer_secret,
            $this->shortcode,
            $this->till_number,
            $this->passkey,
            $this->testmode
        );

        // Initiate STK Push
        $response = $marupurupu_api->stk_push(
            $phone,
            $order->get_total(),
            $order_id,
            $this->callback_url
        );

        if ($response && isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            // Save transaction data
            $this->save_transaction($order_id, $phone, $order->get_total(), $response);

            // Mark order as on-hold (waiting for payment confirmation)
            $order->update_status('on-hold', __('M-Pesa STK Push sent. Awaiting payment confirmation.', 'marupurupu-checkout-for-mpesa'));

            // Add order note
            $order->add_order_note(sprintf(
                /* translators: 1: the M-Pesa merchant request ID, 2: the M-Pesa checkout request ID */
                __('M-Pesa STK Push initiated. MerchantRequestID: %1$s, CheckoutRequestID: %2$s. Customer should enter M-Pesa PIN on their phone.', 'marupurupu-checkout-for-mpesa'),
                $response['MerchantRequestID'],
                $response['CheckoutRequestID']
            ));

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Empty cart
            WC()->cart->empty_cart();

            // Return success and redirect to thank you page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } else {
            // Extract error message from response
            $error_message = __('Unable to initiate M-Pesa payment.', 'marupurupu-checkout-for-mpesa');

            if (isset($response['errorMessage'])) {
                $error_message = $response['errorMessage'];
            } elseif (isset($response['ResponseDescription'])) {
                $error_message = $response['ResponseDescription'];
            } elseif (isset($response['CustomerMessage'])) {
                $error_message = $response['CustomerMessage'];
            } elseif (isset($response['errorCode'])) {
                /* translators: %s: the M-Pesa API error code */
                $error_message = sprintf(__('M-Pesa Error: %s', 'marupurupu-checkout-for-mpesa'), $response['errorCode']);
            }

            // Log full response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('M-Pesa STK Push Error: ' . print_r($response, true));
            }

            wc_add_notice($error_message, 'error');

            /* translators: %s: the payment failure reason */
            $order->add_order_note(sprintf(__('M-Pesa payment failed: %s', 'marupurupu-checkout-for-mpesa'), $error_message));

            return array(
                'result' => 'failure',
                'redirect' => ''
            );
        }
    }

    /**
     * Save transaction to database
     */
    private function save_transaction($order_id, $phone, $amount, $response) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'marupurupu_transactions';

        $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'merchant_request_id' => isset($response['MerchantRequestID']) ? $response['MerchantRequestID'] : '',
                'checkout_request_id' => isset($response['CheckoutRequestID']) ? $response['CheckoutRequestID'] : '',
                'phone_number' => $phone,
                'amount' => $amount,
                'status' => 'pending',
                'request_data' => wp_json_encode($response),
            ),
            array('%d', '%s', '%s', '%s', '%f', '%s', '%s')
        );
    }

    /**
     * Get decrypted option value
     *
     * @param string $key Option key
     * @param mixed $empty_value Default value if empty
     * @return string Decrypted value
     */
    private function get_decrypted_option($key, $empty_value = null) {
        $value = $this->get_option($key, $empty_value);

        if (empty($value)) {
            return $value;
        }

        // Decrypt using encryption class
        return Marupurupu_Encryption::decrypt($value);
    }

    /**
     * Process admin options with encryption.
     *
     * Since generate_text_html()/generate_password_html() never redisplay
     * the decrypted secret (see those methods), a blank submission for one
     * of these fields means "the admin didn't touch it," not "clear it."
     * WC_Settings_API::process_admin_options() saves every declared field
     * unconditionally, reading straight from $_POST -- a field key missing
     * from $_POST resolves to null, and validate_text_field()/
     * validate_password_field() both turn null into '', which would wipe
     * the stored credential on every settings save that didn't also retype
     * it. To prevent that, a blank submission re-injects the existing
     * (still-encrypted) stored value into $_POST before handing off to the
     * parent save, so it's written back unchanged instead of cleared.
     */
    public function process_admin_options() {
        $post_data = $this->get_post_data();

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $field_key = $this->get_field_key($field);
            $submitted = isset($post_data[$field_key]) ? trim(stripslashes((string) $post_data[$field_key])) : '';

            if ($submitted === '') {
                // Preserve whatever's already stored (still in its
                // encrypted form -- passes through the parent save as-is).
                $existing = $this->get_option($field, '');
                if ($existing !== '') {
                    $_POST[$field_key] = $existing;
                } else {
                    unset($_POST[$field_key]);
                }
                continue;
            }

            // A resubmitted already-encrypted value (shouldn't normally
            // happen now that forms never redisplay secrets, but harmless
            // to guard against) is left as-is rather than double-encrypted.
            if (Marupurupu_Encryption::is_encrypted($submitted)) {
                continue;
            }

            $encrypted_value = Marupurupu_Encryption::encrypt($submitted);

            if ($encrypted_value === '') {
                // Encryption failed (e.g. OpenSSL unavailable). Do NOT save
                // an empty string in place of what was just typed -- that
                // would silently discard it with no indication anything
                // went wrong. Keep the previous value and surface a real
                // error instead.
                $this->add_error(sprintf(
                    /* translators: %s: settings field name */
                    __('Could not encrypt the %s field -- the new value was not saved. Check that the OpenSSL PHP extension is enabled on this server.', 'marupurupu-checkout-for-mpesa'),
                    $field
                ));
                $existing = $this->get_option($field, '');
                if ($existing !== '') {
                    $_POST[$field_key] = $existing;
                } else {
                    unset($_POST[$field_key]);
                }
                continue;
            }

            $_POST[$field_key] = $encrypted_value;

            // A credential was just freshly encrypted and saved -- if this
            // site had its credentials cleared by the 2026-08-25 encryption
            // upgrade, that's now resolved; stop showing the notice about it.
            delete_option('marupurupu_credentials_cleared_notice');
        }

        return parent::process_admin_options();
    }

    /**
     * Build the description shown under a credential field, given whether
     * a value is already saved for it. Shared by generate_text_html() and
     * generate_password_html() so the messaging is identical either way.
     *
     * @param string $original_description The field's own configured description (if any).
     * @param bool   $has_saved_value       Whether a value is currently stored.
     * @return string
     */
    private function credential_field_description($original_description, $has_saved_value) {
        $note = $has_saved_value
            ? __('A value is saved (masked above). Click "Change" to replace it.', 'marupurupu-checkout-for-mpesa')
            : __('No value currently saved.', 'marupurupu-checkout-for-mpesa');

        return trim($original_description . ' ' . $note);
    }

    /**
     * Build a masked display string for an already-saved credential --
     * fixed-width bullets plus the last 4 real characters, the same
     * disclosure level as a masked card number ("•••• •••• •••• 4242") or a
     * GitHub token prefix. This exists because a plain "•••••••• (saved)"
     * placeholder -- which vanishes the instant the field is focused, and
     * isn't the field's actual value at all -- was read by a real merchant
     * as "there's nothing here," prompting them to keep re-typing
     * credentials on every save. Showing an actual (if partial) fingerprint
     * of the real stored value lets them visually confirm something
     * specific is saved, at a disclosure level (4 of 32-64 characters)
     * that's far too little to reconstruct the secret.
     *
     * @param string $decrypted Decrypted credential. Only ever used here to
     *                          slice off a masked substring -- never placed
     *                          in the page as-is.
     * @return string
     */
    private function mask_credential_for_display($decrypted) {
        if (strlen($decrypted) <= 4) {
            // Too short to reveal any of it without giving away the whole
            // value -- fall back to a generic, fixed-length mask.
            return str_repeat('•', 8);
        }

        return str_repeat('•', 8) . substr($decrypted, -4);
    }

    /**
     * Render the settings-table row for an already-saved encrypted
     * credential: a masked, read-only display of the real value plus a
     * "Change" button that swaps in an empty, real input for fresh entry.
     * Shared by generate_text_html() and generate_password_html() so a
     * text-type field (consumer_key) and a password-type field
     * (consumer_secret) behave identically.
     *
     * The real `name="{$field_key}"` input starts empty and hidden --
     * untouched, it submits '', which process_admin_options() already
     * treats as "preserve existing" (see that method's doc comment). Only
     * clicking "Change" (which clears and shows it) lets it submit a real
     * new value. This means an admin who saves the page without clicking
     * "Change" can never accidentally overwrite the real secret with the
     * masked display text -- the masked text isn't inside the submitted
     * field at all.
     *
     * Echoes directly, escaping each dynamic value where it's printed --
     * the control contains an <input> and a <button>, which wp_kses_post()
     * would strip, so it can't be built as a string and filtered afterwards.
     * The "Change" click is handled by assets/js/mpesa-admin-credentials.js
     * (enqueued here, once per page, in the footer).
     *
     * @param string $field_key
     * @param array  $data       Field data (already wp_parse_args'd by the caller).
     * @param string $decrypted  Decrypted credential, used only to compute the mask.
     * @return void Prints the masked/editable control (not the whole <tr>).
     */
    private function render_masked_credential_control($field_key, $data, $decrypted) {
        $masked = $this->mask_credential_for_display($decrypted);

        wp_enqueue_script('marupurupu-admin-credentials', MARUPURUPU_PLUGIN_URL . 'assets/js/mpesa-admin-credentials.js', array(), MARUPURUPU_VERSION, true);
        ?>
        <span id="<?php echo esc_attr($field_key); ?>_masked" style="font-family: monospace; letter-spacing: 2px; display: inline-block; padding: 0 4px;"><?php echo esc_html($masked); ?></span>
        <button type="button" id="<?php echo esc_attr($field_key); ?>_change" class="button button-small mpesa-credential-change" data-field="<?php echo esc_attr($field_key); ?>"><?php esc_html_e('Change', 'marupurupu-checkout-for-mpesa'); ?></button>
        <input class="input-text regular-input <?php echo esc_attr($data['class']); ?>" type="<?php echo esc_attr($data['type']); ?>" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="display: none; <?php echo esc_attr($data['css']); ?>" value="" placeholder="<?php echo esc_attr__('Enter a new value to replace it', 'marupurupu-checkout-for-mpesa'); ?>" <?php disabled($data['disabled'], true); ?> <?php echo wp_kses_post($this->get_custom_attribute_html($data)); ?> />
        <?php
    }

    /**
     * Generate text input HTML. Encrypted-credential fields with a saved
     * value show a masked fingerprint instead -- see
     * render_masked_credential_control() for why and how.
     *
     * @param string $key Field key
     * @param array $data Field data
     * @return string HTML
     */
    public function generate_text_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        $value = $this->get_option($key);
        $decrypted_for_mask = null;

        if (in_array($key, self::ENCRYPTED_FIELDS, true)) {
            $has_saved_value = $value !== '';
            $data['description'] = $this->credential_field_description($data['description'], $has_saved_value);

            if ($has_saved_value) {
                $decrypted_for_mask = Marupurupu_Encryption::decrypt($value);
            }

            $value = '';
        }

        // Callback URL is computed (includes the auto-generated secret
        // token), not just whatever was last saved -- always show the live value.
        if ($key === 'callback_url') {
            $value = $this->callback_url;
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo wp_kses_post($this->get_tooltip_html($data)); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php if ($decrypted_for_mask !== null): ?>
                        <?php $this->render_masked_credential_control($field_key, $data, $decrypted_for_mask); ?>
                    <?php else: ?>
                        <input class="input-text regular-input <?php echo esc_attr($data['class']); ?>" type="<?php echo esc_attr($data['type']); ?>" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($data['placeholder']); ?>" <?php disabled($data['disabled'], true); ?> <?php echo wp_kses_post($this->get_custom_attribute_html($data)); ?> />
                    <?php endif; ?>
                    <?php echo wp_kses_post($this->get_description_html($data)); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate password input HTML. Encrypted-credential fields with a
     * saved value show a masked fingerprint instead of a blank field --
     * see render_masked_credential_control() for why and how.
     *
     * @param string $key Field key
     * @param array $data Field data
     * @return string HTML
     */
    public function generate_password_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'password',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        $value = $this->get_option($key);
        $decrypted_for_mask = null;

        // A saved credential is never placed into the page as its real
        // value -- see render_masked_credential_control()'s doc comment
        // for the full reasoning (mirrors how Stripe/PayPal gateway
        // plugins handle secrets, just with a masked fingerprint instead
        // of a blank field).
        if (in_array($key, self::ENCRYPTED_FIELDS, true)) {
            $has_saved_value = $value !== '';
            $data['description'] = $this->credential_field_description($data['description'], $has_saved_value);

            if ($has_saved_value) {
                $decrypted_for_mask = Marupurupu_Encryption::decrypt($value);
            }

            $value = '';
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo wp_kses_post($this->get_tooltip_html($data)); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php if ($decrypted_for_mask !== null): ?>
                        <?php $this->render_masked_credential_control($field_key, $data, $decrypted_for_mask); ?>
                    <?php else: ?>
                        <input class="input-text regular-input <?php echo esc_attr($data['class']); ?>" type="<?php echo esc_attr($data['type']); ?>" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($data['placeholder']); ?>" <?php disabled($data['disabled'], true); ?> <?php echo wp_kses_post($this->get_custom_attribute_html($data)); ?> />
                    <?php endif; ?>
                    <?php echo wp_kses_post($this->get_description_html($data)); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle callback from M-Pesa
     */
    public function handle_callback() {
        $callback_handler = new Marupurupu_Callback($this->callback_secret);
        $callback_handler->process_callback();
    }
}
