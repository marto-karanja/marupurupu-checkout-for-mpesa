<?php
/**
 * M-Pesa Blocks Support
 *
 * Adds compatibility with WooCommerce block-based checkout
 * Implements the AbstractPaymentMethodType interface for blocks integration
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * M-Pesa Till Payment Blocks Integration
 */
final class WC_Mpesa_Till_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name/id
     *
     * @var string
     */
    protected $name = 'mpesa_till';

    /**
     * Gateway instance
     *
     * @var WC_Mpesa_Till_Gateway
     */
    private $gateway;

    /**
     * Initialize the payment method
     */
    public function initialize() {
        // Get gateway settings
        $this->settings = get_option('woocommerce_mpesa_till_settings', []);

        // Get gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways['mpesa_till']) ? $gateways['mpesa_till'] : null;

        if (defined('WP_DEBUG') && WP_DEBUG && !$this->gateway) {
            error_log('M-Pesa Blocks: gateway instance not found in payment_gateways() during blocks registration.');
        }
    }

    /**
     * Check if the payment method is active
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    /**
     * Register scripts for the payment method
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path = '/assets/js/mpesa-blocks.js';
        $script_asset_path = WC_MPESA_TILL_PLUGIN_DIR . 'assets/js/mpesa-blocks.asset.php';

        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : [
                'dependencies' => [
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ],
                'version' => WC_MPESA_TILL_VERSION,
            ];

        wp_register_script(
            'wc-mpesa-till-blocks',
            WC_MPESA_TILL_PLUGIN_URL . 'assets/js/mpesa-blocks.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-mpesa-till-blocks',
                'marupurupu-checkout-for-mpesa',
                WC_MPESA_TILL_PLUGIN_DIR . 'languages'
            );
        }

        // Localize script with dynamic data
        wp_localize_script(
            'wc-mpesa-till-blocks',
            'wcMpesaTillData',
            [
                'title' => $this->gateway ? $this->gateway->title : __('M-Pesa', 'marupurupu-checkout-for-mpesa'),
                'description' => $this->gateway ? $this->gateway->description : '',
            ]
        );

        return ['wc-mpesa-till-blocks'];
    }

    /**
     * Get payment method data for use in JavaScript
     *
     * @return array
     */
    public function get_payment_method_data() {
        if (!$this->gateway) {
            // Gateway instance wasn't found (e.g. WooCommerce not fully
            // initialized yet) -- return sensible defaults rather than an
            // empty array, so the blocks checkout doesn't render a blank
            // payment method.
            return [
                'title' => __('M-Pesa', 'marupurupu-checkout-for-mpesa'),
                'description' => __('Pay securely using M-Pesa mobile money.', 'marupurupu-checkout-for-mpesa'),
                'supports' => ['products'],
                'icon' => '',
            ];
        }

        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports']),
            'icon' => $this->gateway->icon,
        ];
    }
}
