/**
 * M-Pesa Order Status Checker
 */
(function($) {
    'use strict';

    var MpesaOrderStatus = {
        checkInterval: null,
        checkCount: 0,
        maxChecks: 60, // Check for 5 minutes (60 * 5 seconds)

        init: function() {
            if (typeof marupurupu_order_params === 'undefined') {
                return;
            }

            // Clear reload flags if we've successfully reloaded and showing success
            if (sessionStorage && $('#mpesa-status-success').is(':visible')) {
                sessionStorage.removeItem('marupurupu_payment_confirmed');
                sessionStorage.removeItem('marupurupu_reload_done');
            }

            this.bindEvents();
            this.scheduleRetrySection();

            // Don't start checking if payment is already successful
            if ($('#mpesa-status-success').is(':visible')) {
                return;
            }

            // Don't start checking if already failed and showing retry options
            if ($('#mpesa-status-failed').is(':visible')) {
                return;
            }

            // Only start checking if we're in pending state
            if ($('#mpesa-status-pending').length > 0) {
                this.startStatusCheck();
            }
        },

        /**
         * Translated string passed from PHP (marupurupu_order_params.i18n).
         */
        t: function(key) {
            var strings = marupurupu_order_params.i18n || {};
            return strings[key] || '';
        },

        /**
         * Reveal the retry options after 2 minutes, if the payment is still
         * pending by then (the section only exists in the pending state).
         * Checking visibility first means a payment that succeeds before the
         * 2 minutes are up doesn't get its retry form un-hidden afterwards.
         */
        scheduleRetrySection: function() {
            var $retrySection = $('#mpesa-retry-section');

            if (!$retrySection.length) {
                return;
            }

            setTimeout(function() {
                if ($('#mpesa-status-pending').is(':visible')) {
                    $retrySection.show();
                }
            }, 120000);
        },

        bindEvents: function() {
            // Retry payment button
            $('#mpesa-retry-payment').on('click', this.handleRetryPayment.bind(this));

            // Verify transaction code button
            $('#mpesa-verify-code-btn').on('click', this.handleVerifyCode.bind(this));

            // Phone number formatting
            $('#mpesa-retry-phone').on('input', function() {
                var value = $(this).val().replace(/\D/g, '');
                if (value.length > 0 && value[0] !== '2') {
                    if (value[0] === '0') {
                        value = '254' + value.substring(1);
                    } else if (value[0] === '7' || value[0] === '1') {
                        value = '254' + value;
                    }
                }
                $(this).val(value.substring(0, 12));
            });
        },

        startStatusCheck: function() {
            var self = this;

            // Initial check
            this.checkStatus();

            // Check every 5 seconds
            this.checkInterval = setInterval(function() {
                self.checkCount++;

                if (self.checkCount >= self.maxChecks) {
                    self.stopStatusCheck();
                    self.handleTimeout();
                    return;
                }

                self.checkStatus();
            }, 5000);
        },

        stopStatusCheck: function() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }
        },

        checkStatus: function() {
            var self = this;

            // Don't check if already stopped
            if (!this.checkInterval) {
                return;
            }

            $.ajax({
                url: marupurupu_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'marupurupu_check_status',
                    nonce: marupurupu_order_params.check_status_nonce,
                    order_id: marupurupu_order_params.order_id,
                    order_key: marupurupu_order_params.order_key
                },
                success: function(response) {
                    if (response.success) {
                        self.updateStatusDisplay(response.data);

                        if (response.data.complete) {
                            self.stopStatusCheck();
                            self.showPaymentSuccess(response.data);
                            return; // Exit immediately
                        } else if (response.data.failed) {
                            self.stopStatusCheck();
                            self.showPaymentFailed(response.data);
                            return; // Exit immediately
                        }
                    }
                }
                // A failed poll is silent: the next interval simply tries again.
            });
        },

        updateStatusDisplay: function(data) {
            $('#mpesa-status-text').text(data.message);

            // Update status indicator
            var $indicator = $('#mpesa-status-indicator');
            $indicator.removeClass('pending completed failed')
                     .addClass(data.status);

            // Update progress text
            var timeElapsed = this.checkCount * 5;
            $('#mpesa-time-elapsed').text(this.formatTime(timeElapsed));
        },

        showPaymentSuccess: function(data) {
            var self = this;

            // Stop all checking immediately
            this.stopStatusCheck();

            // Check if we're already showing success (to prevent reload loop)
            if ($('#mpesa-status-success').is(':visible')) {
                return;
            }

            // Hide pending/retry sections
            $('#mpesa-status-pending').hide();
            $('#mpesa-retry-section').hide();
            $('#mpesa-verify-section').hide();

            // Show success section
            $('#mpesa-status-success').fadeIn();

            // Show success message with next steps
            this.showMessage(data.message + ' ' + this.t('success_next'), 'success');

            // Set a flag to prevent multiple reloads
            if (sessionStorage) {
                sessionStorage.setItem('marupurupu_payment_confirmed', 'true');
            }

            // Reload page ONCE after 3 seconds to show updated order details
            var countdown = 3;
            var $reloadMsg = $('<p style="text-align: center; margin-top: 15px; color: #666;"></p>');
            $reloadMsg.text(self.t('refreshing').replace('%d', countdown));
            $('.mpesa-order-status').append($reloadMsg);

            var countdownInterval = setInterval(function() {
                countdown--;
                if (countdown > 0) {
                    $reloadMsg.text(self.t('refreshing').replace('%d', countdown));
                } else {
                    clearInterval(countdownInterval);

                    // Only reload if we haven't already
                    if (!sessionStorage || sessionStorage.getItem('marupurupu_reload_done') !== 'true') {
                        if (sessionStorage) {
                            sessionStorage.setItem('marupurupu_reload_done', 'true');
                        }
                        location.reload();
                    }
                }
            }, 1000);
        },

        showPaymentFailed: function(data) {
            // Stop all checking immediately
            this.stopStatusCheck();

            // Hide pending section
            $('#mpesa-status-pending').hide();

            // Show failed section
            $('#mpesa-status-failed').fadeIn();

            // Show retry and verify sections
            $('#mpesa-retry-section').fadeIn();
            $('#mpesa-verify-section').fadeIn();

            // Show error message with next steps
            this.showMessage([
                data.message,
                '',
                { strong: this.t('what_next') },
                '1. ' + this.t('failed_step_1'),
                '2. ' + this.t('failed_step_2'),
                '3. ' + this.t('failed_step_3')
            ], 'error');

            // Scroll to retry section
            $('html, body').animate({
                scrollTop: $('#mpesa-retry-section').offset().top - 100
            }, 500);
        },

        handleTimeout: function() {
            // Stop checking
            this.stopStatusCheck();

            // Hide pending section
            $('#mpesa-status-pending').hide();

            // Show retry and verify sections
            $('#mpesa-retry-section').fadeIn();
            $('#mpesa-verify-section').fadeIn();

            // Show timeout message with clear next steps
            this.showMessage([
                { strong: this.t('timeout_title') },
                '',
                this.t('timeout_intro'),
                '• ' + this.t('timeout_cause_1'),
                '• ' + this.t('timeout_cause_2'),
                '• ' + this.t('timeout_cause_3'),
                '',
                { strong: this.t('what_next') },
                '1. ' + this.t('timeout_step_1'),
                '2. ' + this.t('timeout_step_2'),
                '3. ' + this.t('timeout_step_3'),
                '4. ' + this.t('timeout_step_4')
            ], 'warning');

            // Scroll to action section
            $('html, body').animate({
                scrollTop: $('#mpesa-retry-section').offset().top - 100
            }, 500);
        },

        handleRetryPayment: function(e) {
            var self = this;

            e.preventDefault();

            var $button = $(e.currentTarget);
            var phone = $('#mpesa-retry-phone').val();

            if (!phone || !phone.match(/^254[0-9]{9}$/)) {
                this.showMessage(this.t('invalid_phone'), 'error');
                return;
            }

            $button.prop('disabled', true).text(this.t('sending'));

            $.ajax({
                url: marupurupu_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'marupurupu_retry_payment',
                    nonce: marupurupu_order_params.retry_payment_nonce,
                    order_id: marupurupu_order_params.order_id,
                    order_key: marupurupu_order_params.order_key,
                    phone: phone
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        self.showMessage(response.data.message + ' ' + self.t('check_phone'), 'success');

                        // Hide retry/verify sections
                        $('#mpesa-retry-section').slideUp();
                        $('#mpesa-verify-section').slideUp();
                        $('#mpesa-status-failed').hide();

                        // Show pending section
                        $('#mpesa-status-pending').fadeIn();

                        // Reset and restart status checking
                        self.stopStatusCheck(); // Stop any existing checks first
                        self.checkCount = 0;
                        self.startStatusCheck();
                    } else {
                        self.showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showMessage(self.t('connection_error'), 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(self.t('send_request'));
                }
            });
        },

        handleVerifyCode: function(e) {
            var self = this;

            e.preventDefault();

            var $button = $(e.currentTarget);
            var code = $('#mpesa-transaction-code').val().trim().toUpperCase();

            if (!code) {
                this.showMessage(this.t('enter_code'), 'error');
                return;
            }

            $button.prop('disabled', true).text(this.t('verifying'));

            $.ajax({
                url: marupurupu_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'marupurupu_verify_code',
                    nonce: marupurupu_order_params.verify_code_nonce,
                    order_id: marupurupu_order_params.order_id,
                    order_key: marupurupu_order_params.order_key,
                    transaction_code: code
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success');
                        $('#mpesa-verify-section').slideUp();

                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        self.showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showMessage(self.t('connection_error'), 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(self.t('verify_payment'));
                }
            });
        },

        /**
         * Show a message. `parts` is a string, or an array of lines where each
         * line is a string or {strong: 'text'}. Everything is inserted as text
         * (never parsed as HTML), so server-supplied messages cannot inject markup.
         */
        showMessage: function(parts, type) {
            var $container = $('#mpesa-messages');

            var alertClass = 'woocommerce-info';
            if (type === 'error') {
                alertClass = 'woocommerce-error';
            } else if (type === 'success') {
                alertClass = 'woocommerce-message';
            }

            var $message = $('<div></div>').addClass(alertClass);
            var lines = Array.isArray(parts) ? parts : [parts];

            $.each(lines, function(index, line) {
                if (index > 0) {
                    $message.append(document.createElement('br'));
                }

                if (line && typeof line === 'object' && line.strong) {
                    $message.append($('<strong></strong>').text(line.strong));
                } else {
                    $message.append(document.createTextNode(line));
                }
            });

            $container.empty().append($message);

            $('html, body').animate({
                scrollTop: $container.offset().top - 100
            }, 500);

            if (type === 'success') {
                setTimeout(function() {
                    $message.fadeOut();
                }, 5000);
            }
        },

        formatTime: function(seconds) {
            var minutes = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return minutes + ':' + (secs < 10 ? '0' : '') + secs;
        }
    };

    $(document).ready(function() {
        MpesaOrderStatus.init();
    });

})(jQuery);
