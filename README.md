# Paystack for FluentCart

[![Download Latest](https://img.shields.io/badge/Download-Latest-blue?style=for-the-badge&logo=github)](https://github.com/WPManageNinja/paystack-for-fluent-cart/releases/latest/download/paystack-for-fluent-cart.zip)

A WordPress plugin that integrates Paystack payment gateway with FluentCart.

## Features

- ✅ One-time payments
- ✅ Subscription support
- ✅ Refund processing
- ✅ Webhook integration
- ✅ Test and Live modes
- ✅ Multiple currency support (NGN, GHS, ZAR, USD)

## Installation

### Prerequisites

- WordPress 5.6 or higher
- PHP 7.4 or higher
- [FluentCart](https://wordpress.org/plugins/fluent-cart/) plugin installed and activated
- A [Paystack](https://paystack.com) account

### Install & Activate

1. **Download the Plugin**
   - Visit the [latest release](../../releases/latest)
   - Download the `Source code (zip)` file

2. **Upload to WordPress**
   - Go to your WordPress admin dashboard
   - Navigate to **Plugins > Add New**
   - Click **Upload Plugin**
   - Select the downloaded zip file and click **Install Now**

3. **Activate the Plugin**
   - After installation, click **Activate Plugin**
   - Alternatively, go to **Plugins** and click "Activate" below the plugin name

4. **Configure Paystack**
   - Go to **FluentCart > Settings > Payment Methods**
   - Find and enable **Paystack**
   - Enter your Test and Live API keys from the [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer)
   - Configure your webhook URL (see [Configuration](#configuration) below)

## Updates

To update the Paystack for FluentCart addon:

1. **Check for Updates**
   - Go to **FluentCart > Settings > Payment Methods**
   - Click on the **Paystack** payment method
   - Click the **Check for Updates** button

2. **Download the New Version**
   - If a new version is available, an **Update Now** button will appear
   - Clicking this button will take you to the latest release page
   - Download the `Source code (zip)` file

3. **Install the Update**
   - Go to **Plugins > Add New > Upload Plugin**
   - Upload the new zip file
   - WordPress will automatically replace the old version with the new one
   - Reactivate the plugin if prompted

> **Note:** Since this addon is distributed via GitHub releases (not the WordPress Plugin Directory), updates must be installed manually using the steps above.

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- FluentCart plugin (free or pro version)
- Paystack account

## Configuration

1. Get your API keys from your [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer)
2. Add your test/live public and secret keys in the FluentCart Paystack settings
3. Configure webhook URL in Paystack Dashboard:
   ```
   https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=paystack
   ```

## For Developers

This plugin serves as both a production-ready payment gateway and a **comprehensive example** for third-party developers who want to integrate their own payment gateways with FluentCart.

### 📚 Integration Documentation

See **[PAYMENT_GATEWAY_INTEGRATION_GUIDE.md](CUSTOM_PAYMENT_GATEWAY_INTEGRATION_GUIDE.md)** for a complete guide on building payment gateway integrations for FluentCart, including:

- Plugin setup and structure
- Gateway registration with FluentCart API  
- Settings field configuration (all supported field types)
- Payment method rendering options (hooks, custom JS)
- Checkout processing (redirect, onsite, popup/modal)
- Payment confirmation handling
- Web hook/IPN implementation
- Subscription and refund support
- Testing and debugging

### Example Implementations

- **Redirect Payment**: See `Onetime/PaystackProcessor.php` 
- **Popup/Modal Payment**: See `assets/js/paystack-checkout.js`
- **Web hook Handling**: See `Webhook/PaystackWebhook.php`
- **Settings Management**: See `Settings/PaystackSettingsBase.php`

## Development

### Directory Structure

```
paystack-for-fluent-cart/
├── paystack-for-fluent-cart.php    # Main plugin file
├── assets/
│   ├── paystack-checkout.js        # Frontend payment handler
│   └── images/
│       └── paystack-logo.svg       # Payment method logo
├── includes/
│   ├── PaystackGateway.php         # Main gateway class
│   ├── API/
│   │   └── PaystackAPI.php         # API client
│   ├── Webhook/
│   │   └── PaystackWebhook.php     # Webhook handler
│   ├── Onetime/
│   │   └── PaystackProcessor.php   # One-time payment processor
│   ├── Subscriptions/
│   │   └── PaystackSubscriptions.php # Subscription handler
│   ├── Settings/
│   │   └── PaystackSettingsBase.php  # Settings management
│   └── Confirmations/
│       └── PaystackConfirmations.php # Payment confirmations
└── README.md
```

### Hooks and Filters

#### Filters

- `paystack_fc/payment_args` - Modify payment arguments before sending to Paystack
- `paystack_fc/subscription_payment_args` - Modify subscription payment arguments
- `paystack_fc/paystack_settings` - Modify Paystack settings

#### Actions

- `paystack_fc/payment_success` - Triggered on successful payment
- `paystack_fc/payment_failed` - Triggered on failed payment
- `paystack_fc/subscription_created` - Triggered when subscription is created

## TODO

The following features need implementation:

1. **API Integration**
   - [ ] Complete Paystack API client implementation
   - [ ] Initialize transaction API call
   - [ ] Verify transaction API call
   - [ ] Subscription plan creation
   - [ ] Subscription management

2. **Payment Processing**
   - [ ] Complete handleSinglePayment implementation
   - [ ] Complete handleSubscription implementation
   - [ ] Implement refund processing

3. **Webhooks**
   - [ ] Complete webhook handlers for all events
   - [ ] Subscription webhook handlers
   - [ ] Refund webhook handlers

4. **Confirmations**
   - [ ] Payment verification on return URL
   - [ ] Subscription activation confirmation

## Testing

### Test Mode

1. Enable test mode in settings
2. Use test API keys from Paystack
3. Use [Paystack test cards](https://paystack.com/docs/payments/test-payments/)

### Test Cards

- Success: `4084084084084081`
- Insufficient funds: `4084080000000408`
- Timeout: `5060666666666666666`

## Support

For issues, questions, or contributions, please contact the plugin author.

## License

GPLv2 or later. See LICENSE file for details.

[Guide to Integrate CustomPayment Gateway with FluentCart](CUSTOM_PAYMENT_GATEWAY_INTEGRATION_GUIDE.md)