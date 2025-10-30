# Paystack for FluentCart - Setup Guide

## ✅ Plugin Created Successfully!

The plugin structure has been created following the Mollie gateway pattern. Here's what was created:

## 📁 Directory Structure

```
paystack-for-fluent-cart/
├── paystack-for-fluent-cart.php          # Main plugin file with autoloader
├── README.md                              # Developer documentation
├── readme.txt                             # WordPress.org format readme
├── SETUP.md                               # This file
├── .gitignore                             # Git ignore file
│
├── assets/
│   ├── paystack-checkout.js              # Frontend payment handler
│   └── images/
│       └── paystack-logo.svg             # Payment method logo
│
└── includes/
    ├── PaystackGateway.php               # Main gateway class (extends AbstractPaymentGateway)
    │
    ├── API/
    │   └── PaystackAPI.php               # Paystack API client wrapper
    │
    ├── Webhook/
    │   └── PaystackWebhook.php           # Webhook handler for payment notifications
    │
    ├── Onetime/
    │   └── PaystackProcessor.php         # One-time payment processor
    │
    ├── Subscriptions/
    │   └── PaystackSubscriptions.php     # Subscription management
    │
    ├── Settings/
    │   └── PaystackSettingsBase.php      # Gateway settings management
    │
    └── Confirmations/
        └── PaystackConfirmations.php     # Payment confirmation handler
```

## 🚀 Quick Start

### 1. Activate the Plugin

```bash
# Navigate to WordPress admin
Plugins > Installed Plugins > Activate "Paystack for FluentCart"
```

### 2. Configure Settings

1. Go to **FluentCart > Settings > Payment Methods**
2. Find **Paystack** in the list
3. Click to configure
4. Add your API keys:
   - **Test Mode**: Use test keys for development
   - **Live Mode**: Use live keys for production

### 3. Get API Keys

