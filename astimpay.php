<?php
/**
 * Plugin Name
 *
 * @package           AstimPay WooCommerce
 * @author            AstimPay
 * @copyright         2024 AstimPay
 * @license           GPL-2.0
 *
 * @wordpress-plugin
 * Plugin Name:       AstimPay WooCommerce
 * Plugin URI:        https://github.com/astimpay
 * Description:       A payment gateway for WooCommerce.
 * Version:           1.0.0
 * Author:            AstimPay
 * Author URI:        https://astimpay.com
 * Text Domain:       wc-astimpay
 * License:           GPL v2
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:        https://github.com/astimpay
 * Requires Plugins:  woocommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the AstimPay library
require_once(plugin_dir_path(__FILE__) . '/lib/astimpay.php');

// Include the gateway class
add_action('plugins_loaded', 'astimpay_init', 0);

function astimpay_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    include_once('includes/class-wc-astimpay-gateway.php');

    add_filter('woocommerce_payment_gateways', 'add_astimpay_gateway');

    function add_astimpay_gateway($gateways) {
        $gateways[] = 'WC_AstimPay_Gateway';
        return $gateways;
    }
}
