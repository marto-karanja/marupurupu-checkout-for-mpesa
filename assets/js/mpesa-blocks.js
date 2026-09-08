/**
 * M-Pesa Payment Method for WooCommerce Blocks
 *
 * Registers M-Pesa as a payment method for block-based checkout
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;

// Get M-Pesa settings from server
const settings = getSetting('mpesa_till_data', {});
const defaultLabel = __('M-Pesa', 'mpesa-till-gateway');
const label = decodeEntities(settings.title) || defaultLabel;

/**
 * Content component for M-Pesa payment method
 * Displays the payment method description and instructions
 */
const Content = () => {
    return createElement(
        'div',
        { className: 'wc-block-mpesa-till-content' },
        decodeEntities(settings.description || '')
    );
};

/**
 * Label component for M-Pesa payment method
 * Displays the payment method name/title
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
registerPaymentMethod({
    name: 'mpesa_till',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
});
