<?php

namespace PaystackFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Events\Order\OrderRefund;
use PaystackFluentCart\Settings\PaystackSettingsBase;
use PaystackFluentCart\Confirmations\PaystackConfirmations;
use PaystackFluentCart\PaystackHelper;
use PaystackFluentCart\Subscriptions\PaystackSubscriptions;
use PaystackFluentCart\Refund\PaystackRefund;

class PaystackWebhook
{
    public function init()
    {
        add_action('fluent_cart/payments/paystack/webhook_charge_success', [$this, 'handleChargeSuccess'], 10, 1);
        add_action('fluent_cart/payments/paystack/webhook_subscription_create', [$this, 'handleSubscriptionCreate'], 10, 1);
        add_action('fluent_cart/payments/paystack/webhook_subscription_disable', [$this, 'handleSubscriptionDisable'], 10, 1);
        add_action('fluent_cart/payments/paystack/webhook_refund_processed', [$this, 'handleRefundProcessed'], 10, 1);

        add_action('fluent_cart/payments/paystack/webhook_invoice_create', [$this, 'handleInvoiceUpdate'], 10, 1);
        add_action('fluent_cart/payments/paystack/webhook_invoice_update', [$this, 'handleInvoiceUpdate'], 10, 1);
    }

    /**
     * Verify and process Paystack webhook
     */
    public function verifyAndProcess()
    {
        // Get webhook payload
        $input = @file_get_contents('php://input');
        $data = json_decode($input, true);

        // Verify webhook signature
//        if (!$this->verifySignature($input)) {
//            http_response_code(401);
//            exit('Invalid signature');
//        }


        $orderHash = Arr::get($data, 'data.metadata.order_hash');

        $order = null;
        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }



        // transaction reference
        $transactionreference = Arr::get($data, 'data.transaction_reference');

        if ($transactionreference) {
            $referenceParts = explode('_', $transactionreference);
            $transactionHash = $referenceParts[0];

            $order = PaystackHelper::getOrderFromTransactionHash($transactionHash);
        }

        if (!$order) {
            http_response_code(404);
            exit('Order not found');
        }

        $event = str_replace('.', '_', Arr::get($data, 'event'));


        if (has_action('fluent_cart/payments/paystack/webhook_' . $event)) {
            do_action('fluent_cart/payments/paystack/webhook_' . $event, [
                'payload' => Arr::get($data, 'data'),
                'order' => $order
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        http_response_code(200);
        exit('Webhook not handled');
    }

    /**
     * Verify Paystack webhook signature
     */
    private function verifySignature($payload)
    {
        $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        
        if (!$signature) {
            return false;
        }

        $secretKey = (new PaystackSettingsBase())->getSecretKey();
        $computedSignature = hash_hmac('sha512', $payload, $secretKey);

        return hash_equals($signature, $computedSignature);
    }

    /**
     * Handle successful charge
     */
    public function handleChargeSuccess($data)
    {
       $paystackTransaction = Arr::get($data, 'payload');
       $paystackTransactionId = Arr::get($paystackTransaction, 'id');

        $transactionMeta = Arr::get($paystackTransaction, 'metadata', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        // Find the transaction by UUID
        $transactionModel = null;

        if ($transactionHash) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'paystack')
                ->first();
        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }

        $subscriptionHash = Arr::get($transactionMeta, 'subscription_hash', '');
        $subscriptionModel = null;

        if ($subscriptionHash) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();
        }

        // Check if already processed
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'paystack-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }


        $paystackPlan = Arr::get($transactionMeta, 'paystack_plan', '');
        $paystackCustomer = Arr::get($paystackTransaction, 'customer.customer_code', []);
        $paystackCustomerAuthorization = Arr::get($paystackTransaction, 'authorization.authorization_code', []);

        $billingInfo = [
            'type' => Arr::get($paystackTransaction, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($paystackTransaction, 'authorization.last4'),
            'brand' => Arr::get($paystackTransaction, 'authorization.brand'),
            'payment_method_id' => Arr::get($paystackTransaction, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($paystackTransaction, 'authorization.channel'),
            'exp_month' => Arr::get($paystackTransaction, 'authorization.exp_month'),
            'exp_year' => Arr::get($paystackTransaction, 'authorization.exp_year')
        ];

        if ($paystackPlan) {
            $updatedSubData = (new PaystackSubscriptions())->createSubscriptionOnPaytsack( $subscriptionModel, [
                'customer_code' => $paystackCustomer,
                'authorization_code' => $paystackCustomerAuthorization,
                'plan_code' => $paystackPlan,
                'billing_info' => $billingInfo,
                'is_first_payment_only_for_authorization' => Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') === 'yes'
            ]);
        }


        (new PaystackConfirmations())->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $paystackTransactionId,
            'charge' => $paystackTransaction,
            'subscription_data' => $updatedSubData ?? [],
            'billing_info' => $billingInfo
        ]);

        $this->sendResponse(200, 'Charge processed successfully');

    }

    /**
     * Handle subscription creation
     */
    public function handleSubscriptionCreate($data)
    {

    }

    // handling invoice paid
    public function handleInvoiceUpdate($data)
    {
        $order = Arr::get($data, 'order');
        $invoice = Arr::get($data, 'data');
        $invoiceStatus = Arr::get($invoice, 'status');

        if ($invoiceStatus === 'paid') {

        }
    }

    /**
     * Handle subscription disable
     */
    public function handleSubscriptionDisable($data)
    {

    }

    /**
     * Handle refund processed
     */
    public function handleRefundProcessed($data)
    {
       $refund = Arr::get($data, 'payload');
       $order = Arr::get($data, 'order');
    
       $transactionReference = Arr::get($refund, 'transaction_reference');
       $transactionHash = explode('_', $transactionReference)[0];

       $parentTransaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'paystack')
            ->first();


        if (!$parentTransaction) {
           $this->sendResponse(200, 'Parent transaction found, refund processing can be handled here.');
        }

        $currentCreatedRefund = null;

        $refundId = Arr::get($refund, 'id');
        $amount = Arr::get($refund, 'amount');
        $refundCurrency = Arr::get($refund, 'currency');

        // Prepare refund data matching Stripe pattern
        $refundData = [
            'order_id'           => $order->id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => Status::TRANSACTION_REFUNDED,
            'payment_method'     => 'paystack',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $refundId,
            'total'              => $amount,
            'currency'           => $refundCurrency,
            'meta'               => [
                'parent_id'          => $parentTransaction->id,
                'refund_description' => Arr::get($refund, 'description', ''),
                'refund_source'      => 'webhook'
            ]
        ];


        $syncedRefund = (new PaystackRefund())->createOrUpdateIpnRefund($refundData, $parentTransaction);
        if ($syncedRefund->wasRecentlyCreated) {
            $currentCreatedRefund = $syncedRefund;
        }

        (new OrderRefund($order, $currentCreatedRefund))->dispatch();


    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
        ]);

        exit;
    }
}

