<?php

namespace Tests\Unit\Services\Otp;

use App\Services\Otp\Channels\SmsOtpChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsOtpChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.africastalking.username' => 'sandbox',
            'services.africastalking.api_key' => 'test-key',
            'services.africastalking.sender_id' => 'MIVAFID',
        ]);
    }

    public function test_sends_the_code_by_sms(): void
    {
        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => []]], 200)]);

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.africastalking.com/version1/messaging'
                && $request->hasHeader('apiKey', 'test-key')
                && $request['username'] === 'sandbox'
                && $request['to'] === '+22890000001'
                && str_contains($request['message'], '123456');
        });
    }

    public function test_returns_false_on_provider_error_without_throwing(): void
    {
        Http::fake(['api.africastalking.com/*' => Http::response(['error' => 'insufficient balance'], 500)]);

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
    }

    public function test_returns_false_when_credentials_are_missing(): void
    {
        config(['services.africastalking.api_key' => null]);
        Http::fake();

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }
}
