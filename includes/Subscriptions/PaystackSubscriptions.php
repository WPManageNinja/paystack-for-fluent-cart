<?php

namespace PaystackFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use PaystackFluentCart\API\PaystackAPI;
use PaystackFluentCart\PaystackHelper;

class PaystackSubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription($paymentInstance, $paymentArgs)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $subscription = $paymentInstance->subscription;

        $plan = self::getOrCreatePayStackPlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'data.plan_code'),
        ]);

        $firstPayment = [
            'email'     => $fcCustomer->email,
            'amount'    => (int)$transaction->total,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid . '_' . time(),
            'metadata'  => [
                'paystack_plan'    => Arr::get($plan, 'data.plan_code'),
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'subscription_hash'=> $subscription->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'amount_is_for_authorization_only' => 'no'
            ]
        ];

        // firstPayment of the subscription, paystack requires The customer must have already done a transaction for authorization
        // see details: https://paystack.com/docs/payments/recurring-charges/
        if ($firstPayment['amount'] <= 0) {
            $firstPayment['amount'] = PaystackHelper::getMinimumAmountForAuthorization($transaction->currency);
            $firstPayment['metadata']['amount_is_for_authorization_only'] = 'yes'; // we'll refund this amount later after confirmation
        }

        
        // Apply filters for customization
        $firstPayment = apply_filters('fluent_cart/paystack/subscription_payment_args', $firstPayment, [
            'order'       => $order,
            'transaction' => $transaction,
            'subscription' => $subscription
        ]);

        // Initialize Paystack transaction
        $paystackTransaction = PaystackAPI::createPaystackObject('transaction/initialize', $firstPayment);

        if (is_wp_error($paystackTransaction)) {
           return $paystackTransaction;
        }

        if ($paystackTransaction['status'] !== true) {
            return new \WP_Error(
                'paystack_initialization_failed',
                __('Failed to initialize Paystack transaction.', 'paystack-for-fluent-cart'),
                ['response' => $paystackTransaction]
            );
        }

        return [
            'status'       => 'success',
            'nextAction'   => 'paystack',
            'actionName'   => 'custom',
            'message'      => __('Opening Paystack payment popup...', 'paystack-for-fluent-cart'),
            'data'         => [
                'paystack_data'    => $paystackTransaction['data'],
                'intent'           => 'subscription',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public static function getOrCreatePayStackPlan($paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $variation = $subscription->variation;
        $product = $subscription->product;


        $interval = PaystackHelper::mapIntervalToPaystack($subscription->billing_interval);

        $fctPaystackPlanId = 'fct_paystack_recurring_plan_'
            . $order->mode . '_'
            . $product->id . '_'
            . $order->variation_id
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $subscription->bill_times . '_'
            . $subscription->trial_days . '_'
            . $transaction->currency;

        // make plan first with name, amount, interval, etc.
        $planData = [
            'name'              => $subscription->item_name,
            'description'       => $fctPaystackPlanId,   
            'amount'            => (int)($subscription->recurring_total),
            'interval'          => $interval,
            'send_invoices'    => apply_filters('fluent_cart/paystack/send_invoices_for_subscription_plan', true, [
                'subscription' => $subscription,
                'order'        => $order,
            ]),
            'send_sms'         => apply_filters('fluent_cart/paystack/send_sms_for_subscription_plan', false, [
                'subscription' => $subscription,
                'order'        => $order,
            ])
        ];

        if ($subscription->bill_times) {
            $planData['invoice_limit'] = $subscription->bill_times;
        }

        $fctPaystackPlanId = apply_filters('fluent_cart/paystack_recurring_plan_id', $fctPaystackPlanId, [
            'plan_data' => $planData,
            'variation' => $variation,
            'product'   => $product
        ]);

        // checking if this plan already created in paystack - start (not needed if we could pass something unique to paystack while creating plan)
        $paystackPlanCode = $product->getProductMeta($fctPaystackPlanId);

        if ($paystackPlanCode) {
            $plan = PaystackAPI::getPaystackObject('plan/' . $paystackPlanCode);
            if (!is_wp_error($plan) && Arr::get($plan, 'status') === true) {
                return $plan;
            }
        }
        // checking if this plan already created in paystack - end

        $plan = PaystackAPI::createPaystackObject('plan', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // updating paystack plan id in product meta - for future use
        $product->updateProductMeta($fctPaystackPlanId, Arr::get($plan, 'data.plan_code'));

        return $plan;
    }

 
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'paystack') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Paystack as payment method.', 'paystack-for-fluent-cart')
            );
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;
        $order = $subscriptionModel->order;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'paystack-for-fluent-cart')
            );
        }

        $payStackCustomerId = $subscriptionModel->vendor_customer_id;
        $payStackPlanId = $subscriptionModel->vendor_plan_id;

        $payStackSubscription = PaystackAPI::getPaystackObject('subscription/' . $vendorSubscriptionId);
        if (is_wp_error($payStackSubscription)) {
            return $payStackSubscription;
        }

        $authorizationCode = Arr::get($payStackSubscription, 'data.authorization.authorization_code');
        $subscriptionUpdateData = PaystackHelper::getSubscriptionUpdateData($payStackSubscription, $subscriptionModel);
        // get all transaction for this customer, then match the transactions with vendor_plan_id with plan_code of transactions
        $customerTransactions = [];

        $next = null;
        do{
            if ($next) {
                $transactions = PaystackAPI::getPaystackObject('transaction', [
                    'customer' => $payStackCustomerId,
                    'next'     => $next
                ]);
            } else {
                $transactions = PaystackAPI::getPaystackObject('transaction', [
                    'customer' => $payStackCustomerId
                ]);
            }

            if (is_wp_error($transactions)) {
                break;
            }
            $customerTransactions = [...$customerTransactions, ...Arr::get($transactions, 'data', [])];

            $next = Arr::get($transactions, 'meta.next',null);

        } while($next);



        $subscriptionTransactions = array_filter($customerTransactions, function($transaction) use ($authorizationCode) {
            return Arr::get($transaction, 'authorization.authorization_code') === $authorizationCode;
        });

        $newPayment = false;
        foreach($subscriptionTransactions as $payment){
            $vendorChargeId = Arr::get($payment, 'id');

            if (Arr::get($payment, 'status') == 'success') {

                $amount = Arr::get($payment, 'amount');
                $methodType  = Arr::get($payment, 'authorization.payment_type');
                $cardLast4 =  Arr::get($payment, 'authorization.last4', null);
                $cardBrand = Arr::get($payment, 'authorization.brand', null);

                $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();

                if (!$transaction) {

                    $transaction = OrderTransaction::query()
                        ->where('subscription_id', $subscriptionModel->id)
                        ->where('vendor_charge_id', '')
                        ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                        ->first();

                    if ($transaction) {
                        $transaction->update([
                            'vendor_charge_id'      => $vendorChargeId,
                            'status'                => Status::TRANSACTION_SUCCEEDED,
                            'total'                 => $amount,
                            'card_last_4'           => $cardLast4,
                            'card_brand'            => $cardBrand,
                            'payment_method_type'   => $methodType
                        ]);

                        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

                        continue;
                    }
                    // Create new transaction
                    $transactionData = [
                        'order_id'         => $order->id,
                        'amount'           => $amount,
                        'currency'         => Arr::get($payment, 'currency'),
                        'vendor_charge_id' => $vendorChargeId,
                        'status'           => status::TRANSACTION_SUCCEEDED,
                        'payment_method'   => 'paystack',
                        'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                        'meta'             => Arr::get($payment, 'authorization', []),
                        'card_last_4'      => $cardLast4,
                        'card_brand'       => $cardBrand,
                        'created_at'       => DateTime::anyTimeToGmt(Arr::get($payment, 'paidAt'))->format('Y-m-d H:i:s'),
                    ];
                    $newPayment = true;
                    SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                    // Update existing transaction if status has changed
                    $transaction->update([
                        'status' => status::TRANSACTION_SUCCEEDED,
                    ]);

                    (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                }
            }
        }

        // Update subscription data
        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    /**
     * Create subscription on Paystack
     * @param Subscription $subscriptionModel
     * @param array $args , expects 'customer_code', 'plan_code', 'authorization_code', 'billingInfo'
     */
    public function createSubscriptionOnPaytsack($subscriptionModel, $args = [])
    {
        $order = $subscriptionModel->order;
        $startDate = null;

        // subscribe customer to plan

        if ($subscriptionModel->trial_days > 0) {
            $startDate = time() + ($subscriptionModel->trial_days * DAY_IN_SECONDS);
        }

        $data = [
            'customer' => Arr::get($args, 'customer_code'),
            'plan' => Arr::get($args, 'plan_code'),
            'authorization' => Arr::get($args, 'authorization_code')
        ];

        if ($startDate) {
            $data['start_date'] =  DateTime::anytimeToGmt($startDate)->format('Y-m-d H:i:s');
        }

        $payStackSubscription = PaystackAPI::createPaystackObject('subscription', $data);


        if (is_wp_error($payStackSubscription)) {
            // log the error message
            fluent_cart_add_log(__('Paystack Subscription Creation Failed', 'fluent-cart-pro'), __('Failed to create subscription in Paystack. Error: ', 'fluent-cart-pro')  . $payStackSubscription->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);
    
            return [];
        }

        $oldStatus = $subscriptionModel->status;
        $status = PaystackHelper::getFctSubscriptionStatus(Arr::get($payStackSubscription, 'data.status'));

        $updateData = [
            'vendor_subscription_id' => Arr::get($payStackSubscription, 'data.subscription_code'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($args, 'customer_code'),
            'next_billing_date'      => Arr::get($payStackSubscription, 'data.next_payment_date') ? DateTime::anyTimeToGmt(Arr::get($payStackSubscription, 'data.next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];

        $subscriptionModel->update($updateData);

        $subscriptionModel->updateMeta('active_payment_method', Arr::get($args, 'billingInfo', []));
        $subscriptionModel->updateMeta('paystack_email_token', Arr::get($payStackSubscription, 'data.email_token'));

        fluent_cart_add_log(__('Paystack Subscription Created', 'paystack-for-fluent-cart'), 'Subscription created on Paystack. Code: ' . Arr::get($payStackSubscription, 'data.subscription_code'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        if ($oldStatus != $subscriptionModel->status && (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }



        return $updateData;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $vendorSubscriptionId)
            ->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'paystack-for-fluent-cart')
            );
        }

        // Get subscription code and token for cancellation
        $subscriptionCode = $vendorSubscriptionId;
        $token = $subscriptionModel->getMeta('paystack_email_token');

        if (!$token) {
            return new \WP_Error(
                'missing_token',
                __('Missing email token for subscription cancellation.', 'paystack-for-fluent-cart')
            );
        }

        // Disable subscription via Paystack API
        $response = PaystackAPI::deletePaystackObject('subscription/disable', [
            'code'  => $subscriptionCode,
            'token' => $token
        ]);

        if (is_wp_error($response)) {
            fluent_cart_add_log('Paystack Subscription Cancellation Failed', $response->get_error_message(), 'error', [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id,
            ]);
            return $response;
        }


        if (Arr::get($response, 'status') != true) {
            return new \WP_Error(
                'cancellation_failed',
                Arr::get($response, 'message', __('Failed to cancel subscription on Paystack.', 'paystack-for-fluent-cart'))
            );
        }

        // Update subscription status
        $subscriptionModel->update([
            'status'     => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ]);

        fluent_cart_add_log(
            __('Paystack Subscription Cancelled', 'paystack-for-fluent-cart'),
            __('Subscription cancelled on Paystack. Code: ', 'paystack-for-fluent-cart') . $subscriptionCode,
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id,
            ]
        );

        return [
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ];
    }

}

