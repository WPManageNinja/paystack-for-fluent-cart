<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use lucatume\WPBrowser\TestCase\WPTestCase;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Confirmations\PaystackConfirmations;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class PaystackConfirmPaymentTest extends WPTestCase
{
    private $requests = [];

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

    public function test_successful_charge_marks_the_transaction_paid(): void
    {
        $transaction = $this->createPendingTransaction(10000);
        $this->mockVerify($this->remoteCharge($transaction, 'success', 10000, 'NGN'));

        $response = $this->confirm('5123456789');

        $this->assertSame('success', $response['status']);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
    }

    public function test_fees_passed_to_customer_match_on_requested_amount(): void
    {
        $transaction = $this->createPendingTransaction(10000);
        $charge = $this->remoteCharge($transaction, 'success', 10150, 'NGN');
        $charge['requested_amount'] = 10000;
        $this->mockVerify($charge);

        $response = $this->confirm('5123456789');

        $this->assertSame('success', $response['status']);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
    }

    /**
     * @dataProvider unpaidCharges
     */
    public function test_unpaid_or_mismatched_charge_is_rejected(string $status, int $amount, string $currency): void
    {
        $transaction = $this->createPendingTransaction(10000);
        $this->mockVerify($this->remoteCharge($transaction, $status, $amount, $currency));

        $response = $this->confirm('5123456789');

        $this->assertSame('failed', $response['status']);
        $fresh = $transaction->fresh();
        $this->assertSame(Status::TRANSACTION_PENDING, $fresh->status);
        $this->assertSame(10000, (int) $fresh->total);
    }

    public function unpaidCharges(): array
    {
        return [
            'abandoned'      => ['abandoned', 10000, 'NGN'],
            'failed'         => ['failed', 10000, 'NGN'],
            'lower amount'   => ['success', 100, 'NGN'],
            'other currency' => ['success', 10000, 'GHS'],
        ];
    }

    public function test_authorization_only_charge_accepts_the_minimum_amount(): void
    {
        $transaction = $this->createPendingTransaction(0);
        $this->mockVerify($this->remoteCharge($transaction, 'success', 5000, 'NGN', ['amount_is_for_authorization_only' => 'yes']));

        $response = $this->confirm('5123456789');

        $this->assertSame('success', $response['status']);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->fresh()->status);
    }

    public function test_authorization_flag_does_not_lower_the_expected_amount(): void
    {
        $transaction = $this->createPendingTransaction(10000);
        $this->mockVerify($this->remoteCharge($transaction, 'success', 5000, 'NGN', ['amount_is_for_authorization_only' => 'yes']));

        $response = $this->confirm('5123456789');

        $this->assertSame('failed', $response['status']);
        $this->assertSame(Status::TRANSACTION_PENDING, $transaction->fresh()->status);
    }

    public function test_non_numeric_transaction_id_is_rejected_without_api_call(): void
    {
        $transaction = $this->createPendingTransaction(10000);
        $this->mockVerify($this->remoteCharge($transaction, 'success', 10000, 'NGN'));

        $response = $this->confirm('../refund');

        $this->assertSame('failed', $response['status']);
        $this->assertSame([], $this->requests);
    }

    private function confirm(string $trxId): array
    {
        $_REQUEST['paystack_fct_nonce'] = wp_create_nonce('paystack_fct_nonce');
        $_REQUEST['trx_id'] = $trxId;

        ob_start();
        try {
            (new PaystackConfirmations())->confirmPaystackPayment();
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        }

        return json_decode(ob_get_clean(), true);
    }

    private function createPendingTransaction(int $total): OrderTransaction
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'currency'       => 'NGN',
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
            'currency'         => 'NGN',
            'total'            => $total,
            'uuid'             => md5(wp_generate_uuid4()),
        ]);
    }

    private function remoteCharge(OrderTransaction $transaction, string $status, int $amount, string $currency, array $meta = []): array
    {
        return [
            'id'       => 5123456789,
            'status'   => $status,
            'amount'   => $amount,
            'currency' => $currency,
            'paid_at'  => $status === 'success' ? gmdate('c') : null,
            'metadata' => array_merge(['transaction_hash' => $transaction->uuid], $meta),
            'customer' => ['customer_code' => 'CUS_test'],
        ];
    }

    private function mockVerify(array $charge): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($charge) {
            $this->requests[] = $url;
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => wp_json_encode(['status' => true, 'message' => 'Transaction retrieved', 'data' => $charge]),
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
