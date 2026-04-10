<?php
/**
 * Plugin Name: ZwennPay QR Generator
 * Plugin URI: https://zwennpay.com/
 * Description: The official ZwennPay plugin that enables QR code payments directly on your WooCommerce checkout page.
 * Version: 1.3.0
 * Author: ZwennPay
 * License: GPL v2 or later
 * Text Domain: zwennpay-qr
 */
include_once plugin_dir_path(__FILE__) . '/includes/phpqrcode/qrlib.php';
if (!defined('ABSPATH')) {
    exit;
}

class ZwennPay_QR_Generator {

    const API_URL         = 'https://apiuat.zwennpay.com:9425/api/v1.0/Common/GetMerchantQR';
    const OPTION_NAME     = 'zwennpay_qr_options';
    const TX_TABLE        = 'zwennpay_transactions';  // Transaction history table
    const TXS_PER_PAGE    = 10;

    public function __construct() {
        add_action('admin_init',              array($this, 'ensure_tx_table_exists'), 1);
        add_action('admin_menu',              array($this, 'add_admin_menu'));
        add_action('admin_init',              array($this, 'register_settings'));
        add_action('admin_enqueue_scripts',   array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts',      array($this, 'enqueue_frontend_scripts'));
        add_action('wp_ajax_zwennpay_test_connection',       array($this, 'ajax_test_connection'));
        add_action('wp_ajax_zwennpay_test_original',         array($this, 'ajax_test_original'));
        add_action('wp_ajax_zwennpay_generate_qr_admin',     array($this, 'ajax_generate_qr_admin'));
        add_action('wp_ajax_zwennpay_preview_qr_admin',      array($this, 'ajax_preview_qr_admin'));
        add_action('wp_ajax_zwennpay_generate_qr',           array($this, 'ajax_generate_qr'));
        add_action('wp_ajax_nopriv_zwennpay_generate_qr',    array($this, 'ajax_generate_qr'));
        add_action('wp_ajax_zwennpay_get_transactions',      array($this, 'ajax_get_transactions'));
        add_action('wp_ajax_zwennpay_delete_transactions',   array($this, 'ajax_delete_transactions'));
        add_action('wp_ajax_zwennpay_delete_single_tx',      array($this, 'ajax_delete_single_tx'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_links'));
    }

    // -------------------------------------------------------------------------
    // Activation / Table management
    // -------------------------------------------------------------------------

    public static function activate() {
        self::create_tx_table();
    }

    public function ensure_tx_table_exists() {
        global $wpdb;
        $table_name   = $wpdb->prefix . self::TX_TABLE;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if ($table_exists !== $table_name) {
            self::create_tx_table();
        }
    }

    private static function create_tx_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . self::TX_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            order_number varchar(100) NOT NULL DEFAULT '',
            bill_number varchar(100) NOT NULL DEFAULT '',
            reference_number varchar(255) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Plugin links
    // -------------------------------------------------------------------------

    public function add_plugin_links($links) {
        $settings_link = '<a href="options-general.php?page=zwennpay-qr-settings">' . __('Settings', 'zwennpay-qr') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // -------------------------------------------------------------------------
    // Admin menu & settings registration
    // -------------------------------------------------------------------------

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

        // Only Merchant ID and Store Label are exposed in settings now
        add_settings_section('zwennpay_qr_main_section', __('Main Settings', 'zwennpay-qr'), array($this, 'render_section_info'), 'zwennpay-qr-settings');
        add_settings_field('merchant_id',  __('Merchant ID', 'zwennpay-qr'),   array($this, 'render_text_field'),   'zwennpay-qr-settings', 'zwennpay_qr_main_section', 'merchant_id');
        add_settings_field('store_label',  __('Store Label', 'zwennpay-qr'),   array($this, 'render_text_field'),   'zwennpay-qr-settings', 'zwennpay_qr_main_section', 'store_label');
    }

    public function render_section_info() {}

    // -------------------------------------------------------------------------
    // Sanitize / Get options
    // -------------------------------------------------------------------------

    public function sanitize_options($input) {
        $new_input   = array();
        $old_options = get_option(self::OPTION_NAME);

        $val = isset($input['merchant_id']) ? $input['merchant_id'] : 0;
        if (is_numeric($val) && $val > 0 && $val <= 1000) {
            $new_input['merchant_id'] = absint($val);
        } else {
            add_settings_error('zwennpay_qr_options_group', 'merchant_id_error', 'Merchant ID must be a number up to 1000.', 'error');
            $new_input['merchant_id'] = isset($old_options['merchant_id']) ? $old_options['merchant_id'] : 0;
        }

        $new_input['store_label'] = sanitize_text_field($input['store_label'] ?? '');

        // Preserve hidden/advanced options that may have been saved previously
        $preserve = array('transaction_amount','convenience_tip','convenience_fee_fixed','convenience_fee_percentage',
                          'bill_number','mobile_no','loyalty_number','customer_label','terminal_label',
                          'purpose_transaction','reference_label','qr_size','qr_color','show_amount');
        foreach ($preserve as $key) {
            if (isset($old_options[$key])) {
                $new_input[$key] = $old_options[$key];
            }
        }

        return $new_input;
    }

    public function get_options() {
        $defaults = array(
            'merchant_id'                => 0,
            'store_label'                => '',
            'transaction_amount'         => 0,
            'reference_label'            => '',
            'bill_number'                => '',
            'mobile_no'                  => '',
            'loyalty_number'             => '',
            'customer_label'             => '',
            'terminal_label'             => '',
            'purpose_transaction'        => '',
            'convenience_tip'            => 0,
            'convenience_fee_fixed'      => 0,
            'convenience_fee_percentage' => 0,
            'qr_size'                    => 256,
            'qr_color'                   => '#000000',
            'show_amount'                => 1,
        );
        return wp_parse_args(get_option(self::OPTION_NAME, array()), $defaults);
    }

    // -------------------------------------------------------------------------
    // EMV / QR helpers
    // -------------------------------------------------------------------------

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

    public function build_request_body($overrides = array()) {
        $options = $this->get_options();
        $s       = wp_parse_args($overrides, $options);

        // Store label: set to true only when not empty
        $store   = isset($s['store_label']) ? $s['store_label'] : '';

        $ref     = isset($s['reference_label']) ? $s['reference_label'] : '';
        $bill    = isset($s['bill_number']) ? $s['bill_number'] : '';
        $mobile  = isset($s['mobile_no']) ? $s['mobile_no'] : '';
        $loyalty = isset($s['loyalty_number']) ? $s['loyalty_number'] : '';
        $customer= isset($s['customer_label']) ? $s['customer_label'] : '';
        $terminal= isset($s['terminal_label']) ? $s['terminal_label'] : '';
        $purpose = isset($s['purpose_transaction']) ? $s['purpose_transaction'] : '';
        $amount  = floatval($s['transaction_amount']);
        $tip     = floatval($s['convenience_tip']);
        $feeFixed= floatval($s['convenience_fee_fixed']);
        $feePct  = floatval($s['convenience_fee_percentage']);

        return array(
            "MerchantId"                           => absint($s['merchant_id']),
            "SetTransactionAmount"                 => $amount > 0,
            "TransactionAmount"                    => $amount > 0 ? $amount : 0,
            "SetConvenienceIndicatorTip"            => $tip > 0,
            "ConvenienceIndicatorTip"               => $tip > 0 ? $tip : 0,
            "SetConvenienceFeeFixed"               => $feeFixed > 0,
            "ConvenienceFeeFixed"                  => $feeFixed > 0 ? $feeFixed : 0,
            "SetConvenienceFeePercentage"          => $feePct > 0,
            "ConvenienceFeePercentage"             => $feePct > 0 ? $feePct : 0,
            // Bill number: always true when provided (order number in WC context)
            "SetAdditionalBillNumber"              => !empty($bill),
            "AdditionalRequiredBillNumber"         => !empty($bill),
            "AdditionalBillNumber"                 => !empty($bill) ? $bill : "string",
            "SetAdditionalMobileNo"                => !empty($mobile),
            "AdditionalRequiredMobileNo"           => !empty($mobile),
            "AdditionalMobileNo"                   => !empty($mobile) ? $mobile : "string",
            // Store label: driven by whether it has a value
            "SetAdditionalStoreLabel"              => !empty($store),
            "AdditionalRequiredStoreLabel"         => !empty($store),
            "AdditionalStoreLabel"                 => !empty($store) ? $store : "string",
            "SetAdditionalLoyaltyNumber"           => !empty($loyalty),
            "AdditionalRequiredLoyaltyNumber"      => !empty($loyalty),
            "AdditionalLoyaltyNumber"              => !empty($loyalty) ? $loyalty : "string",
            "SetAdditionalReferenceLabel"          => !empty($ref),
            "AdditionalRequiredReferenceLabel"     => !empty($ref),
            "AdditionalReferenceLabel"             => !empty($ref) ? $ref : "string",
            "SetAdditionalCustomerLabel"           => !empty($customer),
            "AdditionalRequiredCustomerLabel"      => !empty($customer),
            "AdditionalCustomerLabel"              => !empty($customer) ? $customer : "string",
            "SetAdditionalTerminalLabel"           => !empty($terminal),
            "AdditionalRequiredTerminalLabel"      => !empty($terminal),
            "AdditionalTerminalLabel"              => !empty($terminal) ? $terminal : "string",
            "SetAdditionalPurposeTransaction"      => !empty($purpose),
            "AdditionalRequiredPurposeTransaction" => !empty($purpose),
            "AdditionalPurposeTransaction"         => !empty($purpose) ? $purpose : "string",
        );
    }

    public function build_merchant_only_body() {
        $options = $this->get_options();
        return array(
            "MerchantId"                           => absint($options['merchant_id']),
            "SetTransactionAmount"                 => false, "TransactionAmount"            => 0,
            "SetConvenienceIndicatorTip"            => false, "ConvenienceIndicatorTip"       => 0,
            "SetConvenienceFeeFixed"               => false, "ConvenienceFeeFixed"           => 0,
            "SetConvenienceFeePercentage"          => false, "ConvenienceFeePercentage"      => 0,
            "SetAdditionalBillNumber"              => false, "AdditionalRequiredBillNumber"  => false, "AdditionalBillNumber"     => "string",
            "SetAdditionalMobileNo"                => false, "AdditionalRequiredMobileNo"    => false, "AdditionalMobileNo"       => "string",
            "SetAdditionalStoreLabel"              => false, "AdditionalRequiredStoreLabel"  => false, "AdditionalStoreLabel"     => "string",
            "SetAdditionalLoyaltyNumber"           => false, "AdditionalRequiredLoyaltyNumber" => false, "AdditionalLoyaltyNumber" => "string",
            "SetAdditionalReferenceLabel"          => false, "AdditionalRequiredReferenceLabel" => false, "AdditionalReferenceLabel" => "string",
            "SetAdditionalCustomerLabel"           => false, "AdditionalRequiredCustomerLabel"  => false, "AdditionalCustomerLabel"  => "string",
            "SetAdditionalTerminalLabel"           => false, "AdditionalRequiredTerminalLabel"  => false, "AdditionalTerminalLabel"  => "string",
            "SetAdditionalPurposeTransaction"      => false, "AdditionalRequiredPurposeTransaction" => false, "AdditionalPurposeTransaction" => "string",
        );
    }

    public function api_request($body = null, $description = 'Custom') {
        if ($body === null) {
            $body        = $this->build_request_body();
            $description = 'Settings-based';
        }

        $response = wp_remote_post(self::API_URL, array(
            'timeout'    => 30,
            'headers'    => array('Content-Type' => 'application/json', 'Accept' => 'text/plain'),
            'body'       => json_encode($body),
            'sslverify'  => false,
            'httpversion'=> '1.1',
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message(), 'data' => '', 'debug' => array('description' => $description, 'wp_error' => true));
        }

        $code          = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $is_success    = ($code === 200 && !empty($response_body));

        return array(
            'success' => $is_success,
            'data'    => $response_body,
            'error'   => !$is_success ? ($code !== 200 ? "HTTP $code" : 'Empty response from API') : '',
            'debug'   => array('description' => $description, 'http_code' => $code, 'request_body' => json_encode($body, JSON_PRETTY_PRINT)),
        );
    }

    // -------------------------------------------------------------------------
    // Transaction History CRUD
    // -------------------------------------------------------------------------

    /**
     * Log a transaction (called by WooCommerce gateway on status changes).
     *
     * @param string $order_number   WC order number / ID
     * @param string $bill_number    Same as order number (used as bill number in API)
     * @param string $reference_number ZwennPay reference extracted from QR EMV data
     * @param float  $amount
     * @param string $status         pending | failed | success
     */
    public function log_transaction($order_number, $bill_number, $reference_number, $amount, $status = 'pending') {
        global $wpdb;
        $this->ensure_tx_table_exists();

        // If a row for this order already exists, update it; otherwise insert.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TX_TABLE . " WHERE order_number = %s LIMIT 1",
            $order_number
        ));

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . self::TX_TABLE,
                array(
                    'status'           => $status,
                    'reference_number' => $reference_number,
                    'amount'           => $amount,
                ),
                array('id' => $existing),
                array('%s', '%s', '%f'),
                array('%d')
            );
            return $existing;
        }

