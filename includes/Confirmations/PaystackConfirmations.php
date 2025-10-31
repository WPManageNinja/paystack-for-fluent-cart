<?php

namespace PaystackFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use PaystackFluentCart\API\PaystackAPI;

class PaystackConfirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_paystack_payment', [$this, 'confirmPaystackPayment']);
        add_action('wp_ajax_fluent_cart_confirm_paystack_payment', [$this, 'confirmPaystackPayment']);
    }

    /**
     * Confirm Paystack payment after successful checkout
     */
    public function confirmPaystackPayment()
    {
        if (!isset($_REQUEST['reference'])) {
            wp_send_json([
                'message' => 'Payment reference is required to confirm the payment.',
                'status' => 'failed'
            ], 400);
        }

        $reference = sanitize_text_field($_REQUEST['reference']);
        $paystackTransactionId = sanitize_text_field($_REQUEST['trx_id'] ?? '');
        
        // get the transaction from paystack using the reference
        $paystackTransaction = PaystackAPI::getPaystackObject('transaction/' . $paystackTransactionId);

        if (is_wp_error($paystackTransaction) || Arr::get($paystackTransaction, 'status') !== true) {
            wp_send_json([
                'message' => $paystackTransaction->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        $transactionMeta = Arr::get($paystackTransaction, 'data.metadata', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        // Find the transaction by UUID
        $transaction = null;

        if ($transactionHash) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'paystack')
                ->first();
        }

        if (!$transaction) {
            $transaction = OrderTransaction::query()
                ->where('reference', $reference)
                ->where('payment_method', 'paystack')
                ->first();
        }

        if (!$transaction) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }

        // Check if already processed
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transaction->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transaction->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'paystack-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }

        $data = Arr::get($paystackTransaction, 'data');

        $this->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => $paystackTransactionId,
            'transaction' => $data,
        ]);

        wp_send_json([
            'redirect_url' => $transaction->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transaction->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'paystack-for-fluent-cart'),
            'status' => 'success'
        ], 200);
    }

    /**
     * Confirm payment success and update transaction
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $args = [])
    {
        $reference = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'transaction');

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();

        if (!$order) {
            return;
        }

        // Extract billing information from Paystack authorization
        $authorization = Arr::get($transactionData, 'authorization', []);
        $billingInfo = [
            'type' => Arr::get($authorization, 'payment_type', 'card'),
            'last4' => Arr::get($authorization, 'last4'),
            'brand' => Arr::get($authorization, 'brand'),
            'payment_method_id' => Arr::get($authorization, 'authorization_code'),
            'payment_method_type' => Arr::get($authorization, 'channel'),
            'exp_month' => Arr::get($authorization, 'exp_month'),
            'exp_year' => Arr::get($authorization, 'exp_year')
        ];

        $amount = Arr::get($transactionData, 'amount', 0); // Paystack returns amount in kobo/cents
        $currency = Arr::get($transactionData, 'currency');

        $meta = $this->getTransactionMeta($transactionData, $order);

        // Update transaction
        $transactionUpdateData = array_filter([
            'order_id' => $order->id,
            'total' => $amount,
            'currency' => $currency,
            'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'paystack',
            'card_last_4' => Arr::get($billingInfo, 'last4', ''),
            'card_brand' => Arr::get($billingInfo, 'brand', ''),
            'payment_method_type' => Arr::get($billingInfo, 'payment_method_type', ''),
            'vendor_charge_id' => $reference,
            'reference' => $reference,
            'meta' => array_merge($transaction->meta ?? [], $meta)
        ]);

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        fluent_cart_add_log(__('Paystack Payment Confirmation', 'paystack-for-fluent-cart'), __('Payment confirmation received from Paystack. Reference:', 'paystack-for-fluent-cart') . ' ' . $reference, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);

        // Handle renewal orders with subscriptions
        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            $parentOrderId = $transaction->order->parent_id;
            if (!$parentOrderId) {
                return $order;
            }

            $subscription = Subscription::query()->where('parent_order_id', $parentOrderId)->first();

            if (!$subscription) {
                return $order; // No subscription found for this renewal order.
            }

            $response = PaystackAPI::getPaystackObject('subscription/' . $subscription->vendor_subscription_id);

            $subscriptionArgs = [
                'status' => Status::SUBSCRIPTION_ACTIVE,
                'canceled_at' => null,
                'current_payment_method' => 'paystack'
            ];

            if (!is_wp_error($response)) {
                $nextBillingDate = Arr::get($response, 'data.next_payment_date') ?? null;
                if ($nextBillingDate) {
                    $subscriptionArgs['next_billing_date'] = DateTime::anyTimeToGmt($nextBillingDate)->format('Y-m-d H:i:s');
                }
            }

            SubscriptionService::recordManualRenewal($subscription, $transaction, [
                'billing_info' => $billingInfo,
                'subscription_args' => $subscriptionArgs
            ]);

        } else {
            $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
            
            if ($subscription) {
                // Store payment method info and subscription code if available
                $subscription->updateMeta('active_payment_method', $billingInfo);
                $subscriptionCode = Arr::get($transactionData, 'customer.customer_code');
                if ($subscriptionCode) {
                    $subscription->updateMeta('customer_code', $subscriptionCode);
                }
            }

            // Sync order status
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        return $order;
    }

    /**
     * Extract transaction metadata from Paystack response
     */
    private function getTransactionMeta($transactionData, Order $order)
    {
        $meta = [
            'paystack_reference' => Arr::get($transactionData, 'reference'),
            'paystack_transaction_id' => Arr::get($transactionData, 'id'),
            'payment_channel' => Arr::get($transactionData, 'channel'),
            'ip_address' => Arr::get($transactionData, 'ip_address'),
            'fees' => Arr::get($transactionData, 'fees', 0) / 100, // Convert from kobo
        ];

        $customer = Arr::get($transactionData, 'customer', []);
        if ($customer) {
            $meta['customer_email'] = Arr::get($customer, 'email');
            $meta['customer_code'] = Arr::get($customer, 'customer_code');
        }

        $authorization = Arr::get($transactionData, 'authorization', []);
        if ($authorization) {
            $meta['authorization_code'] = Arr::get($authorization, 'authorization_code');
            $meta['bin'] = Arr::get($authorization, 'bin');
            $meta['bank'] = Arr::get($authorization, 'bank');
            $meta['country_code'] = Arr::get($authorization, 'country_code');
        }

        return $meta;
    }
}

