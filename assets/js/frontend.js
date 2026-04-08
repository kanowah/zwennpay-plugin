/**
 * ZwennPay QR Frontend JavaScript v1.4.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Handle dynamic QR generation from forms
        $(document).on('click', '.zwennpay-generate-btn', function() {
            var $container = $(this).closest('.zwennpay-qr-container');
            var $display = $container.find('.zwennpay-qr-display');
            var $btn = $(this);
            
            var amount = parseFloat($container.find('.zwennpay-amount-input').val()) || 0;
            var reference = $container.find('.zwennpay-reference-input').val() || '';
            var size = parseInt($container.data('size')) || 256;
            var color = $container.data('color') || '#000000';
            var showAmount = $container.data('show-amount') === 'true';
            
            $btn.prop('disabled', true).text('Generating...');
            $display.html('<p class="zwennpay-loading">' + zwennpayFrontend.strings.loading + '</p>');
            
            $.ajax({
                url: zwennpayFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zwennpay_generate_qr',
                    nonce: zwennpayFrontend.nonce,
                    amount: amount,
                    reference: reference
                },
                success: function(response) {
                    console.log('Frontend QR response:', response);
                    
                    if (response.success && response.data.qr_data) {
                        var cleanColor = color.replace('#', '');
                        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' + 
                                   size + 'x' + size + 
                                   '&color=' + cleanColor + 
                                   '&data=' + encodeURIComponent(response.data.qr_data) +
                                   '&margin=10';
                        
                        var html = '<div class="zwennpay-qr-image">' +
                                   '<img src="' + qrUrl + '" alt="Payment QR Code" width="' + size + '" height="' + size + '">' +
                                   '</div>';
                        
                        // Add extracted Merchant Info
                        if (response.data.merchant_name || response.data.merchant_city) {
                            html += '<div class="zwennpay-merchant-info" style="text-align:center; margin-top:10px; font-size:14px;">';
                            if (response.data.merchant_name) {
                                html += '<strong style="display:block;">' + response.data.merchant_name + '</strong>';
                            }
                            if (response.data.merchant_city) {
                                html += '<span style="display:block; color:#555;">' + response.data.merchant_city + '</span>';
                            }
                            html += '</div>';
                        }
                        
                        if (showAmount && amount > 0) {
                            html += '<div class="zwennpay-qr-amount" style="margin-top:10px;font-weight:bold;">' + amount.toFixed(2) + '</div>';
                        }
                        
                        $display.html(html);
                    } else {
                        var errorMsg = response.data ? response.data.message : 'Unknown error';
                        $display.html('<div class="zwennpay-error">' + zwennpayFrontend.strings.error + ' ' + errorMsg + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Frontend AJAX error:', xhr, status, error);
                    $display.html('<div class="zwennpay-error">' + zwennpayFrontend.strings.error + '</div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Generate QR Code');
                }
            });
        });
    });

})(jQuery);