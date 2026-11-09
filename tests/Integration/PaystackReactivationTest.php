<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Confirmations\PaystackConfirmations;
use PaystackFluentCart\Subscriptions\PaystackSubscriptions;
use PaystackFluentCart\Webhook\PaystackWebhook;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class PaystackReactivationTest extends WPTestCase
{
    private $subscriptionCreates = 0;

    private $planBodies = [];

    private $remoteCharge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriptionCreates = 0;
        $this->planBodies = [];
        $this->remoteCharge = null;
        $this->resetApiSettings();
        add_filter('paystack_fc/paystack_settings', function ($settings) {
            return array_merge($settings, [
                'test_secret_key' => Helper::encryptKey('sk_test_key'),
                'live_secret_key' => Helper::encryptKey('sk_live_key'),
            ]);
        });
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', function () {
            return function () {
                throw new \RuntimeException('wp_die');
            };
        });
        $this->mockApi();
    }

    protected function tearDown(): void
    {
        remove_all_filters('paystack_fc/paystack_settings');
        remove_all_filters('pre_http_request');
        remove_all_filters('wp_doing_ajax');
        remove_all_filters('wp_die_ajax_handler');
        unset($_REQUEST['paystack_fct_nonce'], $_REQUEST['trx_id']);
        $this->resetApiSettings();
        parent::tearDown();
    }

    public function test_webhook_reactivation_paying_the_last_installment_creates_no_paystack_subscription(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(3, 2);

        $this->webhook($this->charge($transaction, $subscription));

        $sub = Subscription::query()->find($subscription->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertSame(0, $this->subscriptionCreates);
        $this->assertSame(3, (int) $sub->bill_count);
        $this->assertSame(Status::SUBSCRIPTION_COMPLETED, $sub->status);
        $this->assertSame('SUB_old', $sub->vendor_subscription_id);
    }

    public function test_confirmation_reactivation_paying_the_last_installment_creates_no_paystack_subscription(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(3, 2);
        $this->remoteCharge = $this->charge($transaction, $subscription);

        $this->confirm();

        $sub = Subscription::query()->find($subscription->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertSame(0, $this->subscriptionCreates);
        $this->assertSame(3, (int) $sub->bill_count);
        $this->assertSame(Status::SUBSCRIPTION_COMPLETED, $sub->status);
    }

    public function test_last_installment_reactivation_from_another_gateway_drops_its_subscription_id(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(3, 2);
        $renewal = Order::query()->find($transaction->order_id);
        $renewal->config = ['old_payment_method' => 'stripe', 'old_vendor_subscription_id' => 'SUB_old'];
        $renewal->save();

        $this->webhook($this->charge($transaction, $subscription));

        $sub = Subscription::query()->find($subscription->id);
        $this->assertSame(0, $this->subscriptionCreates);
        $this->assertSame(Status::SUBSCRIPTION_COMPLETED, $sub->status);
        $this->assertEmpty($sub->vendor_subscription_id);
    }

    public function test_reactivation_with_an_installment_left_still_creates_the_paystack_subscription(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(3, 1);

        $this->webhook($this->charge($transaction, $subscription));

        $sub = Subscription::query()->find($subscription->id);
        $this->assertSame(1, $this->subscriptionCreates);
        $this->assertSame(2, (int) $sub->bill_count);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $sub->status);
        $this->assertSame('SUB_new', $sub->vendor_subscription_id);
    }

    public function test_reactivation_plan_is_capped_at_the_installments_left_after_this_payment(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(6, 2);

        $plan = PaystackSubscriptions::getOrCreatePayStackPlan($this->paymentInstance($transaction, $subscription));

        $this->assertSame('PLN_test', Arr::get($plan, 'data.plan_code'));
        $this->assertCount(1, $this->planBodies);
        $this->assertSame(3, (int) Arr::get($this->planBodies[0], 'invoice_limit'));
    }

    public function test_first_purchase_plan_keeps_the_full_installment_count(): void
    {
        [$transaction, $subscription] = $this->makeFirstPurchase(6);

        PaystackSubscriptions::getOrCreatePayStackPlan($this->paymentInstance($transaction, $subscription));

        $this->assertSame(6, (int) Arr::get($this->planBodies[0], 'invoice_limit'));
    }

    public function test_reactivation_plans_with_different_caps_are_cached_apart(): void
    {
        [$transaction, $subscription] = $this->makeReactivation(6, 2);
        PaystackSubscriptions::getOrCreatePayStackPlan($this->paymentInstance($transaction, $subscription));

        $this->paySucceeded($subscription, 1);
        [$nextTransaction] = $this->makeRenewalTransaction($subscription);
        PaystackSubscriptions::getOrCreatePayStackPlan($this->paymentInstance($nextTransaction, Subscription::query()->find($subscription->id)));

        $this->assertCount(2, $this->planBodies);
        $this->assertSame(2, (int) Arr::get($this->planBodies[1], 'invoice_limit'));
    }

    /**
     * Canceled automatic subscription with $paid succeeded charges, plus a pending
     * reactivation (renewal order) transaction, as pro's reactivation checkout leaves it.
     */
    private function makeReactivation(int $billTimes, int $paid): array
    {
        [$parentTransaction, $subscription] = $this->makeFirstPurchase($billTimes, [
            'status'                 => Status::SUBSCRIPTION_CANCELED,
            'vendor_subscription_id' => 'SUB_old',
        ]);

        $parentTransaction->update(['status' => Status::TRANSACTION_SUCCEEDED, 'subscription_id' => $subscription->id]);
        $this->paySucceeded($subscription, $paid - 1);

        [$transaction] = $this->makeRenewalTransaction($subscription);

        return [$transaction, Subscription::query()->find($subscription->id)];
    }

    private function makeFirstPurchase(int $billTimes, array $subscriptionAttributes = []): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'currency'       => 'NGN',
            'payment_method' => 'paystack',
            'mode'           => Status::ORDER_MODE_TEST,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 2500,
            'total_amount'   => 2500,
            'total_paid'     => 0,
        ]);

        $subscription = (new SubscriptionFactory())->create(array_merge([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'status'                 => Status::SUBSCRIPTION_PENDING,
            'collection_method'      => 'automatic',
            'current_payment_method' => 'paystack',
            'recurring_amount'       => 2500,
            'recurring_total'        => 2500,
            'bill_times'             => $billTimes,
        ], $subscriptionAttributes));

        $transaction = $this->transaction($order, Status::ORDER_TYPE_SUBSCRIPTION, Status::TRANSACTION_PENDING, $subscription->id);

        return [$transaction, $subscription];
    }

    private function makeRenewalTransaction(Subscription $subscription): array
    {
        $renewal = (new OrderFactory())->create([
            'customer_id'    => $subscription->customer_id,
            'parent_id'      => $subscription->parent_order_id,
            'currency'       => 'NGN',
            'payment_method' => 'paystack',
            'mode'           => Status::ORDER_MODE_TEST,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 2500,
            'total_amount'   => 2500,
            'total_paid'     => 0,
        ]);

        return [$this->transaction($renewal, Status::ORDER_TYPE_RENEWAL, Status::TRANSACTION_PENDING, $subscription->id)];
    }

    private function paySucceeded(Subscription $subscription, int $count): void
    {
        $order = Order::query()->find($subscription->parent_order_id);
        for ($i = 0; $i < $count; $i++) {
            $this->transaction($order, Status::ORDER_TYPE_RENEWAL, Status::TRANSACTION_SUCCEEDED, $subscription->id);
        }
    }

    private function transaction($order, string $orderType, string $status, int $subscriptionId): OrderTransaction
    {
        return OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => $orderType,
            'subscription_id'  => $subscriptionId,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method'   => 'paystack',
            'payment_mode'     => 'test',
            'status'           => $status,
            'currency'         => 'NGN',
            'total'            => 2500,
            'uuid'             => md5(wp_generate_uuid4()),
        ]);
    }

    private function paymentInstance(OrderTransaction $transaction, Subscription $subscription)
    {
        return (object) [
            'order'        => Order::query()->find($transaction->order_id),
            'transaction'  => $transaction,
            'subscription' => $subscription,
        ];
    }

    private function charge(OrderTransaction $transaction, Subscription $subscription): array
    {
        return [
            'id'               => 5123456789,
            'status'           => 'success',
            'amount'           => 2500,
            'requested_amount' => 2500,
            'currency'         => 'NGN',
            'paid_at'          => gmdate('c'),
            'metadata'         => [
                'transaction_hash'  => $transaction->uuid,
                'subscription_hash' => $subscription->uuid,
                'paystack_plan'     => 'PLN_test',
            ],
            'customer'         => ['customer_code' => 'CUS_test'],
            'authorization'    => ['authorization_code' => 'AUTH_test', 'last4' => '4081', 'channel' => 'card'],
        ];
    }

    private function webhook(array $charge): void
    {
        ob_start();
        try {
            (new PaystackWebhook())->handleChargeSuccess(['payload' => $charge, 'order' => null]);
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        }
        ob_end_clean();
    }

    private function confirm(): void
    {
        $_REQUEST['paystack_fct_nonce'] = wp_create_nonce('paystack_fct_nonce');
        $_REQUEST['trx_id'] = '5123456789';

        ob_start();
        try {
            (new PaystackConfirmations())->confirmPaystackPayment();
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        }
        ob_end_clean();
    }

    private function mockApi(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);

            if (strpos($path, '/transaction/') !== false) {
                $data = $this->remoteCharge;
            } elseif (substr($path, -13) === '/subscription') {
                $this->subscriptionCreates++;
                $data = ['subscription_code' => 'SUB_new', 'status' => 'active', 'email_token' => 'tok'];
            } elseif (substr($path, -5) === '/plan') {
                $this->planBodies[] = json_decode((string) Arr::get($args, 'body'), true);
                $data = ['plan_code' => 'PLN_test', 'currency' => 'NGN'];
            } else {
                $data = [];
            }

            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => wp_json_encode(['status' => true, 'message' => 'OK', 'data' => $data]),
                'headers'  => [],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    private function resetApiSettings(): void
    {
        $property = new \ReflectionProperty(PaystackAPI::class, 'settings');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
