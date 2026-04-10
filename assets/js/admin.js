/**
 * ZwennPay QR Admin JavaScript v1.5.0
 * Tabs switch via JS only — no page reload, no URL change.
 */
(function($) {
    'use strict';

    var currentPage   = 1;
    var hasQRPreview  = false;
    var searchTimer   = null;
    var historyLoaded = false; // load history lazily on first tab switch

    $(document).ready(function() {

        // ----------------------------------------------------------------
        // Tab switching — pure JS, zero page reload
        // ----------------------------------------------------------------
        $('.zwennpay-tab-link').on('click', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            switchTab(tab);
        });

        function switchTab(tab) {
            // Update nav
            $('.zwennpay-tab-link').removeClass('nav-tab-active');
            $('.zwennpay-tab-link[data-tab="' + tab + '"]').addClass('nav-tab-active');

            // Show/hide panels
            $('.zwennpay-tab-panel').hide();
            $('#zwennpay-tab-' + tab).show();

            // Lazy-load history on first visit to that tab
            if (tab === 'history' && !historyLoaded) {
                historyLoaded = true;
                loadTransactions(1);
            }
        }

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
        // Settings: auto-run preview on load / after save
        // ----------------------------------------------------------------
        $('#zwennpay-settings-form').on('submit', function() {
            sessionStorage.setItem('zwennpay_generate_on_load', '1');
        });

        if (sessionStorage.getItem('zwennpay_generate_on_load') === '1') {
            sessionStorage.removeItem('zwennpay_generate_on_load');
            generateQR();
        } else {
            generateQR();
        }

        $('#zwennpay-generate-preview').on('click', function() {
            $(this).prop('disabled', true).text(zwennpayAdmin.strings.generating || 'Generating...');
            generateQR();
            $('html, body').animate({ scrollTop: 0 }, 200);
        });

        $('#zwennpay-download-pdf').on('click', function() {
            downloadPreviewPDF();
        });

        // ----------------------------------------------------------------
        // History: search, filter
        // ----------------------------------------------------------------
        $('#zwennpay-tx-search').on('input', function() {
            var val = $(this).val();
            $('#zwennpay-tx-search-clear').toggle(val.length > 0);
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { loadTransactions(1); }, 400);
        });

        $('#zwennpay-tx-search-clear').on('click', function() {
            $('#zwennpay-tx-search').val('').trigger('input');
        });

        $('#zwennpay-tx-status-filter').on('change', function() {
            loadTransactions(1);
        });

        // ----------------------------------------------------------------
        // generateQR
        // ----------------------------------------------------------------
        function generateQR() {
            var $qrDiv       = $('#zwennpay-preview-qr');
            var $text        = $('#zwennpay-preview-text');
            var $logo        = $('.Zvenn-Pay-logo');
            var $downloadBtn = $('#zwennpay-download-pdf');

            $qrDiv.hide().empty();
            $('.zwennpay-merchant-info').remove();
            $text.show().text('Generating...');
            hasQRPreview = false;
            $downloadBtn.prop('disabled', true);

            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'zwennpay_preview_qr_admin', nonce: zwennpayAdmin.nonce },
                success: function(response) {
                    if (response.success && response.qr_data) {
                        $text.hide();
                        $qrDiv.show().html('<img src="' + response.qr_data + '" alt="QR Code" style="display:block;margin:0 auto;">');

                        if (response.merchant_name || response.merchant_city) {
                            var info = '<div class="zwennpay-merchant-info" style="text-align:center;margin-top:10px;font-family:sans-serif;font-size:10px;line-height:1.4;">';
                            if (response.merchant_name) info += '<strong style="display:block;">' + escapeHtml(response.merchant_name) + '</strong>';
                            if (response.merchant_city) info += '<span style="display:block;color:#666;">' + escapeHtml(response.merchant_city) + '</span>';
                            info += '</div>';
                            $logo.after(info);
                        }

                        hasQRPreview = true;
                        $downloadBtn.prop('disabled', false);
                    } else {
                        $text.html('<span style="color:red;">' + escapeHtml(response.error || 'No QR data received') + '</span>');
                    }
                    $('#zwennpay-generate-preview').prop('disabled', false).text('Preview QR');
                },
                error: function(xhr, status, error) {
                    $text.html('<span style="color:red;">Error: ' + escapeHtml(error) + '</span>');
                    $('#zwennpay-generate-preview').prop('disabled', false).text('Preview QR');
                }
            });
        }

        // ----------------------------------------------------------------
        // loadTransactions — AJAX fetch, auto-expiry happens server-side
        // ----------------------------------------------------------------
        function loadTransactions(page) {
            currentPage = page;
            var $body       = $('#zwennpay-tx-body');
            var $pagination = $('#zwennpay-tx-pagination');
            var search      = $('#zwennpay-tx-search').val()         || '';
            var statusFilter= $('#zwennpay-tx-status-filter').val()  || '';

            $body.html(
                '<tr><td colspan="5" style="text-align:center;padding:30px;">' +
                '<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> ' +
                (zwennpayAdmin.strings.loading_transactions || 'Loading...') +
                '</td></tr>'
            );

            $.ajax({
                url:  zwennpayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action:        'zwennpay_get_transactions',
                    nonce:         zwennpayAdmin.nonce,
                    page:          page,
                    search:        search,
                    status_filter: statusFilter,
                },
                success: function(response) {
                    if (!response.success) {
                        $body.html('<tr><td colspan="5" style="text-align:center;padding:20px;color:#dc3232;">' + (zwennpayAdmin.strings.error_loading || 'Error loading.') + '</td></tr>');
                        return;
                    }

                    var data = response.data;

                    if (!data.rows || data.rows.length === 0) {
                        var msg = search
                            ? 'No transactions found matching "<strong>' + escapeHtml(search) + '</strong>".'
                            : (zwennpayAdmin.strings.no_transactions || 'No transactions yet.');
                        $body.html('<tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">' + msg + '</td></tr>');
                        $pagination.hide();
                        return;
                    }

                    var html = '';
                    $.each(data.rows, function(i, row) {
                        var date   = new Date(row.created_at.replace(/-/g, '/'));
                        var amount = parseFloat(row.amount) || 0;
                        var status = row.status || 'pending';

                        var badgeStyle = 'display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;';
                        if (status === 'success')     badgeStyle += 'background:#d4edda;color:#155724;';
                        else if (status === 'failed') badgeStyle += 'background:#f8d7da;color:#721c24;';
                        else                          badgeStyle += 'background:#fff3cd;color:#856404;';

                        var label = status.charAt(0).toUpperCase() + status.slice(1);

                        html += '<tr>';
                        html += '<td><strong style="display:block;">' + formatDate(date) + '</strong><span style="color:#888;font-size:11px;">' + formatTime(date) + '</span></td>';
                        html += '<td><strong>' + escapeHtml(row.order_number) + '</strong></td>';
                        html += '<td style="font-family:monospace;font-size:12px;">' + escapeHtml(row.reference_number || '—') + '</td>';
                        html += '<td><strong>Rs ' + amount.toFixed(2) + '</strong></td>';
                        html += '<td><span style="' + badgeStyle + '">' + escapeHtml(label) + '</span></td>';
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

            if (data.pages <= 1) { $('#zwennpay-tx-links').html(''); return; }

            var html      = '';
            var start     = Math.max(1, data.current_page - 2);
            var end       = Math.min(data.pages, data.current_page + 2);

            if (data.current_page > 1) {
                html += '<a class="button page-numbers" data-page="1">&laquo;</a> ';
                html += '<a class="button page-numbers" data-page="' + (data.current_page - 1) + '">&lsaquo;</a> ';
            }
            for (var i = start; i <= end; i++) {
                if (i === data.current_page) html += '<span class="button page-numbers current">' + i + '</span> ';
                else                         html += '<a class="button page-numbers" data-page="' + i + '">' + i + '</a> ';
            }
            if (data.current_page < data.pages) {
                html += '<a class="button page-numbers" data-page="' + (data.current_page + 1) + '">&rsaquo;</a> ';
                html += '<a class="button page-numbers" data-page="' + data.pages + '">&raquo;</a> ';
            }

            $('#zwennpay-tx-links').html(html);
            $('#zwennpay-tx-links .page-numbers').on('click', function() {
                var p = $(this).data('page');
                if (p && !$(this).hasClass('current')) {
                    loadTransactions(p);
                    $('html, body').animate({ scrollTop: $('#zwennpay-tab-history').offset().top - 32 }, 300);
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

            if (!hasQRPreview || $qrDiv.is(':hidden') || !$qrDiv.find('img').length) {
                alert(zwennpayAdmin.strings.no_preview || 'Please preview a QR first.');
                return;
            }

            var origHtml = $downloadBtn.html();
            $downloadBtn.prop('disabled', true).html(
                '<span class="spinner is-active" style="float:none;vertical-align:middle;margin-top:-2px;"></span> ' +
                (zwennpayAdmin.strings.downloading || 'Preparing...')
            );

            html2canvas($previewBox[0], {
                scale: 4, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false,
                scrollX: 0, scrollY: 0,
                ignoreElements: function(el) { return el.classList && el.classList.contains('zwennpay-preview-buttons'); },
                onclone: function(doc) {
                    var b = doc.getElementById('zwennpay-preview-box');
                    if (b) { b.style.overflow = 'visible'; b.style.transform = 'none'; }
                    var c = doc.getElementById('zwennpay-preview-container');
                    if (c) c.style.overflow = 'visible';
                },
            }).then(function(canvas) {
                var jsPDF    = window.jspdf.jsPDF;
                var pw       = 210;
                var ph       = Math.max((canvas.height * pw) / canvas.width, 100);
                var pdf      = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [pw, ph] });
                pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, pw, ph);
                var now = new Date();
                var ts  = now.getFullYear() +
                          String(now.getMonth() + 1).padStart(2, '0') +
                          String(now.getDate()).padStart(2, '0') + '_' +
                          String(now.getHours()).padStart(2, '0') +
                          String(now.getMinutes()).padStart(2, '0');
                pdf.save('ZwennPay_QR_' + ts + '.pdf');
                $downloadBtn.prop('disabled', false).html(origHtml);
            }).catch(function(err) {
                console.error('PDF error:', err);
                alert(zwennpayAdmin.strings.download_error || 'PDF error.');
                $downloadBtn.prop('disabled', false).html(origHtml);
            });
        }

        // ----------------------------------------------------------------
        // Helpers
        // ----------------------------------------------------------------
        function formatDate(d) {
            var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return m[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function formatTime(d) {
            var h = d.getHours(), mi = d.getMinutes(), s = d.getSeconds();
            var ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + pad(mi) + ':' + pad(s) + ' ' + ap;
        }

        function pad(n) { return n < 10 ? '0' + n : String(n); }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }
    });

})(jQuery);