<?php

namespace PaystackFluentCart\Webhook;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\DateTime\DateTime;
use PaystackFluentCart\Settings\PaystackSettingsBase;
use PaystackFluentCart\Confirmations\PaystackConfirmations;
use PaystackFluentCart\PaystackHelper;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Subscriptions\PaystackSubscriptions;

class PaystackWebhook
{
    public function init()
    {
        add_action('fluent_cart/payments/paystack/webhook_charge_success', [$this, 'handleChargeSuccess'], 10, 1); // done
        add_action('fluent_cart/payments/paystack/webhook_subscription_create', [$this, 'handleSubscriptionCreate'], 10, 1); // done
        add_action('fluent_cart/payments/paystack/webhook_subscription_not_renew', [$this, 'handleSubscriptionCanceled'], 10, 1);
        add_action('fluent_cart/payments/paystack/webhook_refund_processed', [$this, 'handleRefundProcessed'], 10, 1); // done

        add_action('fluent_cart/payments/paystack/webhook_invoice_create', [$this, 'handleInvoiceCreate'], 10, 1); // not handling
        add_action('fluent_cart/payments/paystack/webhook_invoice_update', [$this, 'handleInvoiceUpdate'], 10, 1); // done

        // .... rest of the webhook handlers can be added here
    }

