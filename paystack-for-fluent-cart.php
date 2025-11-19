<?php
/**
 * Plugin Name: Paystack for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Paystack in FluentCart - supports one-time payments, subscriptions, and automatic refunds via webhooks.
 * Version: 1.0.1
 * Author: FluentCart
 * Author URI: https://fluentcart.com
 * Text Domain: paystack-for-fluent-cart
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('PAYSTACK_FC_VERSION', '1.0.1');
define('PAYSTACK_FC_PLUGIN_FILE', __FILE__);
define('PAYSTACK_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYSTACK_FC_PLUGIN_URL', plugin_dir_url(__FILE__));


function paystack_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Paystack for FluentCart', 'paystack-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'paystack-for-fluent-cart'); ?>
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
                    <strong><?php esc_html_e('Paystack for FluentCart', 'paystack-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher', 'paystack-for-fluent-cart'); ?>
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


// Activation and deactivation hooks
register_activation_hook(__FILE__, 'paystack_fc_on_activation');
register_deactivation_hook(__FILE__, 'paystack_fc_on_deactivation');

/**
 * Plugin activation callback
 */
function paystack_fc_on_activation() {
    if (!paystack_fc_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Paystack for FluentCart requires FluentCart to be installed and activated.', 'paystack-for-fluent-cart'),
            esc_html__('Plugin Activation Error', 'paystack-for-fluent-cart'),
            ['back_link' => true]
        );
    }
    
    // Set default options
    $default_options = [
        'paystack_fc_version' => PAYSTACK_FC_VERSION,
        'paystack_fc_installed_time' => current_time('timestamp'),
    ];
    
    foreach ($default_options as $option => $value) {
        add_option($option, $value);
    }
    
    // Clear any relevant caches
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

/**
 * Plugin deactivation callback
 */
function paystack_fc_on_deactivation() {
    // Clear transients
    delete_transient('paystack_fc_api_status');
    
    // Clear wp_cache if object caching is enabled
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('paystack_fc');
    }
    
    // Note: We do not delete options or user data on deactivation
    // Only on uninstall (handled in uninstall.php)
}

// Legacy activation hook for backward compatibility
register_activation_hook(__FILE__, function() {
    paystack_fc_on_activation();
});

