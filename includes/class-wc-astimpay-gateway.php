<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_AstimPay_Gateway extends WC_Payment_Gateway {

    private $astimpay_api_key;
    private $astimpay_api_base_url;

    public function __construct() {
        $this->id                 = 'astimpay';
        $this->icon               = ''; // URL of the icon
        $this->has_fields         = false;
        $this->method_title       = 'AstimPay';
        $this->method_description = 'Allows payments via AstimPay. Converts site currency to BDT.';

        // Define user setting fields
        $this->init_form_fields();
        $this->init_settings();

        // Initialize the declared properties
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');
        $this->enabled            = $this->get_option('enabled');
        $this->astimpay_api_key   = $this->get_option('live_api_key');
        $this->astimpay_api_base_url = $this->get_option('live_api_base_url');

        // Save admin settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_astimpay_gateway', array($this, 'astimpay_payment_verification'));
        
        // Hook to display notices on the Thank You page
        add_action('woocommerce_before_thankyou', 'print_astimpay_notices');
		
		// Register the custom REST API route for IPN
		add_action('woocommerce_api_astimpay_ipn', array($this, 'handle_astimpay_ipn'));

        // Add the custom payment cancellation notice
        add_action('template_redirect', array($this, 'astimpay_payment_cancel_notice'));

    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable AstimPay Payment',
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'AstimPay',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'Payment method description that the customer will see on the checkout page.',
                'default'     => 'Pay securely using your AstimPay account.',
            ),
            'live_api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Get your live API key from the AstimPay dashboard.',
            ),
            'live_api_base_url' => array(
                'title'       => 'API Base URL',
                'type'        => 'text',
                'description' => 'Enter the base URL for AstimPay API (e.g., https://sandbox.astimpay.com).',
            ),
            'site_to_bdt_rate' => array(
                'title'       => 'Site Currency to BDT Exchange Rate',
                'type'        => 'text',
                'description' => 'Set the exchange rate for your site\'s currency to BDT.',
                'default'     => '1',  // Default rate if site currency is BDT
                'desc_tip'    => true,
            ),
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Convert the order total to BDT
        $amount_in_bdt = $this->convert_to_bdt($order->get_total());

        // Send payment request to AstimPay
        $response = $this->send_payment_to_astimpay($order, $amount_in_bdt);

        if ($response['status'] === 'success') {
            // Payment successful
            $order->payment_complete();
            wc_reduce_stock_levels($order_id);

            // Return success and redirect to the order received page
            return array(
                'result'   => 'success',
                'redirect' => $response['payment_url'],
            );
        } else {
            // Payment failed
            wc_add_notice('Payment error: ' . $response['message'], 'error');
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }
    }

    public function astimpay_payment_cancel_notice() {
        if (isset($_GET['payment']) && $_GET['payment'] === 'canceled') {
            wc_add_notice(__('Your payment has been canceled.', 'woocommerce'), 'notice');
            WC()->session->set('wc_notices', wc_get_notices());
        }
    }

    public function astimpay_payment_verification() {
        // error_log('AstimPay verification API triggered.'); // Debugging log to check if API is triggered.

        if (isset($_GET['order_id']) && isset($_GET['invoice_id']) && isset($_GET['_wpnonce'])) {
            // Verify nonce
            if (!wp_verify_nonce($_GET['_wpnonce'], 'astimpay_payment_verification_nonce')) {
                wc_add_notice(__('Security check failed.', 'woocommerce'), 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            $order_id = sanitize_text_field($_GET['order_id']);
            $invoice_id = sanitize_text_field($_GET['invoice_id']);

            // Proceed with further validation and AstimPay payment verification
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'woocommerce'), 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            // Get API key and base URL from the plugin settings
            $api_key = $this->astimpay_api_key;
            $api_base_url = $this->astimpay_api_base_url;

            $astimpay = new AstimPay($api_key, $api_base_url);

            try {
                $status = $astimpay->verifyPayment($invoice_id);

                if ($status === 'Completed') {
                    $order->payment_complete();
                    wc_add_notice(__('Payment successful via AstimPay.', 'woocommerce'), 'success');
                } elseif ($status === 'Pending') {
                    $order->update_status('pending', __('Payment pending via AstimPay.', 'woocommerce'));
                    wc_add_notice(__('Payment is pending.', 'woocommerce'), 'notice');
                } else {
                    wc_add_notice(__('Unexpected payment status received.', 'woocommerce'), 'error');
                }

            } catch (Exception $e) {
                wc_add_notice('Payment verification failed: ' . $e->getMessage(), 'error');
            }

            // Store notices in the session
            WC()->session->set('wc_notices', wc_get_notices());

            // Redirect to thank you page or checkout page
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;

        } else {
            wc_add_notice(__('Invalid request.', 'woocommerce'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }    

    public function handle_astimpay_ipn() {
        // Define your API key
        $apiKey = $this->astimpay_api_key;

        // Get the API key from the request headers
        $headerApi = isset($_SERVER['HTTP_API_KEY']) ? $_SERVER['HTTP_API_KEY'] : null;

        // Verify the API key
        if ($headerApi !== $apiKey) {
            http_response_code(401); // Unauthorized
            die("Unauthorized Action");
        }

        // Get the raw request body
        $rawData = file_get_contents('php://input');

        // Parse the JSON data
        $params = json_decode($rawData, true);

        // Check if JSON data was successfully parsed
        if ($params === null) {
            http_response_code(400); // Bad Request
            die("Invalid JSON data");
        }

        // Log the IPN request data for debugging
        // error_log('AstimPay IPN Request: ' . print_r($params, true));

        // Ensure required parameters are present
        if (!isset($params['invoice_id']) || !isset($params['status'])) {
            http_response_code(400); // Bad Request
            die("Missing required parameters");
        }

        // Retrieve order based on invoice ID
        $order_id = sanitize_text_field($params['invoice_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            http_response_code(404); // Order not found
            die("Order not found");
        }

        // Check if the order is still pending
        if ($order->get_status() !== 'pending') {
            http_response_code(200); // OK
            die("Order is not pending");
        }

        // Process the webhook data based on the payment status
        $status = sanitize_text_field($params['status']);
        $payment_method = sanitize_text_field($params['payment_method']);
        $transaction_id = sanitize_text_field($params['transaction_id']);
        $amount = sanitize_text_field($params['amount']);
        $sender_number = sanitize_text_field($params['sender_number']);

        if ($status === 'Completed') {
            // Complete the payment and update the order status
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(
                __('Payment successfully completed via AstimPay. Transaction ID: %s, Amount: %s, Sender Number: %s, Payment Method: %s', 'woocommerce'),
                $transaction_id, $amount, $sender_number, $payment_method
            ));
            http_response_code(200); // OK
            die("Order completed successfully");
        } elseif ($status === 'Pending') {
            // Mark the order as pending
            $order->update_status('pending', __('Payment pending via AstimPay.', 'woocommerce'));
            http_response_code(200); // OK
            die("Payment is still pending");
        } elseif ($status === 'FAILED' || $status === 'ERROR') {
            // Mark the order as failed
            $order->update_status('failed', __('Payment failed via AstimPay.', 'woocommerce'));
            http_response_code(200); // OK
            die("Payment failed");
        } else {
            http_response_code(400); // Bad Request
            die("Unexpected payment status");
        }
    }

    private function convert_to_bdt($amount) {
        $currency = get_woocommerce_currency();

        // If the currency is already BDT, no conversion is needed
        if ($currency == 'BDT') {
            return $amount;
        }

        // Get the admin-defined exchange rate
        $exchange_rate = $this->get_option('site_to_bdt_rate');

        // Convert the amount to BDT using the exchange rate
        return number_format($amount * $exchange_rate, 2, '.', '');
    }

    private function send_payment_to_astimpay($order, $amount_in_bdt) {
        $api_key = $this->astimpay_api_key;
        $api_base_url = $this->astimpay_api_base_url;

        $astimpay = new AstimPay($api_key, $api_base_url);

        try {
            // Retrieve customer information
            $customer = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $email = $order->get_billing_email();

            $request_data = array(
                'full_name'    => $customer,
                'email'        => $email,
                'amount'       => number_format($amount_in_bdt, 2, '.', ''),
                'invoice_id'   => $order->get_id(),
                'metadata'     => array(
                    'user_id'  => get_current_user_id(), // Assuming logged-in user ID
                    'order_id' => $order->get_id()
                ),
                'redirect_url' => site_url('/?wc-api=wc_astimpay_gateway&order_id=' . $order->get_id() . '&invoice_id=' . $order->get_id() . '&_wpnonce=' . wp_create_nonce('astimpay_payment_verification_nonce')),
                'return_type'  => 'GET',
                'cancel_url'   => home_url('/checkout/?payment=canceled'), // Custom cancel URL
                'webhook_url'  => home_url('/wp-json/astimpay/v1/ipn')
            );

            // Log request data
            // error_log('AstimPay Request Data: ' . print_r($request_data, true));

            $payment_url = $astimpay->initPayment($request_data);

            // Log response
            // error_log('AstimPay Response: ' . print_r($payment_url, true));

            return array('status' => 'success', 'payment_url' => $payment_url);

        } catch (Exception $e) {
            // Log error message
            // error_log('AstimPay Error: ' . $e->getMessage());

            return array('status' => 'error', 'message' => $e->getMessage());
        }
    }
}

// Hook to print notices on Thank You page
function print_astimpay_notices() {
    if (WC()->session) {
        wc_print_notices();
        WC()->session->__unset('wc_notices'); // Clear the notices after they are displayed
    }
}
