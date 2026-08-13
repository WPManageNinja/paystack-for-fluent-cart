<?php
/**
 * Paystack Gateway Class
 *
 * @package PaystackFluentCart
 * @since 1.0.0
 */


namespace PaystackFluentCart;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use PaystackFluentCart\Settings\PaystackSettingsBase;
use PaystackFluentCart\Subscriptions\PaystackSubscriptions;
use PaystackFluentCart\Refund\PaystackRefund;

class PaystackGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'paystack';
    private $addonSlug = 'paystack-for-fluent-cart';
    private $addonFile = 'paystack-for-fluent-cart/paystack-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions',
        'manual_subscription'
    ];

    public function __construct()
    {
        parent::__construct(
            new PaystackSettingsBase(),
            new PaystackSubscriptions()
        );

        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'paystack';
            return $methods;
        });
    }

    public function meta(): array
    {
        $logo = PAYSTACK_FCT_PLUGIN_URL . 'assets/images/paystack-logo.svg';
        
        return [
            'title'              => __('Paystack', 'paystack-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Paystack',
            'admin_title'        => 'Paystack',
            'description'        => __('Pay securely with Paystack - Card, Bank Transfer, USSD, and more', 'paystack-for-fluent-cart'),
            'logo'               => $logo,
            'tag' => 'beta',
            'icon'               => $logo,
            'brand_color'        => '#00C3F7',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'is_addon'           => true,
            'addon_source'       => [
                'type' => 'github',
                'link' => 'https://github.com/WPManageNinja/paystack-for-fluent-cart/releases/latest',
                'slug' => $this->addonSlug,
                'file' => $this->addonFile,
                'is_installed' => true
            ],
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new Webhook\PaystackWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/paystack_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\PaystackConfirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            if ($this->shouldChargeSubscriptionAsOneTime($paymentInstance)) {
                return (new Onetime\PaystackProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
            }

            return (new Subscriptions\PaystackSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Onetime\PaystackProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        PaystackHelper::checkCurrencySupport();

        $publicKey = (new Settings\PaystackSettingsBase())->getPublicKey();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'paystack-for-fluent-cart'),
            'payment_args' => [
                'public_key' => $publicKey

            ],
        ], 200);
    }


    public function handleIPN(): void
    {
        (new Webhook\PaystackWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'paystack-fluent-cart-checkout-handler',
                'src'    => PAYSTACK_FCT_PLUGIN_URL . 'assets/paystack-checkout.js',
                'version' => PAYSTACK_FCT_VERSION
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_paystack_data' => [
                'public_key' => $this->settings->getPublicKey(),
                'button_text' => $this->getCheckoutButtonText(),
                'body_text'   => $this->getCheckoutBodyText(),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'paystack-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'paystack-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'paystack-for-fluent-cart'),
                    'Pay securely, available payment options are shown in the next step.' => __('Pay securely, available payment options are shown in the next step.', 'paystack-for-fluent-cart'),
                    'Pay with Paystack' => __('Pay with Paystack', 'paystack-for-fluent-cart'),
                    'Processing...' => __('Processing...', 'paystack-for-fluent-cart'),
                    'Verifying payment...' => __('Verifying payment...', 'paystack-for-fluent-cart'),
                    'Payment cancelled' => __('Payment cancelled', 'paystack-for-fluent-cart'),
                    'Order handler not available' => __('Order handler not available', 'paystack-for-fluent-cart'),
                    'Payment data not received' => __('Payment data not received', 'paystack-for-fluent-cart'),
                    'An unknown error occurred' => __('An unknown error occurred', 'paystack-for-fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'paystack-for-fluent-cart'),
                    'An error occurred while loading paystack.' => __('An error occurred while loading paystack.', 'paystack-for-fluent-cart'),
                ],
                'nonce' => wp_create_nonce('paystack_fct_nonce')
            ]
        ];
    }

    private function getCheckoutButtonText(): string
    {
        $buttonText = $this->settings->get('checkout_button_text');

        if (is_string($buttonText) && trim($buttonText) !== '') {
            return trim($buttonText);
        }

        return __('Pay with Paystack', 'paystack-for-fluent-cart');
    }

    private function getCheckoutBodyText(): string
    {
        $bodyText = $this->settings->get('checkout_body_text');

        if (is_string($bodyText) && trim($bodyText) !== '') {
            return trim($bodyText);
        }

        return __('Pay securely, available payment options are shown in the next step.', 'paystack-for-fluent-cart');
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return 'https://dashboard.paystack.com/#/transactions';
        }

        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === status::TRANSACTION_REFUNDED) {
            return 'https://dashboard.paystack.com/#/refunds/' . $paymentId;
        }

        return 'https://dashboard.paystack.com/#/transactions/' . $paymentId;
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        if (!$subscription || !$subscription->vendor_subscription_id) {
            return 'https://dashboard.paystack.com/#/subscriptions';
        }

        return 'https://dashboard.paystack.com/#/subscriptions/' . $subscription->vendor_subscription_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'paystack_refund_error',
                __('PaystackRefund amount is required.', 'paystack-for-fluent-cart')
            );
        }

        return (new PaystackRefund())->processRemoteRefund($transaction, $amount, $args);

    }

    public function getWebhookInstructions(): array
    { 
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=paystack');
        $configureLink = 'https://dashboard.paystack.com/#/settings/developers';
        
        $svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M10 6V8H5V19H16V14H18V20C18 20.5523 17.5523 21 17 21H4C3.44772 21 3 20.5523 3 20V7C3 6.44772 3.44772 6 4 6H10ZM21 3V11H19L18.9999 6.413L11.2071 14.2071L9.79289 12.7929L17.5849 5H13V3H21Z"></path></svg>';

        /* translators: %s: Paystack Developer Settings link link with icon */
        $step = fn($url) => \sprintf(
            '<p>%s</p>',
            \sprintf(
                __('Click %1$s', 'paystack-for-fluent-cart'),
                \sprintf('<a href="%s" target="_blank">%s %s</a>', esc_url($url), __('Paystack Developer Settings Page', 'paystack-for-fluent-cart'), $svg)
            )
        );

        return [
            'title'       => __('Webhook URL', 'paystack-for-fluent-cart'),
            'webhook_url' => esc_url($webhook_url),
            'description' => __('You should configure your webhook URL in Paystack Dashboard.', 'paystack-for-fluent-cart'),
            'steps'       => [
                'title' => __('How to configure?', 'paystack-for-fluent-cart'),
                'list'  => [
                    __('In your Paystack Dashboard under Settings &rarr; Developers', 'paystack-for-fluent-cart'),
                    $step($configureLink),
                ],
            ],
        ];

    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'paystack-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'paystack-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_public_key' => [
                                'value'       => '',
                                'label'       => __('Live Public Key', 'paystack-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('pk_live_xxxxxxxxxxxxxxxx', 'paystack-for-fluent-cart'),
                            ],
                            'live_secret_key' => [
                                'value'       => '',
                                'label'       => __('Live Secret Key', 'paystack-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('sk_live_xxxxxxxxxxxxxxxx', 'paystack-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'paystack-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_public_key' => [
                                'value'       => '',
                                'label'       => __('Test Public Key', 'paystack-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('pk_test_xxxxxxxxxxxxxxxx', 'paystack-for-fluent-cart'),
                            ],
                            'test_secret_key' => [
                                'value'       => '',
                                'label'       => __('Test Secret Key', 'paystack-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('sk_test_xxxxxxxxxxxxxxxx', 'paystack-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'checkout_button_text' => [
                'value'       => __('Pay with Paystack', 'paystack-for-fluent-cart'),
                'label'       => __('Checkout Button Text', 'paystack-for-fluent-cart'),
                'type'        => 'text',
                'placeholder' => __('Pay with Paystack', 'paystack-for-fluent-cart'),
            ],
            'checkout_body_text' => [
                'value'       => __('Pay securely, available payment options are shown in the next step.', 'paystack-for-fluent-cart'),
                'label'       => __('Checkout Body Text', 'paystack-for-fluent-cart'),
                'type'        => 'text',
                'placeholder' => __('Pay securely, available payment options are shown in the next step.', 'paystack-for-fluent-cart'),
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'paystack-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_secret_key'] = Helper::encryptKey($data['test_secret_key']);
        } else {
            $data['live_secret_key'] = Helper::encryptKey($data['live_secret_key']);
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('paystack', new self());
    }
}

