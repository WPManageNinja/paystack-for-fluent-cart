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
        'subscriptions'
    ];

    public function __construct()
    {
        parent::__construct(
            new PaystackSettingsBase(),
            new PaystackSubscriptions()
        );
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
                'file' => $this->addonFile
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
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'paystack-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'paystack-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'paystack-for-fluent-cart'),
                ],
                'nonce' => wp_create_nonce('paystack_fct_nonce')
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

    public function getWebhhoInstructions(): string
    { 
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=paystack');
        $configureLink = 'https://dashboard.paystack.com/#/settings/developers';

        return sprintf(
            '<div>
                <p><b>%s</b><code class="copyable-content">%s</code></p>
                <p>%s</p>
            </div>',
            __('Webhook URL: ', 'paystack-for-fluent-cart'),
            esc_html($webhook_url),
            sprintf(
                /* translators: %s: Paystack Developer Settings link */
                __('Configure this webhook URL in your Paystack Dashboard under Settings > Developers to receive payment notifications. You can access the <a href="%1$s" target="_blank">%2$s</a> here.', 'paystack-for-fluent-cart'),
                esc_url($configureLink),
                __('Paystack Developer Settings Page', 'paystack-for-fluent-cart')
            )
        );

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
            'webhook_info' => [
                'value' => $this->getWebhhoInstructions(),
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

