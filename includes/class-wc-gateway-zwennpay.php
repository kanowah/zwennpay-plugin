<?php
/**
 * WooCommerce Payment Gateway for ZwennPay QR
 *
 * @package ZwennPay_QR
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Zwennpay extends WC_Payment_Gateway {

    const API_URL            = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQR';
    const STATUS_API_URL     = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQRPaymentStatus';
    const DEFAULT_TIMEOUT_MINUTES   = 5;
    const DEFAULT_INTERVAL_SECONDS  = 15;

    public $id;
    public $icon;
    public $has_fields;
    public $method_title;
    public $method_description;
    public $title;
    public $description;
    public $timeout_minutes;
    public $check_interval;
    public $supports;
    public $form_fields;

    public function __construct() {
        $this->id                 = 'zwennpay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('ZwennPay QR', 'zwennpay-qr');
        $this->method_description = __('Accept payments via ZwennPay QR code. Customers scan the QR code with their mobile banking app to pay.', 'zwennpay-qr');
        $this->supports           = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled         = $this->get_option('enabled');
        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        /*$this->timeout_minutes = apply_filters('zwennpay_timeout', self::DEFAULT_TIMEOUT_MINUTES);
        $this->check_interval  = apply_filters('zwennpay_interval', self::DEFAULT_INTERVAL_SECONDS);*/
        $this->timeout_minutes = self::DEFAULT_TIMEOUT_MINUTES;
        $this->check_interval  = self::DEFAULT_INTERVAL_SECONDS;

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_ajax_zwennpay_wc_confirm_payment',        array($this, 'ajax_confirm_payment'));
        add_action('wp_ajax_nopriv_zwennpay_wc_confirm_payment', array($this, 'ajax_confirm_payment'));
        add_action('wp_enqueue_scripts',                       array($this, 'enqueue_checkout_scripts'));
        add_action('woocommerce_receipt_zwennpay',             array($this, 'render_payment_page'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'zwennpay-qr'),
                'type'    => 'checkbox',
                'label'   => __('Enable ZwennPay QR Payment', 'zwennpay-qr'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'zwennpay-qr'),
                'type'        => 'text',
                'description' => __('Payment method title that the customer sees on checkout.', 'zwennpay-qr'),
                'default'     => __('ZwennPay QR', 'zwennpay-qr'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'zwennpay-qr'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer sees on checkout.', 'zwennpay-qr'),
                'default'     => __('Scan the QR code with your mobile banking app to complete payment.', 'zwennpay-qr'),
                'desc_tip'    => true,
            ),
           /* 'timeout_minutes' => array(
                'title'       => __('Payment Timeout (minutes)', 'zwennpay-qr'),
                'type'        => 'number',
                'description' => __('How long to wait for payment before timing out.', 'zwennpay-qr'),
                'default'     => self::DEFAULT_TIMEOUT_MINUTES,
                'min'         => 1,
                'max'         => 30,
                'desc_tip'    => true,
            ),
            'check_interval_seconds' => array(
                'title'       => __('Status Check Interval (seconds)', 'zwennpay-qr'),
                'type'        => 'number',
                'description' => __('How often to check payment status with the API.', 'zwennpay-qr'),
                'default'     => self::DEFAULT_INTERVAL_SECONDS,
                'min'         => 5,
                'max'         => 60,
                'desc_tip'    => true,
            ),*/
            'instructions' => array(
                'title'       => __('Instructions', 'zwennpay-qr'),
                'type'        => 'textarea',
                'description' => __('Instructions shown on the payment page after order placement.', 'zwennpay-qr'),
                'default' => __("Open your mobile banking app
Scan the QR code below
Confirm the payment amount
Wait for payment confirmation", 'zwennpay-qr'),
                'desc_tip'    => true,
            ),
        );
    }

    public function is_available() {
        if (!WC()->cart) {
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Process payment
    // -------------------------------------------------------------------------

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Invalid order.', 'zwennpay-qr'), 'error');
            return array('result' => 'failure');
        }

        $amount       = floatval($order->get_total());
        $order_number = $order->get_order_number();   // Human-readable order number

        // Get ZwennPay plugin options
        $zwennpay_options = get_option('zwennpay_qr_options', array());

        // Build API request — order number is used as bill number
        $body = $this->build_request_body($amount, $order_number, $order, $zwennpay_options);

        $response = $this->api_request($body);

        if (!$response['success'] || empty($response['data'])) {
            $error_msg = !empty($response['error']) ? $response['error'] : __('Unknown error', 'zwennpay-qr');
            wc_add_notice(
                sprintf(__('Failed to generate QR code: %s. Please try again or choose another payment method.', 'zwennpay-qr'), $error_msg),
                'error'
            );

            // Log failed transaction
            $this->record_transaction($order_number, $order_number, '', $amount, 'failed');

            return array('result' => 'failure');
        }

        // Extract reference from EMV ID 62 → sub-TLV 05
        $reference = $this->extract_reference_from_emv($response['data']);

        if (empty($reference)) {
            wc_add_notice(__('Failed to extract payment reference from QR code. Please contact support.', 'zwennpay-qr'), 'error');

            $this->record_transaction($order_number, $order_number, '', $amount, 'failed');

            return array('result' => 'failure');
        }

        $qr_image = $this->generate_qr_base64($response['data']);

        if (empty($qr_image)) {
            wc_add_notice(__('Failed to generate QR image. Please try again.', 'zwennpay-qr'), 'error');

            $this->record_transaction($order_number, $order_number, $reference, $amount, 'failed');

            return array('result' => 'failure');
        }

        // Persist QR data on the order
        $order->update_meta_data('_zwennpay_qr_data',         $response['data']);
        $order->update_meta_data('_zwennpay_reference',        $reference);
        $order->update_meta_data('_zwennpay_qr_image',         $qr_image);
        $order->update_meta_data('_zwennpay_payment_status',   'pending');
        $order->update_meta_data('_zwennpay_payment_started',  current_time('mysql'));
        $order->save();

        $order->update_status('pending-payment', __('Awaiting ZwennPay QR payment', 'zwennpay-qr'));

        // Log pending transaction
        $this->record_transaction($order_number, $order_number, $reference, $amount, 'pending');

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    // -------------------------------------------------------------------------
    // Enqueue scripts
    // -------------------------------------------------------------------------

    public function enqueue_checkout_scripts() {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return;
        }

        $order_id = absint(get_query_var('order-pay'));
        $order    = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        if ($order->has_status(array('processing', 'completed', 'paid'))) {
            return;
        }

        $reference = $order->get_meta('_zwennpay_reference');

        if (empty($reference)) {
            return;
        }

        wp_enqueue_script(
            'zwennpay-wc-checkout',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/wc-zwennpay.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_enqueue_style(
            'zwennpay-wc-checkout',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/wc-zwennpay.css',
            array(),
            '1.0.0'
        );

        wp_localize_script('zwennpay-wc-checkout', 'zwennpayWc', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('zwennpay_wc_nonce'),
            'orderId'      => $order_id,
            'reference'    => $reference,
            'timeout'      => $this->timeout_minutes * 60 * 1000,
            'interval'     => $this->check_interval * 1000,
            'statusApiUrl' => self::STATUS_API_URL,  // polled directly by browser
            'strings'      => array(
                'checking'       => __('Checking payment status...', 'zwennpay-qr'),
                'paid'           => __('Payment received! Redirecting...', 'zwennpay-qr'),
                'timeout'        => __('Payment timeout. Please try again or contact support.', 'zwennpay-qr'),
                'error'          => __('Error checking payment status. Retrying...', 'zwennpay-qr'),
                'scan_qr'        => __('Scan the QR code with your mobile banking app', 'zwennpay-qr'),
                'waiting'        => __('Waiting for payment...', 'zwennpay-qr'),
                'time_remaining' => __('Time remaining', 'zwennpay-qr'),
                'minutes'        => __('min', 'zwennpay-qr'),
                'seconds'        => __('sec', 'zwennpay-qr'),
                'leave_warning'  => __('Payment is in progress. Are you sure you want to leave?', 'zwennpay-qr'),
            ),
        ));
    }

    // -------------------------------------------------------------------------
    // Render payment page
    // -------------------------------------------------------------------------

    public function render_payment_page($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            echo '<div class="woocommerce-error">' . esc_html__('Invalid order.', 'zwennpay-qr') . '</div>';
            return;
        }

        if ($order->has_status(array('processing', 'completed', 'paid'))) {
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $qr_image     = $order->get_meta('_zwennpay_qr_image');
        $reference    = $order->get_meta('_zwennpay_reference');
        $amount       = $order->get_total();
        $instructions = $this->get_option('instructions', '');

        if (empty($qr_image) || empty($reference)) {
            echo '<div class="woocommerce-error">' .
                 esc_html__('QR code not available. The payment session may have expired. Please contact support or place a new order.', 'zwennpay-qr') .
                 '</div>';
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));
        ?>
        <div id="zwennpay-wc-payment" class="zwennpay-wc-payment">

            <div class="zwennpay-wc-header">
                <h3><?php esc_html_e('Scan to Pay', 'zwennpay-qr'); ?></h3>
                <p class="zwennpay-wc-order-info">
                    <?php printf(esc_html__('Order #%s', 'zwennpay-qr'), esc_html($order->get_order_number())); ?>
                </p>
            </div>

            <div class="zwennpay-wc-amount">
                <span class="zwennpay-wc-amount-label"><?php esc_html_e('Amount to Pay:', 'zwennpay-qr'); ?></span>
                <span class="zwennpay-wc-amount-value"><?php echo $order->get_formatted_order_total(); ?></span>
            </div>

            <div class="zwennpay-wc-qr-container">
                <img src="<?php echo esc_url($plugin_url . 'assets/maucas-logo.svg'); ?>"
                     alt="Maucas"
                     class="zwennpay-wc-logo-top">

                <div class="zwennpay-wc-qr-image">
                    <img src="<?php echo esc_attr($qr_image); ?>"
                         alt="<?php esc_attr_e('Payment QR Code', 'zwennpay-qr'); ?>">
                </div>

                <img src="<?php echo esc_url($plugin_url . 'assets/Zvenn-Pay-logo.png'); ?>"
                     alt="ZwennPay"
                     class="zwennpay-wc-logo-bottom">
            </div>

            <div class="zwennpay-wc-reference" style="display:none;">
                <?php printf(
                    esc_html__('Reference: %s', 'zwennpay-qr'),
                    '<strong>' . esc_html($reference) . '</strong>'
                ); ?>
            </div>

            <?php if (!empty($instructions)) : ?>
                <div class="zwennpay-wc-instructions">
                    <h4><?php esc_html_e('How to Pay:', 'zwennpay-qr'); ?></h4>
                    <ol>
                        <?php foreach (explode("\n", $instructions) as $line) :
                            $line = trim($line);
                            if (!empty($line)) : ?>
                                <li><?php echo esc_html($line); ?></li>
                            <?php endif;
                        endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <div class="zwennpay-wc-status">
                <div class="zwennpay-wc-status-icon">
                    <span class="zwennpay-wc-spinner"></span>
                </div>
                <p class="zwennpay-wc-status-text zwennpay-wc-status-waiting">
                    <?php esc_html_e('Waiting for payment...', 'zwennpay-qr'); ?>
                </p>
                <div class="zwennpay-wc-timer">
                    <span class="zwennpay-wc-timer-label"><?php esc_html_e('Time remaining:', 'zwennpay-qr'); ?></span>
                    <span id="zwennpay-time-remaining" class="zwennpay-wc-timer-value">
                        <?php echo esc_html($this->timeout_minutes); ?>:00
                    </span>
                </div>
            </div>

            <div class="zwennpay-wc-footer-note">
                <p><em><?php esc_html_e('Please do not close this page or navigate away until payment is confirmed.', 'zwennpay-qr'); ?></em></p>
                <p><?php printf(
                    esc_html__('If payment is not received within %s minutes, the QR code will expire.', 'zwennpay-qr'),
                    esc_html($this->timeout_minutes)
                ); ?></p>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: confirm payment (called by browser after ZwennPay API says paid)
    // -------------------------------------------------------------------------

    /**
     * Called once by the browser when the direct ZwennPay status poll returns
     * ResponseCode "00". Marks the WC order as paid and returns the redirect URL.
     * No outbound API call is made here — the browser already confirmed payment.
     */
    public function ajax_confirm_payment() {
        check_ajax_referer('zwennpay_wc_nonce', 'nonce');

        $order_id  = isset($_POST['order_id'])  ? absint($_POST['order_id'])                    : 0;
        $reference = isset($_POST['reference']) ? sanitize_text_field($_POST['reference'])      : '';

        if (!$order_id || !$reference) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }

        // Idempotent: if already paid just return the redirect URL
        if ($order->has_status(array('processing', 'completed', 'paid')) ||
            $order->get_meta('_zwennpay_payment_status') === 'paid') {
            wp_send_json_success(array(
                'status'   => 'paid',
                'redirect' => $order->get_checkout_order_received_url(),
            ));
        }

        // Mark order as paid
        $order->update_meta_data('_zwennpay_payment_status', 'paid');
        $order->update_meta_data('_zwennpay_paid_at',        current_time('mysql'));
        $order->save();

        $order->payment_complete($reference);
        $order->add_order_note(sprintf(
            __('ZwennPay QR payment completed. Reference: %s', 'zwennpay-qr'),
            $reference
        ));

        // Update transaction history
        $this->record_transaction(
            $order->get_order_number(),
            $order->get_order_number(),
            $reference,
            floatval($order->get_total()),
            'success'
        );

        wp_send_json_success(array(
            'status'   => 'paid',
            'redirect' => $order->get_checkout_order_received_url(),
        ));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Record (insert or update) a transaction in the history table.
     */
    private function record_transaction($order_number, $bill_number, $reference_number, $amount, $status) {
        if (isset($GLOBALS['zwennpay_qr_instance']) && method_exists($GLOBALS['zwennpay_qr_instance'], 'log_transaction')) {
            $GLOBALS['zwennpay_qr_instance']->log_transaction($order_number, $bill_number, $reference_number, $amount, $status);
        }
    }

    /**
     * Build API request body.
     *
     * The order number is used as both the bill number AND passed as
     * AdditionalBillNumber. SetAdditionalBillNumber and
     * AdditionalRequiredBillNumber are ALWAYS true.
     *
     * Store label flags are driven by whether the stored option is non-empty.
     *
     * @param float  $amount
     * @param string $order_number  WC order number (used as bill number)
     * @param WC_Order $order
     * @param array  $options       ZwennPay plugin options
     */
    private function build_request_body($amount, $order_number, $order, $options) {
        $tip      = floatval($options['convenience_tip']            ?? 0);
        $feeFixed = floatval($options['convenience_fee_fixed']      ?? 0);
        $feePct   = floatval($options['convenience_fee_percentage'] ?? 0);
        $mobile   = $options['mobile_no']     ?? '';
        $store    = $options['store_label']   ?? '';
        $loyalty  = $options['loyalty_number']?? '';
        $terminal = $options['terminal_label']?? '';
        $purpose  = !empty($options['purpose_transaction'])
                    ? $options['purpose_transaction']
                    : 'Order #' . $order_number;

        // Customer label from billing name
        $customer = trim(
            ($order->get_billing_first_name() ?? '') . ' ' .
            ($order->get_billing_last_name()  ?? '')
        );

        return array(
            "MerchantId"                           => absint($options['merchant_id'] ?? 0),

            "SetTransactionAmount"                 => $amount > 0,
            "TransactionAmount"                    => $amount > 0 ? $amount : 0,

            "SetConvenienceIndicatorTip"            => $tip > 0,
            "ConvenienceIndicatorTip"               => $tip > 0 ? $tip : 0,

            "SetConvenienceFeeFixed"               => $feeFixed > 0,
            "ConvenienceFeeFixed"                  => $feeFixed > 0 ? $feeFixed : 0,

            "SetConvenienceFeePercentage"          => $feePct > 0,
            "ConvenienceFeePercentage"             => $feePct > 0 ? $feePct : 0,

            // ── Bill Number = Order Number ──────────────────────────────────
            // Both Set and Required are ALWAYS true so the API enforces it.
            "SetAdditionalBillNumber"              => true,
            "AdditionalRequiredBillNumber"         => true,
            "AdditionalBillNumber"                 => strval($order_number),

            "SetAdditionalMobileNo"                => !empty($mobile),
            "AdditionalRequiredMobileNo"           => false,
            "AdditionalMobileNo"                   => !empty($mobile) ? $mobile : "string",

            // ── Store Label: driven by option value ─────────────────────────
            "SetAdditionalStoreLabel"              => !empty($store),
            "AdditionalRequiredStoreLabel"         => !empty($store),
            "AdditionalStoreLabel"                 => !empty($store) ? $store : "string",

            "SetAdditionalLoyaltyNumber"           => !empty($loyalty),
            "AdditionalRequiredLoyaltyNumber"      => false,
            "AdditionalLoyaltyNumber"              => !empty($loyalty) ? $loyalty : "string",

            // Reference label NOT set — auto-generated by server in ID 62
            "SetAdditionalReferenceLabel"          => false,
            "AdditionalRequiredReferenceLabel"     => false,
            "AdditionalReferenceLabel"             => "string",

            "SetAdditionalCustomerLabel"           => !empty($customer),
            "AdditionalRequiredCustomerLabel"      => false,
            "AdditionalCustomerLabel"              => !empty($customer) ? $customer : "string",

            "SetAdditionalTerminalLabel"           => !empty($terminal),
            "AdditionalRequiredTerminalLabel"      => false,
            "AdditionalTerminalLabel"              => !empty($terminal) ? $terminal : "string",

            "SetAdditionalPurposeTransaction"      => true,
            "AdditionalRequiredPurposeTransaction" => false,
            "AdditionalPurposeTransaction"         => $purpose,
        );
    }

    private function api_request($body) {
        $response = wp_remote_post(self::API_URL, array(
            'timeout'     => 30,
            'headers'     => array('Content-Type' => 'application/json', 'Accept' => 'text/plain'),
            'body'        => json_encode($body),
            'sslverify'   => false,
            'httpversion' => '1.1',
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'data' => '', 'error' => $response->get_error_message());
        }

        $code          = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $is_success    = ($code === 200 && !empty($response_body));

        return array(
            'success' => $is_success,
            'data'    => $response_body,
            'error'   => !$is_success ? ($code !== 200 ? "HTTP $code" : 'Empty response from API') : '',
        );
    }

    private function parse_emv_data($qr_string) {
        $data = array();
        $i    = 0;
        $len  = strlen($qr_string);

        while ($i < $len) {
            if ($i + 4 > $len) break;
            $id         = substr($qr_string, $i, 2); $i += 2;
            $length_str = substr($qr_string, $i, 2);
            if (!is_numeric($length_str)) break;
            $value_len  = intval($length_str); $i += 2;
            if ($i + $value_len > $len) break;
            $data[$id]  = substr($qr_string, $i, $value_len);
            $i         += $value_len;
        }

        return $data;
    }

    /**
     * Extract reference label from EMV field 62, sub-TLV 05.
     */
    private function extract_reference_from_emv($qr_string) {
        $emv_data = $this->parse_emv_data($qr_string);

        if (!isset($emv_data['62'])) {
            return '';
        }

        $sub_data = $this->parse_emv_data($emv_data['62']);

        return $sub_data['05'] ?? '';
    }

    private function generate_qr_base64($text, $size = 300) {
        include_once plugin_dir_path(dirname(__FILE__)) . 'includes/phpqrcode/qrlib.php';

        $internal_size = max(600, intval($size) * 2);
        $module_size   = max(8, intval(round($internal_size / 60)));
        $temp_file     = tempnam(sys_get_temp_dir(), 'zwqr_');

        if (!$temp_file) return '';

        try {
            \QRcode::png($text, $temp_file, QR_ECLEVEL_L, $module_size, 2);
            $image_data = file_get_contents($temp_file);
        } catch (Exception $e) {
            $image_data = '';
        }

        if (file_exists($temp_file)) unlink($temp_file);

        if (empty($image_data)) return '';

        return 'data:image/png;base64,' . base64_encode($image_data);
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('error', __('Invalid order.', 'zwennpay-qr'));

        $order->add_order_note(sprintf(
            __('Refund of %1$s requested. Reason: %2$s. Process this refund through the ZwennPay dashboard.', 'zwennpay-qr'),
            $amount ? wc_price($amount) : wc_price($order->get_total()),
            $reason
        ));

        return true;
    }

    public function payment_fields() {
        echo '<p>' . esc_html($this->description ?: __('Scan to Pay', 'zwennpay-qr')) . '</p>';
    }
}