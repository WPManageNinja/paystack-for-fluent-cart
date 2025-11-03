<?php

namespace PaystackFluentCart;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;

class PaystackHelper
{
    public static function getPaystackKeys()
    {
        $settings = (new Settings\PaystackSettingsBase());

        $mode = $settings->getMode(); // 'test' or 'live'

        if ($mode === 'live') {
            $publicKey = $settings->get('live_public_key');
            $secretKey = $settings->get('live_secret_key');
        } else {
            $publicKey = $settings->get('test_public_key');
            $secretKey = $settings->get('test_secret_key');
        }

        return [
            'public_key' => trim($publicKey),
            'secret_key' => trim($secretKey),
        ];
    }

    public static function mapIntervalToPaystack($interval)
    {
        $intervalMaps = [
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'monthly',
            'yearly'  => 'annually',
        ];

        return $intervalMaps[$interval] ?? 'monthly';
    }

    public static function getFctSubscriptionStatus($status)
    {
        $statusMap = [
            'active'    => status::SUBSCRIPTION_ACTIVE,
            'inactive'  => status::SUBSCRIPTION_EXPIRED,
            'cancelled' => status::SUBSCRIPTION_CANCELED,
            'paused'    => status::SUBSCRIPTION_PAUSED
        ];

        return $statusMap[$status] ?? 'active';
    }

    public static function getMinimumAmountForAuthorization($currency)
    {
        $currency = strtoupper($currency);
        $minimumAmounts = [
            'NGN' => 50.00,
            'GHS' => 0.10,
            'ZAR' => 1.00,
            'KES' => 3.00,
            'USD' => 2.00
        ];

        return $minimumAmounts[$currency] * 100 ?? 100;
    }
}