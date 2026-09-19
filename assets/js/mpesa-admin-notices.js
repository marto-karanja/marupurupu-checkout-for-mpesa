/**
 * Admin notices: persist a "Dismiss" click.
 *
 * Enqueued by Mpesa_Encryption_Admin when one of its dismissible notices is
 * shown. The nonce is passed in via wp_localize_script() as
 * `mpesaAdminNotices`.
 */
jQuery(function ($) {
    'use strict';

    $(document).on('click', '.mpesa-dismiss-notice', function () {
        var $button = $(this);

        $.post(ajaxurl, {
            action: 'mpesa_dismiss_notice',
            notice: $button.data('notice'),
            nonce: mpesaAdminNotices.nonce
        });

        $('#' + $button.data('target')).fadeOut();
    });
});
