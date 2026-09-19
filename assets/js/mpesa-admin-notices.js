/**
 * Admin notices: persist a "Dismiss" click.
 *
 * Enqueued by Marupurupu_Encryption_Admin when one of its dismissible notices is
 * shown. The nonce is passed in via wp_localize_script() as
 * `marupurupuAdminNotices`.
 */
jQuery(function ($) {
    'use strict';

    $(document).on('click', '.mpesa-dismiss-notice', function () {
        var $button = $(this);

        $.post(ajaxurl, {
            action: 'marupurupu_dismiss_notice',
            notice: $button.data('notice'),
            nonce: marupurupuAdminNotices.nonce
        });

        $('#' + $button.data('target')).fadeOut();
    });
});
