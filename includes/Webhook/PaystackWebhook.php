<?php

namespace PaystackFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use PaystackFluentCart\Settings\PaystackSettingsBase;

class PaystackWebhook
{
    public function init()
    {
        // Add any webhook initialization logic here
    }

    /**
     * Verify and process Paystack webhook
     */
    public function verifyAndProcess()
    {
        // Get webhook payload
        $input = @file_get_contents('php://input');
        $event = json_decode($input, true);

        if (!$event) {
            http_response_code(400);
            exit('Invalid payload');
        }

        // Verify webhook signature
        if (!$this->verifySignature($input)) {
            http_response_code(401);
            exit('Invalid signature');
        }

        // Process the event
        $eventType = Arr::get($event, 'event');
        
        switch ($eventType) {
            case 'charge.success':
                $this->handleChargeSuccess($event);
                break;
            
            case 'subscription.create':
                $this->handleSubscriptionCreate($event);
                break;
            
            case 'subscription.disable':
                $this->handleSubscriptionDisable($event);
                break;
            
            case 'refund.processed':
                $this->handleRefundProcessed($event);
                break;
            
            default:
                // Log unhandled event
                fluent_cart_add_log('Paystack Webhook', 'Unhandled event: ' . $eventType, 'info');
                break;
        }

        http_response_code(200);
        exit('Webhook processed');
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
    private function handleChargeSuccess($event)
    {
        $data = Arr::get($event, 'data', []);
        $reference = Arr::get($data, 'reference');
        
        if (!$reference) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $reference)
            ->where('payment_method', 'paystack')
            ->first();

        if (!$transaction) {
            return;
        }

        // Update transaction
        $transaction->update([
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => Arr::get($data, 'id'),
            'total'            => Arr::get($data, 'amount'),
        ]);

        // Sync order status
        if ($transaction->order) {
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        }

        fluent_cart_add_log('Paystack Payment Success', 'Payment confirmed via webhook. Ref: ' . $reference, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Handle subscription creation
     */
    private function handleSubscriptionCreate($event)
    {
        // TODO: Implement subscription creation webhook handler
    }

    /**
     * Handle subscription disable
     */
    private function handleSubscriptionDisable($event)
    {
        // TODO: Implement subscription disable webhook handler
    }

    /**
     * Handle refund processed
     */
    private function handleRefundProcessed($event)
    {
        // TODO: Implement refund webhook handler
    }
}

