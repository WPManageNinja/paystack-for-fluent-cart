<?php

namespace PaystackFluentCart\API;

use PaystackFluentCart\Settings\PaystackSettingsBase;

class PaystackAPI
{
    private $baseUrl = 'https://api.paystack.co';
    private $settings;

    public function __construct()
    {
        $this->settings = new PaystackSettingsBase();
    }

    /**
     * Make API request to Paystack
     */
    private function request($endpoint, $method = 'GET', $data = [])
    {
        $url = $this->baseUrl . $endpoint;
        $secretKey = $this->settings->getSecretKey();

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

    /**
     * Initialize transaction
     */
    public function initializeTransaction($data)
    {
        return $this->request('/transaction/initialize', 'POST', $data);
    }

    /**
     * Verify transaction
     */
    public function verifyTransaction($reference)
    {
        return $this->request('/transaction/verify/' . $reference, 'GET');
    }

    /**
     * Create subscription
     */
    public function createSubscription($data)
    {
        return $this->request('/subscription', 'POST', $data);
    }

    /**
     * Disable subscription
     */
    public function disableSubscription($code, $token)
    {
        return $this->request('/subscription/disable', 'POST', [
            'code' => $code,
            'token' => $token
        ]);
    }

    /**
     * Create refund
     */
    public function createRefund($reference, $amount)
    {
        return $this->request('/refund', 'POST', [
            'transaction' => $reference,
            'amount' => $amount
        ]);
    }

    /**
     * Get transaction
     */
    public function getTransaction($id)
    {
        return $this->request('/transaction/' . $id, 'GET');
    }

    /**
     * Get subscription
     */
    public function getSubscription($code)
    {
        return $this->request('/subscription/' . $code, 'GET');
    }
}

