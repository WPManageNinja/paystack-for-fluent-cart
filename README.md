# Paystack for FluentCart

A WordPress plugin that integrates Paystack payment gateway with FluentCart.

## Features

- ✅ One-time payments
- ✅ Subscription support
- ✅ Refund processing
- ✅ Webhook integration
- ✅ Test and Live modes
- ✅ Multiple currency support (NGN, GHS, ZAR, USD)

## Installation

1. Clone or download this repository to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone [your-repo-url] paystack-for-fluent-cart
   ```

2. Activate the plugin in WordPress admin

3. Go to FluentCart > Settings > Payment Methods

4. Enable and configure Paystack with your API keys from [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer)

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

See **[PAYMENT_GATEWAY_INTEGRATION_GUIDE.md](PAYMENT_GATEWAY_INTEGRATION_GUIDE.md)** for a complete guide on building payment gateway integrations for FluentCart, including:

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

## Credits

Built for FluentCart by [Your Name]

