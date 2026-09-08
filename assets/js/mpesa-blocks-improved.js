/**
 * M-Pesa Payment Method for WooCommerce Blocks
 *
 * Registers M-Pesa as a payment method for block-based checkout
 *
 * @package WC_Mpesa_Till
 * @version 1.1.0
 */

(function() {
    'use strict';

    // Check if required dependencies are available
    if (typeof window.wc === 'undefined' ||
        typeof window.wc.wcBlocksRegistry === 'undefined' ||
        typeof window.wc.wcSettings === 'undefined') {
        console.error('M-Pesa Blocks: Required WooCommerce Blocks dependencies not loaded');
        return;
    }

    if (typeof window.wp === 'undefined' ||
        typeof window.wp.element === 'undefined' ||
        typeof window.wp.htmlEntities === 'undefined') {
        console.error('M-Pesa Blocks: Required WordPress dependencies not loaded');
        return;
    }

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;

    // Get M-Pesa settings from server
    const settings = getSetting('mpesa_till_data', {});

    // Fallback values
    const defaultLabel = __('M-Pesa', 'mpesa-gateway-for-woocommerce');
    const label = settings.title ? decodeEntities(settings.title) : defaultLabel;
    const description = settings.description ? decodeEntities(settings.description) : '';

    console.log('M-Pesa Blocks: Initializing payment method', {
        name: 'mpesa_till',
        label: label,
        hasDescription: !!description,
        hasIcon: !!settings.icon,
        settings: settings
    });

    /**
     * Content component for M-Pesa payment method
     * Displays the payment method description and instructions
     */
    const Content = (props) => {
        return createElement(
            'div',
            {
                className: 'wc-block-mpesa-till-content',
                style: { marginTop: '10px' }
            },
            description || __('Pay securely using M-Pesa mobile money.', 'mpesa-gateway-for-woocommerce')
        );
    };

    /**
     * Label component for M-Pesa payment method
     * Displays the payment method name/title with optional icon
     */
    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;

        // Add icon if available
        if (settings.icon) {
            return createElement(
                'span',
                { className: 'wc-block-mpesa-till-label' },
                createElement('img', {
                    src: settings.icon,
                    alt: label,
                    style: {
                        height: '24px',
                        width: 'auto',
                        marginRight: '8px',
                        verticalAlign: 'middle'
                    }
                }),
                createElement(PaymentMethodLabel, { text: label })
            );
        }

        return createElement(PaymentMethodLabel, { text: label });
    };

    /**
     * Register M-Pesa payment method with WooCommerce Blocks
     */
    try {
        registerPaymentMethod({
            name: 'mpesa_till',
            label: createElement(Label, null),
            content: createElement(Content, null),
            edit: createElement(Content, null),
            canMakePayment: () => {
                // Payment method is always available if it's enabled
                console.log('M-Pesa Blocks: canMakePayment check - returning true');
                return true;
            },
            ariaLabel: label,
            supports: {
                features: settings.supports || ['products'],
            },
        });

        console.log('M-Pesa Blocks: Payment method registered successfully');
    } catch (error) {
        console.error('M-Pesa Blocks: Failed to register payment method', error);
    }
})();
