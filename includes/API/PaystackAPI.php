<?php
/**
 * Paystack API Handler
 *
 * @package PaystackFluentCart
 * @since 1.0.0
 */


namespace PaystackFluentCart\API;

use PaystackFluentCart\Settings\PaystackSettingsBase;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}


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
        // Input validation
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        
        // Validate HTTP method
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $url = self::$baseUrl . $endpoint;
        $secretKey = self::getSettings()->getSecretKey();

        if (!$secretKey) {
            return new \WP_Error('missing_api_key', 'Paystack API key is not configured');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . sanitize_text_field($secretKey),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'PaystackFluentCart/1.0.0 WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 30,
            'sslverify' => true, // Always verify SSL
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
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

