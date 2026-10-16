<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Activity;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use lucatume\WPBrowser\TestCase\WPTestCase;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Webhook\PaystackWebhook;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class PaystackRefundWebhookTest extends WPTestCase
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
    }

    protected function tearDown(): void
    {
        remove_all_filters('paystack_fc/paystack_settings');
        remove_all_filters('pre_http_request');
        $this->resetApiSettings();
        parent::tearDown();
    }

    public function test_dashboard_refund_is_recorded_with_the_paystack_refund_id(): void
    {
        $parent = $this->createPaidOrder();
        $this->mockListRefunds([
            $this->remoteRefund(18711500, 99999, 5000),
            $this->remoteRefund(18711460, (int) $parent->vendor_charge_id, 5000),
        ]);

        $result = (new PaystackWebhook())->recordRefundFromWebhook($this->webhookData($parent, '5000'));

        $this->assertSame('Refund recorded', $result);
        $refunds = $this->refundRows($parent);
        $this->assertCount(1, $refunds);
        $this->assertSame('18711460', $refunds[0]->vendor_charge_id);
        $this->assertSame(5000, (int) $refunds[0]->total);
        $this->assertStringContainsString('refund?', $this->requests[0]);
        $this->assertStringContainsString('perPage=100', $this->requests[0]);
        $this->assertStringContainsString('from=', $this->requests[0]);
        $this->assertStringContainsString('transaction=' . $parent->vendor_charge_id, $this->requests[0]);
    }

    public function test_admin_refund_already_recorded_adds_no_second_row(): void
    {
        $parent = $this->createPaidOrder();
        $this->createRefundRow($parent, '18711274', 5000);
        $this->mockListRefunds([
            $this->remoteRefund(18711274, (int) $parent->vendor_charge_id, 5000),
        ]);

        $result = (new PaystackWebhook())->recordRefundFromWebhook($this->webhookData($parent, '5000'));

        $this->assertSame('Refund already recorded', $result);
        $this->assertCount(1, $this->refundRows($parent));
    }

    public function test_second_equal_partial_refund_gets_its_own_row(): void
    {
        $parent = $this->createPaidOrder();
        $this->createRefundRow($parent, '18711274', 2000);
        $this->mockListRefunds([
            $this->remoteRefund(18711300, (int) $parent->vendor_charge_id, 2000),
            $this->remoteRefund(18711274, (int) $parent->vendor_charge_id, 2000),
        ]);

        $result = (new PaystackWebhook())->recordRefundFromWebhook($this->webhookData($parent, '2000'));

        $this->assertSame('Refund recorded', $result);
        $ids = array_map(function ($row) {
            return $row->vendor_charge_id;
        }, $this->refundRows($parent));
        sort($ids);
        $this->assertSame(['18711274', '18711300'], $ids);
    }

    public function test_unprocessed_or_other_amount_refund_is_not_matched(): void
    {
        $parent = $this->createPaidOrder();
        $this->mockListRefunds([
            $this->remoteRefund(18711460, (int) $parent->vendor_charge_id, 5000, 'pending'),
            $this->remoteRefund(18711461, (int) $parent->vendor_charge_id, 4000),
        ]);

        $webhook = new PaystackWebhook();
        $result = $webhook->recordRefundFromWebhook($this->webhookData($parent, '5000'));
        $webhook->recordRefundFromWebhook($this->webhookData($parent, '5000'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(503, $result->get_error_data()['status']);
        $this->assertCount(0, $this->refundRows($parent));
        $this->assertSame(1, Activity::query()
            ->where('module_id', $parent->order_id)
            ->where('title', 'Paystack Refund Not Matched')
            ->count());
    }

    public function test_api_failure_answers_503_and_records_nothing(): void
    {
        $parent = $this->createPaidOrder();
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 500, 'message' => 'Error'], 'body' => wp_json_encode(['status' => false, 'message' => 'Server error']), 'headers' => [], 'cookies' => []];
        });

        $result = (new PaystackWebhook())->recordRefundFromWebhook($this->webhookData($parent, '5000'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(503, $result->get_error_data()['status']);
        $this->assertCount(0, $this->refundRows($parent));
        $this->assertSame(0, Activity::query()
            ->where('module_id', $parent->order_id)
            ->where('title', 'Paystack Refund Not Matched')
            ->count());
    }

    public function test_unknown_parent_is_acknowledged_without_api_call(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->requests[] = $url;
            return $pre;
        }, 10, 3);

        $result = (new PaystackWebhook())->recordRefundFromWebhook([
            'transaction_reference' => 'unknown-uuid_1',
            'amount'                => '5000',
        ]);

        $this->assertSame('Parent transaction not found', $result);
        $this->assertSame([], $this->requests);
    }

    private function createPaidOrder(): OrderTransaction
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'currency'       => 'NGN',
            'payment_method' => 'paystack',
            'mode'           => Status::ORDER_MODE_TEST,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 10000,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
        ]);

        return OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => Status::ORDER_TYPE_PAYMENT,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method'   => 'paystack',
            'payment_mode'     => 'test',
            'vendor_charge_id' => (string) wp_rand(6000000000, 6999999999),
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'currency'         => 'NGN',
            'total'            => 10000,
            'uuid'             => md5(wp_generate_uuid4()),
        ]);
    }

    private function createRefundRow(OrderTransaction $parent, string $vendorId, int $amount): void
    {
        OrderTransaction::query()->create([
            'order_id'         => $parent->order_id,
            'order_type'       => Status::ORDER_TYPE_PAYMENT,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'payment_method'   => 'paystack',
            'payment_mode'     => 'test',
            'vendor_charge_id' => $vendorId,
            'status'           => Status::TRANSACTION_REFUNDED,
            'currency'         => 'NGN',
            'total'            => $amount,
            'meta'             => ['parent_id' => $parent->id],
        ]);
        Order::query()->where('id', $parent->order_id)->update(['total_refund' => $amount]);
    }

    private function refundRows(OrderTransaction $parent): array
    {
        return OrderTransaction::query()
            ->where('order_id', $parent->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->get()
            ->all();
    }

    private function webhookData(OrderTransaction $parent, string $amount): array
    {
        return [
            'status'                => 'processed',
            'transaction_reference' => $parent->uuid . '_1760000000',
            'refund_reference'      => '1234567890',
            'amount'                => $amount,
            'currency'              => 'NGN',
            'processor'             => 'mpgs_zib',
            'customer'              => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com'],
            'integration'           => 463433,
            'domain'                => 'test',
        ];
    }

    private function remoteRefund(int $id, int $transaction, int $amount, string $status = 'processed'): array
    {
        return [
            'id'          => $id,
            'integration' => 463433,
            'domain'      => 'test',
            'transaction' => $transaction,
            'amount'      => $amount,
            'currency'    => 'NGN',
            'status'      => $status,
        ];
    }

    private function mockListRefunds(array $refunds): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($refunds) {
            $this->requests[] = $url;
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => wp_json_encode(['status' => true, 'message' => 'Refunds retrieved', 'data' => $refunds]),
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
