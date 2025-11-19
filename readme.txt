=== Paystack for FluentCart ===
Tags: paystack, fluentcart, nigeria, ghana, south africa
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Contributors: hasanuzzamanshamim, akmelias

Accept payments via Paystack in FluentCart - supports one-time payments, subscriptions, and automatic refunds.

== Description ==

**⚠️ THIRD-PARTY SERVICE NOTICE: This plugin connects to Paystack's external API (api.paystack.co) to process payments. See "Third-Party Service Disclosure" section below for complete details.**

**Paystack for FluentCart** seamlessly integrates the powerful Paystack payment gateway with FluentCart, enabling you to accept payments from customers across Nigeria, Ghana, South Africa, and internationally with support for multiple currencies.

= Why Choose Paystack for FluentCart? =

✅ **Secure & Trusted** - Industry-standard security with PCI DSS compliance
✅ **Multiple Payment Methods** - Cards, Bank Transfer, USSD, Mobile Money, and more  
✅ **Multi-Currency Support** - NGN, GHS, ZAR, USD
✅ **Subscription Ready** - Recurring payments support
✅ **Instant Notifications** - Real-time webhook integration
✅ **Easy Setup** - Configure in minutes with your Paystack credentials

= Key Features =

* **One-time Payments**: Accept card payments, bank transfers, USSD, and mobile money
* **Recurring Subscriptions**: Full support for subscription-based businesses
* **Automatic Refunds**: Process refunds directly from your WordPress dashboard
* **Webhook Integration**: Automatic payment verification and order updates
* **Test & Live Modes**: Test your integration thoroughly before going live
* **Multi-Currency**: Support for NGN, GHS, ZAR, and USD
* **Mobile Optimized**: Responsive checkout experience across all devices

= Supported Countries & Currencies =

* 🇳🇬 **Nigeria** - NGN (Nigerian Naira)
* 🇬🇭 **Ghana** - GHS (Ghanaian Cedi)  
* 🇿🇦 **South Africa** - ZAR (South African Rand)
* 🌍 **International** - USD (US Dollar)

= Requirements =

* WordPress 5.6 or higher
* PHP 7.4 or higher
* FluentCart plugin (free)
* SSL certificate (required for live payments)
* Paystack merchant account ([Sign up for free](https://paystack.com))

= Important: Third-Party Service Disclosure =

**This plugin relies on Paystack, a third-party payment processing service, to function properly.**

**External API Connections:**
This plugin makes API calls to Paystack's servers (https://api.paystack.co) to:
- Initialize payment transactions
- Verify payment status
- Process refunds
- Manage subscription billing
- Handle webhook notifications

**Data Transmission:**
When processing payments, the following data is transmitted to Paystack:
- Customer email addresses
- Transaction amounts and currency
- Order reference numbers
- Customer billing information (if provided)
- Subscription details (for recurring payments)

**Legal Requirements:**
By using this plugin, you and your customers are subject to:
- [Paystack Terms of Service](https://paystack.com/terms)
- [Paystack Privacy Policy](https://paystack.com/privacy)
- [Paystack Cookie Policy](https://paystack.com/privacy#cookies)

**Data Security:**
- No sensitive payment card data is stored on your server
- All payment processing occurs on Paystack's PCI DSS compliant infrastructure
- Communication with Paystack uses SSL/TLS encryption
- Only non-sensitive transaction metadata is stored locally for order management

**Service Availability:**
This plugin's payment functionality depends on Paystack's service availability. Paystack service outages may temporarily affect payment processing.

**Compliance Notice:**
Ensure your website's privacy policy includes disclosure of data sharing with Paystack for payment processing purposes, as required by GDPR and other privacy regulations.

== Installation ==

= Automatic Installation =

1. Go to your WordPress admin dashboard
2. Navigate to Plugins → Add New
3. Search for "Paystack for FluentCart"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Upload to `/wp-content/plugins/paystack-for-fluent-cart/`
3. Activate the plugin through the 'Plugins' menu in WordPress

= Configuration =

1. Ensure FluentCart is installed and activated
2. Get your API keys from [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer)
3. Go to FluentCart → Settings → Payment Methods
4. Find "Paystack" and click to configure
5. Enter your test/live api keys
6. Configure webhook URL: `https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=paystack`
7. Save your settings and test with a small transaction

== Frequently Asked Questions ==

= Do I need a Paystack account? =

Yes, you need a Paystack merchant account. You can sign up for free at [paystack.com](https://paystack.com). The approval process typically takes 1-2 business days.

= Where do I get my API keys? =

1. Log into your [Paystack Dashboard](https://dashboard.paystack.com)
2. Navigate to Settings → API Keys & Webhooks
3. Copy your Public and Secret keys (use test keys for testing)

= Does this support recurring subscriptions? =

Yes! The plugin fully supports Paystack's subscription API for recurring payments. You can create subscription products in FluentCart and they'll automatically sync with Paystack.

= Is it secure? =

Absolutely. All transactions are processed securely through Paystack's PCI DSS compliant infrastructure. No sensitive card details are ever stored on your server.

= What happens if a payment fails? =

Failed payments are automatically logged and customers are notified. You can view all transaction statuses in your FluentCart dashboard and Paystack dashboard.

= Can I test before going live? =

Yes! The plugin includes full test mode support. Use your test API keys to process test transactions without any real charges.

= What currencies are supported? =

Currently supported: NGN (Nigerian Naira), GHS (Ghanaian Cedi), ZAR (South African Rand), and USD (US Dollar).

= Do I need an SSL certificate? =

Yes, SSL is required for live payment processing. Most hosting providers offer free SSL certificates through Let's Encrypt.

== Screenshots ==
1. Payment method list in FluentCart settings
2. Easy configuration in FluentCart settings
3. Rendered on checkout page
4. Seamless checkout experience for customers  
5. Payment confirmation
6. Order details in WordPress dashboard

== Changelog ==

= 1.0.0 =
* Initial release
* Support for one-time payments
* Subscription payments foundation
* Webhook integration for automatic order updates
* Refund processing capabilities
* Test and live mode support
* Multi-currency support (NGN, GHS, ZAR, USD)
* Security enhancements and input validation
* WordPress.org compliance improvements

== Upgrade Notice ==

= 1.0.0 =
Initial release of Paystack for FluentCart. Install now to start accepting payments through Paystack.

== Support ==

For support, please visit:
* [FluentCart Support](https://fluentcart.com/support)
* [WordPress.org Support Forum](https://wordpress.org/support/plugin/paystack-for-fluent-cart)