1. Log into [Paystack Dashboard](https://dashboard.paystack.com)
2. Go to **Settings > API Keys & Webhooks**
3. Copy your **Public Key** and **Secret Key**

### 4. Configure Webhook

1. In Paystack Dashboard, go to **Settings > API Keys & Webhooks**
2. Add webhook URL:
   ```
   https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=paystack
   ```
3. Select events to listen for (recommended: all events)

## 🎯 How It Works

### Registration Flow

1. Plugin loads via `plugins_loaded` hook
2. Checks if FluentCart is active
3. Registers PSR-4 autoloader for `PaystackFluentCart\` namespace
4. Hooks into `fluent_cart/register_payment_methods`
5. Calls `PaystackGateway::register()` which registers with FluentCart

### Payment Flow

1. **Customer initiates checkout** → FluentCart collects order info
2. **FluentCart calls** `makePaymentFromPaymentInstance()` 
3. **PaystackProcessor** prepares payment data
4. **JavaScript handler** opens Paystack popup
5. **Customer pays** on Paystack's secure platform
6. **Paystack webhook** notifies your site
7. **PaystackWebhook** verifies and processes payment
8. **FluentCart** completes the order

## 🔧 Implementation Status

### ✅ Completed

- [x] Plugin structure and organization
- [x] Gateway registration with FluentCart
- [x] Settings management (test/live mode)
- [x] Payment method metadata
- [x] Frontend JavaScript handler
- [x] Webhook endpoint and signature verification
- [x] Confirmation page handler
- [x] API client structure
- [x] Subscription module structure
- [x] Refund method structure
- [x] Currency validation
- [x] Transaction URL generation
- [x] Subscription URL generation

### 🚧 Needs Implementation (Your Part)

These are marked with `// TODO:` comments in the code:

#### 1. API Integration (`includes/API/PaystackAPI.php`)
- Already has methods defined, just needs testing
- Methods available:
  - `initializeTransaction()`
  - `verifyTransaction()`
  - `createSubscription()`
  - `disableSubscription()`
  - `createRefund()`
  - `getTransaction()`
  - `getSubscription()`

#### 2. Payment Processing (`includes/Onetime/PaystackProcessor.php`)
- `handleSinglePayment()` - Initialize transaction via API
- `handleSubscription()` - Create subscription and first charge

#### 3. Webhook Handlers (`includes/Webhook/PaystackWebhook.php`)
- `handleSubscriptionCreate()` - Process subscription creation
- `handleSubscriptionDisable()` - Process subscription cancellation
- `handleRefundProcessed()` - Process refund confirmations

#### 4. Confirmations (`includes/Confirmations/PaystackConfirmations.php`)
- `maybeConfirmPayment()` - Verify payment on return URL

#### 5. Subscriptions (`includes/Subscriptions/PaystackSubscriptions.php`)
- `reSyncSubscriptionFromRemote()` - Sync subscription status
- `cancel()` - Cancel subscription via API

#### 6. Refunds (`includes/PaystackGateway.php`)
- `processRefund()` - Process refund via API

## 📝 Code Examples

### Initialize a Transaction

```php
$api = new \PaystackFluentCart\API\PaystackAPI();
$response = $api->initializeTransaction([
    'amount' => 10000, // in kobo (100 NGN)
    'email' => 'customer@example.com',
    'reference' => 'unique-ref-123'
]);

if (is_wp_error($response)) {
    // Handle error
} else {
    $authUrl = $response['data']['authorization_url'];
    // Redirect customer to $authUrl
}
```

### Verify a Transaction

```php
$api = new \PaystackFluentCart\API\PaystackAPI();
$response = $api->verifyTransaction('unique-ref-123');

if (!is_wp_error($response) && $response['data']['status'] === 'success') {
    // Payment successful
}
```

## 🧪 Testing

### Test Cards

Use these test cards from [Paystack documentation](https://paystack.com/docs/payments/test-payments/):

- **Success**: `4084084084084081` | CVV: `408` | PIN: `0000` | Expiry: Any future date
- **Insufficient Funds**: `4084080000000408`
- **Timeout**: `5060666666666666666`

### Test Process

1. Enable **Test Mode** in Paystack settings
2. Use test API keys
3. Create a test order
4. Use test cards at checkout
5. Verify webhook receives notification
6. Check order status updates correctly

## 🔗 Useful Links

- [Paystack API Documentation](https://paystack.com/docs/api/)
- [Paystack Test Payments](https://paystack.com/docs/payments/test-payments/)
- [FluentCart Documentation](https://fluentcart.com/docs/)
- [Paystack Webhooks Guide](https://paystack.com/docs/payments/webhooks/)

## 🛠️ Customization

### Filters Available

```php
// Modify payment arguments before sending to Paystack
add_filter('paystack_fc/payment_args', function($paymentData, $context) {
    // $paymentData = array of payment data
    // $context = ['order' => $order, 'transaction' => $transaction]
    return $paymentData;
}, 10, 2);

// Modify subscription payment arguments
add_filter('paystack_fc/subscription_payment_args', function($paymentData, $context) {
    return $paymentData;
}, 10, 2);

// Modify settings
add_filter('paystack_fc/paystack_settings', function($settings) {
    return $settings;
}, 10, 1);
```

## 🐛 Debugging

### Enable Debug Logging

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Check Logs

```bash
tail -f wp-content/debug.log
```

### Webhook Testing

Use Paystack's webhook testing feature in the dashboard to send test webhooks.

## 📋 Next Steps

1. ✅ **Plugin is ready** - Structure is complete
2. 🔧 **Implement API calls** - Complete the TODO items
3. 🧪 **Test in sandbox** - Use test mode and test cards
4. 🚀 **Go live** - Switch to live mode with live keys
5. 📊 **Monitor** - Check Paystack dashboard for transactions

## ⚠️ Important Notes

- The plugin follows FluentCart's payment gateway structure
- It extends `AbstractPaymentGateway` as required
- Auto-loading uses PSR-4 standard
- All currency amounts should be in smallest unit (kobo for NGN)
- Webhook signature verification is critical for security
- Always test in sandbox mode first

## 💡 Support

For implementation help:
- Review the Mollie gateway implementation in FluentCart Pro
- Check FluentCart's payment gateway documentation
- Review Paystack's API documentation
- Test each component individually

---

**Created**: October 30, 2025
**Version**: 1.0.0
**FluentCart Compatibility**: Latest version

