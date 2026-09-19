/**
 * M-Pesa Payment Method for WooCommerce Blocks
 *
 * Registers M-Pesa as a payment method for block-based checkout
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement, useState, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;

// Get M-Pesa settings from server
const settings = getSetting('mpesa_till_data', {});
const defaultLabel = __('M-Pesa', 'marupurupu-checkout-for-mpesa');
const label = decodeEntities(settings.title) || defaultLabel;

/**
 * Content component for M-Pesa payment method
 * Renders description + phone number input and registers onPaymentSetup handler
 */
const Content = (props) => {
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup } = eventRegistration;
    const [phone, setPhone] = useState('');

    useEffect(() => {
        const unsubscribe = onPaymentSetup(() => {
            if (!phone) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Please enter your M-Pesa phone number.', 'marupurupu-checkout-for-mpesa'),
                };
            }
            if (!/^254[0-9]{9}$/.test(phone)) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Please enter a valid M-Pesa phone number in format: 254XXXXXXXXX', 'marupurupu-checkout-for-mpesa'),
                };
            }
            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        mpesa_phone_number: phone,
                    },
                },
            };
        });
        return () => {
            unsubscribe();
        };
    }, [onPaymentSetup, emitResponse.responseTypes, phone]);

    return createElement(
        'div',
        { className: 'wc-block-mpesa-till-content' },
        settings.description
            ? createElement('p', { className: 'wc-block-mpesa-till-description' }, decodeEntities(settings.description))
            : null,
        createElement(
            'p',
            { className: 'form-row form-row-wide', style: { margin: '12px 0 0' } },
            createElement(
                'label',
                { htmlFor: 'mpesa-phone-number' },
                __('M-Pesa Phone Number', 'marupurupu-checkout-for-mpesa'),
                createElement('span', { className: 'required', 'aria-hidden': 'true' }, '\u00a0*')
            ),
            createElement('input', {
                id: 'mpesa-phone-number',
                name: 'mpesa_phone_number',
                type: 'tel',
                value: phone,
                placeholder: '254XXXXXXXXX',
                maxLength: 12,
                className: 'wc-block-components-text-input',
                style: {
                    width: '100%',
                    padding: '8px',
                    marginTop: '4px',
                    fontSize: '14px',
                    border: '1px solid #ccc',
                    borderRadius: '4px',
                    display: 'block',
                },
                onChange: (e) => setPhone(e.target.value),
            }),
            createElement(
                'small',
                { style: { color: '#666', marginTop: '4px', display: 'block' } },
                __('Enter phone number in format: 254XXXXXXXXX', 'marupurupu-checkout-for-mpesa')
            )
        )
    );
};

/**
 * Edit component shown in the block editor preview (no live event handlers)
 */
const Edit = () => {
    return createElement(
        'div',
        { className: 'wc-block-mpesa-till-content' },
        settings.description
            ? createElement('p', { className: 'wc-block-mpesa-till-description' }, decodeEntities(settings.description))
            : null,
        createElement(
            'p',
            { className: 'form-row form-row-wide', style: { margin: '12px 0 0' } },
            createElement(
                'label',
                { htmlFor: 'mpesa-phone-number-edit' },
                __('M-Pesa Phone Number', 'marupurupu-checkout-for-mpesa'),
                createElement('span', { className: 'required', 'aria-hidden': 'true' }, '\u00a0*')
            ),
            createElement('input', {
                id: 'mpesa-phone-number-edit',
                type: 'tel',
                placeholder: '254XXXXXXXXX',
                disabled: true,
                className: 'wc-block-components-text-input',
                style: {
                    width: '100%',
                    padding: '8px',
                    marginTop: '4px',
                    fontSize: '14px',
                    border: '1px solid #ccc',
                    borderRadius: '4px',
                    display: 'block',
                },
            }),
            createElement(
                'small',
                { style: { color: '#666', marginTop: '4px', display: 'block' } },
                __('Enter phone number in format: 254XXXXXXXXX', 'marupurupu-checkout-for-mpesa')
            )
        )
    );
};

/**
 * Label component for M-Pesa payment method
 */
const Label = (props) => {
    const { PaymentMethodLabel } = props.components;

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
    edit: createElement(Edit, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
});
