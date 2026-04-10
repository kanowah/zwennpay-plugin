<?php
/**
 * WooCommerce Payment Gateway for ZwennPay QR
 *
 * @package ZwennPay_QR
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Zwennpay extends WC_Payment_Gateway {

    /**
     * API endpoint for generating QR codes
     */
    const API_URL = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQR';

    /**
     * API endpoint for checking payment status
     */
    const STATUS_API_URL = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQRPaymentStatus';

    /**
     * Default timeout in minutes
     */
    const DEFAULT_TIMEOUT_MINUTES = 5;

    /**
     * Default check interval in seconds
     */
    const DEFAULT_INTERVAL_SECONDS = 15;

    /**
     * Gateway ID
     *
     * @var string
     */
    public $id;

    /**
     * Gateway icon
     *
     * @var string
     */
    public $icon;

    /**
     * Whether gateway has fields
     *
     * @var bool
     */
    public $has_fields;

    /**
     * Gateway method title (admin)
     *
     * @var string
     */
    public $method_title;

    /**
     * Gateway method description (admin)
     *
     * @var string
     */
    public $method_description;

    /**
     * Gateway title (checkout)
     *
     * @var string
     */
    public $title;

    /**
     * Gateway description (checkout)
     *
     * @var string
     */
    public $description;

    /**
     * Payment timeout in minutes
     *
     * @var int
     */
    public $timeout_minutes;

    /**
     * Status check interval in seconds
     *
     * @var int
     */
    public $check_interval;

    /**
     * Supported features
     *
     * @var array
     */
    public $supports;

    /**
     * Form fields for admin settings
     *
     * @var array
     */
    public $form_fields;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'zwennpay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('ZwennPay QR', 'zwennpay-qr');
        $this->method_description = __('Accept payments via ZwennPay QR code. Customers scan the QR code with their mobile banking app to pay.', 'zwennpay-qr');
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');

        error_log('Gateway enabled: ' . $this->enabled);

        // Define properties from settings
        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        $this->timeout_minutes = intval($this->get_option('timeout_minutes', self::DEFAULT_TIMEOUT_MINUTES));
        $this->check_interval  = intval($this->get_option('check_interval_seconds', self::DEFAULT_INTERVAL_SECONDS));

        // Save settings hook
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // AJAX endpoint for checking payment status
        add_action('wp_ajax_zwennpay_wc_check_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_nopriv_zwennpay_wc_check_status', array($this, 'ajax_check_payment_status'));

        // Enqueue scripts on pay page
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));

        // Display QR on pay page
        add_action('woocommerce_receipt_zwennpay', array($this, 'render_payment_page'));
        add_filter('woocommerce_available_payment_gateways', function($gateways) {
    error_log('Available gateways: ' . print_r(array_keys($gateways), true));
    return $gateways;
});
    }

    /**
     * Initialize form fields for admin settings
     */
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
            'timeout_minutes' => array(
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
            ),
            'instructions' => array(
                'title'       => __('Instructions', 'zwennpay-qr'),
                'type'        => 'textarea',
                'description' => __('Instructions shown on the payment page after order placement.', 'zwennpay-qr'),
                'default' => __("1. Open your mobile banking app
2. Scan the QR code below
3. Confirm the payment amount
4. Wait for payment confirmation", 'zwennpay-qr'),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Check if gateway is available
     * Works for both physical and virtual orders
     */
public function is_available() {
    error_log('ZwennPay is_available called');

    if (!WC()->cart) {
        error_log('No cart');
        return false;
    }

    error_log('Cart total: ' . WC()->cart->total);

    return true;
}

    /**
     * Process payment - called when order is placed
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice(__('Invalid order.', 'zwennpay-qr'), 'error');
            return array('result' => 'failure');
        }

        $amount = floatval($order->get_total());
        
        // Get ZwennPay options from main plugin
        $zwennpay_options = get_option('zwennpay_qr_options', array());

        // Build request body with order-specific data
        $body = $this->build_request_body($amount, $order_id, $order, $zwennpay_options);

        // Call API to generate QR code
        $response = $this->api_request($body);

        if (!$response['success'] || empty($response['data'])) {
            $error_msg = !empty($response['error']) ? $response['error'] : __('Unknown error', 'zwennpay-qr');
            wc_add_notice(
                sprintf(__('Failed to generate QR code: %s. Please try again or choose another payment method.', 'zwennpay-qr'), $error_msg),
                'error'
            );
            return array('result' => 'failure');
        }

        // Parse EMV data to extract reference from ID 62, sub-TLV 05
        $reference = $this->extract_reference_from_emv($response['data']);
        
        if (empty($reference)) {
            wc_add_notice(__('Failed to extract payment reference from QR code. Please contact support.', 'zwennpay-qr'), 'error');
            return array('result' => 'failure');
        }

        // Generate QR image
        $qr_image = $this->generate_qr_base64($response['data']);

        if (empty($qr_image)) {
            wc_add_notice(__('Failed to generate QR image. Please try again.', 'zwennpay-qr'), 'error');
            return array('result' => 'failure');
        }

        // Store payment data in order meta
        $order->update_meta_data('_zwennpay_qr_data', $response['data']);
        $order->update_meta_data('_zwennpay_reference', $reference);
        $order->update_meta_data('_zwennpay_qr_image', $qr_image);
        $order->update_meta_data('_zwennpay_payment_status', 'pending');
        $order->update_meta_data('_zwennpay_payment_started', current_time('mysql'));
        $order->save();

        // Set order status to pending payment
        $order->update_status('pending-payment', __('Awaiting ZwennPay QR payment', 'zwennpay-qr'));

        // Return success with redirect to payment page
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    /**
     * Enqueue scripts on WooCommerce pay page
     */
    public function enqueue_checkout_scripts() {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return;
        }

        $order_id = absint(get_query_var('order-pay'));
        $order = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        // Check if already paid
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
            'timeout'      => $this->timeout_minutes * 60 * 1000, // Convert to milliseconds
            'interval'     => $this->check_interval * 1000,        // Convert to milliseconds
            'statusApiUrl' => self::STATUS_API_URL,
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

    /**
     * Render payment page with QR code
     * Hooked to woocommerce_receipt_zwennpay
     */
    public function render_payment_page($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            echo '<div class="woocommerce-error">' . esc_html__('Invalid order.', 'zwennpay-qr') . '</div>';
            return;
        }

        // Check if already paid - redirect to thank you page
        if ($order->has_status(array('processing', 'completed', 'paid'))) {
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $qr_image  = $order->get_meta('_zwennpay_qr_image');
        $reference = $order->get_meta('_zwennpay_reference');
        $amount    = $order->get_total();
        $instructions = $this->get_option('instructions', '');

        if (empty($qr_image) || empty($reference)) {
            echo '<div class="woocommerce-error">' . 
                 esc_html__('QR code not available. The payment session may have expired. Please contact support or place a new order.', 'zwennpay-qr') . 
                 '</div>';
            return;
        }

        // Get plugin URL for logos
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        ?>
        <div id="zwennpay-wc-payment" class="zwennpay-wc-payment">
            
            <div class="zwennpay-wc-header">
                <h3><?php esc_html_e('Pay with ZwennPay QR', 'zwennpay-qr'); ?></h3>
                <p class="zwennpay-wc-order-info">
                    <?php printf(
                        /* translators: %s: order number */
                        esc_html__('Order #%s', 'zwennpay-qr'),
                        esc_html($order->get_order_number())
                    ); ?>
                </p>
            </div>

            <div class="zwennpay-wc-amount">
                <span class="zwennpay-wc-amount-label"><?php esc_html_e('Amount to Pay:', 'zwennpay-qr'); ?></span>
                <span class="zwennpay-wc-amount-value"><?php echo $order->get_formatted_order_total(); ?></span>
            </div>

            <div class="zwennpay-wc-qr-container">
                <!-- Top logo (maucas) -->
                <img src="<?php echo esc_url($plugin_url . 'assets/maucas-logo.svg'); ?>" 
                     alt="Maucas" 
                     class="zwennpay-wc-logo-top">
                
                <!-- QR Code -->
                <div class="zwennpay-wc-qr-image">
                    <img src="<?php echo esc_attr($qr_image); ?>" 
                         alt="<?php esc_attr_e('Payment QR Code', 'zwennpay-qr'); ?>">
                </div>
                
                <!-- Bottom logo (ZwennPay) -->
                <img src="<?php echo esc_url($plugin_url . 'assets/Zvenn-Pay-logo.png'); ?>" 
                     alt="ZwennPay" 
                     class="zwennpay-wc-logo-bottom">
            </div>

            <div class="zwennpay-wc-reference">
                <?php printf(
                    /* translators: %s: reference number */
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
                <p>
                    <em><?php esc_html_e('Please do not close this page or navigate away until payment is confirmed.', 'zwennpay-qr'); ?></em>
                </p>
                <p>
                    <?php printf(
                        /* translators: %s: timeout in minutes */
                        esc_html__('If payment is not received within %s minutes, the QR code will expire.', 'zwennpay-qr'),
                        esc_html($this->timeout_minutes)
                    ); ?>
                </p>
            </div>

        </div>
        <?php
    }

    /**
     * AJAX handler for checking payment status
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('zwennpay_wc_nonce', 'nonce');

        $order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $reference = isset($_POST['reference']) ? sanitize_text_field($_POST['reference']) : '';

        if (!$order_id || !$reference) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }

        // Check current status first (in case it was already processed by another request)
        $current_status = $order->get_meta('_zwennpay_payment_status');
        if ($current_status === 'paid') {
            wp_send_json_success(array(
                'status'   => 'paid',
                'redirect' => $order->get_checkout_order_received_url(),
            ));
        }

        // Check if order is already in a final paid state
        if ($order->has_status(array('processing', 'completed', 'paid'))) {
            wp_send_json_success(array(
                'status'   => 'paid',
                'redirect' => $order->get_checkout_order_received_url(),
            ));
        }

        // Call the status API
        $status_url = self::STATUS_API_URL . '?referenceLabel=' . urlencode($reference);

        $response = wp_remote_get($status_url, array(
            'timeout'   => 10,
            'sslverify' => false,
            'headers'   => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => $response->get_error_message(),
                'status'  => 'error',
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Log the API response for debugging
        $order->update_meta_data('_zwennpay_last_status_check', current_time('mysql'));
        $order->update_meta_data('_zwennpay_last_status_response', $body);
        $order->update_meta_data('_zwennpay_last_status_code', $code);
        $order->save();

        // Handle non-JSON response
        if (!$data) {
            // Try to parse as text response
            wp_send_json_error(array(
                'message' => 'Invalid response from API',
                'raw'     => substr($body, 0, 500),
                'status'  => 'error',
            ));
        }

        // Determine if payment is successful
        // Check multiple possible response formats
        $is_paid = $this->check_if_paid($data);

        if ($is_paid) {
            // Mark order as paid
            $order->update_meta_data('_zwennpay_payment_status', 'paid');
            $order->update_meta_data('_zwennpay_paid_at', current_time('mysql'));
            $order->update_meta_data('_zwennpay_api_response', $body);
            $order->save();

            // Complete the payment
            $order->payment_complete();
            $order->add_order_note(
                sprintf(
                    /* translators: %s: reference number */
                    __('ZwennPay QR payment completed successfully. Reference: %s', 'zwennpay-qr'),
                    $reference
                )
            );

            wp_send_json_success(array(
                'status'   => 'paid',
                'redirect' => $order->get_checkout_order_received_url(),
            ));
        }

        // Payment still pending
        wp_send_json_success(array(
            'status' => 'pending',
        ));
    }

    /**
     * Check if the API response indicates payment is successful
     * Handles multiple possible response formats
     */
    private function check_if_paid($data) {
        // Format 1: { "status": "SUCCESS" } or { "status": "Paid" }
        if (isset($data['status'])) {
            $status = strtoupper(trim($data['status']));
            if (in_array($status, array('SUCCESS', 'PAID', 'COMPLETED', 'CONFIRMED'))) {
                return true;
            }
        }

        // Format 2: { "paymentStatus": "SUCCESS" }
        if (isset($data['paymentStatus'])) {
            $status = strtoupper(trim($data['paymentStatus']));
            if (in_array($status, array('SUCCESS', 'PAID', 'COMPLETED', 'CONFIRMED'))) {
                return true;
            }
        }

        // Format 3: { "paymentStatus": "1" } or { "status": 1 }
        if (isset($data['paymentStatus']) && intval($data['paymentStatus']) === 1) {
            return true;
        }
        if (isset($data['status']) && intval($data['status']) === 1) {
            return true;
        }

        // Format 4: { "isPaid": true }
        if (isset($data['isPaid']) && $data['isPaid'] === true) {
            return true;
        }

        // Format 5: { "success": true }
        if (isset($data['success']) && $data['success'] === true) {
            return true;
        }

        // Format 6: { "transactionStatus": "SUCCESS" }
        if (isset($data['transactionStatus'])) {
            $status = strtoupper(trim($data['transactionStatus']));
            if (in_array($status, array('SUCCESS', 'PAID', 'COMPLETED', 'CONFIRMED'))) {
                return true;
            }
        }

        // Format 7: { "result": "SUCCESS" }
        if (isset($data['result'])) {
            $result = strtoupper(trim($data['result']));
            if (in_array($result, array('SUCCESS', 'PAID', 'COMPLETED', 'CONFIRMED'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build API request body for QR generation
     */
    private function build_request_body($amount, $order_id, $order, $options) {
        $tip      = floatval($options['convenience_tip'] ?? 0);
        $feeFixed = floatval($options['convenience_fee_fixed'] ?? 0);
        $feePct   = floatval($options['convenience_fee_percentage'] ?? 0);
        $bill     = !empty($options['bill_number']) ? $options['bill_number'] : strval($order_id);
        $mobile   = $options['mobile_no'] ?? '';
        $store    = $options['store_label'] ?? '';
        $loyalty  = $options['loyalty_number'] ?? '';
        $customer = !empty($order->get_billing_first_name()) ? $order->get_billing_first_name() : '';
        $customer .= !empty($order->get_billing_last_name()) ? ' ' . $order->get_billing_last_name() : '';
        $customer = trim($customer);
        $terminal = $options['terminal_label'] ?? '';
        $purpose  = !empty($options['purpose_transaction']) ? $options['purpose_transaction'] : 'Order #' . $order_id;

        return array(
            "MerchantId"                           => absint($options['merchant_id']),
            "SetTransactionAmount"                 => $amount > 0,
            "TransactionAmount"                    => $amount > 0 ? $amount : 0,
            "SetConvenienceIndicatorTip"            => $tip > 0,
            "ConvenienceIndicatorTip"               => $tip > 0 ? $tip : 0,
            "SetConvenienceFeeFixed"               => $feeFixed > 0,
            "ConvenienceFeeFixed"                  => $feeFixed > 0 ? $feeFixed : 0,
            "SetConvenienceFeePercentage"          => $feePct > 0,
            "ConvenienceFeePercentage"             => $feePct > 0 ? $feePct : 0,
            "SetAdditionalBillNumber"              => true,
            "AdditionalRequiredBillNumber"         => false,
            "AdditionalBillNumber"                 => $bill,
            "SetAdditionalMobileNo"                => !empty($mobile),
            "AdditionalRequiredMobileNo"           => false,
            "AdditionalMobileNo"                   => !empty($mobile) ? $mobile : "string",
            "SetAdditionalStoreLabel"              => !empty($store),
            "AdditionalRequiredStoreLabel"         => false,
            "AdditionalStoreLabel"                 => !empty($store) ? $store : "string",
            "SetAdditionalLoyaltyNumber"           => !empty($loyalty),
            "AdditionalRequiredLoyaltyNumber"      => false,
            "AdditionalLoyaltyNumber"              => !empty($loyalty) ? $loyalty : "string",
            // Reference label is NOT set - it's auto-generated by the server in ID 62
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

    /**
     * Make API request to generate QR code
     */
    private function api_request($body) {
        $response = wp_remote_post(self::API_URL, array(
            'timeout'     => 30,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'text/plain',
            ),
            'body'        => json_encode($body),
            'sslverify'   => false,
            'httpversion' => '1.1',
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'data'    => '',
                'error'   => $response->get_error_message(),
            );
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

    /**
     * Parse EMV TLV data
     */
    private function parse_emv_data($qr_string) {
        $data = array();
        $i    = 0;
        $len  = strlen($qr_string);

        while ($i < $len) {
            if ($i + 4 > $len) break;
            
            $id = substr($qr_string, $i, 2);
            $i += 2;
            
            $length_str = substr($qr_string, $i, 2);
            if (!is_numeric($length_str)) break;
            
            $value_len = intval($length_str);
            $i += 2;
            
            if ($i + $value_len > $len) break;
            
            $value = substr($qr_string, $i, $value_len);
            $i += $value_len;
            
            $data[$id] = $value;
        }

        return $data;
    }

    /**
     * Extract reference from EMV data
     * Looks for ID 62, then parses sub-TLV to find ID 05 (reference label)
     *
     * Example EMV structure:
     * 62 31
     *     02 08 52578665
     *     05 15 ZPMQR0000216738
     */
    private function extract_reference_from_emv($qr_string) {
        $emv_data = $this->parse_emv_data($qr_string);

        // Check if ID 62 exists
        if (!isset($emv_data['62'])) {
            return '';
        }

        // Parse sub-TLV data within ID 62
        $sub_data = $this->parse_emv_data($emv_data['62']);

        // Sub-TLV 05 contains the reference label
        if (isset($sub_data['05'])) {
            return $sub_data['05'];
        }

        return '';
    }

    /**
     * Generate base64-encoded QR code image
     */
    private function generate_qr_base64($text, $size = 300) {
        // Include phpqrcode library
        include_once plugin_dir_path(dirname(__FILE__)) . 'includes/phpqrcode/qrlib.php';

        $internal_size = max(600, intval($size) * 2);
        $module_size   = max(8, intval(round($internal_size / 60)));

        $temp_file = tempnam(sys_get_temp_dir(), 'zwqr_');
        
        if (!$temp_file) {
            return '';
        }

        try {
            \QRcode::png($text, $temp_file, QR_ECLEVEL_L, $module_size, 2);
            $image_data = file_get_contents($temp_file);
        } catch (Exception $e) {
            $image_data = '';
        }

        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }

        if (empty($image_data)) {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($image_data);
    }

    /**
     * Process refund (optional support)
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('error', __('Invalid order.', 'zwennpay-qr'));
        }
        
        $order->add_order_note(
            sprintf(
                /* translators: %1$s: refund amount, %2$s: refund reason */
                __('Refund of %1$s requested. Reason: %2$s. Process this refund through the ZwennPay dashboard.', 'zwennpay-qr'),
                $amount ? wc_price($amount) : wc_price($order->get_total()),
                $reason
            )
        );

        return true;
    }

    public function payment_fields() {
    if ($this->description) {
        echo '<p>' . esc_html($this->description) . '</p>';
    } else {
        echo '<p>Pay using ZwennPay QR</p>';
    }
}
}