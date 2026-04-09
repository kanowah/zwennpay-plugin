/**
 * ZwennPay QR Admin JavaScript v1.1.0
 */
(function($) {
    'use strict';

    var currentPage  = 1;
    var hasQRPreview = false;

    $(document).ready(function() {

        // ----------------------------------------------------------------
        // Advanced options toggle
        // ----------------------------------------------------------------
        $('#zwennpay-advanced-toggle').on('click', function() {
            var $section = $('#zwennpay-advanced-section');
            var $icon    = $(this).find('.dashicons');
            var isOpen   = $section.is(':visible');

            $section.slideToggle(200);
            $icon.toggleClass('dashicons-arrow-down-alt2', isOpen)
                 .toggleClass('dashicons-arrow-up-alt2', !isOpen);
        });

        // ----------------------------------------------------------------
        // Input validation
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
        // "Generate QR Code" button (form submit) → intercept & call AJAX
        // We hook the form submit so settings are saved first, then QR generated.
        // ----------------------------------------------------------------
        $('#zwennpay-settings-form').on('submit', function(e) {
            // Let the form submit normally (saves settings),
            // but also queue a QR generation after the page reloads.
            // We store a flag in sessionStorage to trigger generation after reload.
            sessionStorage.setItem('zwennpay_generate_on_load', '1');
        });

        // Check if we should auto-generate after a settings save
        if (sessionStorage.getItem('zwennpay_generate_on_load') === '1') {
            sessionStorage.removeItem('zwennpay_generate_on_load');
            generateQR(true);
        } else {
            // Auto-load preview on page open (preview only, no save)
            generateQR(false);
        }

        // ----------------------------------------------------------------
        // "Preview Only" sidebar button
        // ----------------------------------------------------------------
        $('#zwennpay-generate-preview').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text(zwennpayAdmin.strings.generating);
            generateQR(false);
            $('html, body').animate({ scrollTop: 0 }, 200);
        });

        // ----------------------------------------------------------------
        // Download PDF
        // ----------------------------------------------------------------
        $('#zwennpay-download-pdf').on('click', function() {
            downloadPreviewPDF();
        });

        // ----------------------------------------------------------------
        // Clear all QR codes
        // ----------------------------------------------------------------
        $('#zwennpay-clear-qrs').on('click', function() {
            if (!confirm(zwennpayAdmin.strings.confirm_delete_all)) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'zwennpay_delete_generated_qrs', nonce: zwennpayAdmin.nonce },
                success: function(response) {
                    if (response.success) loadQRs(1);
                    $btn.prop('disabled', false);
                },
                error: function() { $btn.prop('disabled', false); }
            });
        });

        // ----------------------------------------------------------------
        // Delete single QR (delegated)
        // ----------------------------------------------------------------
        $(document).on('click', '.zwennpay-delete-single', function() {
            if (!confirm(zwennpayAdmin.strings.confirm_delete_one)) return;
            var qr_id = $(this).data('id');
            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'zwennpay_delete_single_qr', nonce: zwennpayAdmin.nonce, qr_id: qr_id },
                success: function(response) {
                    if (response.success) loadQRs(currentPage);
                }
            });
        });

        // ----------------------------------------------------------------
        // Copy shortcode (delegated)
        // ----------------------------------------------------------------
        $(document).on('click', '.zwennpay-copy-shortcode', function() {
            var sc   = $(this).data('shortcode');
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(sc).then(function() {
                    $btn.text('Copied!');
                    setTimeout(function() { $btn.text('Copy'); }, 2000);
                });
            } else {
                // fallback
                var $tmp = $('<input>').val(sc).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                $btn.text('Copied!');
                setTimeout(function() { $btn.text('Copy'); }, 2000);
            }
        });

        // Initial load of QR list
        loadQRs(1);

        // ----------------------------------------------------------------
        // generateQR
        //   saveToDB = false → calls zwennpay_preview_qr_admin  (no DB write)
        //   saveToDB = true  → calls zwennpay_generate_qr_admin (saves new row)
        // ----------------------------------------------------------------
        function generateQR(saveToDB) {
            var action      = saveToDB ? 'zwennpay_generate_qr_admin' : 'zwennpay_preview_qr_admin';
            var $qrDiv      = $('#zwennpay-preview-qr');
            var $text       = $('#zwennpay-preview-text');
            var $amount     = $('#zwennpay-preview-amount');
            var $logo       = $('.Zvenn-Pay-logo');
            var $downloadBtn= $('#zwennpay-download-pdf');

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
                    action: action,
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
                            if (response.merchant_city) infoHtml += '<span style="display:block;color:#666;">' + escapeHtml(response.merchant_city) + '</span>';
                            infoHtml += '</div>';
                            $logo.after(infoHtml);
                        }

                        var amount = parseFloat($('input[name="zwennpay_qr_options[transaction_amount]"]').val()) || 0;
                        if (amount > 0 && $('input[name="zwennpay_qr_options[show_amount]"]').is(':checked')) {
                            $amount.text('Amount: ' + amount.toFixed(2)).show();
                        }

                        hasQRPreview = true;
                        $downloadBtn.prop('disabled', false);

                        // Refresh list if a new QR was saved
                        if (response.saved) {
                            loadQRs(1);
                        }

                        $('#zwennpay-generate-preview').prop('disabled', false).text('Preview Only');

                    } else {
                        $text.html('<span style="color:red;">' + escapeHtml(response.error || 'No QR data received') + '</span>');
                        $('#zwennpay-generate-preview').prop('disabled', false).text('Preview Only');
                        hasQRPreview = false;
                        $downloadBtn.prop('disabled', true);
                    }
                },
                error: function(xhr, status, error) {
                    $text.html('<span style="color:red;">Error: ' + escapeHtml(error) + '</span>');
                    $('#zwennpay-generate-preview').prop('disabled', false).text('Preview Only');
                    hasQRPreview = false;
                    $downloadBtn.prop('disabled', true);
                }
            });
        }

        // ----------------------------------------------------------------
        // loadQRs — fetches and renders the QR Generated table
        // ----------------------------------------------------------------
        function loadQRs(page) {
            currentPage  = page;
            var $body    = $('#zwennpay-qrs-body');
            var $pagination = $('#zwennpay-qrs-pagination');

            $body.html(
                '<tr><td colspan="4" style="text-align:center;padding:30px;">' +
                '<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> ' +
                zwennpayAdmin.strings.loading_qrs + '</td></tr>'
            );

            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'zwennpay_get_generated_qrs', nonce: zwennpayAdmin.nonce, page: page },
                success: function(response) {
                    if (!response.success) {
                        $body.html('<tr><td colspan="4" style="text-align:center;padding:20px;color:#dc3232;">' + zwennpayAdmin.strings.error_loading + '</td></tr>');
                        return;
                    }

                    var data = response.data;

                    // Toggle clear button
                    if (data.total > 0) {
                        $('#zwennpay-clear-qrs').hide();
                    } else {
                        $('#zwennpay-clear-qrs').hide();
                    }

                    if (data.rows.length === 0) {
                        $body.html(
                            '<tr><td colspan="4" class="zwennpay-no-logs">' +
                            '<span class="dashicons dashicons-qr-code" style="font-size:40px;width:40px;height:40px;color:#ccc;"></span><br>' +
                            zwennpayAdmin.strings.no_qrs + '</td></tr>'
                        );
                        $pagination.hide();
                        return;
                    }

                    var html = '';
                    $.each(data.rows, function(i, row) {
                        var settings = {};
                        try { settings = JSON.parse(row.settings); } catch(e) {}

                        var date          = new Date(row.created_at.replace(/-/g, '/'));
                        var formattedDate = formatDate(date);
                        var formattedTime = formatTime(date);
                        var amount        = parseFloat(row.amount) || 0;
                        var purpose       = row.purpose || '';
                        var label         = row.qr_label || '';
                        var shortcode     = row.shortcode || '';

                        html += '<tr class="zwennpay-log-row">';

                        // Date
                        html += '<td class="zwennpay-log-date-col">';
                        html += '<strong class="zwennpay-log-date">' + formattedDate + '</strong><br>';
                        html += '<span class="zwennpay-log-time">' + formattedTime + '</span>';
                        html += '</td>';

                        // QR image (thumbnail)
                       /* html += '<td style="text-align:center;vertical-align:middle;">';
                        if (row.qr_image) {
                            html += '<img src="' + row.qr_image + '" alt="QR" style="width:60px;height:60px;display:block;margin:0 auto;">';
                        }
                        html += '</td>';*/

                        // Details
                        html += '<td class="zwennpay-log-settings-col">';
                        html += '<div class="zwennpay-log-settings">';

                        if (label) {
                            html += '<span class="zwennpay-setting-tag tag-store"><span class="dashicons dashicons-store"></span> ' + escapeHtml(label) + '</span>';
                        }
                        if (amount > 0) {
                            html += '<span class="zwennpay-setting-tag tag-amount"><span class="dashicons dashicons-money-alt"></span> ' + amount.toFixed(2) + '</span>';
                        }
                        if (purpose) {
                            html += '<span class="zwennpay-setting-tag tag-purpose"><span class="dashicons dashicons-clipboard"></span> ' + escapeHtml(purpose) + '</span>';
                        }
                        if (settings.convenience_tip > 0) {
                            html += '<span class="zwennpay-setting-tag tag-tip"><span class="dashicons dashicons-heart"></span> Tip: ' + parseFloat(settings.convenience_tip).toFixed(2) + '</span>';
                        }
                        if (settings.convenience_fee_fixed > 0) {
                            html += '<span class="zwennpay-setting-tag tag-fee"><span class="dashicons dashicons-tag"></span> Fixed: ' + parseFloat(settings.convenience_fee_fixed).toFixed(2) + '</span>';
                        }
                        if (settings.convenience_fee_percentage > 0) {
                            html += '<span class="zwennpay-setting-tag tag-fee"><span class="dashicons dashicons-percent"></span> Fee: ' + parseFloat(settings.convenience_fee_percentage).toFixed(2) + '%</span>';
                        }
                        if (settings.mobile_no) {
                            html += '<span class="zwennpay-setting-tag tag-info"><span class="dashicons dashicons-phone"></span> ' + escapeHtml(settings.mobile_no) + '</span>';
                        }
                        /*if (settings.merchant_name) {
                            html += '<div class="zwennpay-merchant-details">';
                            html += '<span class="zwennpay-merchant-name">' + escapeHtml(settings.merchant_name) + '</span>';
                            if (settings.merchant_city) html += ' <span class="zwennpay-merchant-city">' + escapeHtml(settings.merchant_city) + '</span>';
                            html += '</div>';
                        }*/

                        html += '</div></td>';

                        // Shortcode
                        html += '<td style="vertical-align:middle;text-align: center;">';
                        if (shortcode) {
                            html += '<code style="font-size:11px;word-break:break-all;">' + escapeHtml(shortcode) + '</code><br>';
                            html += '<button type="button" class="button button-small zwennpay-copy-shortcode" data-shortcode="' + escapeHtml(shortcode) + '" style="margin-top:4px;width:100%;">Copy</button>';
                        }
                        html += '</td>';

                        // Delete
                        html += '<td style="vertical-align:middle;text-align:center;">';
                        html += '<button type="button" class="button button-small button-link-delete zwennpay-delete-single" data-id="' + row.id + '" title="Delete">';
                        html += '<span class="dashicons dashicons-trash"></span>';
                        html += '</button>';
                        html += '</td>';

                        html += '</tr>';
                    });

                    $body.html(html);

                    // Pagination
                    renderPagination($pagination, data);
                },
                error: function() {
                    $body.html('<tr><td colspan="4" style="text-align:center;padding:20px;color:#dc3232;">' + zwennpayAdmin.strings.error_loading + '</td></tr>');
                }
            });
        }

        function renderPagination($pagination, data) {
            if (data.pages > 1) {
                $pagination.show();
                $('#zwennpay-qrs-count').text(data.total + ' item' + (data.total !== 1 ? 's' : ''));

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

                $('#zwennpay-qrs-links').html(html);

                $('#zwennpay-qrs-links .page-numbers').on('click', function() {
                    var pageNum = $(this).data('page');
                    if (pageNum && !$(this).hasClass('current')) {
                        loadQRs(pageNum);
                        $('html, body').animate({ scrollTop: $('.zwennpay-history-section').offset().top - 32 }, 300);
                    }
                });
            } else {
                if (data.total > 0) {
                    $pagination.show();
                    $('#zwennpay-qrs-count').text(data.total + ' item' + (data.total !== 1 ? 's' : ''));
                    $('#zwennpay-qrs-links').html('');
                } else {
                    $pagination.hide();
                }
            }
        }

        // ----------------------------------------------------------------
        // PDF download
        // ----------------------------------------------------------------
        function downloadPreviewPDF() {
            var $downloadBtn = $('#zwennpay-download-pdf');
            var $previewBox  = $('#zwennpay-preview-box');
            var $qrDiv       = $('#zwennpay-preview-qr');

            if (!hasQRPreview || $qrDiv.is(':hidden') || $qrDiv.find('img').length === 0) {
                alert(zwennpayAdmin.strings.no_preview);
                return;
            }

            var originalText = $downloadBtn.html();
            $downloadBtn.prop('disabled', true).html(
                '<span class="spinner is-active" style="float:none;vertical-align:middle;margin-top:-2px;"></span> ' +
                zwennpayAdmin.strings.downloading
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
                var ts  = now.getFullYear() + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0') +
                          '_' + String(now.getHours()).padStart(2,'0') + String(now.getMinutes()).padStart(2,'0');
                pdf.save('ZwennPay_QR_' + ts + '.pdf');

                $downloadBtn.prop('disabled', false).html(originalText);
            }).catch(function(err) {
                console.error('PDF error:', err);
                alert(zwennpayAdmin.strings.download_error);
                $downloadBtn.prop('disabled', false).html(originalText);
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
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[m];
            });
        }
    });

})(jQuery);