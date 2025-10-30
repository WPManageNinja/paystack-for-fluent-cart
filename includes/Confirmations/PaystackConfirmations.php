<?php

namespace PaystackFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;

class PaystackConfirmations
{
    public function init()
    {
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPayment'], 10, 1);
    }

    /**
     * Confirm payment on redirect page
     */
    public function maybeConfirmPayment($data)
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        if ($isReceipt || $method !== 'paystack') {
            return;
        }

        $transactionHash = Arr::get($data, 'trx_hash', '');
        $reference = Arr::get($_GET, 'reference', '');

        if (!$reference) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'paystack')
            ->first();

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        // TODO: Verify payment with Paystack API using reference
        // For now, we'll rely on webhook for confirmation
        
        fluent_cart_add_log('Paystack Payment Return', 'Customer returned from Paystack. Ref: ' . $reference, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Confirm payment by charge
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $chargeData = [])
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = $transaction->order;
        if (!$order) {
            return;
        }

        // Update transaction
        $transaction->update([
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => Arr::get($chargeData, 'id'),
            'total'            => Arr::get($chargeData, 'amount'),
            'card_last_4'      => Arr::get($chargeData, 'authorization.last4'),
            'card_brand'       => Arr::get($chargeData, 'authorization.card_type'),
        ]);

        // Sync order status
        (new StatusHelper($order))->syncOrderStatuses($transaction);

        fluent_cart_add_log('Paystack Payment Confirmation', 'Payment confirmed. Transaction ID: ' . $transaction->id, 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        return $order;
    }
}

