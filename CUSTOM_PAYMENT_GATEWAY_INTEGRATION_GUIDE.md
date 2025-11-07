# FluentCart Payment Gateway Integration Guide

A comprehensive guide for third-party developers to integrate custom payment gateways with FluentCart

## Table of Contents

1. [Introduction](#introduction)
2. [Setup & Prerequisites](#setup--prerequisites)
3. [Gateway Registration](#gateway-registration)
4. [Settings Fields Configuration](#settings-fields-configuration)
5. [Payment Method Rendering](#payment-method-rendering)
6. [Checkout Processing](#checkout-processing)
7. [Payment Processing](#payment-processing)
8. [Payment Confirmation](#payment-confirmation)
9. [Webhook/IPN Handling](#webhookipn-handling)
10. [Advanced Features](#advanced-features)
11. [Testing & Debugging](#testing--debugging)
12. [Complete Example](#complete-example)

---

## Introduction

FluentCart provides a flexible architecture for integrating custom payment gateways. This guide will help you create a payment gateway plugin that seamlessly integrates with FluentCart's checkout process, supports various payment flows, and handles both one-time payments and subscriptions.

### What You'll Learn

- How to create a payment gateway plugin structure
- Register your gateway with FluentCart
- Configure settings fields for admin panel
- Handle different checkout flows (redirect, onsite, popup/modal)
- Process payments and handle confirmations
- Implement webhook/IPN handlers
- Support subscriptions and refunds

---

## Setup & Prerequisites

### Prerequisites

- WordPress 5.6+
- PHP 7.4+
- FluentCart Plugin (Free or Pro)
- Basic understanding of WordPress plugin development
- Familiarity with your payment gateway's API

### Plugin Structure

Create a WordPress plugin with the following structure:

```
your-gateway-for-fluent-cart/
├── your-gateway-for-fluent-cart.php    # Main plugin file
├── includes/
│   ├── YourGateway.php                 # Main gateway class
│   ├── Settings/
│   │   └── YourGatewaySettings.php     # Settings management
│   ├── Processor/
│   │   └── PaymentProcessor.php        # Payment processing logic
│   ├── Webhook/
│   │   └── WebhookHandler.php          # Webhook/IPN handler
│   ├── Confirmations/
│   │   └── PaymentConfirmations.php    # Payment confirmation handler
│   └── API/
│       └── ApiClient.php               # API communication
├── assets/
│   ├── css/
│   ├── js/
│   │   └── checkout-handler.js         # Frontend checkout handling
│   └── images/
│       └── gateway-logo.svg            # Gateway logo
├── languages/                          # Translation files
└── README.md                           # Plugin documentation
```

### Main Plugin File

```php
<?php
/**
 * Plugin Name: Your Gateway for FluentCart
 * Plugin URI: https://yourwebsite.com
 * Description: Payment gateway integration for FluentCart
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: your-gateway-for-fluent-cart
 * Requires plugins: fluent-cart
 * Requires at least: 5.6
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('YOUR_GATEWAY_FC_VERSION', '1.0.0');
define('YOUR_GATEWAY_FC_PLUGIN_FILE', __FILE__);
define('YOUR_GATEWAY_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YOUR_GATEWAY_FC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check dependencies
function your_gateway_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Your Gateway for FluentCart</strong> requires FluentCart to be installed and activated.</p></div>';
        });
        return false;
    }
    return true;
}

// Initialize plugin
add_action('plugins_loaded', function() {
    if (!your_gateway_fc_check_dependencies()) {
        return;
    }

    // Autoloader
    spl_autoload_register(function ($class) {
        $prefix = 'YourGatewayFluentCart\\';
        $base_dir = YOUR_GATEWAY_FC_PLUGIN_DIR . 'includes/';

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

    // Register payment method
    add_action('fluent_cart/register_payment_methods', function() {
        \YourGatewayFluentCart\YourGateway::register();
    });
}, 20);
```

---

## Gateway Registration

### Main Gateway Class

Create your main gateway class that extends `AbstractPaymentGateway`:

```php
<?php

namespace YourGatewayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;

class YourGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'your_gateway';

    public array $supportedFeatures = [
        'payment',          // Basic payment processing
        'refund',          // Refund support
        'webhook',         // Webhook/IPN support
        'subscriptions',   // Subscription support
        'custom_payment'   // Custom checkout flow
    ];

    public function __construct()
    {
        parent::__construct(
            new YourGatewaySettings(),
            // new YourGatewaySubscriptions() // Optional for subscriptions
        );
    }

    public function meta(): array
    {
        $logo = YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/images/gateway-logo.svg';
        
        return [
            'title'              => __('Your Gateway', 'your-gateway-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Your Gateway',
            'admin_title'        => 'Your Gateway',
            'description'        => __('Pay securely with Your Gateway', 'your-gateway-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#007cba',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Initialize components
        (new Webhook\WebhookHandler())->init();
        (new Confirmations\PaymentConfirmations())->init();
    }

    // called after order placed to handle payment
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            // Handle subscription payments
            return (new Processor\SubscriptionProcessor())->handle($paymentInstance);
        }

        // Handle one-time payments
        return (new Processor\PaymentProcessor())->handle($paymentInstance);
    }

    public static function register()
    {
        fluent_cart_api()->registerCustomPaymentMethod('your_gateway', new self());
    }
}
```

---

## Settings Fields Configuration

### Settings Class

The settings class manages your gateway's configuration:

```php
<?php

namespace YourGatewayFluentCart\Settings;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class YourGatewaySettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_your_gateway';

    public static function getDefaults()
    {
        return [
            'is_active'       => 'no',
            'payment_mode'    => 'test',
            'test_api_key'    => '',
            'test_secret_key' => '',
            'live_api_key'    => '',
            'live_secret_key' => '',
            'webhook_secret'  => '',
            'checkout_mode'   => 'redirect', // redirect, onsite, popup
        ];
    }

    public function getApiKey($mode = null)
    {
        $mode = $mode ?: $this->get('payment_mode', 'test');
        return $this->get($mode . '_api_key');
    }

    public function getSecretKey($mode = null)
    {
        $mode = $mode ?: $this->get('payment_mode', 'test');
        return $this->get($mode . '_secret_key');
    }
}
```

### Settings Fields Definition

Define the admin settings fields in your main gateway class:

```php
public function fields(): array
{
    return [
        'notice' => [
            'type'  => 'notice',
            'value' => '<p>Configure your gateway settings below. Get your API keys from your gateway dashboard.</p>'
        ],
        
        'is_active' => [
            'type'    => 'enable',
            'label'   => __('Enable/Disable', 'your-gateway-for-fluent-cart'),
            'value'   => 'yes',
        ],

        'payment_mode' => [
            'type'   => 'tabs',
            'schema' => [
                [
                    'type'   => 'tab',
                    'label'  => __('Test Credentials', 'your-gateway-for-fluent-cart'),
                    'value'  => 'test',
                    'schema' => [
                        'test_api_key' => [
                            'type'        => 'password',
                            'label'       => __('Test API Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your test API key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                        'test_secret_key' => [
                            'type'        => 'password',
                            'label'       => __('Test Secret Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your test secret key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                    ]
                ],
                [
                    'type'   => 'tab',
                    'label'  => __('Live Credentials', 'your-gateway-for-fluent-cart'),
                    'value'  => 'live',
                    'schema' => [
                        'live_api_key' => [
                            'type'        => 'password',
                            'label'       => __('Live API Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your live API key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                        'live_secret_key' => [
                            'type'        => 'password',
                            'label'       => __('Live Secret Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your live secret key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                    ]
                ]
            ]
        ],

        'checkout_mode' => [
            'type'    => 'select',
            'label'   => __('Checkout Mode', 'your-gateway-for-fluent-cart'),
            'value'   => 'redirect',
            'options' => [
                'redirect' => [
                    'label' => __('Redirect to Gateway', 'your-gateway-for-fluent-cart'),
                    'value' => 'redirect'
                ],
                'onsite' => [
                    'label' => __('On-site Payment', 'your-gateway-for-fluent-cart'),
                    'value' => 'onsite'
                ],
                'popup' => [
                    'label' => __('Popup/Modal', 'your-gateway-for-fluent-cart'),
                    'value' => 'popup'
                ]
            ],
            'tooltip' => __('Choose how customers will interact with your payment gateway', 'your-gateway-for-fluent-cart')
        ],

        'webhook_info' => [
            'type'  => 'webhook_info',
            'mode'  => 'both', // or 'test'/'live'
            'info'  => $this->getWebhookInstructions()
        ]
    ];
}

private function getWebhookInstructions()
{
    $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=your_gateway');
    
    return '<h4>Webhook Configuration</h4>
            <p>Configure webhooks in your gateway dashboard:</p>
            <p><strong>Webhook URL:</strong> <code>' . $webhook_url . '</code></p>
            <p><strong>Events to listen:</strong> payment.succeeded, payment.failed, subscription.created, etc.</p>';
}
```

### Available Field Types

Based on the Renderer.vue file, these field types are supported:

- `notice` - Display informational text
- `upcoming` - Show "Upcoming" banner
- `provider` - Connect account integration
- `enable` - On/off toggle switch
- `checkbox` - Single checkbox
- `select` - Dropdown selection
- `radio` - Radio button group
- `webhook_info` - Webhook configuration display
- `input` - Text input
- `password` - Password input
- `number` - Number input
- `email` - Email input
- `text` - Text input
- `color` - Color picker
- `file` - File upload (media library)
- `checkbox_group` - Multiple checkboxes
- `html_attr` - Raw HTML content
- `active_methods` - Display active payment methods
- `tabs` - Tabbed interface for grouping fields
- `radio-select-dependants` - Radio with dependent fields

---

All field types support these common properties:

| Property | Description |
|----------|-------------|
| `type` | Required. Defines the field type (see available types below) |
| `label` | The field label displayed to the user |
| `value` | Default value for the field |
| `placeholder` | Placeholder text for input fields |
| `tooltip` | Brief tooltip displayed on hover |
| `description` | Brief description displayed below the field |
| `max_length` | Maximum length of the text/input/password field |
| `disabled` | Whether the field is disabled (boolean) |

## Payment Method Rendering

### Option 1: Using Hook (Recommended)

Use the `fluent_cart/checkout_embed_payment_method_content` hook to render custom payment elements:

```php
// In your gateway's boot() method
add_action('fluent_cart/checkout_embed_payment_method_content', [$this, 'renderPaymentContent'], 10, 3);

public function renderPaymentContent($method_name, $order_data, $form_id)
{
    if ($method_name !== $this->methodSlug) {
        return;
    }

    $checkout_mode = $this->settings->get('checkout_mode', 'redirect');
    
    switch ($checkout_mode) {
        case 'onsite':
            $this->renderOnsiteForm($order_data);
            break;
        case 'popup':
            $this->renderPopupButton($order_data);
            break;
        case 'redirect':
        default:
            $this->renderRedirectNotice($order_data);
            break;
    }
}

private function renderOnsiteForm($order_data)
{
    echo '<div class="fluent-cart-your-gateway-form">
            <div id="your-gateway-card-element">
                <!-- Card input elements will be inserted here by JavaScript -->
            </div>
            <div id="your-gateway-errors" role="alert"></div>
          </div>';
}

private function renderPopupButton($order_data)
{
    echo '<div class="fluent-cart-your-gateway-popup">
            <button type="button" id="your-gateway-popup-btn" class="btn btn-primary">
                ' . __('Pay with Your Gateway', 'your-gateway-for-fluent-cart') . '
            </button>
          </div>';
}

private function renderRedirectNotice($order_data)
{
    echo '<div class="fluent-cart-your-gateway-redirect">
            <p>' . __('You will be redirected to Your Gateway to complete your payment.', 'your-gateway-for-fluent-cart') . '</p>
          </div>';
}
```

### Option 2: Custom JavaScript

Include custom JavaScript for advanced interactions:

```php
// In your gateway's main class
public function getEnqueueScriptSrc($hasSubscription = 'no'): array
{
    return [
        [
            'handle' => 'your-gateway-sdk',
            'src'    => 'https://js.yourgateway.com/v3/',
        ],
        [
            'handle' => 'your-gateway-checkout',
            'src'    => YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/js/checkout-handler.js',
            'deps'   => ['your-gateway-sdk'],
            'data'   => [
                'api_key'     => $this->settings->getApiKey(),
                'mode'        => $this->settings->get('payment_mode'),
                'ajax_url'    => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('your_gateway_nonce'),
                'translations' => [
                    'loading'        => __('Processing payment...', 'your-gateway-for-fluent-cart'),
                    'error'          => __('Payment failed. Please try again.', 'your-gateway-for-fluent-cart'),
                    'confirm'        => __('Confirm Payment', 'your-gateway-for-fluent-cart'),
                ]
            ]
        ]
    ];
}
```

---

## Checkout Processing

### Payment Processor Class

Create a processor to handle different checkout flows:

```php
<?php

namespace YourGatewayFluentCart\Processor;

use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;
use YourGatewayFluentCart\API\ApiClient;

class PaymentProcessor
{
    private $settings;
    private $apiClient;

    public function __construct()
    {
        $this->settings = new YourGatewaySettings();
        $this->apiClient = new ApiClient($this->settings);
    }

    public function handle(PaymentInstance $paymentInstance)
    {
        $checkout_mode = $this->settings->get('checkout_mode', 'redirect');

        switch ($checkout_mode) {
            case 'redirect':
                return $this->handleRedirectCheckout($paymentInstance);
            case 'onsite':
                return $this->handleOnsiteCheckout($paymentInstance);
            case 'popup':
                return $this->handlePopupCheckout($paymentInstance);
            default:
                return $this->handleRedirectCheckout($paymentInstance);
        }
    }
}
```

### Redirect Checkout Implementation

```php
private function handleRedirectCheckout(PaymentInstance $paymentInstance)
{
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    // Create payment session with your gateway
    $paymentData = [
        'amount'      => $transaction->payment_total,
        'currency'    => $order->currency,
        'description' => "Order #{$order->id}",
        'metadata'    => [
            'order_id'       => $order->id,
            'transaction_id' => $transaction->id,
            'customer_email' => $order->customer->email
        ],
        'success_url' => $this->getSuccessUrl($transaction),
        'cancel_url'  => $this->getCancelUrl($order),
    ];

    $response = $this->apiClient->createCheckoutSession($paymentData);

    if (is_wp_error($response)) {
        return [
            'nextAction' => 'error',
            'status'     => 'failed',
            'message'    => $response->get_error_message()
        ];
    }

    return [
        'status'     => 'success',
        'message'    => __('Redirecting to payment gateway...', 'your-gateway-for-fluent-cart'),
        'redirect_to' => $response['checkout_url'],
    ];
}
```

### On-site Checkout Implementation

```php
private function handleOnsiteCheckout(PaymentInstance $paymentInstance)
{
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    // Create payment intent
    $intentData = [
        'amount'   => $transaction->payment_total,
        'currency' => $order->currency,
        'metadata' => [
            'order_id'       => $order->id,
            'transaction_id' => $transaction->id,
        ]
    ];

    $intent = $this->apiClient->createPaymentIntent($intentData);

    if (is_wp_error($intent)) {
        return [
            'nextAction' => 'error',
            'status'     => 'failed',
            'message'    => $intent->get_error_message()
        ];
    }

     // make payment to your gateway and confirm
    return [
        'status'       => 'success',
        'redirect_to'  => $transaction->getReceiptPageUrl(),
    ]

    // or if you have custom js file to handle payment
    return [
        'status'       => 'success',
        'message'      => __('Please complete your payment details', 'your-gateway-for-fluent-cart'),
        'actionName'   => 'custom',
        'nextAction'   => 'your_gateway', // This should match your gateway slug
        'payment_args' => [
            'client_secret' => $intent['client_secret'],
            'api_key'       => $this->settings->getApiKey(),
            'intent_id'     => $intent['id'],
        ],
    ];
}
```

### Popup/Modal Checkout Implementation

```php
private function handlePopupCheckout(PaymentInstance $paymentInstance)
{
    // Similar to redirect but with popup handling
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    $modalData = [
        'amount'        => $transaction->payment_total,
        'currency'      => $order->currency,
        'customer_email' => $order->customer->email,
        'order_id'      => $order->id,
        'transaction_id' => $transaction->id,
    ];

    // make payment to your gateway and confirm
    return [
        'status'       => 'success',
        'redirect_to'  => $transaction->getReceiptPageUrl(),
    ]

    // or if you have custom js file to handle payment
    return [
        'status'       => 'success',
        'message'      => __('Opening payment modal...', 'your-gateway-for-fluent-cart'),
        'actionName'   => 'custom',
        'nextAction'   => 'your_gateway',
        'payment_args' => [
            'modal_data' => $modalData,
            'api_key'    => $this->settings->getApiKey(),
        ],
    ];
}
```

---

## Payment Processing

### Frontend JavaScript Handler (optional)

Create a Custom JavaScript file to handle the frontend interactions:

```javascript
// assets/js/checkout-handler.js

class YourGatewayCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.response = response;
        this.paymentArgs = response?.payment_args || {};
        this.paymentLoader = paymentLoader;
        
        this.init();
    }

    init() {
        const actionName = this.response?.actionName;
        
        switch (actionName) {
            case 'custom':
                this.handleOnsitePayment();
                break;
            case 'popup':
                this.handlePopupPayment();
                break;
            default:
                console.log('Unknown action:', actionName);
        }
    }

    handleOnsitePayment() {
        // Initialize your gateway's SDK
        const gateway = YourGateway(this.paymentArgs.api_key);
        
        // Create payment elements
        const cardElement = gateway.elements().create('card');
        cardElement.mount('#your-gateway-card-element');

        // Handle form submission
        const submitButton = this.form.querySelector('.fluent_cart_pay_btn');
        submitButton.addEventListener('click', (e) => {
            e.preventDefault();
            this.processOnsitePayment(gateway, cardElement);
        });
    }

    async processOnsitePayment(gateway, cardElement) {
        this.paymentLoader.enableCheckoutButton(false);
        
        try {
            const { error, paymentMethod } = await gateway.createPaymentMethod({
                type: 'card',
                card: cardElement,
            });

            if (error) {
                this.showError(error.message);
                return;
            }

            // Confirm payment with your backend
            const confirmResult = await this.confirmPayment({
                payment_method_id: paymentMethod.id,
                client_secret: this.paymentArgs.client_secret,
            });

            if (confirmResult.success) {
                this.orderHandler.redirectToSuccessPage(confirmResult.redirect_url);
            } else {
                this.showError(confirmResult.message);
            }
        } catch (error) {
            this.showError('Payment processing failed');
        } finally {
            this.paymentLoader.enableCheckoutButton(true);
        }
    }

    handlePopupPayment() {
        const popup = this.createPaymentPopup();
        popup.open(this.paymentArgs.modal_data);
        
        popup.on('success', (result) => {
            this.handlePaymentSuccess(result);
        });
        
        popup.on('error', (error) => {
            this.showError(error.message);
        });
    }

    async confirmPayment(paymentData) {
        const response = await fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'your_gateway_confirm_payment',
                nonce: your_gateway_data.nonce,
                ...paymentData
            })
        });

        return await response.json();
    }

    showError(message) {
        const errorElement = document.getElementById('your-gateway-errors');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }
    }
}

// Register with FluentCart
window.addEventListener("fluent_cart_load_payments_your_gateway", function (e) {
    new YourGatewayCheckout(
        e.detail.form, 
        e.detail.orderHandler, 
        e.detail.response, 
        e.detail.paymentLoader
    );
});
```

---

## Payment Confirmation

### Understanding FluentCart Order Flow

FluentCart has a sophisticated order processing system that handles different types of orders. Understanding this flow is crucial for proper payment gateway integration.

#### Order Types

FluentCart distinguishes between different order types:

- **Regular Orders** (`ORDER_TYPE_SINGLE`): One-time purchases
- **Subscription Orders** (`ORDER_TYPE_SUBSCRIPTION`): Initial subscription setup
- **Renewal Orders** (`ORDER_TYPE_RENEWAL`): Subscription renewals

#### Critical Integration Points

1. **Transaction Status Updates**: Always update transaction status before order processing
2. **Billing Information Storage**: Store payment method details for future renewals
3. **Order Type Handling**: Different order types require different completion flows
4. **Receipt URL Generation**: Proper redirect after payment confirmation

#### Important Notes for Developers

- **Renewal Orders**: These are automatically generated by FluentCart for subscription renewals. When processing payment for renewal orders, you must use `SubscriptionService::recordManualRenewal()` instead of regular order completion flow.

- **Billing Info Storage**: Always store billing information (card details, payment method IDs) as it's used for future subscription charges and customer reference.

- **Status Synchronization**: Use `StatusHelper` to properly sync order statuses based on product fulfillment requirements (digital vs physical products).

- **Receipt URLs**: The `getReceiptPageUrl()` method provides the default success page. Use the `fluentcart/transaction/receipt_page_url` filter to customize redirect behavior.

### Confirmation Handler Class

Create a class to handle payment confirmations:

```php
<?php

namespace YourGatewayFluentCart\Confirmations;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;

class PaymentConfirmations
{
    public function init()
    {
        add_action('wp_ajax_your_gateway_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_your_gateway_confirm_payment', [$this, 'confirmPayment']);
        
        // Handle redirect to fluent-cart thank you page confirmations
        add_action('fluent_cart/before_render_redirect_page', [$this, 'handleRedirectConfirmation'], 10, 1);
    }

    public function confirmPayment()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'your_gateway_nonce')) {
            wp_send_json_error(['message' => 'Invalid request']);
        }

        $payment_method_id = sanitize_text_field($_POST['payment_method_id']);
        $client_secret = sanitize_text_field($_POST['client_secret']);

        // Confirm payment with your gateway
        $confirmation = $this->confirmWithGateway($payment_method_id, $client_secret);

        if (is_wp_error($confirmation)) {
            wp_send_json_error(['message' => $confirmation->get_error_message()]);
        }

        // Update transaction status
        $transaction = OrderTransaction::where('vendor_transaction_id', $confirmation['payment_id'])->first();
        
        if ($transaction) {
            $this->updateTransactionStatus($transaction, $confirmation);
            
            wp_send_json_success([
                'message' => 'Payment confirmed successfully',
                'redirect_url' => $this->getSuccessRedirectUrl($transaction)
            ]);
        }

        wp_send_json_error(['message' => 'Transaction not found']);
    }

    public function handleRedirectConfirmation($data)
    {
        // Check if payment was successful based on URL parameters
        $payment_id = $_GET['payment_id'] ?? null;
        $status = $_GET['status'] ?? null;

        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();

        if ($payment_id && $status === 'success') {
            // Verify payment with your gateway
            $payment = $this->verifyPaymentStatus($payment_id);
            
            if ($payment && $payment['status'] === 'succeeded') {
                $this->updateTransactionStatus($transaction, $payment);
            }
        }

      
        wp_send_json_success([
            'message' => 'Payment confirmed successfully',
            'redirect_url' => $this->getSuccessRedirectUrl($transaction)
        ]);
    }

     /**
     * Get the success redirect URL after payment confirmation
     * This is where customers will be redirected after successful payment
     */
    private function getSuccessRedirectUrl($transaction)
    {
        // Get the default receipt page URL from transaction
        $receiptUrl = $transaction->getReceiptPageUrl();
        
        // getReceiptPageUrl is a filterable function that returns the default receipt page URL

        // filter is 'fluentcart/transaction/receipt_page_url
        
    }

    private function updateTransactionStatus($transaction, $paymentData)
    {
        $transaction->update([
            'status' => Status::PAID,
            'vendor_transaction_id' => $paymentData['id'],
            'payment_note' => 'Payment completed via Your Gateway',
            'updated_at' => current_time('mysql')
        ]);

        // Handle different order types (regular orders vs subscription renewals)
        $order = $transaction->order;
        if ($order) {
            $this->handleOrderCompletion($order, $transaction, $paymentData);
        }
    }

    private function handleOrderCompletion($order, $transaction, $paymentData)
    {
        // Prepare billing info from payment data
        $billingInfo = [
            'type' => $paymentData['payment_method_type'] ?? 'card',
            'last4' => $paymentData['last4'] ?? null,
            'brand' => $paymentData['brand'] ?? null,
            'payment_method_id' => $paymentData['payment_method_id'] ?? null,
        ];

        // Check if this is a subscription renewal order
        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();
            
            if ($subscriptionModel) {
                // Handle subscription renewal - this updates subscription status and billing cycle
                $subscriptionData = $paymentData['subscription_data'] ?? [];
                
                return SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                    'billing_info' => $billingInfo,
                    'subscription_args' => $subscriptionData
                ]);
            }
        }

        // Handle regular orders - this will update order status based on fulfillment requirements
        $statusHelper = new StatusHelper($order);
        $statusHelper->syncOrderStatuses($transaction);
    }
}
```

### Success/Cancel URLs

Implement URL generators for payment flow:

```php
private function getSuccessUrl($transaction)
{
    return add_query_arg([
        'fct_redirect' => 'yes',
        'method' => 'your_gateway',
        'trx_hash' => $transaction->transaction_hash,
        'status' => 'success'
    ], site_url());
}

private function getCancelUrl($order)
{
    return add_query_arg([
        'fct_redirect' => 'yes',
        'method' => 'your_gateway',
        'status' => 'cancelled'
    ], fluent_cart_get_checkout_url());
}
```

---

## Webhook/IPN Handling

### Webhook Handler Class

```php
<?php

namespace YourGatewayFluentCart\Webhook;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use YourGatewayFluentCart\Settings\YourGatewaySettings;

class WebhookHandler
{
    private $settings;

    public function __construct()
    {
        $this->settings = new YourGatewaySettings();
    }

    public function init()
    {
        add_action('init', [$this, 'handleWebhook']);
    }

    public function handleWebhook()
    {
        if (!isset($_GET['fluent-cart']) || $_GET['fluent-cart'] !== 'fct_payment_listener_ipn') {
            return;
        }

        if (!isset($_GET['method']) || $_GET['method'] !== 'your_gateway') {
            return;
        }

        $this->processWebhook();
    }

    private function processWebhook()
    {
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_YOUR_GATEWAY_SIGNATURE'] ?? '';

        // Verify webhook signature
        if (!$this->verifySignature($payload, $signature)) {
            http_response_code(400);
            exit('Invalid signature');
        }

        $event = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON');
        }

        $this->handleWebhookEvent($event);
    }

    private function verifySignature($payload, $signature)
    {
        $webhook_secret = $this->settings->get('webhook_secret');
        
        if (empty($webhook_secret)) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }

    private function handleWebhookEvent($event)
    {
        $event_type = $event['type'] ?? '';
        
        switch ($event_type) {
            case 'payment.succeeded':
                $this->handlePaymentSucceeded($event['data']);
                break;
            case 'payment.failed':
                $this->handlePaymentFailed($event['data']);
                break;
            case 'subscription.created':
                $this->handleSubscriptionCreated($event['data']);
                break;
            case 'subscription.updated':
                $this->handleSubscriptionUpdated($event['data']);
                break;
            case 'subscription.cancelled':
                $this->handleSubscriptionCancelled($event['data']);
                break;
            default:
                // Log unknown event
                error_log("Unknown webhook event: {$event_type}");
        }

        http_response_code(200);
        exit('OK');
    }

    private function handlePaymentSucceeded($payment_data)
    {
        $payment_id = $payment_data['id'];
        $metadata = $payment_data['metadata'] ?? [];
        
        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();
        
        if (!$transaction) {
            // Try to find by order ID from metadata
            if (!empty($metadata['transaction_id'])) {
                $transaction = OrderTransaction::find($metadata['transaction_id']);
            }
        }

        if ($transaction && $transaction->status !== Status::PAID) {
            // Update transaction status
            $transaction->update([
                'status' => Status::PAID,
                'payment_note' => 'Payment confirmed via webhook',
                'updated_at' => current_time('mysql')
            ]);

            // Prepare billing info for storage
            $billingInfo = [
                'type' => $payment_data['payment_method_type'] ?? 'card',
                'last4' => $payment_data['last4'] ?? null,
                'brand' => $payment_data['brand'] ?? null,
                'payment_method_id' => $payment_data['payment_method_id'] ?? null,
            ];

            $order = $transaction->order;

            // Handle different order types appropriately
            if ($order->type == Status::ORDER_TYPE_RENEWAL) {
                // This is a subscription renewal - handle differently
                $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();
                
                if ($subscriptionModel) {
                    $subscriptionData = $payment_data['subscription_data'] ?? [];
                    
                    SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                        'billing_info' => $billingInfo,
                        'subscription_args' => $subscriptionData
                    ]);
                }
            } else {
                // Regular order - sync status based on product fulfillment requirements
                $statusHelper = new StatusHelper($order);
                $statusHelper->syncOrderStatuses($transaction);
            }

            // Trigger payment success events
            do_action('fluent_cart/payment_success', $order, $transaction);
        }
    }

    private function handlePaymentFailed($payment_data)
    {
        $payment_id = $payment_data['id'];
        
        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();
        
        if ($transaction) {
            $transaction->update([
                'status' => Status::FAILED,
                'payment_note' => 'Payment failed: ' . ($payment_data['failure_reason'] ?? 'Unknown reason'),
                'updated_at' => current_time('mysql')
            ]);

            do_action('fluent_cart/payment_failed', $transaction->order, $transaction);
        }
    }
}
```

---

## Advanced Features

### Subscription Support

```php
<?php

namespace YourGatewayFluentCart\Subscriptions;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\API\ApiClient;

class YourGatewaySubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription(PaymentInstance $paymentInstance, $args = [])
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;

        // Create subscription plan with your gateway
        $plan_data = [
            'amount' => $subscription->recurring_amount,
            'currency' => $order->currency,
            'interval' => $this->mapInterval($subscription->billing_interval),
            'product_name' => $subscription->subscription_items[0]->post_title ?? 'Subscription'
        ];

        $plan = $this->createSubscriptionPlan($plan_data);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Create subscription
        $subscription_data = [
            'plan_id' => $plan['id'],
            'customer_email' => $order->customer->email,
            'metadata' => [
                'order_id' => $order->id,
                'subscription_id' => $subscription->id
            ]
        ];

        return $this->createSubscription($subscription_data);
    }

    public function cancelSubscription($subscription, $args = [])
    {
        $vendor_subscription_id = $subscription->vendor_subscription_id;
        
        if (empty($vendor_subscription_id)) {
            return new \WP_Error('no_vendor_id', 'No vendor subscription ID found');
        }

        return (new ApiClient())->cancelSubscription($vendor_subscription_id);
    }

    private function mapInterval($fluentcart_interval)
    {
        $interval_map = [
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'year' => 'yearly'
        ];

        return $interval_map[$fluentcart_interval] ?? 'monthly';
    }
}
```

### Refund Support

```php
<?php

namespace YourGatewayFluentCart\Refund;

class RefundProcessor
{
    public function processRefund($transaction, $amount, $args = [])
    {
        if (!$amount || $amount <= 0) {
            return new \WP_Error('invalid_amount', 'Invalid refund amount');
        }

        $vendor_transaction_id = $transaction->vendor_transaction_id;
        
        if (empty($vendor_transaction_id)) {
            return new \WP_Error('no_transaction_id', 'No vendor transaction ID found');
        }

        $refund_data = [
            'payment_id' => $vendor_transaction_id,
            'amount' => $amount,
            'reason' => $args['reason'] ?? 'Requested by merchant'
        ];

        $response = (new ApiClient())->createRefund($refund_data);

        if (is_wp_error($response)) {
            return $response;
        }

        // Update transaction with refund info
        $transaction->update([
            'refund_amount' => $amount,
            'refund_status' => 'processing',
            'refund_note' => 'Refund initiated: ' . $response['id']
        ]);

        return [
            'status' => 'success',
            'refund_id' => $response['id'],
            'message' => 'Refund processed successfully'
        ];
    }
}
```

---

## Testing & Debugging

### Debug Logging

```php
// Add to your main gateway class
private function log($message, $data = [])
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Your Gateway] %s: %s',
            $message,
            wp_json_encode($data)
        ));
    }
}

// Usage
$this->log('Payment processing started', [
    'order_id' => $order->id,
    'amount' => $transaction->payment_total
]);
```

### Test Mode Detection

```php
public function isTestMode()
{
    return $this->settings->get('payment_mode') === 'test';
}

public function getApiEndpoint()
{
    return $this->isTestMode() 
        ? 'https://api-sandbox.yourgateway.com' 
        : 'https://api.yourgateway.com';
}
```

### Validation Helpers

```php
public static function validateSettings($data): array
{
    $mode = $data['payment_mode'] ?? 'test';
    $api_key = $data[$mode . '_api_key'] ?? '';
    $secret_key = $data[$mode . '_secret_key'] ?? '';

    if (empty($api_key) || empty($secret_key)) {
        return [
            'status' => 'failed',
            'message' => __('API keys are required', 'your-gateway-for-fluent-cart')
        ];
    }

    // Test API connection
    $api_client = new ApiClient(['api_key' => $api_key, 'secret_key' => $secret_key]);
    $test_result = $api_client->testConnection();

    if (is_wp_error($test_result)) {
        return [
            'status' => 'failed',
            'message' => $test_result->get_error_message()
        ];
    }

    return [
        'status' => 'success',
        'message' => __('Gateway credentials verified successfully!', 'your-gateway-for-fluent-cart')
    ];
}
```

---

## Complete Example

Here's how your main gateway class should look when everything is put together:

```php
<?php

namespace YourGatewayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;
use YourGatewayFluentCart\Subscriptions\YourGatewaySubscriptions;

class YourGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'your_gateway';

    public array $supportedFeatures = [
        'payment',
        'refund', 
        'webhook',
        'subscriptions',
        'custom_payment'
    ];

    public function __construct()
    {
        parent::__construct(
            new YourGatewaySettings(),
            new YourGatewaySubscriptions()
        );
    }

    public function meta(): array
    {
        $logo = YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/images/gateway-logo.svg';
        
        return [
            'title'              => __('Your Gateway', 'your-gateway-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Your Gateway',
            'admin_title'        => 'Your Gateway',
            'description'        => __('Pay securely with Your Gateway', 'your-gateway-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#007cba',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        (new Webhook\WebhookHandler())->init();
        (new Confirmations\PaymentConfirmations())->init();
        
        add_action('fluent_cart/checkout_embed_payment_method_content', [$this, 'renderPaymentContent'], 10, 3);
    }

    public function fields(): array
    {
        return [
            // ... settings fields as shown above
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            return $this->subscriptionHandler->handleSubscription($paymentInstance);
        }

        return (new Processor\PaymentProcessor())->handle($paymentInstance);
    }

    public function processRefund($transaction, $amount, $args)
    {
        return (new Refund\RefundProcessor())->processRefund($transaction, $amount, $args);
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'your-gateway-sdk',
                'src'    => 'https://js.yourgateway.com/v3/',
            ],
            [
                'handle' => 'your-gateway-checkout',
                'src'    => YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/js/checkout-handler.js',
                'deps'   => ['your-gateway-sdk']
            ]
        ];
    }

    public static function validateSettings($data): array
    {
        // Validation logic as shown above
    }

    public static function register()
    {
        fluent_cart_api()->registerCustomPaymentMethod('your_gateway', new self());
    }

    public function renderPaymentContent($method_name, $order_data, $form_id)
    {
        if ($method_name !== $this->methodSlug) {
            return;
        }
        
        // Render appropriate content based on checkout mode
    }
}
```

---

## Summary

This guide covers everything you need to create a comprehensive payment gateway integration for FluentCart:

1. **Setup**: Plugin structure and registration
2. **Settings**: Admin configuration with various field types
3. **Rendering**: Multiple options for displaying payment forms
4. **Processing**: Support for redirect, onsite, and popup checkout flows
5. **Confirmation**: Handling payment success/failure
6. **Webhooks**: Real-time payment status updates
7. **Advanced**: Subscriptions, refunds, and debugging

### Key Takeaways

- Always extend `AbstractPaymentGateway` for your main class
- Use the `fluent_cart/register_payment_methods` hook to register your gateway
- Support multiple checkout flows (redirect, onsite, popup) for flexibility
- Implement proper webhook handling for reliable payment confirmation
- Include comprehensive error handling and logging
- Test thoroughly in both test and live modes

### Next Steps

1. Study the existing Paystack implementation in this plugin
2. Check the Paddle implementation in FluentCart Pro for onsite payment examples
3. Refer to the custom payment method documentation for additional details [here](https://dev.fluentcart.com/payment-methods-integration/custom-payment-methods/)
4. Test your implementation thoroughly before releasing

For more examples and advanced features, examine the existing payment gateway implementations in the FluentCart codebase.
