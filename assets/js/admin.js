/**
 * ZwennPay QR Admin JavaScript v1.0.0
 */
(function($) {
    'use strict';

    var currentPage = 1;

    $(document).ready(function() {

        // Input validation handlers
        $('input[data-limit]').on('input', function() {
            var node = $(this);
            var val = node.val().replace(/\D/g, '');
            var limit = parseInt(node.data('limit'), 10);

            if (val.length > limit) {
                val = val.substring(0, limit);
            }
            node.val(val);
        });

        $('input[data-max]').on('input', function() {
            var node = $(this);
            var max = parseFloat(node.data('max'));
            var val = node.val();

            if (val !== '' && parseFloat(val) > max) {
                node.val(max);
            }
        });

        // Auto-generate QR if merchant ID exists
        var merchantIdField = document.querySelector('input[name="zwennpay_qr_options[merchant_id]"]');
        if (merchantIdField && merchantIdField.value) {
            generateQR(false);
        }

        // Generate preview button
        $('#zwennpay-generate-preview').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Generating...');
            generateQR(true);
            jQuery('html, body').animate({ scrollTop: 0 }, 200);
        });

        // Clear logs button
        $('#zwennpay-clear-logs').on('click', function() {
            if (confirm(zwennpayAdmin.strings.confirm_delete)) {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: zwennpayAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'zwennpay_delete_qr_logs',
                        nonce: zwennpayAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            loadLogs(1);
                        }
                        $btn.prop('disabled', false);
                    },
                    error: function() {
                        $btn.prop('disabled', false);
                    }
                });
            }
        });

        // Load logs on page load
        loadLogs(1);

        function generateQR(incrementCounter) {
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

                        $qrDiv.html('<img src="' + response.qr_data + '" alt="QR Code" style="display:block;margin:0 auto;">');

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

                        // Refresh logs if a new entry was logged
                        if (response.logged) {
                            loadLogs(1);
                        }

                        $('#zwennpay-generate-preview').prop('disabled', false).text('Generate Preview');

                    } else {
                        $text.html('<span style="color:red;">' + (response.error || 'No QR data received') + '</span>');
                        $('#zwennpay-generate-preview').prop('disabled', false).text('Generate Preview');
                    }
                },
                error: function(xhr, status, error) {
                        var errorMsg = zwennpayAdmin.strings.error_loading;
                        
                        // Try to get more specific error from response
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMsg = xhr.responseJSON.data.message;
                        } else if (xhr.status === 0) {
                            errorMsg = 'Connection error - please refresh the page';
                        } else if (xhr.status) {
                            errorMsg = 'Server error (' + xhr.status + ')';
                        }
                        
                        $body.html(
                            '<tr><td colspan="3" style="text-align: center; padding: 30px; color: #dc3232;">' +
                            '<span class="dashicons dashicons-dismiss" style="font-size: 40px; width: 40px; height: 40px;"></span><br>' +
                            '<strong>' + errorMsg + '</strong>' +
                            '</td></tr>'
                        );
                    }
            });
        }

        /**
         * Load QR logs with pagination
         */
        function loadLogs(page) {
            currentPage = page;
            var $body = $('#zwennpay-logs-body');
            var $pagination = $('#zwennpay-logs-pagination');

            $body.html(
                '<tr><td colspan="3" style="text-align: center; padding: 30px;">' +
                '<span class="spinner is-active" style="float: none; vertical-align: middle;"></span> ' +
                zwennpayAdmin.strings.loading_logs +
                '</td></tr>'
            );

            $.ajax({
                url: zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zwennpay_get_qr_logs',
                    nonce: zwennpayAdmin.nonce,
                    page: page
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;

                        if (data.logs.length === 0) {
                            $body.html(
                                '<tr><td colspan="3" class="zwennpay-no-logs">' +
                                '<span class="dashicons dashicons-privacy" style="font-size: 40px; width: 40px; height: 40px; color: #ccc;"></span><br>' +
                                zwennpayAdmin.strings.no_logs +
                                '</td></tr>'
                            );
                            $pagination.hide();
                            return;
                        }

                        var html = '';
                        $.each(data.logs, function(index, log) {
                            var settings = {};
                            try {
                                settings = JSON.parse(log.settings);
                            } catch (e) {
                                settings = {};
                            }

                            var date = new Date(log.created_at.replace(/-/g, '/')); // Fix for Safari
                            var formattedDate = formatDate(date);
                            var formattedTime = formatTime(date);

                            html += '<tr class="zwennpay-log-row">';
                            html += '<td class="zwennpay-log-date-col">';
                            html += '<strong class="zwennpay-log-date">' + formattedDate + '</strong><br>';
                            html += '<span class="zwennpay-log-time">' + formattedTime + '</span>';
                            html += '</td>';
                            html += '<td class="zwennpay-log-qr-col">';
                            html += '<img src="' + log.qr_image + '" alt="QR Code" class="zwennpay-log-qr-img">';
                            html += '</td>';
                            html += '<td class="zwennpay-log-settings-col">';
                            html += '<div class="zwennpay-log-settings">';

                            if (settings.transaction_amount > 0) {
                                html += '<span class="zwennpay-setting-tag tag-amount">' +
                                    '<span class="dashicons dashicons-money-alt"></span> ' +
                                    parseFloat(settings.transaction_amount).toFixed(2) +
                                    '</span>';
                            }
                            if (settings.convenience_tip > 0) {
                                html += '<span class="zwennpay-setting-tag tag-tip">' +
                                    '<span class="dashicons dashicons-heart"></span> Tip: ' +
                                    parseFloat(settings.convenience_tip).toFixed(2) +
                                    '</span>';
                            }
                            if (settings.convenience_fee_fixed > 0) {
                                html += '<span class="zwennpay-setting-tag tag-fee">' +
                                    '<span class="dashicons dashicons-tag"></span> Fixed: ' +
                                    parseFloat(settings.convenience_fee_fixed).toFixed(2) +
                                    '</span>';
                            }
                            if (settings.convenience_fee_percentage > 0) {
                                html += '<span class="zwennpay-setting-tag tag-fee">' +
                                    '<span class="dashicons dashicons-percent"></span> Fee: ' +
                                    parseFloat(settings.convenience_fee_percentage).toFixed(2) + '%' +
                                    '</span>';
                            }
                            if (settings.bill_number) {
                                html += '<span class="zwennpay-setting-tag tag-info">' +
                                    '<span class="dashicons dashicons-list-view"></span> Bill: ' +
                                    escapeHtml(settings.bill_number) +
                                    '</span>';
                            }
                            if (settings.mobile_no) {
                                html += '<span class="zwennpay-setting-tag tag-info">' +
                                    '<span class="dashicons dashicons-phone"></span> ' +
                                    escapeHtml(settings.mobile_no) +
                                    '</span>';
                            }
                            if (settings.store_label) {
                                html += '<span class="zwennpay-setting-tag tag-store">' +
                                    '<span class="dashicons dashicons-store"></span> ' +
                                    escapeHtml(settings.store_label) +
                                    '</span>';
                            }
                            if (settings.loyalty_number) {
                                html += '<span class="zwennpay-setting-tag tag-info">' +
                                    '<span class="dashicons dashicons-star-filled"></span> Loyalty: ' +
                                    escapeHtml(settings.loyalty_number) +
                                    '</span>';
                            }
                            if (settings.purpose_transaction) {
                                html += '<span class="zwennpay-setting-tag tag-purpose">' +
                                    '<span class="dashicons dashicons-clipboard"></span> ' +
                                    escapeHtml(settings.purpose_transaction) +
                                    '</span>';
                            }
                            if (settings.customer_label) {
                                html += '<span class="zwennpay-setting-tag tag-customer">' +
                                    '<span class="dashicons dashicons-admin-users"></span> ' +
                                    escapeHtml(settings.customer_label) +
                                    '</span>';
                            }
                            if (settings.terminal_label) {
                                html += '<span class="zwennpay-setting-tag tag-terminal">' +
                                    '<span class="dashicons dashicons-desktop"></span> ' +
                                    escapeHtml(settings.terminal_label) +
                                    '</span>';
                            }
                            if (settings.qr_color && settings.qr_color !== '#000000') {
                                html += '<span class="zwennpay-setting-tag tag-color">' +
                                    '<span class="zwennpay-color-swatch" style="background-color: ' + settings.qr_color + ';"></span> ' +
                                    settings.qr_color +
                                    '</span>';
                            }

                            // Show merchant info if available
                            if (settings.merchant_name) {
                                html += '<div class="zwennpay-merchant-details">';
                                html += '<span class="zwennpay-merchant-name">' + escapeHtml(settings.merchant_name) + '</span>';
                                if (settings.merchant_city) {
                                    html += '<span class="zwennpay-merchant-city">' + escapeHtml(settings.merchant_city) + '</span>';
                                }
                                html += '</div>';
                            }

                            html += '</div>';
                            html += '</td>';
                            html += '</tr>';
                        });

                        $body.html(html);

                        // Pagination
                        if (data.pages > 1) {
                            $pagination.show();
                            $('#zwennpay-logs-count').text(
                                data.total + ' item' + (data.total !== 1 ? 's' : '')
                            );

                            var paginationHtml = '';
                            var startPage = Math.max(1, data.current_page - 2);
                            var endPage = Math.min(data.pages, data.current_page + 2);

                            // First page & prev
                            if (data.current_page > 1) {
                                paginationHtml += '<a class="button page-numbers" data-page="1" title="First page">&laquo;</a> ';
                                paginationHtml += '<a class="button page-numbers" data-page="' + (data.current_page - 1) + '" title="Previous page">&lsaquo;</a> ';
                            }

                            // Page numbers
                            for (var i = startPage; i <= endPage; i++) {
                                if (i === data.current_page) {
                                    paginationHtml += '<span class="button page-numbers current">' + i + '</span> ';
                                } else {
                                    paginationHtml += '<a class="button page-numbers" data-page="' + i + '">' + i + '</a> ';
                                }
                            }

                            // Next & last page
                            if (data.current_page < data.pages) {
                                paginationHtml += '<a class="button page-numbers" data-page="' + (data.current_page + 1) + '" title="Next page">&rsaquo;</a> ';
                                paginationHtml += '<a class="button page-numbers" data-page="' + data.pages + '" title="Last page">&raquo;</a> ';
                            }

                            $('#zwennpay-logs-links').html(paginationHtml);

                            // Pagination click handlers
                            $('#zwennpay-logs-links .page-numbers').on('click', function() {
                                var pageNum = $(this).data('page');
                                if (pageNum && !$(this).hasClass('current')) {
                                    loadLogs(pageNum);
                                    // Smooth scroll to logs section
                                    $('html, body').animate({
                                        scrollTop: $('.zwennpay-history-section').offset().top - 32
                                    }, 300);
                                }
                            });
                        } else {
                            // Show count even for single page
                            if (data.total > 0) {
                                $pagination.show();
                                $('#zwennpay-logs-count').text(
                                    data.total + ' item' + (data.total !== 1 ? 's' : '')
                                );
                                $('#zwennpay-logs-links').html('');
                            } else {
                                $pagination.hide();
                            }
                        }
                    }
                },
                error: function() {
                    $body.html(
                        '<tr><td colspan="3" style="text-align: center; padding: 30px; color: #dc3232;">' +
                        '<span class="dashicons dashicons-dismiss" style="font-size: 40px; width: 40px; height: 40px;"></span><br>' +
                        zwennpayAdmin.strings.error_loading +
                        '</td></tr>'
                    );
                }
            });
        }

        /**
         * Format date
         */
        function formatDate(date) {
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var day = date.getDate();
            var month = months[date.getMonth()];
            var year = date.getFullYear();
            return month + ' ' + day + ', ' + year;
        }

        /**
         * Format time
         */
        function formatTime(date) {
            var hours = date.getHours();
            var minutes = date.getMinutes();
            var seconds = date.getSeconds();
            var ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            return hours + ':' + minutes + ':' + seconds + ' ' + ampm;
        }

        /**
         * Escape HTML to prevent XSS
         */
        function escapeHtml(text) {
            if (!text) return '';
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });

})(jQuery);