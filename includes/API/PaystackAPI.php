<?php

namespace PaystackFluentCart\API;

use PaystackFluentCart\Settings\PaystackSettingsBase;

class PaystackAPI
{
    private static $baseUrl = 'https://api.paystack.co/';
    private static $settings = null;

    /**
     * Get settings instance
     */
    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new PaystackSettingsBase();
        }
        return self::$settings;
    }


    private static function request($endpoint, $method = 'GET', $data = [])
    {
        $url = self::$baseUrl . $endpoint;
        $secretKey = self::getSettings()->getSecretKey();

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);
        
        if ($statusCode >= 400) {
            return new \WP_Error(
                'paystack_api_error',
                $decoded['message'] ?? 'Unknown Paystack API error',
                ['status' => $statusCode, 'response' => $decoded]
            );
        }

        return $decoded;
    }


    public static function getPaystackObject($endpoint, $params = [])
    {
        return self::request($endpoint, 'GET', $params);
    }

    public static function createPaystackObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }

    public static function deletePaystackObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }
}

