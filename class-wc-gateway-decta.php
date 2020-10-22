<?php
/*
Plugin Name: DectaGateway-WooCommerce
Description: DectaGateway WooCommerce payment gateway
Version: 2.1
Author: DectaGateway
Author URI:
Copyright: © 2020 DectaGateway
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

// based on http://docs.woothemes.com/document/woocommerce-payment-gateway-plugin-base/
// docs http://docs.woothemes.com/document/payment-gateway-api/

require_once __DIR__ . '/decta_api.php';
require_once __DIR__ . '/decta_logger_wc.php';

add_action('plugins_loaded', 'wc_dectalv_init');
function wc_dectalv_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    /**
     * Localisation
     */
    load_plugin_textdomain('woocommerce-decta', false, dirname(plugin_basename(__FILE__)) . '/languages');

    /**
     * Gateway class
     */
    class WC_Decta_Gateway extends WC_Payment_Gateway
    {

        /** Logging is disabled by default */
        public static $log_enabled = false;
        /** Logger instance */
        public static $log = false;

        public function __construct()
        {
            $this->id = 'decta';
            $this->method_title = "DectaGateway";
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = "Visa / MasterCard";
            $this->description = __('Pay with Visa / Mastercard', 'woocommerce-decta');
            $this->debug = 'yes' === $this->get_option('debug', 'no');

            self::$log_enabled    = $this->debug;

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'));

            str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Decta_Gateway', home_url('/')));
            add_action('woocommerce_api_wc_gateway_decta', array( $this, 'handle_callback' ));
        }

        public function handle_callback()
        {
            // Docs http://docs.woothemes.com/document/payment-gateway-api/
            // Handle the thing here! http://127.0.0.1/wordpress/?wc-api=wc_gateway_decta&id=&action={paid,sent}
            // The new URL scheme (http://127.0.0.1/wordpress/wc-api/wc_gateway_decta) is broken for some reason.
            // Old one still works.
            global $woocommerce;
            $order = new WC_Order($_GET["id"]);

            $decta = new DectaAPI(
                $this->settings['private-key'],
                $this->settings['public-key'],
                new DectaLoggerWC(self::$log_enabled)
            );

            $decta->log_info('Success callback');
            $payment_id = WC()->session->get('decta_payment_id');
            if ($decta->was_payment_successful((string)$order->get_order_number(), $payment_id)) {
                $order->payment_complete();
                $order->reduce_order_stock();
                $order->add_order_note(__('Payment successful.', 'woocommerce-decta'));
            } else {
                $order->update_status('wc-failed', __('ERROR: Payment was received, but order verification failed.'));
            }
            $decta->log_info('Done processing success, redirecting');
            header("Location: " . $this->get_return_url($order));
        }

        public function init_form_fields()
        {
            // transaction options
            $tx_options = array('payment' => __('Payment', 'woocommerce-decta'), 'authorization' => __('Authorization', 'woocommerce-decta'));

            $this->form_fields = array(
                'enabled' 		=> array(
                'title'       	=> __('Enable API', 'woocommerce-decta'),
                'label'       	=> __('Enable API', 'woocommerce-decta'),
                'type'        	=> 'checkbox',
                'description' 	=> '',
                'default'     	=> 'no'
                ),
                'public-key' 	=> array(
                'title' 		=> __('Public key', 'woocommerce-decta'),
                'type' 			=> 'text',
                'description' 	=> __('Please enter your public key.', 'woocommerce-decta'),
                'default' 		=> ''
                ),
                'private-key' 	=> array(
                'title' 	  	=> __('Secret key', 'woocommerce-decta'),
                'type' 		  	=> 'text',
                'description' 	=> __('Please enter your secret key.', 'woocommerce-decta'),
                'default' 	  	=> ''
                ),
                'debug' 	  	=> array(
                'title'       	=> __('Debug Log', 'woocommerce-decta'),
                'type'        	=> 'checkbox',
                'label'       	=> __('Enable logging', 'woocommerce-decta'),
                'default'     	=> 'yes',
                'description' 	=> sprintf(__('Log DectaGateway events, inside <code>%s</code>', 'woocommerce-decta'), wc_get_log_file_path('DectaGateway'))
                )
            );
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $decta = new DectaAPI(
                $this->settings['private-key'],
                $this->settings['public-key'],
                new DectaLoggerWC(self::$log_enabled)
            );

            $params = array(
                'number' => (string)$order->get_order_number(),
                'referrer' => 'woocommerce v4.x module ' . DECTA_MODULE_VERSION,
                'language' =>  $this->_language('en'),
                'success_redirect' => home_url().'/?wc-api=wc_gateway_decta&action=paid&id='.$order_id,
                'failure_redirect' => $order->get_cancel_order_url(),
                'currency' => $order->get_currency()
            );

            $this->addUserData($decta, $order, $params);
            $this->addProducts($order, $params);

            $payment = $decta->create_payment($params);
            WC()->session->set('decta_payment_id', $payment['id']);
            $decta->log_info('Got checkout url, redirecting');
            $payment['result'] = 'success';
            $payment['redirect'] = $payment['full_page_checkout'];

            return $payment;
        }

        protected function addUserData($decta, $order, &$params)
        {
            $user_data = array(
                'email' => $order->billing_email,
                'phone' => $order->billing_phone,
                'first_name' => $order->billing_first_name,
                'last_name' => $order->billing_last_name,
                'send_to_email' => true
            );
            $findUser = $decta->getUser($user_data['email'], $user_data['phone']);
            if (!$findUser) {
                if ($decta->createUser($user_data)) {
                    $findUser = $decta->getUser($user_data['email'], $user_data['phone']);
                }
            }
            $user_data['original_client'] = $findUser['id'];
            $params['client'] = $user_data;
        }

        protected function addProducts($order, &$params)
        {
            $params['products'][] = [
                'price' => round($order->get_total(), 2),
                'title' => 'default',
                'quantity' => 1
            ];
        }

        public static function _language($lang_id)
        {
            $languages = array('en', 'ru', 'lv');
            
            if (in_array(strtolower($lang_id), $languages)) {
                return $lang_id;
            } else {
                return 'en';
            }
        }
    }

    /**
    * Add the Gateway to WooCommerce
    **/
    function woocommerce_add_decta_gateway($methods)
    {
        $methods[] = 'WC_Decta_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_decta_gateway');
}
