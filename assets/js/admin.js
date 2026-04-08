/**
 * ZwennPay QR Admin JavaScript v1.0.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Auto-generate if merchant ID exists
        /*if(document.querySelector('input[name="zwennpay_qr_options[merchant_id]"]').value) {
            generateQR();
        }*/
        
        // Generate Preview Button
        $('#zwennpay-generate-preview').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Generating...');
            generateQR();
            jQuery('html, body').animate({ scrollTop: 0 }, 200);
        });

        function generateQR(){
            var $qrDiv = $('#zwennpay-preview-qr');
            var $text = $('#zwennpay-preview-text');
            var $amount = $('#zwennpay-preview-amount');
            var $logo = $('.Zvenn-Pay-logo'); // The logo element
            
            $qrDiv.hide().empty();
            $amount.hide();
            
            // Remove any previously added merchant info
            $('.zwennpay-merchant-info').remove();
            
            $text.show().text('Generating QR code...');
            
            $.ajax({
                url: zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zwennpay_generate_qr_admin',
                    nonce: zwennpayAdmin.nonce
                },
                success: function(response) {
                    
                    if (response.success && response.qr_data) {
                        $text.hide();
                        $qrDiv.show();
                        
                        var size = parseInt($('input[name="zwennpay_qr_options[qr_size]"]').val()) || 200;
                        var color = $('input[name="zwennpay_qr_options[qr_color]"]').val() || '#000000';
                        var amount = parseFloat($('input[name="zwennpay_qr_options[transaction_amount]"]').val()) || 0;
                        
                        var cleanColor = color.replace('#', '');
                        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' + 
                                   Math.min(size, 300) + 'x' + Math.min(size, 300) + 
                                   '&color=' + cleanColor + 
                                   '&data=' + encodeURIComponent(response.qr_data) +
                                   '&margin=10';
                        
                        // 1. Render the QR Image
                        $qrDiv.html('<img src="' + qrUrl + '" alt="QR Code" style="display:block;margin:0 auto;">');
                        
                        if (response.merchant_name || response.merchant_city) {
                            var infoHtml = '<div class="zwennpay-merchant-info" style="text-align:center; margin-top:10px; font-family: sans-serif; font-size: 10px; line-height: 1.4;">';
                            if (response.merchant_name) {
                                infoHtml += '<strong style="display:block;">' + response.merchant_name + '</strong>';
                            }
                            if (response.merchant_city) {
                                infoHtml += '<span style="display:block; color: #666;">' + response.merchant_city + '</span>';
                            }
                            infoHtml += '</div>';
                            
                            
                            $logo.after(infoHtml);
                        }

                        if (amount > 0 && $('input[name="zwennpay_qr_options[show_amount]"]').is(':checked')) {
                            $amount.text('Amount: ' + amount.toFixed(2)).show();
                        }
                    } else {
                        $text.html('<span style="color:red;">' + (response.error || 'No QR data received') + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    $text.html('<span style="color:red;">Error: ' + error + '</span>');
                }
            });
        }
    });

})(jQuery);