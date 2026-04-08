<?php
/**
 * Plugin Name: ZwennPay QR Generator
 * Plugin URI: https://zwennpay.com/
 * Description: ZwennPay QR plugin for WordPress
 * Version: 1.0.0
 * Author: ZwennPay
 * License: GPL v2 or later
 * Text Domain: zwennpay-qr
 */
include_once plugin_dir_path(__FILE__) . '/includes/phpqrcode/qrlib.php';
if (!defined('ABSPATH')) {
    exit;
}

class ZwennPay_QR_Generator {

    const API_URL = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQR';
    const OPTION_NAME = 'zwennpay_qr_options';
    const PREVIEW_COUNT_OPTION = 'zwennpay_qr_preview_count';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_shortcode('zwennpay_qr', array($this, 'render_shortcode'));
        add_action('wp_ajax_zwennpay_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_zwennpay_test_original', array($this, 'ajax_test_original'));
        add_action('wp_ajax_zwennpay_generate_qr_admin', array($this, 'ajax_generate_qr_admin'));
        add_action('wp_ajax_zwennpay_generate_qr', array($this, 'ajax_generate_qr'));
        add_action('wp_ajax_nopriv_zwennpay_generate_qr', array($this, 'ajax_generate_qr'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_links'));
    }

    public function add_plugin_links($links) {
        $settings_link = '<a href="options-general.php?page=zwennpay-qr-settings">' . __('Settings', 'zwennpay-qr') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_admin_menu() {
        add_options_page(
            __('ZwennPay QR Settings', 'zwennpay-qr'),
            __('ZwennPay QR', 'zwennpay-qr'),
            'manage_options',
            'zwennpay-qr-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            'zwennpay_qr_options_group',
            self::OPTION_NAME,
            array($this, 'sanitize_options')
        );

        add_settings_section('zwennpay_qr_main_section', __('Main Settings', 'zwennpay-qr'), array($this, 'render_section_info'), 'zwennpay-qr-settings');
        add_settings_field('merchant_id', __('Merchant ID', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_main_section', 'merchant_id');
        add_settings_field('transaction_amount', __('Default Transaction Amount', 'zwennpay-qr'), array($this, 'render_number_field'), 'zwennpay-qr-settings', 'zwennpay_qr_main_section', 'transaction_amount');

        add_settings_section('zwennpay_qr_fee_section', __('', 'zwennpay-qr'), array($this, 'render_section_info'), 'zwennpay-qr-settings');
        add_settings_field('convenience_tip', __('Convenience Tip', 'zwennpay-qr'), array($this, 'render_number_field'), 'zwennpay-qr-settings', 'zwennpay_qr_fee_section', 'convenience_tip');
        add_settings_field('convenience_fee_fixed', __('Fixed Fee', 'zwennpay-qr'), array($this, 'render_number_field'), 'zwennpay-qr-settings', 'zwennpay_qr_fee_section', 'convenience_fee_fixed');
        add_settings_field('convenience_fee_percentage', __('Percentage Fee', 'zwennpay-qr'), array($this, 'render_number_field'), 'zwennpay-qr-settings', 'zwennpay_qr_fee_section', 'convenience_fee_percentage');

        add_settings_section('zwennpay_qr_additional_section', __('', 'zwennpay-qr'), array($this, 'render_additional_section_info'), 'zwennpay-qr-settings');
        add_settings_field('bill_number', __('Bill Number', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'bill_number');
        add_settings_field('mobile_no', __('Mobile Number', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'mobile_no');
        add_settings_field('store_label', __('Store Label', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'store_label');
        add_settings_field('loyalty_number', __('Loyalty Number', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'loyalty_number');
        add_settings_field('customer_label', __('Customer Label', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'customer_label');
        add_settings_field('terminal_label', __('Terminal Label', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'terminal_label');
        add_settings_field('purpose_transaction', __('Purpose of Transaction', 'zwennpay-qr'), array($this, 'render_text_field'), 'zwennpay-qr-settings', 'zwennpay_qr_additional_section', 'purpose_transaction');
    }

    public function render_additional_section_info() {}

    public function sanitize_options($input) {
        $new_input = array();
        $old_options = get_option(self::OPTION_NAME);

        // Merchant ID: only number up to 1000
        $val = isset($input['merchant_id']) ? $input['merchant_id'] : 0;
        if (is_numeric($val) && $val > 0 && $val <= 1000) {
            $new_input['merchant_id'] = absint($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'merchant_id_error', 'Merchant ID must be a number up to 1000.', 'error');
            $new_input['merchant_id'] = isset($old_options['merchant_id']) ? $old_options['merchant_id'] : 0;
        }

        // Default Transaction Amount: only number up to 250,000
        $val = isset($input['transaction_amount']) ? $input['transaction_amount'] : 0;
        if (is_numeric($val) && $val <= 250000) {
            $new_input['transaction_amount'] = floatval($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'transaction_amount_error', 'Default Transaction Amount must be a number up to 250,000.', 'error');
            $new_input['transaction_amount'] = isset($old_options['transaction_amount']) ? $old_options['transaction_amount'] : 0;
        }

        // Convenience Tip: only number up to 1000
        $val = isset($input['convenience_tip']) ? $input['convenience_tip'] : 0;
        if (is_numeric($val) && $val <= 1000) {
            $new_input['convenience_tip'] = floatval($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'convenience_tip_error', 'Convenience Tip must be a number up to 1000.', 'error');
            $new_input['convenience_tip'] = isset($old_options['convenience_tip']) ? $old_options['convenience_tip'] : 0;
        }

        // Fixed Fee: only number up to 1000
        $val = isset($input['convenience_fee_fixed']) ? $input['convenience_fee_fixed'] : 0;
        if (is_numeric($val) && $val <= 1000) {
            $new_input['convenience_fee_fixed'] = floatval($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'fixed_fee_error', 'Fixed Fee must be a number up to 1000.', 'error');
            $new_input['convenience_fee_fixed'] = isset($old_options['convenience_fee_fixed']) ? $old_options['convenience_fee_fixed'] : 0;
        }

        // Percentage Fee: only number up to 100
        $val = isset($input['convenience_fee_percentage']) ? $input['convenience_fee_percentage'] : 0;
        if (is_numeric($val) && $val <= 100) {
            $new_input['convenience_fee_percentage'] = floatval($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'percentage_fee_error', 'Percentage Fee must be a number up to 100.', 'error');
            $new_input['convenience_fee_percentage'] = isset($old_options['convenience_fee_percentage']) ? $old_options['convenience_fee_percentage'] : 0;
        }

        // Bill Number: only number up to 100000
        $val = isset($input['bill_number']) ? $input['bill_number'] : '';
        if (empty($val) || (is_numeric($val) && $val <= 100000)) {
            $new_input['bill_number'] = sanitize_text_field($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'bill_number_error', 'Bill Number must be a number up to 100000.', 'error');
            $new_input['bill_number'] = isset($old_options['bill_number']) ? $old_options['bill_number'] : '';
        }

        // Mobile Number: only 8 numbers
        $val = isset($input['mobile_no']) ? $input['mobile_no'] : '';
        if (empty($val) || (preg_match('/^\d{8}$/', $val))) {
            $new_input['mobile_no'] = sanitize_text_field($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'mobile_no_error', 'Mobile Number must be exactly 8 numbers.', 'error');
            $new_input['mobile_no'] = isset($old_options['mobile_no']) ? $old_options['mobile_no'] : '';
        }

        // Store Label: only string text
        $new_input['store_label'] = sanitize_text_field($input['store_label']);

        // Loyalty number: only number up to 10000
        $val = isset($input['loyalty_number']) ? $input['loyalty_number'] : '';
        if (empty($val) || (is_numeric($val) && $val <= 10000)) {
            $new_input['loyalty_number'] = sanitize_text_field($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'loyalty_number_error', 'Loyalty Number must be a number up to 10000.', 'error');
            $new_input['loyalty_number'] = isset($old_options['loyalty_number']) ? $old_options['loyalty_number'] : '';
        }

        // Reference Label
        $new_input['reference_label'] = sanitize_text_field($input['reference_label']);

        // Customer Label: only string text
        $new_input['customer_label'] = sanitize_text_field($input['customer_label']);

        // Terminal Label: only string text
        $new_input['terminal_label'] = sanitize_text_field($input['terminal_label']);

        // Purpose of transaction: short description string only
        $new_input['purpose_transaction'] = sanitize_text_field($input['purpose_transaction']);

        // Other settings
        $new_input['qr_size'] = absint($input['qr_size']);
        $new_input['qr_color'] = sanitize_hex_color($input['qr_color']);
        $new_input['show_amount'] = isset($input['show_amount']) ? 1 : 0;

        return $new_input;
    }

    public function get_options() {
        $defaults = array(
            'merchant_id' => 0,
            'transaction_amount' => 0,
            'reference_label' => '',
            'bill_number' => '',
            'mobile_no' => '',
            'store_label' => '',
            'loyalty_number' => '',
            'customer_label' => '',
            'terminal_label' => '',
            'purpose_transaction' => '',
            'convenience_tip' => 0,
            'convenience_fee_fixed' => 0,
            'convenience_fee_percentage' => 0,
            'qr_size' => 256,
            'qr_color' => '#000000',
            'show_amount' => 1,
        );
        return wp_parse_args(get_option(self::OPTION_NAME, array()), $defaults);
    }

    private function parse_emv_data($qr_string) {
        $data = array();
        $i = 0;
        $len = strlen($qr_string);
        
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

    public function build_request_body($overrides = array()) {
        $options = $this->get_options();
        $s = wp_parse_args($overrides, $options);

        $ref = isset($s['reference_label']) ? $s['reference_label'] : '';
        $bill = isset($s['bill_number']) ? $s['bill_number'] : '';
        $mobile = isset($s['mobile_no']) ? $s['mobile_no'] : '';
        $store = isset($s['store_label']) ? $s['store_label'] : '';
        $loyalty = isset($s['loyalty_number']) ? $s['loyalty_number'] : '';
        $customer = isset($s['customer_label']) ? $s['customer_label'] : '';
        $terminal = isset($s['terminal_label']) ? $s['terminal_label'] : '';
        $purpose = isset($s['purpose_transaction']) ? $s['purpose_transaction'] : '';

        $amount = floatval($s['transaction_amount']);
        $tip = floatval($s['convenience_tip']);
        $feeFixed = floatval($s['convenience_fee_fixed']);
        $feePct = floatval($s['convenience_fee_percentage']);

        return array(
            "MerchantId" => absint($s['merchant_id']),
            "SetTransactionAmount" => $amount > 0 ? true : false,
            "TransactionAmount" => $amount > 0 ? $amount : 0,
            "SetConvenienceIndicatorTip" => $tip > 0 ? true : false,
            "ConvenienceIndicatorTip" => $tip > 0 ? $tip : 0,
            "SetConvenienceFeeFixed" => $feeFixed > 0 ? true : false,
            "ConvenienceFeeFixed" => $feeFixed > 0 ? $feeFixed : 0,
            "SetConvenienceFeePercentage" => $feePct > 0 ? true : false,
            "ConvenienceFeePercentage" => $feePct > 0 ? $feePct : 0,
            "SetAdditionalBillNumber" => !empty($bill) ? true : false,
            "AdditionalRequiredBillNumber" => !empty($bill) ? true : false,
            "AdditionalBillNumber" => !empty($bill) ? $bill : "string",
            "SetAdditionalMobileNo" => !empty($mobile) ? true : false,
            "AdditionalRequiredMobileNo" => !empty($mobile) ? true : false,
            "AdditionalMobileNo" => !empty($mobile) ? $mobile : "string",
            "SetAdditionalStoreLabel" => !empty($store) ? true : false,
            "AdditionalRequiredStoreLabel" => !empty($store) ? true : false,
            "AdditionalStoreLabel" => !empty($store) ? $store : "string",
            "SetAdditionalLoyaltyNumber" => !empty($loyalty) ? true : false,
            "AdditionalRequiredLoyaltyNumber" => !empty($loyalty) ? true : false,
            "AdditionalLoyaltyNumber" => !empty($loyalty) ? $loyalty : "string",
            "SetAdditionalReferenceLabel" => !empty($ref) ? true : false,
            "AdditionalRequiredReferenceLabel" => !empty($ref) ? true : false,
            "AdditionalReferenceLabel" => !empty($ref) ? $ref : "string",
            "SetAdditionalCustomerLabel" => !empty($customer) ? true : false,
            "AdditionalRequiredCustomerLabel" => !empty($customer) ? true : false,
            "AdditionalCustomerLabel" => !empty($customer) ? $customer : "string",
            "SetAdditionalTerminalLabel" => !empty($terminal) ? true : false,
            "AdditionalRequiredTerminalLabel" => !empty($terminal) ? true : false,
            "AdditionalTerminalLabel" => !empty($terminal) ? $terminal : "string",
            "SetAdditionalPurposeTransaction" => !empty($purpose) ? true : false,
            "AdditionalRequiredPurposeTransaction" => !empty($purpose) ? true : false,
            "AdditionalPurposeTransaction" => !empty($purpose) ? $purpose : "string",
        );
    }

    public function api_request($body = null, $description = 'Custom') {
        if ($body === null) {
            $body = $this->build_request_body();
            $description = 'Settings-based';
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'text/plain',
        );

        $response = wp_remote_post(self::API_URL, array(
            'timeout' => 30,
            'headers' => $headers,
            'body' => json_encode($body),
            'sslverify' => false,
            'httpversion' => '1.1',
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'data' => '',
                'debug' => array(
                    'description' => $description,
                    'wp_error' => true,
                    'error_code' => $response->get_error_code(),
                ),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $response_body = wp_remote_retrieve_body($response);

        $all_headers = array();
        if (is_object($response_headers)) {
            foreach ($response_headers as $key => $value) {
                $all_headers[$key] = $value;
            }
        }

        $is_success = ($code === 200 && !empty($response_body));

        return array(
            'success' => $is_success,
            'data' => $response_body,
            'error' => !$is_success ? ($code !== 200 ? "HTTP $code" : 'Empty response from API') : '',
            'debug' => array(
                'description' => $description,
                'http_code' => $code,
                'response_length' => strlen($response_body),
                'response_preview' => substr($response_body, 0, 500),
                'content_type' => isset($all_headers['content-type']) ? $all_headers['content-type'] : 'unknown',
                'request_body' => json_encode($body, JSON_PRETTY_PRINT),
            ),
        );
    }

    public function ajax_test_connection() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));
        $result = $this->api_request();
        wp_send_json(array('success' => $result['success'], 'data' => $result));
    }

    public function ajax_test_original() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));

        $original_body = array(
            "MerchantId" => 56, "SetTransactionAmount" => false, "TransactionAmount" => 0,
            "SetConvenienceIndicatorTip" => false, "ConvenienceIndicatorTip" => 0,
            "SetConvenienceFeeFixed" => false, "ConvenienceFeeFixed" => 0,
            "SetConvenienceFeePercentage" => false, "ConvenienceFeePercentage" => 0,
            "SetAdditionalBillNumber" => false, "AdditionalRequiredBillNumber" => false, "AdditionalBillNumber" => "string",
            "SetAdditionalMobileNo" => false, "AdditionalRequiredMobileNo" => false, "AdditionalMobileNo" => "string",
            "SetAdditionalStoreLabel" => false, "AdditionalRequiredStoreLabel" => false, "AdditionalStoreLabel" => "string",
            "SetAdditionalLoyaltyNumber" => false, "AdditionalRequiredLoyaltyNumber" => false, "AdditionalLoyaltyNumber" => "string",
            "SetAdditionalReferenceLabel" => false, "AdditionalRequiredReferenceLabel" => false, "AdditionalReferenceLabel" => "string",
            "SetAdditionalCustomerLabel" => false, "AdditionalRequiredCustomerLabel" => false, "AdditionalCustomerLabel" => "string",
            "SetAdditionalTerminalLabel" => false, "AdditionalRequiredTerminalLabel" => false, "AdditionalTerminalLabel" => "string",
            "SetAdditionalPurposeTransaction" => false, "AdditionalRequiredPurposeTransaction" => false, "AdditionalPurposeTransaction" => "string"
        );

        $result = $this->api_request($original_body, 'ORIGINAL CURL (MerchantId: 56)');
        wp_send_json(array('success' => $result['success'], 'data' => $result));
    }

public function ajax_generate_qr_admin() {
    check_ajax_referer('zwennpay_qr_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // ✅ Check if increment is requested
    $increment = isset($_POST['increment_counter']) && $_POST['increment_counter'] === 'true';

    $new_count = null;

    if ($increment) {
        $count = get_option(self::PREVIEW_COUNT_OPTION, 0);
        $count++;
        update_option(self::PREVIEW_COUNT_OPTION, $count);
        $new_count = $count;
    }

    $result = $this->api_request();

    $merchant_name = '';
    $merchant_city = '';

    if ($result['success'] && !empty($result['data'])) {
        $parsed = $this->parse_emv_data($result['data']);
        $merchant_name = isset($parsed['59']) ? $parsed['59'] : '';
        $merchant_city = isset($parsed['60']) ? $parsed['60'] : '';
    }

$qr_base64 = $this->generate_qr_base64($result['data'], 256); // or $options['qr_size']
wp_send_json(array(
    'success' => $result['success'],
    'qr_data' => $qr_base64,
    'merchant_name' => $merchant_name,
    'merchant_city' => $merchant_city,
    'error' => $result['error'],
    'debug' => $result['debug'],
    'new_preview_count' => $new_count,
));
}

public function ajax_generate_qr() {
    check_ajax_referer('zwennpay_qr_frontend_nonce', 'nonce');

    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $reference = isset($_POST['reference']) ? sanitize_text_field($_POST['reference']) : '';

    // Build the QR string locally
    $qr_string = "MerchantId:" . get_option(self::OPTION_NAME)['merchant_id'] .
                 ";Amount:" . $amount .
                 ";Reference:" . $reference;

    $qr_base64 = $this->generate_qr_base64($qr_string, 256);

    wp_send_json_success(array(
        'qr_data' => $qr_base64,
        'merchant_name' => get_option(self::OPTION_NAME)['store_label'],
        'merchant_city' => '' // optional if you have city info
    ));
}

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_zwennpay-qr-settings') return;

        wp_enqueue_script('zwennpay-qrcode-admin', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), '1.0.0', true);
        
        wp_localize_script('zwennpay-qrcode-admin', 'zwennpayAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zwennpay_qr_nonce'),
            'strings' => array(
                'testing' => 'Testing...',
                'generating' => 'Generating...',
                'success' => 'Success!',
                'error' => 'Error:',
            ),
        ));

        wp_enqueue_style('zwennpay-qrcode-admin', plugins_url('assets/css/admin.css', __FILE__), array(), '1.0.0');
    }

    public function enqueue_frontend_scripts() {
        wp_enqueue_script('zwennpay-qrcode-frontend', plugins_url('assets/js/frontend.js', __FILE__), array('jquery'), '1.0.0', true);
        
        wp_localize_script('zwennpay-qrcode-frontend', 'zwennpayFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zwennpay_qr_frontend_nonce'),
            'strings' => array(
                'loading' => 'Loading...',
                'error' => 'Error generating QR code.',
            ),
        ));

        wp_enqueue_style('zwennpay-qrcode-frontend', plugins_url('assets/css/frontend.css', __FILE__), array(), '1.0.0');
    }

    public function generate_qr_image_url($data, $size = 256, $color = '#000000') {
        $size = max(50, min(1000, intval($size)));
        $color = str_replace('#', '', $color);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . 
               '&color=' . $color . '&data=' . urlencode($data) . '&margin=10';
    }

    public function generate_qr_base64($text, $size = 256) {
        // Use output buffering to capture the PNG output
        ob_start();
        \QRcode::png($text, null, QR_ECLEVEL_L, $size / 25); // size is in pixels, adjust scale
        $imageData = ob_get_contents();
        ob_end_clean();

        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'amount' => 0, 'reference' => '', 'size' => 0, 'color' => '',
            'show_form' => 'false', 'show_amount' => 'true',
        ), $atts, 'zwennpay_qr');

        $options = $this->get_options();
        
        if (empty($options['merchant_id'])) {
            return '<p class="zwennpay-error">ZwennPay QR: Merchant ID not configured.</p>';
        }

        $size = $atts['size'] > 0 ? $atts['size'] : $options['qr_size'];
        $color = !empty($atts['color']) ? $atts['color'] : $options['qr_color'];
        $show_amount = filter_var($atts['show_amount'], FILTER_VALIDATE_BOOLEAN);
        $show_form = filter_var($atts['show_form'], FILTER_VALIDATE_BOOLEAN);

        $overrides = array();
        if ($atts['amount'] > 0) $overrides['transaction_amount'] = $atts['amount'];
        if (!empty($atts['reference'])) $overrides['reference_label'] = $atts['reference'];

        $result = $this->api_request($this->build_request_body($overrides));
        
        $merchant_name = '';
        $merchant_city = '';
        if ($result['success'] && !empty($result['data'])) {
            $parsed = $this->parse_emv_data($result['data']);
            $merchant_name = isset($parsed['59']) ? $parsed['59'] : '';
            $merchant_city = isset($parsed['60']) ? $parsed['60'] : '';
        }

        ob_start();
        ?>
        <div class="zwennpay-qr-container" 
             data-size="<?php echo esc_attr($size); ?>"
             data-color="<?php echo esc_attr($color); ?>"
             data-show-amount="<?php echo $show_amount ? 'true' : 'false'; ?>">
            
            <?php if ($show_form): ?>
                <div class="zwennpay-qr-form">
                    <div class="zwennpay-form-group">
                        <label><?php _e('Amount:', 'zwennpay-qr'); ?></label>
                        <input type="number" class="zwennpay-amount-input" step="0.01" min="0" placeholder="Enter amount">
                    </div>
                    <div class="zwennpay-form-group">
                        <label><?php _e('Reference:', 'zwennpay-qr'); ?></label>
                        <input type="text" class="zwennpay-reference-input" placeholder="Enter reference (optional)">
                    </div>
                    <button type="button" class="zwennpay-generate-btn"><?php _e('Generate QR Code', 'zwennpay-qr'); ?></button>
                </div>
            <?php endif; ?>

            <div class="zwennpay-qr-display">
                <?php if ($result['success'] && !empty($result['data'])): ?>
                    <div class="zwennpay-qr-image">
                        <img src="<?php echo esc_url($this->generate_qr_base64($result['data'], $size)); ?>" 
     alt="Payment QR Code" width="<?php echo esc_attr($size); ?>" height="<?php echo esc_attr($size); ?>">
                    </div>
                    
                    <?php if (!empty($merchant_name) || !empty($merchant_city)): ?>
                        <div class="zwennpay-merchant-info" style="text-align:center; margin-top:10px; font-family: sans-serif; font-size: 12px; line-height: 1.4;">
                            <?php if (!empty($merchant_name)): ?>
                                <strong style="display:block;"><?php echo esc_html($merchant_name); ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($merchant_city)): ?>
                                <span style="display:block; color: #666;"><?php echo esc_html($merchant_city); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_amount && $atts['amount'] > 0): ?>
                        <div class="zwennpay-qr-amount"><?php echo esc_html(number_format($atts['amount'], 2)); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="zwennpay-error">
                        <strong>Error:</strong> <?php echo esc_html($result['error']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_section_info() {}

    public function render_text_field($field_name) {
        $options = $this->get_options();
        $value = isset($options[$field_name]) ? $options[$field_name] : '';
        
        $type = 'text';
        $extra_attrs = '';
        
        switch ($field_name) {
            case 'merchant_id':
                $type = 'number';
                $extra_attrs = 'min="1" max="1000" required data-max="1000"';
                break;
            case 'bill_number':
                $type = 'number'; // HTML number field
                $extra_attrs = 'max="100000" data-max="100000"';
                break;
            case 'mobile_no':
                // Use text to allow custom JS masking behavior
                $extra_attrs = 'maxlength="8" pattern="[0-9]{8}" data-limit="8" inputmode="numeric"';
                break;
            case 'loyalty_number':
                $type = 'number';
                $extra_attrs = 'max="10000" data-max="10000"';
                break;
        }
        
        echo '<input type="' . esc_attr($type) . '" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text" ' . $extra_attrs . '>';
    }

    public function render_number_field($field_name) {
        $options = $this->get_options();
        $value = isset($options[$field_name]) ? $options[$field_name] : 0;
        $min = '0';
        $max = '';
        $step = '0.01';
        
        switch ($field_name) {
            case 'transaction_amount':
                $max = ' max="250000" data-max="250000"';
                break;
            case 'convenience_tip':
            case 'convenience_fee_fixed':
                $max = ' max="1000" data-max="1000"';
                break;
            case 'convenience_fee_percentage':
                $max = ' max="100" data-max="100"';
                break;
            case 'qr_size':
                $min = '64';
                $max = ' max="1024"';
                $step = '1';
                break;
        }
        
        echo '<input type="number" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text" step="' . esc_attr($step) . '" min="' . esc_attr($min) . '"' . $max . '>';
        if ($field_name === 'qr_size') echo '<p class="description">QR code size in pixels (64-1024)</p>';
    }

    public function render_color_field($field_name) {
        $options = $this->get_options();
        $value = isset($options[$field_name]) ? $options[$field_name] : '#000000';
        echo '<input type="color" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '">';
    }

    public function render_checkbox_field($field_name) {
        $options = $this->get_options();
        $value = isset($options[$field_name]) ? $options[$field_name] : 0;
        echo '<label><input type="checkbox" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="1" ' . checked($value, 1, false) . '> Display amount below QR code</label>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        
        $preview_count = get_option(self::PREVIEW_COUNT_OPTION, 0);
        ?>
        <div class="wrap zwennpay-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($preview_count > 0): ?>
            <div class="notice notice-info inline" style="margin-top: 0; margin-bottom: 20px;">
                <p><strong>History:</strong> You have generated a QR code preview <strong id="preview-count"><?php echo esc_html($preview_count); ?></strong> times.</p>
            </div>
            <?php endif; ?>
            
            <div class="zwennpay-settings-layout">
                <div class="zwennpay-settings-main">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('zwennpay_qr_options_group');
                        do_settings_sections('zwennpay-qr-settings');
                        submit_button('Save Settings');
                        ?>
                    </form>
                </div>
                
                <div class="zwennpay-settings-sidebar">
                    <div class="zwennpay-preview-box">
                        <div id="zwennpay-preview-container">
                            <div class="maucas-logo"></div>
                            <p id="zwennpay-preview-text">Click "Generate Preview" to see QR code</p>
                            <div id="zwennpay-preview-qr" style="display: none;"></div>
                            <div class="Zvenn-Pay-logo"></div>
                            <p id="zwennpay-preview-amount" style="display: none;"></p>
                        </div>
                        <button type="button" id="zwennpay-generate-preview" class="button button-secondary" style="width:100%;">Generate Preview</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

new ZwennPay_QR_Generator();