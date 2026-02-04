<?php

namespace PaystackFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\Subscriptions\PaystackSubscriptions;
use PaystackFluentCart\Refund\PaystackRefund;

class PaystackConfirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_paystack_payment', [$this, 'confirmPaystackPayment']);
        add_action('wp_ajax_fluent_cart_confirm_paystack_payment', [$this, 'confirmPaystackPayment']);

        // not needed as already handling via custom ajax action in the above two lines
        /*
         * $data params contains
         *  - order_hash (order uuid)
         *  - trx_hash (transaction uuid)
         *  - method (gateway name)
         *  - is_receipt (yes/no), if 'yes' if we are on receipt page, not on thank you page (confirm only in-time of thank you page render)
         *
         * */
//        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPaymentOnReturn']);

    }


    public function confirmPaystackPayment()
    {
        
        if (isset($_REQUEST['paystack_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['paystack_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'paystack_fct_nonce')) {
                $this->confirmationFailed(400);
            }
        } else {
            $this->confirmationFailed(400);
        }
        

        if (!isset($_REQUEST['trx_id'])) {
            $this->confirmationFailed(400);
        }

        $paystackTransactionId = sanitize_text_field(wp_unslash($_REQUEST['trx_id']) ?? '');
        
        // get the transaction from paystack using the reference
        $paystackTransaction = PaystackAPI::getPaystackObject('transaction/' . $paystackTransactionId);

        if (is_wp_error($paystackTransaction)) {
            $this->confirmationFailed(500);
        }

        if (Arr::get($paystackTransaction, 'status') !== true) {
            $this->confirmationFailed(404);
        }

        $transactionMeta = Arr::get($paystackTransaction, 'data.metadata', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        if (empty($transactionHash)) {
            $this->confirmationFailed(404);
        }

        // Find the transaction by UUID
        $transactionModel = null;
        $transactionModel = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'paystack')
            ->first();


        if (!$transactionModel) {
            $this->confirmationFailed(404);
        }

  
        // Check if already processed
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            $this->confirmationSuccess($transactionModel);
        }

        $data = Arr::get($paystackTransaction, 'data');


        $paystackPlan = Arr::get($transactionMeta, 'paystack_plan', '');
        $subscriptionHash = Arr::get($transactionMeta, 'subscription_hash', '');
        $paystackCustomerCode = Arr::get($data, 'customer.customer_code', '');
        $paystackCustomerAuthorizationCode = Arr::get($data, 'authorization.authorization_code', '');

        $billingInfo = [
            'type' => Arr::get($data, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($data, 'authorization.last4'),
            'brand' => Arr::get($data, 'authorization.brand'),
            'payment_method_id' => Arr::get($data, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($data, 'authorization.channel'),
            'exp_month' => Arr::get($data, 'authorization.exp_month'),
            'exp_year' => Arr::get($data, 'authorization.exp_year')
        ];

        if ($paystackPlan && $subscriptionHash) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();

            $updatedSubData  = [];

            if ($subscriptionModel && !in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                $updatedSubData = (new PaystackSubscriptions())->createSubscriptionOnPayStack( $subscriptionModel, [
                    'customer_code' => $paystackCustomerCode,
                    'authorization_code' => $paystackCustomerAuthorizationCode,
                    'plan_code' => $paystackPlan,
                    'billing_info' => $billingInfo,
                    'is_first_payment_only_for_authorization' => Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') === 'yes'
                ]);

            }

        }
        

        $this->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $paystackTransactionId,
            'charge' => $data,
            'subscription_data' => $updatedSubData ?? [],
            'billing_info' => $billingInfo
        ]);

        $this->confirmationSuccess($transactionModel);
    }

    /**
     * Confirm payment success and update transaction
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transactionModel, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'charge');
        $subscriptionData = Arr::get($args, 'subscription_data', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            return;
        }

        $amount = Arr::get($transactionData, 'amount', 0); // Paystack returns amount in kobo/cents
        $currency = Arr::get($transactionData, 'currency');
        $transactionMeta = Arr::get($args, 'charge.metadata', []);

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
            'vendor_charge_id' => $vendorChargeId,
            'meta' => array_merge($transactionModel->meta ?? [], $billingInfo)
        ]);

        $transactionModel->fill($transactionUpdateData);
        $transactionModel->save();

        fluent_cart_add_log(__('Paystack Payment Confirmation', 'paystack-for-fluent-cart'), __('Payment confirmation received from Paystack. Transaction ID:', 'paystack-for-fluent-cart') . ' ' . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);

        // refund the amount if it was just for authorization
        if (Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') === 'yes') {
            // refund the amount as it was just for authorization
            $response = (new PaystackRefund())->refundMinimumAuthorizationAmount($transactionModel);

            if (is_wp_error($response)) {
                fluent_cart_add_log('Refund failed of authorization amount', $response->get_error_message(), 'error', [
                    'module_name' => 'order',
                    'module_id'   => $transactionModel->order_id,
                ]);
            }
        }

        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transactionModel->subscription_id)->first();


            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transactionModel, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        return (new StatusHelper($order))->syncOrderStatuses($transactionModel);
    }

    public function confirmationSuccess(OrderTransaction $transactionModel)
    {
        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'paystack-for-fluent-cart'),
            'status' => 'success'
        ]);
    }

    public function confirmationFailed($code = 422)
    {
        wp_send_json([
            'message' => __('Payment confirmation failed', 'paystack-for-fluent-cart'),
            'status' => 'failed'
        ], $code);
    }
}

