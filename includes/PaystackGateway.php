<?php

namespace PaystackFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;

class PaystackGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'paystack';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions'
    ];

    public function __construct()
    {
        parent::__construct(
            new Settings\PaystackSettingsBase(), 
            new Subscriptions\PaystackSubscriptions()
        );
    }

    public function meta(): array
    {
        $logo = PAYSTACK_FC_PLUGIN_URL . 'assets/images/paystack-logo.svg';
        
        return [
            'title'              => __('Paystack', 'paystack-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Paystack',
            'admin_title'        => 'Paystack',
            'description'        => __('Pay securely with Paystack - Card, Bank Transfer, USSD, and more', 'paystack-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#00C3F7',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
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
            return (new Subscriptions\PaystackSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Onetime\PaystackProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        $this->checkCurrencySupport();

        $publicKey = (new Settings\PaystackSettingsBase())->getPublicKey();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'paystack-for-fluent-cart'),
            'intent'         => [
                'amount' => 12900,
                'currency' => 'NGN'
            ],
            'payment_args' => [
                'public_key' => $publicKey
            ],
        ], 200);
    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getPaystackSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Paystack does not support the currency you are using!', 'paystack-for-fluent-cart')
            ], 422);
        }
    }

    public static function getPaystackSupportedCurrency(): array
    {
        return ['NGN', 'GHS', 'ZAR', 'USD'];
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
                'src'    => PAYSTACK_FC_PLUGIN_URL . 'assets/paystack-checkout.js',
                'version' => PAYSTACK_FC_VERSION
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
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'paystack-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'paystack-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'paystack-for-fluent-cart'),
                ]
            ]
        ];
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

        $isLive = $this->settings->getMode() === 'live';
        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === 'refunded') {
            $parentTransaction = OrderTransaction::query()
                ->where('id', Arr::get($transaction->meta, 'parent_id'))
                ->first();
            if ($parentTransaction) {
                $paymentId = $parentTransaction->vendor_charge_id;
            }
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
                __('Refund amount is required.', 'paystack-for-fluent-cart')
            );
        }

        // Your Paystack refund logic here
        return new \WP_Error(
            'paystack_refund_error',
            __('Paystack refund implementation pending.', 'paystack-for-fluent-cart')
        );
    }

    public function fields(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=paystack');
        
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
            'webhook_info' => [
                'value' => sprintf(
                    '<div><p><b>%s</b><code class="copyable-content">%s</code></p><p>%s</p></div>',
                    __('Webhook URL: ', 'paystack-for-fluent-cart'),
                    $webhook_url,
                    __('Configure this webhook URL in your Paystack Dashboard under Settings > Webhooks to receive payment notifications.', 'paystack-for-fluent-cart')
                ),
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

