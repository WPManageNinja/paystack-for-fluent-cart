<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;
use PaystackFluentCart\PaystackGateway;

class PaystackTestConnectionTest extends WPTestCase
{
    /** @var array<int, array{url:string, method:string, auth:string}> */
    private array $calls = [];

    /** @var array{0:int, 1:mixed} */
    private array $response = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            $this->calls[] = [
                'url'    => $url,
                'method' => (string) ($args['method'] ?? ''),
                'auth'   => (string) ($args['headers']['Authorization'] ?? ''),
            ];

            return [
                'headers'  => [],
                'body'     => is_string($this->response[1]) ? $this->response[1] : wp_json_encode($this->response[1]),
                'response' => ['code' => $this->response[0], 'message' => ''],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function gateway(string $testKey, string $liveKey): PaystackGateway
    {
        $gateway = new PaystackGateway();
        $gateway->settings->settings['test_secret_key'] = Helper::encryptKey($testKey);
        $gateway->settings->settings['live_secret_key'] = Helper::encryptKey($liveKey);

        return $gateway;
    }

    public function test_core_shows_the_button_for_paystack(): void
    {
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new PaystackGateway()));
    }

    public function test_live_key_accepted_by_paystack(): void
    {
        $this->response = [200, ['status' => true, 'message' => 'Balances retrieved', 'data' => [['currency' => 'NGN', 'balance' => 0]]]];

        $result = $this->gateway('sk_test_suite', 'sk_live_suite')->testConnection('live');

        $this->assertSame(['message' => 'Paystack accepted the live secret key.'], $result);
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://api.paystack.co/balance', $this->calls[0]['url']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('Bearer sk_live_suite', $this->calls[0]['auth']);
    }

    public function test_test_key_accepted_by_paystack(): void
    {
        $this->response = [200, ['status' => true, 'message' => 'Balances retrieved', 'data' => []]];

        $result = $this->gateway('sk_test_suite', 'sk_live_suite')->testConnection('test');

        $this->assertSame(['message' => 'Paystack accepted the test secret key.'], $result);
        $this->assertSame('Bearer sk_test_suite', $this->calls[0]['auth']);
    }

    public function test_missing_live_key_makes_no_call(): void
    {
        $result = $this->gateway('sk_test_suite', '')->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('missing_credentials', $result->get_error_code());
        $this->assertSame('No live secret key is saved.', $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public function test_missing_test_key_makes_no_call(): void
    {
        $result = $this->gateway('', 'sk_live_suite')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('No test secret key is saved.', $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public function test_api_rejection_carries_paystack_message(): void
    {
        $this->response = [401, ['status' => false, 'message' => 'Invalid key']];

        $result = $this->gateway('sk_test_bad', '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('paystack_api_error', $result->get_error_code());
        $this->assertSame('Invalid key', $result->get_error_message());
        $this->assertCount(1, $this->calls);
    }

    public function test_success_status_without_status_true_is_an_error(): void
    {
        $this->response = [200, '<html>proxy</html>'];

        $result = $this->gateway('sk_test_suite', '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('paystack_api_error', $result->get_error_code());
    }

    public function test_test_key_in_live_field_is_a_mode_mismatch_without_a_call(): void
    {
        $result = $this->gateway('', 'sk_test_suite')->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
        $this->assertStringContainsString('sk_live_', $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public function test_live_key_in_test_field_is_a_mode_mismatch_without_a_call(): void
    {
        $result = $this->gateway('sk_live_suite', '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
        $this->assertStringContainsString('sk_test_', $result->get_error_message());
        $this->assertSame([], $this->calls);
    }
}
