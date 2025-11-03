<?php

namespace PaystackFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use PaystackFluentCart\API\PaystackAPI;
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
            'amount'    => (int)($transaction->total), // Amount in lowest currency unit (kobo for NGN), cents for USD e.t.c
            'email'     => $fcCustomer->email,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid . '_' . time(),
            'metadata'  => [
                'order_id'         => $order->id,
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];


        // Apply filters for customization
        $paymentData = apply_filters('fluent_cart/paystack/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Initialize Paystack transaction
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

        return [
            'status'       => 'success',
            'nextAction'   => 'paystack',
            'actionName'   => 'custom',
            'message'      => __('Opening Paystack payment popup...', 'paystack-for-fluent-cart'),
            'data'         => [
                'paystack_data'    => $paystackTransaction['data'],
                'intent'           => 'onetime',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=paystack');
    }
}

