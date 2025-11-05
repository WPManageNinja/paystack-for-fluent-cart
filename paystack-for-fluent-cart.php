<?php
/**
 * Plugin Name: Paystack for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Paystack in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.
 * Version: 1.0.0
 * Author: FluentCart
 * Author URI: https://fluentcart.com
 * Text Domain: paystack-for-fluent-cart
 * Domain Path: /languages
 * Requires plugins: fluent-cart
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or exit;

// Define plugin constants
define('PAYSTACK_FC_VERSION', '1.0.0');
define('PAYSTACK_FC_PLUGIN_FILE', __FILE__);
define('PAYSTACK_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYSTACK_FC_PLUGIN_URL', plugin_dir_url(__FILE__));


add_action('plugins_loaded', function() {
    load_plugin_textdomain('paystack-for-fluent-cart', false, dirname(plugin_basename(__FILE__)) . '/languages');
});


function paystack_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e('Paystack for FluentCart', 'paystack-for-fluent-cart'); ?></strong> 
                    <?php _e('requires FluentCart to be installed and activated.', 'paystack-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e('Paystack for FluentCart', 'paystack-for-fluent-cart'); ?></strong> 
                    <?php printf(__('requires FluentCart version %s or higher. You have version %s installed.', 'paystack-for-fluent-cart'), '1.2.5', FLUENTCART_VERSION); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    return true;
}


add_action('plugins_loaded', function() {
    if (!paystack_fc_check_dependencies()) {
        return;
    }

    spl_autoload_register(function ($class) {
        $prefix = 'PaystackFluentCart\\';
        $base_dir = PAYSTACK_FC_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    add_action('fluent_cart/register_payment_methods', function($data) {
        \PaystackFluentCart\PaystackGateway::register();
    }, 10);

}, 20);


register_activation_hook(__FILE__, function() {
    if (!paystack_fc_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Paystack for FluentCart requires FluentCart to be installed and activated.', 'paystack-for-fluent-cart'),
            __('Plugin Activation Error', 'paystack-for-fluent-cart'),
            ['back_link' => true]
        );
    }
});

