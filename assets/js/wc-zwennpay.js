/**
 * ZwennPay WooCommerce Checkout JavaScript
 * Handles payment status monitoring on the pay page
 *
 * Strategy:
 *  - Poll the ZwennPay status API DIRECTLY from the browser (no WP AJAX nonce issues).
 *  - ResponseCode "00" = paid, "02" = pending, anything else = error/unknown.
 *  - On success, call WP AJAX once to mark the WC order as paid and get the redirect URL.
 *
 * @version 1.1.0
 */
(function($) {
    'use strict';

    var statusInterval = null;
    var timerInterval  = null;
    var startTime      = null;
    var isChecking     = false;
    var isPaid         = false;

    var config = {
        timeout:  300000,   // 5 minutes in ms (overridden by localized data)
        interval: 15000,    // 15 seconds in ms
    };

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    function initMonitoring() {
        if (typeof zwennpayWc === 'undefined' || !zwennpayWc.reference) {
            console.warn('ZwennPay: configuration not found on page.');
            return;
        }

        config.timeout  = zwennpayWc.timeout  || config.timeout;
        config.interval = zwennpayWc.interval || config.interval;

        startTime = Date.now();

        // Start intervals
        statusInterval = setInterval(checkPaymentStatus, config.interval);
        timerInterval  = setInterval(updateTimer, 1000);

        // Check immediately on load
        checkPaymentStatus();
    }

    // -------------------------------------------------------------------------
    // Poll ZwennPay API directly
    // -------------------------------------------------------------------------

    function checkPaymentStatus() {
        if (isChecking || isPaid) return;

        var elapsed = Date.now() - startTime;
        if (elapsed >= config.timeout) {
            stopMonitoring();
            showStatus('timeout');
            return;
        }

        isChecking = true;
        showStatus('checking');

        var url = zwennpayWc.statusApiUrl +
                '?referenceLabel=' + encodeURIComponent(zwennpayWc.reference);

        $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            headers: {
                'Accept': 'text/plain'
            },
            data: {},
            timeout: 10000,
            success: function(data) {
                handleStatusResponse(data);
            },
            error: function(xhr, status, error) {
                console.warn('ZwennPay status API error:', status, error, xhr.responseText);
                showStatus('waiting');
            },
            complete: function() {
                isChecking = false;
            }
        });
    }

    /**
     * Interpret the ZwennPay API response.
     *
     * Known response codes:
     *   "00" → Success / paid
     *   "02" → Pending QR Payment
     *   anything else → treat as still pending (log for debugging)
     */
    function handleStatusResponse(data) {
        if (!data) {
            showStatus('waiting');
            return;
        }

        var code = (data.ResponseCode || '').toString().trim();

        if (code === '00') {
            // Payment confirmed — now tell WordPress to mark the order paid
            confirmOrderOnServer();
        } else {
            // "02" = pending, or any other unknown code — keep polling
            if (code !== '02') {
                console.log('ZwennPay: unexpected ResponseCode:', code, data);
            }
            showStatus('waiting');
        }
    }

    // -------------------------------------------------------------------------
    // Tell WordPress the payment succeeded (single WP AJAX call)
    // -------------------------------------------------------------------------

    function confirmOrderOnServer() {
        // Stop polling immediately so we don't fire this twice
        stopMonitoring();
        showStatus('paid');

        $.ajax({
            url:  zwennpayWc.ajaxUrl,
            type: 'POST',
            data: {
                action:    'zwennpay_wc_confirm_payment',
                nonce:     zwennpayWc.nonce,
                order_id:  zwennpayWc.orderId,
                reference: zwennpayWc.reference,
            },
            timeout: 15000,
            success: function(response) {
                var redirectUrl = (response.success && response.data && response.data.redirect)
                    ? response.data.redirect
                    : null;

                setTimeout(function() {
                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                    } else {
                        window.location.reload();
                    }
                }, 1500);
            },
            error: function() {
                // Even if WP AJAX fails, the payment IS done — just reload
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            },
        });
    }

    // -------------------------------------------------------------------------
    // Timer
    // -------------------------------------------------------------------------

    function updateTimer() {
        if (!startTime || isPaid) return;

        var elapsed   = Date.now() - startTime;
        var remaining = Math.max(0, config.timeout - elapsed);
        var totalSec  = Math.ceil(remaining / 1000);
        var minutes   = Math.floor(totalSec / 60);
        var seconds   = totalSec % 60;

        var $timer = $('#zwennpay-time-remaining');
        if ($timer.length) {
            $timer.text(padZero(minutes) + ':' + padZero(seconds));
            if (totalSec <= 60) {
                $timer.addClass('zwennpay-timer-warning');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function stopMonitoring() {
        if (statusInterval) { clearInterval(statusInterval); statusInterval = null; }
        if (timerInterval)  { clearInterval(timerInterval);  timerInterval  = null; }
    }

    function showStatus(type) {
        var $statusText = $('.zwennpay-wc-status-text');
        var $spinner    = $('.zwennpay-wc-spinner');
        var $timer      = $('.zwennpay-wc-timer');

        $statusText.removeClass(
            'zwennpay-wc-status-waiting zwennpay-wc-status-checking ' +
            'zwennpay-wc-status-paid zwennpay-wc-status-timeout zwennpay-wc-status-error'
        );

        switch (type) {
            case 'waiting':
                $statusText.addClass('zwennpay-wc-status-waiting').text(zwennpayWc.strings.waiting);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;
            case 'checking':
                $statusText.addClass('zwennpay-wc-status-checking').text(zwennpayWc.strings.checking);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;
            case 'paid':
                isPaid = true;
                $statusText.addClass('zwennpay-wc-status-paid').text(zwennpayWc.strings.paid);
                $spinner.addClass('zwennpay-spinner-hidden');
                $timer.hide();
                break;
            case 'timeout':
                $statusText.addClass('zwennpay-wc-status-timeout').text(zwennpayWc.strings.timeout);
                $spinner.addClass('zwennpay-spinner-hidden');
                $timer.hide();
                break;
            case 'error':
                $statusText.addClass('zwennpay-wc-status-error').text(zwennpayWc.strings.error);
                $spinner.removeClass('zwennpay-spinner-hidden');
                $timer.show();
                break;
        }
    }

    function padZero(num) {
        return num < 10 ? '0' + num : String(num);
    }

    function setupLeaveWarning() {
        $(window).on('beforeunload', function() {
            if (!isPaid && startTime) {
                return zwennpayWc.strings.leave_warning ||
                       'Payment is in progress. Are you sure you want to leave?';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    $(document).ready(function() {
        if ($('#zwennpay-wc-payment').length > 0) {
            initMonitoring();
            setupLeaveWarning();
        }
    });

})(jQuery);