<?php

namespace PaystackFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use PaystackFluentCart\API\PaystackAPI;
use PaystackSettings\Settings;
use FluentCart\Framework\Support\Arr;

class PaystackProcessor
{
    /**
     * Handle single payment
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Prepare payment data for Paystack
        $paymentData = [
            'amount'    => $transaction->total, // Amount in kobo/cents
            'email'     => $fcCustomer->email,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid . time(),
            'callback_url' => Arr::get($paymentArgs, 'success_url'),
            'metadata'  => [
                'order_id'         => $order->id,
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];

        // Apply filters for customization
        $paymentData = apply_filters('paystack_fc/payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);


        // before initialize check if the transaction is already initialized with the same reference

        $alreadyInitialized = false; // TODO: Implement check logic
        $paystackInitializedData = PaystackAPI::getPaystackObject('transaction/verify/' . $transaction->uuid . 'sdf');


        if (!is_wp_error($paystackInitializedData) && isset($paystackInitializedData['data']) && $paystackInitializedData['data']['status'] === 'success') {
            // get the existing initialized transaction data
        }

        $paystackTransaction = PaystackAPI::createPaystackObject('transaction/initialize', $paymentData);

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
        

        // TODO: Initialize Paystack transaction via API
        // For now, return structure for popup initialization
        
        return [
            'status'       => 'success',
            'nextAction'   => 'paystack',
            'actionName'   => 'custom',
            'message'      => __('Opening Paystack payment popup...', 'paystack-for-fluent-cart'),
            'data'         => [
                'paystack_data'   => $paystackTransaction['data'],
                'intent'          => 'onetime'
            ]
        ];
    }

    /**
     * Handle subscription payment
     */
    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        $fcCustomer = $paymentInstance->order->customer;

        // Prepare payment data for Paystack subscription
        $paymentData = [
            'amount'    => $transaction->total,
            'email'     => $fcCustomer->email,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid,
            'callback_url' => Arr::get($paymentArgs, 'success_url'),
            'metadata'  => [
                'order_id'           => $order->id,
                'order_hash'         => $order->uuid,
                'transaction_hash'   => $transaction->uuid,
                'subscription_hash'  => $subscription->uuid,
                'customer_name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];

        // Apply filters for customization
        $paymentData = apply_filters('paystack_fc/subscription_payment_args', $paymentData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscription
        ]);

        // TODO: Initialize Paystack transaction with subscription plan

        return [
            'status'       => 'success',
            'nextAction'   => 'paystack',
            'actionName'   => 'popup',
            'message'      => __('Opening Paystack payment popup...', 'paystack-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'paystack_data' => $paymentData,
                'transaction_ref' => $transaction->uuid
            ])
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=paystack');
    }
}