    /**
     * Verify and process Paystack webhook
     */
    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();
        if (is_wp_error($payload)) {
            http_response_code(400);
            exit('Not valid payload');
        }

        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON payload');
        }

        if (!$this->verifySignature($payload)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        $order = $this->getFluenCartOrder($data);

        if (!$order && Arr::get($data, 'event') === 'refund.processed') {
            $this->sendResponse(200, 'Parent transaction not found');
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


    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');
        
        // Check payload size (max 1MB)
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }
        
        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }
        
        return $input;
    }

    
    private function verifySignature($payload)
    {
        // Sanitize server input
        $signature = isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'])) : '';
        
        if (!$signature) {
            return false;
        }

        $secretKey = (new PaystackSettingsBase())->getSecretKey();
        
        if (!$secretKey) {
            return false;
        }
        
        $computedSignature = hash_hmac('sha512', $payload, $secretKey);

        return hash_equals($signature, $computedSignature);
    }


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
        if ($transactionModel->status == Status::TRANSACTION_SUCCEEDED) {
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

        if ($paystackPlan && $subscriptionModel && !in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            $updatedSubData = (new PaystackSubscriptions())->createSubscriptionOnPayStack( $subscriptionModel, [
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

    }

    
    public function handleSubscriptionCreate($data)
    {
        $paystackSubscription = Arr::get($data, 'payload');
        
        $order = Arr::get($data, 'order');

        $subscriptionModel = Subscription::query()
            ->where('parent_order_id', $order->id)
            ->first();

        
        if (!$subscriptionModel) {
            $this->sendResponse(200, 'No subscription found for the order, skipping subscription creation handling.');
        }

        if (!in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            $this->sendResponse(200, 'Subscription is already processed, skipping subscription creation handling.');
        }

        $oldStatus = $subscriptionModel->status;


        $oldStatus = $subscriptionModel->status;
        $status = PaystackHelper::getFctSubscriptionStatus(Arr::get($paystackSubscription, 'status'));

        $updateData = [
            'vendor_subscription_id' => Arr::get($paystackSubscription, 'subscription_code'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($paystackSubscription, 'customer.customer_code'),
            'next_billing_date'      => Arr::get($paystackSubscription, 'next_payment_date') ? DateTime::anyTimeToGmt(Arr::get($paystackSubscription, 'next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];


        $billingInfo = [
            'type' => Arr::get($paystackSubscription, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($paystackSubscription, 'authorization.last4'),
            'brand' => Arr::get($paystackSubscription, 'authorization.brand'),
            'payment_method_id' => Arr::get($paystackSubscription, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($paystackSubscription, 'authorization.channel'),
            'exp_month' => Arr::get($paystackSubscription, 'authorization.exp_month'),
            'exp_year' => Arr::get($paystackSubscription, 'authorization.exp_year')
        ];

        $subscriptionModel->update($updateData);

        $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        $subscriptionModel->updateMeta('paystack_email_token', Arr::get($paystackSubscription, 'email_token'));

        fluent_cart_add_log(__('Paystack Subscription Created', 'paystack-for-fluent-cart'), 'Subscription created on Paystack. Code: ' . Arr::get($paystackSubscription, 'subscription_code'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        if ($oldStatus != $subscriptionModel->status && (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

    }

   
    public function handleInvoiceCreate($data)
    {
        // You can implement any specific logic needed when an invoice is created.
        // For now, we'll just log the event.
        // implement if needed;
        // case send notification to admin or customer

    }

    // handling invoice paid
    public function handleInvoiceUpdate($data)
    {
        $invoice = Arr::get($data, 'payload');
 
        if (Arr::get($invoice, 'status') === 'success' && Arr::get($invoice, 'paid') === true) {
            

            $subscriptionCode = Arr::get($invoice, 'subscription.subscription_code');

            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $subscriptionCode)
                ->first();
            
            if (!$subscriptionModel || ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED)) {
                return false;
            }

            $subscriptionModel->reSyncFromRemote();

        }

    }

    public function handleSubscriptionCanceled($data)
    {

        $paystackSubscription = Arr::get($data, 'payload');

        $paystackSubscriptionCode = Arr::get($paystackSubscription, 'subscription_code');

        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $paystackSubscriptionCode)
            ->first();

        
        
        if (!$subscriptionModel || ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED)) {
            return false;
        }

        $subscriptionModel->reSyncFromRemote();

        fluent_cart_add_log(__('Subscription Canceled', 'paystack-for-fluent-cart'), 'Subscription cancellation received from Paystack for subscription code: ' . $paystackSubscriptionCode, 'info', [
            'module_name' => 'subscription',
            'module_id'   => $subscriptionModel->id
        ]);

        $this->sendResponse(200, 'Subscription cancellation processed successfully');

    }

    public function handleRefundProcessed($data)
    {
        $result = $this->recordRefundFromWebhook(Arr::get($data, 'payload', []));

        if (is_wp_error($result)) {
            $this->sendResponse(Arr::get($result->get_error_data(), 'status', 500), $result->get_error_message());
        }

        $this->sendResponse(200, $result);
    }

    /**
     * @param array $refund refund.processed `data`; it carries no refund id.
     * @return string|\WP_Error Response message, or an error whose data.status is the HTTP code.
     */
    public function recordRefundFromWebhook($refund)
    {
        $transactionHash = explode('_', (string) Arr::get($refund, 'transaction_reference'))[0];

        $parentTransaction = $transactionHash ? OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'paystack')
            ->first() : null;

        if (!$parentTransaction) {
            return 'Parent transaction not found';
        }

        $amount = (int) Arr::get($refund, 'amount');

        $refundId = $this->findUnrecordedRefundId($parentTransaction, $amount);

        if (is_wp_error($refundId)) {
            return $refundId;
        }

        if (!$refundId) {
            return 'Refund already recorded';
        }

        $refundData = [
            'order_id'         => $parentTransaction->order_id,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'payment_method'   => 'paystack',
            'payment_mode'     => $parentTransaction->payment_mode,
            'vendor_charge_id' => $refundId,
            'total'            => $amount,
            'currency'         => Arr::get($refund, 'currency'),
            'meta'             => [
                'parent_id'          => $parentTransaction->id,
                'refund_description' => Arr::get($refund, 'description', ''),
                'refund_source'      => 'webhook'
            ]
        ];

        $recordedRefund = Refund::createOrRecordRefund($refundData, $parentTransaction);

        if (is_wp_error($recordedRefund)) {
            return new \WP_Error($recordedRefund->get_error_code(), $recordedRefund->get_error_message(), ['status' => 503]);
        }

        return 'Refund recorded';
    }

    /**
     * Matches the webhook to a processed refund of the parent charge with the same amount
     * that no local refund row holds yet.
     *
     * @return string|null|\WP_Error Refund id, null when every match is already recorded.
     */
    private function findUnrecordedRefundId(OrderTransaction $parentTransaction, $amount)
    {
        $recordedIds = OrderTransaction::query()
            ->where('order_id', $parentTransaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->whereNotNull('vendor_charge_id')
            ->pluck('vendor_charge_id')
            ->map(function ($id) {
                return (string) $id;
            })
            ->toArray();

        $from = gmdate('Y-m-d', strtotime($parentTransaction->created_at . ' UTC'));
        $matchedRecorded = false;

        for ($page = 1; $page <= 5; $page++) {
            $response = PaystackAPI::getPaystackObject('refund?' . http_build_query([
                'transaction' => $parentTransaction->vendor_charge_id,
                'perPage'     => 100,
                'page'        => $page,
                'from'        => $from
            ]));

            if (is_wp_error($response)) {
                return new \WP_Error($response->get_error_code(), $response->get_error_message(), ['status' => 503]);
            }

            $refunds = Arr::get($response, 'data', []);

            foreach ($refunds as $remoteRefund) {
                $transaction = Arr::get($remoteRefund, 'transaction');
                $transactionId = is_array($transaction) ? Arr::get($transaction, 'id') : $transaction;

                if ((string) $transactionId !== (string) $parentTransaction->vendor_charge_id
                    || (int) Arr::get($remoteRefund, 'amount') !== $amount
                    || Arr::get($remoteRefund, 'status') !== 'processed') {
                    continue;
                }

                if (in_array((string) Arr::get($remoteRefund, 'id'), $recordedIds, true)) {
                    $matchedRecorded = true;
                    continue;
                }

                return (string) Arr::get($remoteRefund, 'id');
            }

            if (count($refunds) < 100) {
                break;
            }
        }

        if ($matchedRecorded) {
            return null;
        }

        // Paystack retries for ~72h; log once per refund, not on every retry.
        $logKey = 'fct_paystack_unmatched_refund_' . $parentTransaction->id . '_' . $amount;
        if (!get_transient($logKey)) {
            set_transient($logKey, 1, 3 * DAY_IN_SECONDS);
            fluent_cart_add_log(__('Paystack Refund Not Matched', 'paystack-for-fluent-cart'), sprintf(
                /* translators: 1: formatted refund amount, 2: Paystack transaction id */
                __('Paystack sent a processed refund of %1$s for transaction %2$s, but no matching refund was found on Paystack. Paystack will retry.', 'paystack-for-fluent-cart'),
                Helper::toDecimal($amount, true, $parentTransaction->currency),
                $parentTransaction->vendor_charge_id
            ), 'error', [
                'module_name' => 'order',
                'module_id'   => $parentTransaction->order_id
            ]);
        }

        return new \WP_Error('paystack_refund_not_found', 'Refund not found on Paystack yet', ['status' => 503]);
    }

    public function getFluenCartOrder($data)
    {
        $order = null;

        $orderHash = Arr::get($data, 'data.metadata.order_hash');

        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }

        // transaction reference
        $transactionreference = Arr::get($data, 'data.transaction_reference');

        if ($transactionreference && !$order) {
            $referenceParts = explode('_', $transactionreference);
            $transactionHash = $referenceParts[0];

            $order = PaystackHelper::getOrderFromTransactionHash($transactionHash);
        }

        $paystackSubscriptionCode = Arr::get($data, 'data.subscription_code');
        if (!$paystackSubscriptionCode) {
            $paystackSubscriptionCode = Arr::get($data, 'data.subscription.subscription_code');
        }

        if ($paystackSubscriptionCode && !$order) {
            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $paystackSubscriptionCode)
                ->first();
            
            if ($subscriptionModel) {
                $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
            }
        }

        $emailToken = Arr::get($data, 'data.email_token');


        if ($emailToken && !$order) {
            $order = PaystackHelper::getOrderFromEmailToken($emailToken);
        }

        return $order;
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

