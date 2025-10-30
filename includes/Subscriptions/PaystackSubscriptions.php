<?php

namespace PaystackFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\Framework\Support\Arr;

class PaystackSubscriptions extends AbstractSubscriptionModule
{
    /**
     * Re-sync subscription from Paystack
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'paystack') {
            return new \WP_Error('invalid_payment_method', __('This subscription is not using Paystack as payment method.', 'paystack-for-fluent-cart'));
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'paystack-for-fluent-cart')
            );
        }

        // TODO: Fetch subscription from Paystack API
        // TODO: Sync payments
        // TODO: Update subscription status

        return $subscriptionModel;
    }

    /**
     * Cancel subscription on Paystack
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionModel = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'paystack-for-fluent-cart')
            );
        }

        // TODO: Cancel subscription via Paystack API

        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => current_time('mysql')
        ];
    }

    /**
     * Transform Paystack subscription status to FluentCart status
     */
    private function transformSubscriptionStatus($paystackStatus)
    {
        $statusMap = [
            'active'    => Status::SUBSCRIPTION_ACTIVE,
            'complete'  => Status::SUBSCRIPTION_COMPLETED,
            'cancelled' => Status::SUBSCRIPTION_CANCELED,
            'non-renewing' => Status::SUBSCRIPTION_EXPIRED,
        ];

        return $statusMap[$paystackStatus] ?? Status::SUBSCRIPTION_PENDING;
    }
}

