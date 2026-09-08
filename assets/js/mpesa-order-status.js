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
            if (typeof mpesa_order_params === 'undefined') {
                return;
            }

            // Clear reload flags if we've successfully reloaded and showing success
            if (sessionStorage && $('#mpesa-status-success').is(':visible')) {
                sessionStorage.removeItem('mpesa_payment_confirmed');
                sessionStorage.removeItem('mpesa_reload_done');
                console.log('M-Pesa: Cleared reload flags after successful reload');
            }

            this.bindEvents();

            // Don't start checking if payment is already successful
            if ($('#mpesa-status-success').is(':visible')) {
                console.log('M-Pesa: Payment already confirmed, skipping status checks');
                return;
            }

            // Don't start checking if already failed and showing retry options
            if ($('#mpesa-status-failed').is(':visible')) {
                console.log('M-Pesa: Payment already failed, status checks not needed');
                return;
            }

            // Only start checking if we're in pending state
            if ($('#mpesa-status-pending').length > 0) {
                this.startStatusCheck();
            }
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

            console.log('M-Pesa: Starting payment status checks (max ' + this.maxChecks + ' checks)');

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

                console.log('M-Pesa: Status check #' + self.checkCount + '/' + self.maxChecks);
                self.checkStatus();
            }, 5000);
        },

        stopStatusCheck: function() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
                console.log('M-Pesa: Status checking stopped');
            }
        },

        checkStatus: function() {
            var self = this;

            // Don't check if already stopped
            if (!this.checkInterval) {
                return;
            }

            $.ajax({
                url: mpesa_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mpesa_check_status',
                    nonce: mpesa_order_params.check_status_nonce,
                    order_id: mpesa_order_params.order_id,
                    order_key: mpesa_order_params.order_key
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
                },
                error: function() {
                    // Silent fail - will retry on next interval
                    console.log('Status check failed - will retry');
                }
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
            // Stop all checking immediately
            this.stopStatusCheck();

            // Check if we're already showing success (to prevent reload loop)
            if ($('#mpesa-status-success').is(':visible')) {
                console.log('M-Pesa: Already showing success, skipping reload');
                return;
            }

            // Hide pending/retry sections
            $('#mpesa-status-pending').hide();
            $('#mpesa-retry-section').hide();
            $('#mpesa-verify-section').hide();

            // Show success section
            $('#mpesa-status-success').fadeIn();

            // Show success message with next steps
            var successMsg = data.message + ' ' +
                'Your order is being processed. You will receive a confirmation email shortly.';
            this.showMessage(successMsg, 'success');

            // Log to console for debugging
            console.log('Payment confirmed successfully:', data);

            // Set a flag to prevent multiple reloads
            if (sessionStorage) {
                sessionStorage.setItem('mpesa_payment_confirmed', 'true');
            }

            // Reload page ONCE after 3 seconds to show updated order details
            var countdown = 3;
            var $reloadMsg = $('<p style="text-align: center; margin-top: 15px; color: #666;"></p>');
            $reloadMsg.text('Refreshing page in ' + countdown + ' seconds...');
            $('.mpesa-order-status').append($reloadMsg);

            var countdownInterval = setInterval(function() {
                countdown--;
                if (countdown > 0) {
                    $reloadMsg.text('Refreshing page in ' + countdown + ' seconds...');
                } else {
                    clearInterval(countdownInterval);

                    // Only reload if we haven't already
                    if (!sessionStorage || sessionStorage.getItem('mpesa_reload_done') !== 'true') {
                        if (sessionStorage) {
                            sessionStorage.setItem('mpesa_reload_done', 'true');
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
            var failureMsg = data.message + '<br><br>' +
                '<strong>What to do next:</strong><br>' +
                '1. If you didn\'t complete the payment, click "Send Payment Request" below to retry<br>' +
                '2. If you already paid, enter your M-Pesa transaction code to verify<br>' +
                '3. Contact support if you need assistance';
            this.showMessage(failureMsg, 'error');

            // Log to console for debugging
            console.log('Payment failed:', data);

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
            var timeoutMsg = '<strong>Payment confirmation timeout</strong><br><br>' +
                'We haven\'t received confirmation yet. This could mean:<br>' +
                '• The payment is still processing (please wait a few more minutes)<br>' +
                '• You didn\'t complete the payment on your phone<br>' +
                '• There was a network delay<br><br>' +
                '<strong>What to do next:</strong><br>' +
                '1. Check your phone for M-Pesa confirmation SMS<br>' +
                '2. If you received an SMS, enter the transaction code below to verify<br>' +
                '3. If you didn\'t pay yet, click "Send Payment Request" to retry<br>' +
                '4. Refresh this page in a few minutes to check status';

            this.showMessage(timeoutMsg, 'warning');

            // Log timeout
            console.log('Status check timeout after ' + this.checkCount + ' attempts');

            // Scroll to action section
            $('html, body').animate({
                scrollTop: $('#mpesa-retry-section').offset().top - 100
            }, 500);
        },

        handleRetryPayment: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var phone = $('#mpesa-retry-phone').val();

            if (!phone || !phone.match(/^254[0-9]{9}$/)) {
                this.showMessage('Please enter a valid phone number (format: 254XXXXXXXXX)', 'error');
                return;
            }

            $button.prop('disabled', true).text('Sending...');

            $.ajax({
                url: mpesa_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mpesa_retry_payment',
                    nonce: mpesa_order_params.retry_payment_nonce,
                    order_id: mpesa_order_params.order_id,
                    order_key: mpesa_order_params.order_key,
                    phone: phone
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        this.showMessage(response.data.message + ' Check your phone now.', 'success');

                        // Hide retry/verify sections
                        $('#mpesa-retry-section').slideUp();
                        $('#mpesa-verify-section').slideUp();
                        $('#mpesa-status-failed').hide();

                        // Show pending section
                        $('#mpesa-status-pending').fadeIn();

                        // Reset and restart status checking
                        this.stopStatusCheck(); // Stop any existing checks first
                        this.checkCount = 0;
                        console.log('M-Pesa: Payment retry initiated, restarting status checks');
                        this.startStatusCheck();
                    } else {
                        this.showMessage(response.data.message, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showMessage('Connection error. Please try again.', 'error');
                }.bind(this),
                complete: function() {
                    $button.prop('disabled', false).text('Send Payment Request');
                }
            });
        },

        handleVerifyCode: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var code = $('#mpesa-transaction-code').val().trim().toUpperCase();

            if (!code) {
                this.showMessage('Please enter the M-Pesa transaction code', 'error');
                return;
            }

            $button.prop('disabled', true).text('Verifying...');

            $.ajax({
                url: mpesa_order_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mpesa_verify_code',
                    nonce: mpesa_order_params.verify_code_nonce,
                    order_id: mpesa_order_params.order_id,
                    order_key: mpesa_order_params.order_key,
                    transaction_code: code
                },
                success: function(response) {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        $('#mpesa-verify-section').slideUp();

                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        this.showMessage(response.data.message, 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showMessage('Connection error. Please try again.', 'error');
                }.bind(this),
                complete: function() {
                    $button.prop('disabled', false).text('Verify Payment');
                }
            });
        },

        showMessage: function(message, type) {
            var $container = $('#mpesa-messages');

            var alertClass = 'woocommerce-info';
            if (type === 'error') {
                alertClass = 'woocommerce-error';
            } else if (type === 'success') {
                alertClass = 'woocommerce-message';
            }

            var $message = $('<div class="' + alertClass + '">' + message + '</div>');

            $container.html($message);

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
