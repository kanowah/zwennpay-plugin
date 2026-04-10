/**
 * ZwennPay WooCommerce Checkout JavaScript
 * Handles payment status monitoring on the pay page
 *
 * @version 1.0.0
 */
(function($) {
    'use strict';

    // State variables
    var statusInterval = null;
    var timerInterval  = null;
    var startTime      = null;
    var isChecking     = false;
    var isPaid         = false;

    // Configuration (from wp_localize_script)
    var config = {
        timeout:  300000,  // 5 minutes in ms
        interval: 15000,   // 15 seconds in ms
    };

    /**
     * Initialize payment monitoring
     */
    function initMonitoring() {
        if (typeof zwennpayWc === 'undefined' || !zwennpayWc.reference) {
            console.warn('ZwennPay: Configuration not found');
            return;
        }

        // Update config from localized data
        config.timeout  = zwennpayWc.timeout;
        config.interval = zwennpayWc.interval;

        // Set start time
        startTime = Date.now();

        // Start intervals
        statusInterval = setInterval(checkPaymentStatus, config.interval);
        timerInterval  = setInterval(updateTimer, 1000);

        // Check immediately on load
        checkPaymentStatus();
    }

    /**
     * Check payment status via AJAX
     */
    function checkPaymentStatus() {
        // Prevent concurrent requests
        if (isChecking || isPaid) {
            return;
        }

        // Check for timeout
        var elapsed = Date.now() - startTime;
        if (elapsed >= config.timeout) {
            stopMonitoring();
            showStatus('timeout');
            return;
        }

        isChecking = true;
        showStatus('checking');

        $.ajax({
            url: zwennpayWc.ajaxUrl,
            type: 'POST',
            data: {
                action:   'zwennpay_wc_check_status',
                nonce:    zwennpayWc.nonce,
                order_id: zwennpayWc.orderId,
                reference: zwennpayWc.reference,
            },
            timeout: 10000, // 10 second request timeout
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.status === 'paid') {
                        handlePaymentSuccess(response.data.redirect);
                    } else {
                        showStatus('waiting');
                    }
                } else {
                    // Error from server, but don't stop - just show waiting
                    showStatus('waiting');
                    console.warn('ZwennPay status check error:', response.data);
                }
            },
            error: function(xhr, status, error) {
                // Network error - don't stop monitoring, just show waiting
                showStatus('waiting');
                console.warn('ZwennPay AJAX error:', status, error);
            },
            complete: function() {
                isChecking = false;
            },
        });
    }

    /**
     * Handle successful payment
     */
    function handlePaymentSuccess(redirectUrl) {
        isPaid = true;
        stopMonitoring();
        showStatus('paid');

        // Redirect after short delay for user to see success message
        setTimeout(function() {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            } else {
                // Fallback: try to get the order received URL
                window.location.reload();
            }
        }, 2000);
    }

    /**
     * Update the countdown timer display
     */
    function updateTimer() {
        if (!startTime || isPaid) {
            return;
        }

        var elapsed   = Date.now() - startTime;
        var remaining = Math.max(0, config.timeout - elapsed);
        var totalSec  = Math.ceil(remaining / 1000);

        var minutes = Math.floor(totalSec / 60);
        var seconds = totalSec % 60;

        var timeStr = padZero(minutes) + ':' + padZero(seconds);

        var $timer = $('#zwennpay-time-remaining');
        if ($timer.length) {
            $timer.text(timeStr);

            // Add warning class when less than 1 minute remaining
            if (totalSec <= 60) {
                $timer.addClass('zwennpay-timer-warning');
            }
        }
    }

    /**
     * Stop all monitoring intervals
     */
    function stopMonitoring() {
        if (statusInterval) {
            clearInterval(statusInterval);
            statusInterval = null;
        }
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
    }

    /**
     * Show status message and update UI
     */
    function showStatus(type) {
        var $statusText = $('.zwennpay-wc-status-text');
        var $spinner    = $('.zwennpay-wc-spinner');
        var $timer      = $('.zwennpay-wc-timer');

        // Remove all status classes
        $statusText.removeClass('zwennpay-wc-status-waiting zwennpay-wc-status-checking zwennpay-wc-status-paid zwennpay-wc-status-timeout zwennpay-wc-status-error');

        switch (type) {
            case 'waiting':
                $statusText
                    .addClass('zwennpay-wc-status-waiting')
                    .text(zwennpayWc.strings.waiting);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;

            case 'checking':
                $statusText
                    .addClass('zwennpay-wc-status-checking')
                    .text(zwennpayWc.strings.checking);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;

            case 'paid':
                $statusText
                    .addClass('zwennpay-wc-status-paid')
                    .text(zwennpayWc.strings.paid);
                $spinner.addClass('zwennpay-spinner-hidden');
                $timer.hide();
                break;

            case 'timeout':
                $statusText
                    .addClass('zwennpay-wc-status-timeout')
                    .text(zwennpayWc.strings.timeout);
                $spinner.addClass('zwennpay-spinner-hidden');
                $timer.hide();
                break;

            case 'error':
                $statusText
                    .addClass('zwennpay-wc-status-error')
                    .text(zwennpayWc.strings.error);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;
        }
    }

    /**
     * Pad number with leading zero
     */
    function padZero(num) {
        return num < 10 ? '0' + num : String(num);
    }

    /**
     * Warn user before leaving page
     */
    function setupLeaveWarning() {
        $(window).on('beforeunload', function() {
            if (!isPaid && startTime) {
                return zwennpayWc.strings.leave_warning || 'Payment is in progress. Are you sure you want to leave?';
            }
        });
    }

    /**
     * Document ready
     */
    $(document).ready(function() {
        // Only initialize if we're on the ZwennPay payment page
        if ($('#zwennpay-wc-payment').length > 0) {
            initMonitoring();
            setupLeaveWarning();
        }
    });

})(jQuery);