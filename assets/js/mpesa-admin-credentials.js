/**
 * Settings page: "Change" button on a saved (masked) credential field.
 *
 * Swaps the read-only masked display for an empty, real input. Enqueued by
 * WC_Mpesa_Till_Gateway::render_masked_credential_control().
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.mpesa-credential-change') : null;
        if (!button) {
            return;
        }

        var fieldId = button.getAttribute('data-field');
        var masked = document.getElementById(fieldId + '_masked');
        var input = document.getElementById(fieldId);

        if (masked) { masked.style.display = 'none'; }
        button.style.display = 'none';
        if (input) {
            input.style.display = '';
            input.value = '';
            input.focus();
        }
    });
})();
