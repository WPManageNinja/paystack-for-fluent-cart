<?php

namespace PaystackFluentCart\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class PaystackSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_paystack';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('paystack_fc/paystack_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'        => 'no',
            'test_public_key'  => '',
            'test_secret_key'  => '',
            'live_public_key'  => '',
            'live_secret_key'  => '',
            'payment_mode'     => 'test',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getSecretKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $secretKey = $this->get('test_secret_key');
        } else {
            $secretKey = $this->get('live_secret_key');
        }

        return Helper::decryptKey($secretKey);
    }

    public function getPublicKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_public_key');
        } else {
            return $this->get('live_public_key');
        }
    }
}

