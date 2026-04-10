/**
 * ZwennPay QR Admin JavaScript v1.3.0
 */
(function($) {
    'use strict';

    var currentPage  = 1;
    var hasQRPreview = false;

    $(document).ready(function() {

        // ----------------------------------------------------------------
        // Input validation (kept for any hidden/future fields)
        // ----------------------------------------------------------------
        $('input[data-limit]').on('input', function() {
            var node  = $(this);
            var val   = node.val().replace(/\D/g, '');
            var limit = parseInt(node.data('limit'), 10);
            if (val.length > limit) val = val.substring(0, limit);
            node.val(val);
        });

        $('input[data-max]').on('input', function() {
            var node = $(this);
            var max  = parseFloat(node.data('max'));
            var val  = node.val();
            if (val !== '' && parseFloat(val) > max) node.val(max);
        });

        // ----------------------------------------------------------------
        // "Save Settings" form submit → after reload, run preview
        // ----------------------------------------------------------------
        $('#zwennpay-settings-form').on('submit', function() {
            sessionStorage.setItem('zwennpay_generate_on_load', '1');
        });

        if (sessionStorage.getItem('zwennpay_generate_on_load') === '1') {
            sessionStorage.removeItem('zwennpay_generate_on_load');
            generateQR();
        } else {
            generateQR(); // auto-preview on page open
        }

        // ----------------------------------------------------------------
        // "Preview QR" sidebar button
        // ----------------------------------------------------------------
        $('#zwennpay-generate-preview').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text(zwennpayAdmin.strings.generating || 'Generating...');
            generateQR();
            $('html, body').animate({ scrollTop: 0 }, 200);
        });

        // ----------------------------------------------------------------
        // Download PDF
        // ----------------------------------------------------------------
        $('#zwennpay-download-pdf').on('click', function() {
            downloadPreviewPDF();
        });

        // Initial load of transaction history
        loadTransactions(1);

        // ----------------------------------------------------------------
        // generateQR — always preview-only (no DB write from admin settings page)
        // ----------------------------------------------------------------
        function generateQR() {
            var $qrDiv       = $('#zwennpay-preview-qr');
            var $text        = $('#zwennpay-preview-text');
            var $amount      = $('#zwennpay-preview-amount');
            var $logo        = $('.Zvenn-Pay-logo');
            var $downloadBtn = $('#zwennpay-download-pdf');

            $qrDiv.hide().empty();
            $amount.hide();
            $('.zwennpay-merchant-info').remove();
            $text.show().text('Generating...');
            hasQRPreview = false;
            $downloadBtn.prop('disabled', true);

            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zwennpay_preview_qr_admin',
                    nonce:  zwennpayAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.qr_data) {
                        $text.hide();
                        $qrDiv.show();
                        $qrDiv.html('<img src="' + response.qr_data + '" alt="QR Code" style="display:block;margin:0 auto;">');

                        if (response.merchant_name || response.merchant_city) {
                            var infoHtml = '<div class="zwennpay-merchant-info" style="text-align:center;margin-top:10px;font-family:sans-serif;font-size:10px;line-height:1.4;">';
                            if (response.merchant_name) infoHtml += '<strong style="display:block;">' + escapeHtml(response.merchant_name) + '</strong>';
                            if (response.merchant_city) infoHtml += '<span style="display:block;color:#666;">'  + escapeHtml(response.merchant_city) + '</span>';
                            infoHtml += '</div>';
                            $logo.after(infoHtml);
                        }

                        hasQRPreview = true;
                        $downloadBtn.prop('disabled', false);

                    } else {
                        $text.html('<span style="color:red;">' + escapeHtml(response.error || 'No QR data received') + '</span>');
                        hasQRPreview = false;
                        $downloadBtn.prop('disabled', true);
                    }

                    $('#zwennpay-generate-preview').prop('disabled', false).text('Preview QR');
                },
                error: function(xhr, status, error) {
                    $text.html('<span style="color:red;">Error: ' + escapeHtml(error) + '</span>');
                    $('#zwennpay-generate-preview').prop('disabled', false).text('Preview QR');
                    hasQRPreview = false;
                    $downloadBtn.prop('disabled', true);
                }
            });
        }

        // ----------------------------------------------------------------
        // loadTransactions — fetches and renders the Transaction History table
        // ----------------------------------------------------------------
        function loadTransactions(page) {
            currentPage     = page;
            var $body       = $('#zwennpay-tx-body');
            var $pagination = $('#zwennpay-tx-pagination');

            $body.html(
                '<tr><td colspan="5" style="text-align:center;padding:30px;">' +
                '<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> ' +
                (zwennpayAdmin.strings.loading_transactions || 'Loading...') + '</td></tr>'
            );

            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'zwennpay_get_transactions', nonce: zwennpayAdmin.nonce, page: page },
                success: function(response) {
                    if (!response.success) {
                        $body.html('<tr><td colspan="6" style="text-align:center;padding:20px;color:#dc3232;">' + (zwennpayAdmin.strings.error_loading || 'Error loading.') + '</td></tr>');
                        return;
                    }

                    var data = response.data;

                    if (data.rows.length === 0) {
                        $body.html(
                            '<tr><td colspan="5" class="zwennpay-no-logs" style="text-align:center;padding:30px;color:#888;">' +
                            (zwennpayAdmin.strings.no_transactions || 'No transactions yet.') + '</td></tr>'
                        );
                        $pagination.hide();
                        return;
                    }

                    var html = '';
                    $.each(data.rows, function(i, row) {
                        var date          = new Date(row.created_at.replace(/-/g, '/'));
                        var formattedDate = formatDate(date);
                        var formattedTime = formatTime(date);
                        var amount        = parseFloat(row.amount) || 0;
                        var status        = row.status || 'pending';
                        var statusClass   = 'zwennpay-status-' + status;
                        var statusLabel   = status.charAt(0).toUpperCase() + status.slice(1);

                        // Status badge colours
                        var badgeStyle = 'display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;';
                        if (status === 'success') {
                            badgeStyle += 'background:#d4edda;color:#155724;';
                        } else if (status === 'failed') {
                            badgeStyle += 'background:#f8d7da;color:#721c24;';
                        } else {
                            badgeStyle += 'background:#fff3cd;color:#856404;';
                        }

                        html += '<tr>';

                        // Date & Time
                        html += '<td>';
                        html += '<strong style="display:block;">' + formattedDate + '</strong>';
                        html += '<span style="color:#888;font-size:11px;">' + formattedTime + '</span>';
                        html += '</td>';

                        // Order #
                        html += '<td><strong>' + escapeHtml(row.order_number) + '</strong></td>';

                        // Reference Number
                        html += '<td style="font-family:monospace;font-size:12px;">' + escapeHtml(row.reference_number || '—') + '</td>';

                        // Amount
                        html += '<td><strong>Rs ' + amount.toFixed(2) + '</strong></td>';

                        // Status badge
                        html += '<td><span style="' + badgeStyle + '">' + escapeHtml(statusLabel) + '</span></td>';

                        html += '</tr>';
                    });

                    $body.html(html);

                    renderPagination($pagination, data);
                },
                error: function() {
                    $body.html('<tr><td colspan="5" style="text-align:center;padding:20px;color:#dc3232;">' + (zwennpayAdmin.strings.error_loading || 'Error.') + '</td></tr>');
                }
            });
        }

        function renderPagination($pagination, data) {
            if (data.total > 0) {
                $pagination.show();
                $('#zwennpay-tx-count').text(data.total + ' transaction' + (data.total !== 1 ? 's' : ''));
            } else {
                $pagination.hide();
                return;
            }

            if (data.pages <= 1) {
                $('#zwennpay-tx-links').html('');
                return;
            }

            var html      = '';
            var startPage = Math.max(1, data.current_page - 2);
            var endPage   = Math.min(data.pages, data.current_page + 2);

            if (data.current_page > 1) {
                html += '<a class="button page-numbers" data-page="1">&laquo;</a> ';
                html += '<a class="button page-numbers" data-page="' + (data.current_page - 1) + '">&lsaquo;</a> ';
            }
            for (var i = startPage; i <= endPage; i++) {
                if (i === data.current_page) {
                    html += '<span class="button page-numbers current">' + i + '</span> ';
                } else {
                    html += '<a class="button page-numbers" data-page="' + i + '">' + i + '</a> ';
                }
            }
            if (data.current_page < data.pages) {
                html += '<a class="button page-numbers" data-page="' + (data.current_page + 1) + '">&rsaquo;</a> ';
                html += '<a class="button page-numbers" data-page="' + data.pages + '">&raquo;</a> ';
            }

            $('#zwennpay-tx-links').html(html);

            $('#zwennpay-tx-links .page-numbers').on('click', function() {
                var pageNum = $(this).data('page');
                if (pageNum && !$(this).hasClass('current')) {
                    loadTransactions(pageNum);
                    $('html, body').animate({ scrollTop: $('.zwennpay-history-section').offset().top - 32 }, 300);
                }
            });
        }

        // ----------------------------------------------------------------
        // PDF download
        // ----------------------------------------------------------------
        function downloadPreviewPDF() {
            var $downloadBtn = $('#zwennpay-download-pdf');
            var $previewBox  = $('#zwennpay-preview-box');
            var $qrDiv       = $('#zwennpay-preview-qr');

            if (!hasQRPreview || $qrDiv.is(':hidden') || $qrDiv.find('img').length === 0) {
                alert(zwennpayAdmin.strings.no_preview || 'Please preview a QR first.');
                return;
            }

            var originalHtml = $downloadBtn.html();
            $downloadBtn.prop('disabled', true).html(
                '<span class="spinner is-active" style="float:none;vertical-align:middle;margin-top:-2px;"></span> ' +
                (zwennpayAdmin.strings.downloading || 'Preparing...')
            );

            html2canvas($previewBox[0], {
                scale: 4, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
                scrollX: 0, scrollY: 0,
                ignoreElements: function(el) { return el.classList && el.classList.contains('zwennpay-preview-buttons'); },
                onclone: function(clonedDoc) {
                    var b = clonedDoc.getElementById('zwennpay-preview-box');
                    if (b) { b.style.overflow = 'visible'; b.style.transform = 'none'; }
                    var c = clonedDoc.getElementById('zwennpay-preview-container');
                    if (c) c.style.overflow = 'visible';
                }
            }).then(function(canvas) {
                var jsPDF    = window.jspdf.jsPDF;
                var pdfWidth = 210;
                var pdfHeight= Math.max((canvas.height * pdfWidth) / canvas.width, 100);
                var pdf      = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [pdfWidth, pdfHeight] });
                pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, pdfWidth, pdfHeight);

                var now = new Date();
                var ts  = now.getFullYear() +
                          String(now.getMonth() + 1).padStart(2, '0') +
                          String(now.getDate()).padStart(2, '0') + '_' +
                          String(now.getHours()).padStart(2, '0') +
                          String(now.getMinutes()).padStart(2, '0');
                pdf.save('ZwennPay_QR_' + ts + '.pdf');

                $downloadBtn.prop('disabled', false).html(originalHtml);
            }).catch(function(err) {
                console.error('PDF error:', err);
                alert(zwennpayAdmin.strings.download_error || 'PDF error.');
                $downloadBtn.prop('disabled', false).html(originalHtml);
            });
        }

        // ----------------------------------------------------------------
        // Helpers
        // ----------------------------------------------------------------
        function formatDate(date) {
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
        }

        function formatTime(date) {
            var h = date.getHours(), m = date.getMinutes(), s = date.getSeconds();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s + ' ' + ampm;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }
    });

})(jQuery);