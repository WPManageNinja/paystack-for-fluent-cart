<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use lucatume\WPBrowser\TestCase\WPTestCase;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Webhook\PaystackWebhook;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

class PaystackChargeWebhookTest extends WPTestCase
{
    private $requests = [];

    private $remoteCharge;

    private $remoteStatus = true;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->resetApiSettings();
        parent::tearDown();
    }

    public function test_matching_charge_marks_the_transaction_paid(): void
    {
        $transaction = $this->createPendingTransaction(10000, 'NGN');

        $this->handle($this->charge($transaction, 10000, 'NGN'));

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertSame([], $this->requests);
    }

    /**
     * @dataProvider mismatchedCharges
     */
    public function test_mismatched_charge_is_acknowledged_without_marking_paid(int $amount, string $currency): void
    {
        $transaction = $this->createPendingTransaction(10000, 'USD');

        $response = $this->handle($this->charge($transaction, $amount, $currency));

        $this->assertSame('failed', $response['status'] ?? null);
        $fresh = $transaction->fresh();
        $this->assertSame(Status::TRANSACTION_PENDING, $fresh->status);
        $this->assertSame(10000, (int) $fresh->total);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame(Status::PAYMENT_PENDING, Order::query()->find($transaction->order_id)->payment_status);
    }

    public function mismatchedCharges(): array
    {
        return [
            'other currency' => [10000, 'NGN'],
            'lower amount'   => [100, 'USD'],
        ];
    }

    public function test_underpaid_charge_does_not_create_the_paystack_subscription(): void
    {
        $transaction = $this->createPendingTransaction(10000, 'NGN');
        $subscription = $this->createPendingSubscription($transaction);

        $this->handle($this->charge($transaction, 100, 'NGN', [
            'paystack_plan'     => 'PLN_test',
            'subscription_hash' => $subscription->uuid,
        ]));

        $this->assertSame([], $this->requests);
        $this->assertSame(Status::SUBSCRIPTION_PENDING, $subscription->fresh()->status);
        $this->assertSame(Status::TRANSACTION_PENDING, $transaction->fresh()->status);
    }

    public function test_authorization_flag_on_a_full_charge_issues_no_refund(): void
    {
        $transaction = $this->createPendingTransaction(10000, 'NGN');

        $this->handle($this->charge($transaction, 10000, 'NGN', ['amount_is_for_authorization_only' => 'yes']));

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertSame([], $this->requests);
    }

    public function test_zero_total_transaction_refunds_the_authorization_amount(): void
    {
        $transaction = $this->createPendingTransaction(0, 'NGN');

        $this->handle($this->charge($transaction, 5000, 'NGN'));

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertCount(1, $this->requests);
        $this->assertStringEndsWith('/refund', $this->requests[0]);
    }

    public function test_payload_without_requested_amount_is_checked_against_the_verify_api(): void
    {
        $transaction = $this->createPendingTransaction(10000, 'NGN');
        $charge = $this->charge($transaction, 10150, 'NGN');
        unset($charge['requested_amount']);
        $this->remoteCharge = array_merge($charge, ['requested_amount' => 10000]);

        $this->handle($charge);

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
        $this->assertStringEndsWith('/transaction/5123456789', $this->requests[0]);
    }

    /**
     * @dataProvider unverifiableResponses
     */
    public function test_unverifiable_charge_is_not_acknowledged(bool $reachable): void
    {
        $transaction = $this->createPendingTransaction(10000, 'NGN');
        $charge = $this->charge($transaction, 10000, 'NGN');
        unset($charge['requested_amount']);
        if ($reachable) {
            $this->remoteCharge = $charge;
            $this->remoteStatus = false;
        }

        $response = $this->handle($charge);

        $this->assertSame('Could not verify the charge with Paystack.', $response['message'] ?? null);
        $this->assertSame(Status::TRANSACTION_PENDING, $transaction->fresh()->status);
    }

    public function unverifiableResponses(): array
    {
        return [
            'api error'    => [false],
            'status false' => [true],
        ];
    }

    private function handle(array $charge): array
    {
        ob_start();
        try {
            (new PaystackWebhook())->handleChargeSuccess([
                'payload' => $charge,
                'order'   => null,
            ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        }

        return (array) json_decode(ob_get_clean(), true);
    }

    private function createPendingTransaction(int $total, string $currency): OrderTransaction
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'currency'       => $currency,
            'payment_method' => 'paystack',
            'mode'           => Status::ORDER_MODE_TEST,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => $total,
            'total_amount'   => $total,
            'total_paid'     => 0,
        ]);

        return OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => Status::ORDER_TYPE_PAYMENT,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method'   => 'paystack',
            'payment_mode'     => 'test',
            'status'           => Status::TRANSACTION_PENDING,
            'currency'         => $currency,
            'total'            => $total,
            'uuid'             => md5(wp_generate_uuid4()),
        ]);
    }

    private function createPendingSubscription(OrderTransaction $transaction): Subscription
    {
        $order = Order::query()->find($transaction->order_id);

        return (new SubscriptionFactory())->create([
            'customer_id'       => $order->customer_id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'status'            => Status::SUBSCRIPTION_PENDING,
            'collection_method' => 'automatic',
            'current_payment_method' => 'paystack',
        ]);
    }

    private function charge(OrderTransaction $transaction, int $amount, string $currency, array $meta = []): array
    {
        return [
            'id'               => 5123456789,
            'status'           => 'success',
            'amount'           => $amount,
            'requested_amount' => $amount,
            'currency'         => $currency,
            'paid_at'          => gmdate('c'),
            'metadata'         => array_merge(['transaction_hash' => $transaction->uuid], $meta),
            'customer'         => ['customer_code'    => 'CUS_test'],
            'authorization'    => ['authorization_code' => 'AUTH_test', 'last4'            => '4081', 'channel'          => 'card'],
        ];
    }

    private function mockApi(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->requests[] = $url;
            if (strpos($url, '/transaction/') !== false) {
                if (!$this->remoteCharge) {
                    return new \WP_Error('http_request_failed', 'Paystack unreachable');
                }
                $data = $this->remoteCharge;
            } else {
                $data = strpos($url, '/refund') !== false
                ? ['id' => 18711999, 'status' => 'pending']
                : ['subscription_code' => 'SUB_test', 'status' => 'active'];
            }

            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => wp_json_encode(['status' => $this->remoteStatus, 'message' => 'OK', 'data' => $data]),
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