        $wpdb->insert(
            $wpdb->prefix . self::TX_TABLE,
            array(
                'order_number'    => $order_number,
                'bill_number'     => $bill_number,
                'reference_number'=> $reference_number,
                'amount'          => $amount,
                'status'          => $status,
                'created_at'      => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%f', '%s', '%s')
        );
        return $wpdb->insert_id;
    }

    private function get_transactions($page = 1, $per_page = 10) {
        global $wpdb;
        $table_name   = $wpdb->prefix . self::TX_TABLE;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if ($table_exists !== $table_name) return false;

        $offset = ($page - 1) * $per_page;
        $wpdb->hide_errors();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at, order_number, bill_number, reference_number, amount, status
                 FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ),
            ARRAY_A
        );

        if ($wpdb->last_error) { $wpdb->show_errors(); return false; }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $wpdb->show_errors();

        return array(
            'rows'         => $rows ? $rows : array(),
            'total'        => $total,
            'pages'        => ceil($total / $per_page),
            'current_page' => $page,
        );
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_test_connection() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));
        $result = $this->api_request();
        wp_send_json(array('success' => $result['success'], 'data' => $result));
    }

    public function ajax_test_original() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));
        $body   = array(
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
            "SetAdditionalPurposeTransaction" => false, "AdditionalRequiredPurposeTransaction" => false, "AdditionalPurposeTransaction" => "string",
        );
        $result = $this->api_request($body, 'ORIGINAL CURL (MerchantId: 56)');
        wp_send_json(array('success' => $result['success'], 'data' => $result));
    }

    private function fetch_preview_qr() {
        $preview_result = $this->api_request($this->build_merchant_only_body(), 'Preview (merchant ID only)');

        $merchant_name = '';
        $merchant_city = '';
        $preview_qr    = '';

        if ($preview_result['success'] && !empty($preview_result['data'])) {
            $parsed        = $this->parse_emv_data($preview_result['data']);
            $merchant_name = isset($parsed['59']) ? $parsed['59'] : '';
            $merchant_city = isset($parsed['60']) ? $parsed['60'] : '';
            $preview_qr    = $this->generate_qr_base64($preview_result['data'], 256);
        }

        return array(
            'result'        => $preview_result,
            'qr_data'       => $preview_qr,
            'merchant_name' => $merchant_name,
            'merchant_city' => $merchant_city,
        );
    }

    public function ajax_preview_qr_admin() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $preview = $this->fetch_preview_qr();

        wp_send_json(array(
            'success'       => $preview['result']['success'],
            'qr_data'       => $preview['qr_data'],
            'merchant_name' => $preview['merchant_name'],
            'merchant_city' => $preview['merchant_city'],
            'error'         => $preview['result']['error'],
            'saved'         => false,
        ));
    }

    public function ajax_generate_qr_admin() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $preview = $this->fetch_preview_qr();

        wp_send_json(array(
            'success'       => $preview['result']['success'],
            'qr_data'       => $preview['qr_data'],
            'merchant_name' => $preview['merchant_name'],
            'merchant_city' => $preview['merchant_city'],
            'error'         => $preview['result']['error'],
            'saved'         => false,
        ));
    }

    public function ajax_generate_qr() {
        check_ajax_referer('zwennpay_qr_frontend_nonce', 'nonce');

        $amount    = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $reference = isset($_POST['reference']) ? sanitize_text_field($_POST['reference']) : '';
        $options   = get_option(self::OPTION_NAME);

        $qr_string = "MerchantId:" . $options['merchant_id'] . ";Amount:" . $amount . ";Reference:" . $reference;
        $qr_base64 = $this->generate_qr_base64($qr_string, 256);

        wp_send_json_success(array(
            'qr_data'       => $qr_base64,
            'merchant_name' => $options['store_label'],
            'merchant_city' => '',
        ));
    }

    public function ajax_get_transactions() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));

        $this->ensure_tx_table_exists();
        $page   = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $result = $this->get_transactions($page, self::TXS_PER_PAGE);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error occurred'));
        }

        wp_send_json_success($result);
    }

    public function ajax_delete_transactions() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));

        global $wpdb;
        $table_name   = $wpdb->prefix . self::TX_TABLE;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if ($table_exists === $table_name) {
            $wpdb->query("TRUNCATE TABLE $table_name");
        }
        wp_send_json_success(array('message' => __('All transactions deleted successfully', 'zwennpay-qr')));
    }

    public function ajax_delete_single_tx() {
        check_ajax_referer('zwennpay_qr_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Unauthorized'));

        $id = isset($_POST['tx_id']) ? intval($_POST['tx_id']) : 0;
        if (!$id) wp_send_json_error(array('message' => 'Invalid ID'));

        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TX_TABLE, array('id' => $id), array('%d'));
        wp_send_json_success(array('message' => 'Deleted'));
    }

    // -------------------------------------------------------------------------
    // Scripts & styles
    // -------------------------------------------------------------------------

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_zwennpay-qr-settings') return;

        wp_enqueue_script('html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', array(), '1.4.1', true);
        wp_enqueue_script('jspdf',       'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',         array(), '2.5.1', true);
        wp_enqueue_script('zwennpay-qrcode-admin', plugins_url('assets/js/admin.js', __FILE__), array('jquery', 'html2canvas', 'jspdf'), '1.3.0', true);

        wp_localize_script('zwennpay-qrcode-admin', 'zwennpayAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zwennpay_qr_nonce'),
            'strings' => array(
                'testing'              => 'Testing...',
                'generating'           => 'Generating...',
                'success'              => 'Success!',
                'error'                => 'Error:',
                'loading_transactions' => 'Loading transaction history...',
                'no_transactions'      => 'No transactions recorded yet.',
                'confirm_delete_all'   => 'Are you sure you want to delete ALL transaction history? This cannot be undone.',
                'confirm_delete_one'   => 'Delete this transaction record?',
                'deleted'              => 'Deleted successfully.',
                'error_loading'        => 'Error loading transactions.',
                'downloading'          => 'Preparing PDF...',
                'download_error'       => 'Error generating PDF. Please try again.',
                'no_preview'           => 'Please generate a QR code preview first.',
            ),
        ));

        wp_enqueue_style('zwennpay-qrcode-admin', plugins_url('assets/css/admin.css', __FILE__), array(), '1.3.0');
    }

    public function enqueue_frontend_scripts() {
        wp_enqueue_script('zwennpay-qrcode-frontend', plugins_url('assets/js/frontend.js', __FILE__), array('jquery'), '1.1.0', true);
        wp_localize_script('zwennpay-qrcode-frontend', 'zwennpayFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zwennpay_qr_frontend_nonce'),
            'strings' => array('loading' => 'Loading...', 'error' => 'Error generating QR code.'),
        ));
        wp_enqueue_style('zwennpay-qrcode-frontend', plugins_url('assets/css/frontend.css', __FILE__), array(), '1.1.0');
    }

    // -------------------------------------------------------------------------
    // QR generation helpers
    // -------------------------------------------------------------------------

    public function generate_qr_base64($text, $display_size = 256) {
        $internal_size = max(600, intval($display_size) * 2);
        $module_size   = max(8, intval(round($internal_size / 60)));

        $temp_file = tempnam(sys_get_temp_dir(), 'zwqr_');
        \QRcode::png($text, $temp_file, QR_ECLEVEL_L, $module_size, 2);
        $image_data = file_get_contents($temp_file);
        unlink($temp_file);

        if (empty($image_data)) return '';
        return 'data:image/png;base64,' . base64_encode($image_data);
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'amount'      => 0,
            'reference'   => '',
            'purpose'     => '',
            'size'        => 0,
            'color'       => '',
            'show_form'   => 'false',
            'show_amount' => 'true',
        ), $atts, 'zwennpay_qr');

        $options = $this->get_options();

        if (empty($options['merchant_id'])) {
            return '<p class="zwennpay-error">ZwennPay QR: Merchant ID not configured.</p>';
        }

        $size        = $atts['size'] > 0 ? intval($atts['size']) : intval($options['qr_size']);
        $size        = max(200, $size);
        $color       = !empty($atts['color']) ? $atts['color'] : $options['qr_color'];
        $show_amount = filter_var($atts['show_amount'], FILTER_VALIDATE_BOOLEAN);
        $show_form   = filter_var($atts['show_form'], FILTER_VALIDATE_BOOLEAN);

        $overrides = array();
        if ($atts['amount'] > 0)        $overrides['transaction_amount']    = $atts['amount'];
        if (!empty($atts['reference'])) $overrides['reference_label']       = $atts['reference'];
        if (!empty($atts['purpose']))   $overrides['purpose_transaction']   = $atts['purpose'];

        $result        = $this->api_request($this->build_request_body($overrides));
        $merchant_name = '';
        $merchant_city = '';

        if ($result['success'] && !empty($result['data'])) {
            $parsed        = $this->parse_emv_data($result['data']);
            $merchant_name = isset($parsed['59']) ? $parsed['59'] : '';
            $merchant_city = isset($parsed['60']) ? $parsed['60'] : '';
        }

        ob_start();
        $plugin_url = plugin_dir_url(__FILE__);
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

            <div class="zwennpay-qr-display" style="text-align:center;">
                <?php if ($result['success'] && !empty($result['data'])): ?>
                    <img src="<?php echo esc_url($plugin_url . 'assets/maucas-logo.svg'); ?>"
                         alt="Maucas"
                         style="display:block;margin:0 auto;transform:scale(7.2);height:48px;width:auto;">
                    <div class="zwennpay-qr-image" style="margin-top:10px;text-align:center;">
                        <img src="<?php echo esc_attr($this->generate_qr_base64($result['data'], $size)); ?>"
                             alt="Payment QR Code"
                             width="<?php echo esc_attr($size); ?>"
                             height="<?php echo esc_attr($size); ?>"
                             style="display:block;margin:0 auto;max-width:100%;height:auto;image-rendering:crisp-edges;image-rendering:-moz-crisp-edges;image-rendering:pixelated;">
                    </div>
                    <img src="<?php echo esc_url($plugin_url . 'assets/Zvenn-Pay-logo.png'); ?>"
                         alt="ZwennPay"
                         style="display:block;margin:0 auto;height:35px;width:auto;margin-left:auto;margin-right:auto;margin-top:-15px;margin-bottom:-10px;">
                    <?php if ($show_amount && $atts['amount'] > 0): ?>
                        <div class="zwennpay-qr-amount" style="text-align:center;font-weight:bold;margin-top:14px;">Rs <?php echo esc_html(number_format($atts['amount'], 2)); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="zwennpay-error"><strong>Error:</strong> <?php echo esc_html($result['error']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    public function render_text_field($field_name) {
        $options     = $this->get_options();
        $value       = isset($options[$field_name]) ? $options[$field_name] : '';
        $type        = 'text';
        $extra_attrs = '';

        if ($field_name === 'merchant_id') {
            $type        = 'number';
            $extra_attrs = 'min="1" max="1000" required data-max="1000"';
        }

        echo '<input type="' . esc_attr($type) . '" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text" ' . $extra_attrs . '>';

        /*if ($field_name === 'store_label') {
            echo '<p class="description">' . esc_html__('When set, the store label is included in the QR data sent to ZwennPay.', 'zwennpay-qr') . '</p>';
        }*/
    }

    public function render_number_field($field_name) {
        $options = $this->get_options();
        $value   = isset($options[$field_name]) ? $options[$field_name] : 0;
        $min     = '0';
        $max     = '';
        $step    = '0.01';
        echo '<input type="number" name="' . self::OPTION_NAME . '[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text" step="' . esc_attr($step) . '" min="' . esc_attr($min) . '"' . $max . '>';
    }

    // -------------------------------------------------------------------------
    // Settings page render
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $options = $this->get_options();
        ?>
        <div class="wrap zwennpay-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="zwennpay-settings-layout">
                <div class="zwennpay-settings-main">
                    <form method="post" action="options.php" id="zwennpay-settings-form">
                        <?php settings_fields('zwennpay_qr_options_group'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label><?php esc_html_e('Merchant ID', 'zwennpay-qr'); ?></label></th>
                                <td><?php $this->render_text_field('merchant_id'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php esc_html_e('Store Label', 'zwennpay-qr'); ?></label></th>
                                <td><?php $this->render_text_field('store_label'); ?></td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'zwennpay-qr')); ?>
                    </form>
                </div>

                <!-- Sidebar preview -->
                <div class="zwennpay-settings-sidebar">
                    <div class="zwennpay-preview-box" id="zwennpay-preview-box">
                        <div id="zwennpay-preview-container">
                            <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/maucas-logo.svg'); ?>" alt="QR Code" style="display:block;margin:0 auto 10px;transform:scale(7.2);height:48px;width:auto;">
                            <p id="zwennpay-preview-text">Click "Preview QR" to preview</p>
                            <div id="zwennpay-preview-qr" style="display:none;"></div>
                            <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/Zvenn-Pay-logo.png'); ?>" alt="ZwennPay" style="display:block;height:35px;width:auto;margin:-10px 0 -10px;">
                            <div class="Zvenn-Pay-logo"></div>
                            <p id="zwennpay-preview-amount" style="display:none;"></p>
                        </div>
                        <div class="zwennpay-preview-buttons">
                            <button type="button" id="zwennpay-generate-preview" class="button button-secondary" style="flex:1;"><?php _e('Preview QR', 'zwennpay-qr'); ?></button>
                            <button type="button" id="zwennpay-download-pdf" class="button button-primary" style="flex:1;" disabled>
                                <span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:-2px;"></span>
                                <?php _e('Download PDF', 'zwennpay-qr'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Transaction History Section -->
                <div class="zwennpay-history-section" style="margin-bottom:20px;">
                    <div class="zwennpay-history-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 style="margin:0;font-size:1.3em;">
                            <?php esc_html_e('Transaction History', 'zwennpay-qr'); ?>
                        </h2>
                    </div>

                    <div id="zwennpay-tx-container" class="zwennpay-logs-card">
                        <table class="wp-list-table widefat fixed striped zwennpay-logs-table">
                            <thead>
                                <tr>
                                    <th style="width:140px;"><?php esc_html_e('Date & Time', 'zwennpay-qr'); ?></th>
                                    <th style="width:100px;"><?php esc_html_e('Order #', 'zwennpay-qr'); ?></th>
                                    <th><?php esc_html_e('Reference Number', 'zwennpay-qr'); ?></th>
                                    <th style="width:110px;"><?php esc_html_e('Amount', 'zwennpay-qr'); ?></th>
                                    <th style="width:90px;"><?php esc_html_e('Status', 'zwennpay-qr'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="zwennpay-tx-body">
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:30px;">
                                        <span class="spinner is-active" style="float:none;vertical-align:middle;"></span>
                                        <?php esc_html_e('Loading...', 'zwennpay-qr'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div id="zwennpay-tx-pagination" class="tablenav bottom" style="display:none;">
                            <div class="tablenav-pages">
                                <span class="displaying-num" id="zwennpay-tx-count"></span>
                                <span class="pagination-links" id="zwennpay-tx-links"></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- .zwennpay-settings-layout -->
        </div><!-- .wrap -->
        <?php
    }
}

register_activation_hook(__FILE__, array('ZwennPay_QR_Generator', 'activate'));
$GLOBALS['zwennpay_qr_instance'] = new ZwennPay_QR_Generator();

// =============================================================================
// WooCommerce Integration
// =============================================================================

add_action('plugins_loaded', 'zwennpay_init_gateway', 11);

function zwennpay_init_gateway() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-zwennpay.php';
    if (file_exists($gateway_file)) {
        require_once $gateway_file;
    }
}

add_filter('woocommerce_payment_gateways', 'zwennpay_add_gateway_class');

function zwennpay_add_gateway_class($gateways) {
    if (class_exists('WC_Gateway_Zwennpay')) {
        $gateways[] = 'WC_Gateway_Zwennpay';
    }
    return $gateways;
}

/* =========================
 * ZwennPay Activation Setup
 * ========================= */

register_activation_hook(__FILE__, 'zwennpay_create_checkout_page');

function zwennpay_create_checkout_page() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'ZwennPay requires WooCommerce to be installed and activated.',
            'Plugin dependency check',
            array('back_link' => true)
        );
        return;
    }

    $existing_page = get_page_by_path('zwennpay-checkout');

    if ($existing_page) {
        $page_id = $existing_page->ID;
    } else {
        $page_id = wp_insert_post(array(
            'post_title'   => 'Checkout',
            'post_name'    => 'zwennpay-checkout',
            'post_content' => '[woocommerce_checkout]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ));
    }

    if ($page_id && !is_wp_error($page_id)) {
        update_option('woocommerce_checkout_page_id', $page_id);
    }

    flush_rewrite_rules();
}