/**
 * ZwennPay QR Admin JavaScript v1.0.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        $('input[data-limit]').on('input', function() {
            var node = $(this);
            var val = node.val().replace(/\D/g, '');
            var limit = parseInt(node.data('limit'), 10);
            
            if (val.length > limit) {
                val = val.substring(0, limit);
            }
            node.val(val);
        });

        // 2. Numeric Max Validation: Prevent entering value > max
        $('input[data-max]').on('input', function() {
            var node = $(this);
            var max = parseFloat(node.data('max'));
            var val = node.val();

            if (val !== '' && parseFloat(val) > max) {
                node.val(max);
            }
        });

        var merchantIdField = document.querySelector('input[name="zwennpay_qr_options[merchant_id]"]');
        if(merchantIdField && merchantIdField.value) {
            generateQR(false); 
        }
        
        $('#zwennpay-generate-preview').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Generating...');
            generateQR(true); 
            jQuery('html, body').animate({ scrollTop: 0 }, 200);
        });

        function generateQR(incrementCounter){
            var $qrDiv = $('#zwennpay-preview-qr');
            var $text = $('#zwennpay-preview-text');
            var $amount = $('#zwennpay-preview-amount');
            var $logo = $('.Zvenn-Pay-logo'); 
            
            $qrDiv.hide().empty();
            $amount.hide();
            
            $('.zwennpay-merchant-info').remove();
            
            $text.show().text('Generating QR code...');
            
            $.ajax({
                url: zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zwennpay_generate_qr_admin',
                    nonce: zwennpayAdmin.nonce,
                    increment_counter: incrementCounter ? 'true' : 'false'
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

                        if (incrementCounter && response.new_preview_count) {
                            $('#zwennpay-counter').text(response.new_preview_count);
                            // Ensure the notice is visible if it was hidden before
                            $('#zwennpay-history-notice').show();
                        }

                        if (incrementCounter) {
                            var countEl = $('#preview-count');
                            var currentCount = parseInt(countEl.text()) || 0;
                            countEl.text(currentCount + 1);
                        }
                        $('#zwennpay-generate-preview').prop('disabled', false).text('Generate Preview');

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